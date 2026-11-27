<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Contracts\DurableAuditStore;
use Illuminate\Contracts\Encryption\Encrypter;

/** Trusted local administration only. Never retries a handler or reopens a handle. */
final class OperationRecovery
{
    public function __construct(
        private readonly OperationStore $store,
        private readonly OperationCoordinator $coordinator,
        private readonly Encrypter $encrypter,
        private readonly AuditStore $audit,
    ) {}

    public function inspect(string $id, int $expectedVersion): Operation
    {
        $operation = $this->store->find($id);
        if ($operation === null || $operation->version !== $expectedVersion || $operation->state !== OperationState::OutcomeUnknown) {
            throw new \RuntimeException('Only the inspected unknown outcome can be reconciled. Executing operations remain reserved.');
        }
        $this->coordinator->intent($operation);

        return $operation;
    }

    public function reconcile(string $id, int $expectedVersion, string $reportedBy, string $outcome, string $evidence): Operation
    {
        $operation = $this->inspect($id, $expectedVersion);
        $report = new OperationReconciliation($id, $operation->bindingDigest, $operation->version,
            $reportedBy, $outcome, $evidence, now()->getTimestamp(), max($operation->expiresAt, now()->getTimestamp() + 30 * 86400));
        $next = $operation->transition(OperationState::Reconciled,
            encryptedReconciliation: $this->encrypter->encrypt($report->toJson(), false), retainUntil: $report->retainUntil);
        if (! $this->audit instanceof DurableAuditStore) {
            throw new \LogicException('Reconciliation requires durable audit.');
        }
        $this->audit->writeDurable(['level' => 'info', 'message' => 'Operation reconciliation admitted',
            'tool' => 'operation-recovery', 'status' => 'admitted', 'timestamp' => gmdate('c'),
            'metadata' => ['schema' => 1, 'operation_id' => $operation->id, 'expected_version' => $expectedVersion,
                'reported_outcome' => $outcome]]);
        if (! $this->store->transition($operation, $next)) {
            throw new \RuntimeException('Operation changed during reconciliation. Inspect it again.');
        }

        return $next;
    }

    public function report(Operation $operation): OperationReconciliation
    {
        if ($operation->state !== OperationState::Reconciled || $operation->encryptedReconciliation === null) {
            throw new \RuntimeException('No reconciliation report is retained.');
        }
        $json = $this->encrypter->decrypt($operation->encryptedReconciliation, false);
        if (! is_string($json)) {
            throw new \RuntimeException('Invalid reconciliation report.');
        }
        $report = OperationReconciliation::fromJson($json);
        if (! hash_equals($report->operationId, $operation->id) || ! hash_equals($report->bindingDigest, $operation->bindingDigest)
            || $report->previousVersion !== $operation->version - 1 || $report->retainUntil !== $operation->expiresAt) {
            throw new \RuntimeException('Reconciliation report does not belong to this operation.');
        }

        return $report;
    }
}
