<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

/** Created by trusted transport code, never deserialized from tool arguments. */
final readonly class ExecutionContext
{
    public function __construct(
        public string $actorId,
        public string $grantId,
        public string $site,
        public string $profile,
        public string $transport,
        public string $operationId,
        public int $deadlineNanoseconds,
        public ?string $credentialId = null,
        public ?string $credentialRevision = null,
    ) {
        if (strlen($operationId) > 128 || strlen($actorId) > 255 || strlen($grantId) > 255
            || $actorId === '' || $grantId === '' || $site === '' || $operationId === ''
            || ! in_array($transport, ['http', 'stdio'], true)
            || ($credentialRevision !== null && ! preg_match('/^[a-f0-9]{64}$/D', $credentialRevision))) {
            throw new \InvalidArgumentException('Invalid execution context.');
        }
    }

    public function withOperationId(string $operationId): self
    {
        return new self($this->actorId, $this->grantId, $this->site, $this->profile,
            $this->transport, $operationId, $this->deadlineNanoseconds, $this->credentialId, $this->credentialRevision);
    }

    public function checkDeadline(): void
    {
        if (hrtime(true) >= $this->deadlineNanoseconds) {
            throw new \RuntimeException('Execution deadline exceeded before starting work.');
        }
    }
}
