<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\Operations\OperationRecovery;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Illuminate\Console\Command;

/** Local administrator recovery inspection. Never resumes or retries an operation. */
final class InspectOperationCommand extends Command
{
    protected $signature = 'mcp:inspect-operation {id : Server-issued operation handle}';

    protected $description = 'Inspect retained operation state and recovery identity without exposing input or results';

    public function handle(OperationStore $store, OperationCoordinator $coordinator, OperationRecovery $recovery): int
    {
        $id = $this->argument('id');
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{64}$/D', $id)) {
            $this->error('A 64-character operation handle is required.');

            return self::INVALID;
        }
        try {
            $operation = $store->find($id);
            if ($operation === null) {
                $this->error('Operation not retained. Absence is not proof that no write occurred.');

                return self::FAILURE;
            }
            $intent = $coordinator->intent($operation);
            $data = [
                'schema' => 1, 'operation_id' => $operation->id, 'state' => $operation->state->value,
                'version' => $operation->version,
                $operation->state === OperationState::Reconciled ? 'retained_until' : 'retry_until' => gmdate('c', $operation->expiresAt),
                'actor_id' => $intent->actorId, 'grant_id' => $intent->grantId,
                'action_id' => $intent->actionId, 'action_version' => $intent->actionVersion,
                'action_fingerprint' => $intent->actionFingerprint, 'effect' => $intent->effect->value,
                'site' => $intent->site, 'profile' => $intent->profile, 'transport' => $intent->transport,
                'input_digest' => $intent->inputDigest, 'prepared_at' => gmdate('c', $intent->preparedAt),
            ];
            if ($operation->state === OperationState::Reconciled) {
                $report = $recovery->report($operation);
                $data['reconciliation'] = ['reported_outcome' => $report->outcome, 'reported_by' => $report->reportedBy,
                    'recorded_at' => gmdate('c', $report->recordedAt), 'evidence_sha256' => hash('sha256', $report->evidence)];
            }
            $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Operation recovery identity could not be verified. No state was changed.');

            return self::FAILURE;
        }
    }
}
