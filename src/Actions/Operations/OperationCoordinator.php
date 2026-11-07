<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Support\InputBudget;
use Illuminate\Contracts\Encryption\Encrypter;

/** Authorization must be rechecked before entry, including result replay. */
final class OperationCoordinator
{
    public function __construct(private readonly OperationStore $store, private readonly Encrypter $encrypter, private readonly int $retrySeconds = 3600)
    {
        if ($retrySeconds < 60 || $retrySeconds > 86400) {
            throw new \InvalidArgumentException('Operation retry window must be between one minute and one day.');
        }
    }

    /** Preparation never runs the handler; execution cannot recreate a missing handle.
     * @param  array<string, mixed>  $input
     */
    public function prepare(ActionDefinition $action, array $input, ExecutionContext $context): Operation
    {
        if ($action->effect === ActionEffect::Read) {
            throw new \InvalidArgumentException('Read actions do not require preparation.');
        }
        $context->checkDeadline();
        $id = bin2hex(random_bytes(32));
        $binding = self::binding($action, $input, $context);
        $intent = OperationIntent::capture($id, $binding, self::inputDigest($input), $action, $context);
        $prepared = new Operation($id, $binding, OperationState::Prepared, 1,
            now()->getTimestamp() + $this->retrySeconds,
            encryptedIntent: $this->encrypter->encrypt($intent->toJson(), false));
        if (! $this->store->create($prepared)) {
            throw new \RuntimeException('Cannot reserve operation handle.');
        }
        $next = $prepared->transition($action->effect === ActionEffect::Edit
            ? OperationState::Ready : OperationState::AwaitingApproval);
        if (! $this->store->transition($prepared, $next)) {
            throw new \RuntimeException('Operation preparation conflict.');
        }

        return $next;
    }

    /** @param array<string, mixed> $input
     * @param  callable(): array<string, mixed>  $execute
     */
    public function execute(ActionDefinition $action, array $input, ExecutionContext $context, callable $execute): OperationResult
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $context->operationId)) {
            throw new \RuntimeException('A server-issued prepared operation handle is required.');
        }
        $existing = $this->store->find($context->operationId);
        if ($existing === null) {
            throw new \RuntimeException('Operation is unknown or no longer retained. Reconcile the previous outcome.');
        }
        if (! hash_equals(self::binding($action, $input, $context), $existing->bindingDigest)) {
            throw new \RuntimeException('Operation handle conflicts with this request.');
        }
        // Legacy/corrupt records cannot enter the handler without durable recovery context.
        $this->intent($existing);
        if ($existing->expiresAt <= now()->getTimestamp()) {
            throw new \RuntimeException('Operation retry window expired. Reconcile the previous outcome.');
        }
        if ($existing->state === OperationState::Succeeded && $existing->encryptedResult !== null) {
            $json = $this->encrypter->decrypt($existing->encryptedResult, false);
            if (! is_string($json)) {
                throw new \RuntimeException('Invalid stored operation result.');
            }
            $data = json_decode($json, true, 258, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \RuntimeException('Invalid stored operation result.');
            }
            $result = [];
            foreach ($data as $key => $value) {
                if (! is_string($key)) {
                    throw new \RuntimeException('Invalid stored operation result key.');
                }
                $result[$key] = $value;
            }

            return new OperationResult($result, true);
        }
        if ($existing->state !== OperationState::Ready) {
            throw new \RuntimeException('Operation is pending or has an unresolved outcome. It will not be executed again.');
        }
        $ready = $existing;
        $context->checkDeadline();
        $executing = $ready->transition(OperationState::Executing);
        if (! $this->store->transition($ready, $executing)) {
            throw new \RuntimeException('Operation already claimed.');
        }
        $callbackStarted = false;
        try {
            $context->checkDeadline();
            if ($executing->expiresAt <= now()->getTimestamp()) {
                throw new \RuntimeException('Operation expired before execution.');
            }
            $callbackStarted = true;
            $data = $execute();
            // Match the executor result budget before invoking any serializer.
            (new InputBudget(1048576, 20000, 256))->check($data);
            $json = json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 258);
            if (strlen($json) > 1048576) {
                throw new \RuntimeException('Operation result exceeds storage budget.');
            }
            $encrypted = $this->encrypter->encrypt($json, false);
            $complete = $executing->transition(OperationState::Succeeded, $encrypted);
            if (! $this->store->transition($executing, $complete)) {
                throw new \RuntimeException('Operation completion conflict.');
            }

            return new OperationResult($data, false);
        } catch (\Throwable $error) {
            try {
                $this->store->transition($executing, $executing->transition($callbackStarted ? OperationState::OutcomeUnknown : OperationState::FailedNoEffect));
            } catch (\Throwable) {
                // The durable executing marker also prevents replay after a storage failure.
            }
            throw $error;
        }
    }

    /** Trusted recovery API, not an authorization decision or a public MCP response. */
    public function intent(Operation $operation): OperationIntent
    {
        if ($operation->encryptedIntent === null) {
            throw new \RuntimeException('Operation has no recovery intent. Reconcile its outcome.');
        }
        $json = $this->encrypter->decrypt($operation->encryptedIntent, false);
        if (! is_string($json)) {
            throw new \RuntimeException('Invalid stored operation intent.');
        }
        $intent = OperationIntent::fromJson($json);
        if (! hash_equals($operation->id, $intent->operationId)
            || ! hash_equals($operation->bindingDigest, $intent->bindingDigest)) {
            throw new \RuntimeException('Operation intent belongs to a different operation.');
        }

        return $intent;
    }

    /** @param array<string, mixed> $input */
    private static function inputDigest(array $input): string
    {
        return hash('sha256', json_encode(self::canonical($input), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $input */
    private static function binding(ActionDefinition $action, array $input, ExecutionContext $context): string
    {
        return hash('sha256', json_encode([
            $context->actorId, $context->grantId, $action->fingerprint(), $context->site,
            $context->profile, $context->transport, self::canonical($input),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<array-key, mixed> $value
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
