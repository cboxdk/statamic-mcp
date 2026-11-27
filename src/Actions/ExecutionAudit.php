<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Contracts\DurableAuditStore;
use Psr\Log\LoggerInterface;

/** Write admission is mandatory and independent of optional telemetry/log sampling. */
final class ExecutionAudit
{
    public function __construct(private readonly AuditStore $store, private readonly LoggerInterface $logger) {}

    public function admit(ActionDefinition $action, ExecutionContext $context, string $executionId): void
    {
        if (! $this->store instanceof DurableAuditStore) {
            throw new \LogicException('Registered writes require a durable audit store.');
        }
        $this->store->writeDurable($this->entry($action, $context, $executionId, 'admitted', 'none'));
    }

    /** OperationStore remains the authoritative outcome if this secondary record fails. */
    public function outcome(ActionDefinition $action, ExecutionContext $context, string $executionId, string $outcome, string $effects): void
    {
        try {
            if (! $this->store instanceof DurableAuditStore) {
                throw new \LogicException('Durable audit store unavailable.');
            }
            $this->store->writeDurable($this->entry($action, $context, $executionId, $outcome, $effects));
        } catch (\Throwable) {
            try {
                $this->logger->warning('MCP outcome audit unavailable; inspect the retained operation.', ['operation_id' => $context->operationId]);
            } catch (\Throwable) {
                // An already committed write must not become a retryable logging error.
            }
        }
    }

    /** @return array{level: string, message: string, tool: string, action: string, status: string, correlation_id: string, timestamp: string, metadata: array<string, mixed>} */
    private function entry(ActionDefinition $action, ExecutionContext $context, string $executionId, string $status, string $effects): array
    {
        return ['level' => 'info', 'message' => 'Registered action execution', 'tool' => $action->id(),
            'action' => $action->name, 'status' => $status, 'correlation_id' => $executionId,
            'timestamp' => gmdate('c'), 'metadata' => ['schema_version' => 1,
                'operation_id' => $context->operationId, 'action_version' => $action->version,
                'action_fingerprint' => $action->fingerprint(), 'transport' => $context->transport,
                'effect' => $action->effect->value, 'effects' => $effects]];
    }
}
