<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Runs a built-in write at most once per idempotency key.
 *
 * The operation record is created before the write starts, so a retry finds it
 * whatever happened since: a finished write replays its stored result, a write
 * that saved nothing leaves the key spent, and a write that started and never
 * reported (a crash, a killed worker, a lost store) answers "unknown" and is
 * never run a second time. Two concurrent calls with the same key race on the
 * store's atomic create; only the winner writes.
 */
final class IdempotentWrite
{
    /** Same budget as OperationCoordinator's stored results. */
    private const RESULT_BUDGET = 1048576;

    public function __construct(private readonly OperationStore $store, private readonly Encrypter $encrypter, private readonly int $retrySeconds = 3600)
    {
        if ($retrySeconds < 60 || $retrySeconds > 86400) {
            throw new \InvalidArgumentException('Operation retry window must be between one minute and one day.');
        }
    }

    /**
     * The recovery intent for one keyed call. The operation id depends on the
     * actor and the key only, so the same key with a different request finds
     * the first record and is refused instead of running.
     *
     * @param  array<string, mixed>  $input
     */
    public static function intent(string $key, string $actorId, string $grantId, string $domain, string $action,
        ActionEffect $effect, string $site, string $profile, string $transport, array $input): OperationIntent
    {
        $canonical = json_encode(self::canonical($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $domain = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($domain)), '-') ?: 'tool';
        $actionId = 'statamic-mcp/' . $domain . '/' . $action;
        $fingerprint = hash('sha256', $actionId);

        return new OperationIntent(
            hash('sha256', "statamic-mcp:idempotency\0{$actorId}\0{$key}"),
            hash('sha256', implode("\0", [$actorId, $grantId, $actionId, $site, $profile, $transport, $canonical])),
            $actorId, $grantId, $actionId, 1, $fingerprint, $effect, $site, $profile, $transport,
            hash('sha256', $canonical), now()->getTimestamp(),
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $write
     * @param  callable(): ?string  $effects  what the write reports it did: 'committed', 'unknown' or null for nothing
     *
     * @return array{status: 'executed'|'replayed', result: array<string, mixed>}|array{status: 'reused'|'spent'|'unknown'|'expired'}
     */
    public function run(OperationIntent $intent, callable $write, callable $effects): array
    {
        $prepared = new Operation($intent->operationId, $intent->bindingDigest, OperationState::Prepared, 1,
            now()->getTimestamp() + $this->retrySeconds,
            encryptedIntent: $this->encrypter->encrypt($intent->toJson(), false));
        if (! $this->store->create($prepared)) {
            return $this->existing($intent);
        }
        $ready = $prepared->transition(OperationState::Ready);
        $executing = $ready->transition(OperationState::Executing);
        if (! $this->store->transition($prepared, $ready) || ! $this->store->transition($ready, $executing)) {
            return ['status' => 'unknown'];
        }

        try {
            $result = $write();
        } catch (\Throwable $error) {
            $this->finish($executing, $effects() === null ? OperationState::FailedNoEffect : OperationState::OutcomeUnknown);
            throw $error;
        }

        $effect = $effects();
        if ($effect === 'unknown') {
            $this->finish($executing, OperationState::OutcomeUnknown);
        } elseif ($effect === null && ($result['success'] ?? true) === false) {
            $this->finish($executing, OperationState::FailedNoEffect);
        } else {
            $this->finish($executing, OperationState::Succeeded, $this->encryptResult($result));
        }

        return ['status' => 'executed', 'result' => $result];
    }

    /** @return array{status: 'replayed', result: array<string, mixed>}|array{status: 'reused'|'spent'|'unknown'|'expired'} */
    private function existing(OperationIntent $intent): array
    {
        $operation = $this->store->find($intent->operationId);
        if ($operation === null) {
            // Created by someone else and already pruned: its window has passed.
            return ['status' => 'expired'];
        }
        if (! hash_equals($operation->bindingDigest, $intent->bindingDigest)) {
            return ['status' => 'reused'];
        }

        return match ($operation->state) {
            OperationState::Succeeded => $operation->expiresAt <= now()->getTimestamp() || $operation->encryptedResult === null
                ? ['status' => 'expired']
                : ['status' => 'replayed', 'result' => $this->decryptResult($operation->encryptedResult)],
            OperationState::FailedNoEffect => ['status' => 'spent'],
            OperationState::Prepared, OperationState::Ready, OperationState::Executing,
            OperationState::OutcomeUnknown, OperationState::Reconciled => ['status' => 'unknown'],
            default => ['status' => 'expired'],
        };
    }

    private function finish(Operation $executing, OperationState $state, ?string $encryptedResult = null): void
    {
        try {
            $this->store->transition($executing, $executing->transition($state, $encryptedResult));
        } catch (\Throwable) {
            // The record stays executing, which a retry reports as unknown: never run twice.
        }
    }

    /** @param array<string, mixed> $result */
    private function encryptResult(array $result): string
    {
        $json = json_encode((object) $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 258);
        if (strlen($json) > self::RESULT_BUDGET) {
            // The write landed; only its response is too large to keep.
            $json = json_encode(['result_retained' => false, 'note' => 'The write was saved. Its response was too large to keep for replay; read the record.'], JSON_THROW_ON_ERROR);
        }

        return $this->encrypter->encrypt($json, false);
    }

    /** @return array<string, mixed> */
    private function decryptResult(string $encrypted): array
    {
        $json = $this->encrypter->decrypt($encrypted, false);
        $data = is_string($json) ? json_decode($json, true, 258, JSON_THROW_ON_ERROR) : null;
        if (! is_array($data)) {
            throw new \RuntimeException('Invalid stored operation result.');
        }
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::canonical($child);
            }
        }

        return $value;
    }
}
