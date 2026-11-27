<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Runs a built-in write at most once per operation record.
 *
 * The record exists before the write starts, so a retry finds it whatever
 * happened since: a finished write replays its stored result, a write that
 * saved nothing is spent, and a write that started and never reported (a
 * crash, a killed worker, a lost store) answers "unknown" and is never run a
 * second time. Idempotency keys and approvals both run through here.
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
     * @param  array{id: string, fingerprint: string}|null  $capability
     */
    public static function intent(string $key, string $actorId, string $grantId, string $domain, string $action,
        ActionEffect $effect, string $site, string $profile, string $transport, array $input, ?array $capability = null, int $version = 1): OperationIntent
    {
        return OperationIntent::forToolCall(hash('sha256', "statamic-mcp:idempotency\0{$actorId}\0{$key}"),
            $actorId, $grantId, $domain, $action, $effect, $site, $profile, $transport, $input, $capability, $version);
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
            $existing = $this->store->find($intent->operationId);

            // Gone already: created by someone else and pruned, its window passed.
            return $existing === null ? ['status' => 'expired'] : $this->outcome($existing, $intent->bindingDigest);
        }
        $ready = $prepared->transition(OperationState::Ready);
        if (! $this->store->transition($prepared, $ready)) {
            return ['status' => 'unknown'];
        }

        return $this->runReady($ready, $write, $effects);
    }

    /**
     * Claim a ready operation and run its write. Only one caller can claim it;
     * every other caller is told the outcome is unknown.
     *
     * @param  callable(): array<string, mixed>  $write
     * @param  callable(): ?string  $effects
     *
     * @return array{status: 'executed', result: array<string, mixed>}|array{status: 'unknown'}
     */
    public function runReady(Operation $ready, callable $write, callable $effects): array
    {
        $executing = $ready->transition(OperationState::Executing);
        if (! $this->store->transition($ready, $executing)) {
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

    /**
     * What a retry of an operation that is not ready gets.
     *
     * @return array{status: 'replayed', result: array<string, mixed>}|array{status: 'reused'|'spent'|'unknown'|'expired'}
     */
    public function outcome(Operation $operation, string $bindingDigest): array
    {
        if (! hash_equals($operation->bindingDigest, $bindingDigest)) {
            return ['status' => 'reused'];
        }

        return match ($operation->state) {
            OperationState::Succeeded => $operation->expiresAt <= now()->getTimestamp() || $operation->encryptedResult === null
                ? ['status' => 'expired']
                : ['status' => 'replayed', 'result' => $this->decryptResult($operation->encryptedResult)],
            OperationState::FailedNoEffect => ['status' => 'spent'],
            OperationState::Prepared, OperationState::Ready, OperationState::AwaitingApproval, OperationState::Executing,
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
}
