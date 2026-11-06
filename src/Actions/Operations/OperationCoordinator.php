<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Illuminate\Contracts\Encryption\Encrypter;

/** Authorization must be rechecked before entry, including result replay. */
final class OperationCoordinator
{
    public function __construct(private readonly OperationStore $store, private readonly Encrypter $encrypter) {}

    /** @param array<string, mixed> $input
     * @param  callable(): array<string, mixed>  $execute
     */
    public function execute(ActionDefinition $action, array $input, ExecutionContext $context, callable $execute): OperationResult
    {
        $id = hash('sha256', json_encode([$context->actorId, $context->grantId, $context->operationId], JSON_THROW_ON_ERROR));
        $binding = hash('sha256', json_encode([
            $action->fingerprint(), $context->site, $context->profile, $context->transport, self::canonical($input),
        ], JSON_THROW_ON_ERROR));
        $prepared = new Operation($id, $binding, OperationState::Prepared, 1, time() + 3600);
        if (! $this->store->create($prepared)) {
            $existing = $this->store->find($id);
            if ($existing === null || ! hash_equals($binding, $existing->bindingDigest)) {
                throw new \RuntimeException('Operation key conflicts with a different request.');
            }
            if ($existing->expiresAt <= time()) {
                throw new \RuntimeException('Operation retry window expired. Reconcile the previous outcome.');
            }
            if ($existing->state === OperationState::Succeeded && $existing->encryptedResult !== null) {
                $json = $this->encrypter->decrypt($existing->encryptedResult, false);
                if (! is_string($json)) {
                    throw new \RuntimeException('Invalid stored operation result.');
                }
                $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
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
            throw new \RuntimeException('Operation is pending or has an unresolved outcome. It will not be executed again.');
        }
        $ready = $prepared->transition(OperationState::Ready);
        if (! $this->store->transition($prepared, $ready)) {
            throw new \RuntimeException('Operation preparation conflict.');
        }
        $context->checkDeadline();
        $executing = $ready->transition(OperationState::Executing);
        if (! $this->store->transition($ready, $executing)) {
            throw new \RuntimeException('Operation already claimed.');
        }
        try {
            $data = $execute();
            $json = json_encode((object) $data, JSON_THROW_ON_ERROR, 32);
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
                $this->store->transition($executing, $executing->transition(OperationState::OutcomeUnknown));
            } catch (\Throwable) {
                // The durable executing marker also prevents replay after a storage failure.
            }
            throw $error;
        }
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
