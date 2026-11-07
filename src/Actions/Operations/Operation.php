<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

final readonly class Operation
{
    public function __construct(
        public string $id,
        public string $bindingDigest,
        public OperationState $state,
        public int $version,
        public int $expiresAt,
        public ?string $encryptedResult = null,
        public ?string $encryptedIntent = null,
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/D', $id) || ! preg_match('/^[a-f0-9]{64}$/D', $bindingDigest)
            || $version < 1 || $expiresAt < 1 || ($encryptedResult !== null && strlen($encryptedResult) > 2_097_152)) {
            throw new \InvalidArgumentException('Invalid operation record.');
        }
        if ($encryptedIntent !== null && ($encryptedIntent === '' || strlen($encryptedIntent) > 32768)) {
            throw new \InvalidArgumentException('Invalid encrypted operation intent.');
        }
        if ($encryptedResult !== null && $state !== OperationState::Succeeded) {
            throw new \InvalidArgumentException('Only completed operations may contain a result.');
        }
    }

    public function transition(OperationState $next, ?string $encryptedResult = null): self
    {
        if (! $this->state->canTransitionTo($next)) {
            throw new \LogicException('Invalid operation transition.');
        }
        if ($this->expiresAt <= now()->getTimestamp() && in_array($next, [OperationState::Ready, OperationState::Executing], true)) {
            throw new \LogicException('Expired operation cannot start.');
        }

        return new self($this->id, $this->bindingDigest, $next, $this->version + 1, $this->expiresAt, $encryptedResult, $this->encryptedIntent);
    }

    /** @return array{id: string, binding: string, state: string, version: int, expires_at: int, result: ?string, intent: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'binding' => $this->bindingDigest, 'state' => $this->state->value,
            'version' => $this->version, 'expires_at' => $this->expiresAt, 'result' => $this->encryptedResult, 'intent' => $this->encryptedIntent];
    }

    public static function fromJson(string $json): self
    {
        $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($value) || ! is_string($value['id'] ?? null) || ! is_string($value['binding'] ?? null)
            || ! is_string($value['state'] ?? null) || ! is_int($value['version'] ?? null)
            || ! is_int($value['expires_at'] ?? null) || ! array_key_exists('result', $value)
            || (! is_string($value['result']) && $value['result'] !== null)
            || (isset($value['intent']) && ! is_string($value['intent']))) {
            throw new \RuntimeException('Corrupt operation record.');
        }

        return new self($value['id'], $value['binding'], OperationState::from($value['state']), $value['version'], $value['expires_at'], $value['result'], $value['intent'] ?? null);
    }
}
