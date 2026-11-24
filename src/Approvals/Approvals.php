<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\Events;
use Cboxdk\StatamicMcp\Actions\LifecycleEvents;
use Cboxdk\StatamicMcp\Actions\Operations\IdempotentWrite;
use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\Operations\OperationIntent;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Approvals for gated tool calls, kept as operations in the OperationStore.
 *
 * An approval is the operation it approves: awaiting_approval is pending,
 * ready is approved, cancelled is rejected, and executing onwards is claimed
 * and consumed. Claiming the approval and claiming the write are therefore
 * one atomic transition, and a crash after the claim leaves the approval
 * spent, never free for another write. Approving never grants anything: the
 * retried call is authorized again in full before the claim.
 */
final class Approvals
{
    public function __construct(
        private readonly OperationStore $store,
        private readonly OperationCoordinator $coordinator,
        private readonly IdempotentWrite $writes,
        private readonly Encrypter $encrypter,
        private readonly int $ttlSeconds = 900,
        private readonly ApprovalChannels $channels = new ApprovalChannels,
    ) {
        if ($ttlSeconds < 60 || $ttlSeconds > 86400) {
            throw new \InvalidArgumentException('Approval window must be between one minute and one day.');
        }
    }

    /**
     * Record a call that needs approval. The intent's id is random: an
     * approval handle is opaque and says nothing about the call.
     *
     * @param  array<string, mixed>  $preview  what the person is shown, and the record version to hold the write to
     */
    public function request(OperationIntent $intent, array $preview): Operation
    {
        $json = json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $prepared = new Operation($intent->operationId, $intent->bindingDigest, OperationState::Prepared, 1,
            now()->getTimestamp() + $this->ttlSeconds,
            encryptedIntent: $this->encrypter->encrypt($intent->toJson(), false),
            encryptedPreview: $this->encrypter->encrypt($json, false));
        if (! $this->store->create($prepared)) {
            throw new \RuntimeException('Cannot reserve an approval.');
        }
        $pending = $prepared->transition(OperationState::AwaitingApproval);
        if (! $this->store->transition($prepared, $pending)) {
            throw new \RuntimeException('Cannot record the approval request.');
        }
        $this->emit(Events\ApprovalRequested::class, $pending->id, $intent->actionId);
        $this->channels->announce($this->pendingApproval($pending, $intent->actorId, $preview));

        return $pending;
    }

    /**
     * Approvals waiting for this actor, newest last.
     *
     * @return list<array{id: string, expires_at: int, requested_at: int, preview: array<string, mixed>}>
     */
    public function pendingFor(string $actorId, int $limit = 50): array
    {
        $pending = [];
        foreach ($this->store->awaitingApproval(1000) as $operation) {
            try {
                $intent = $this->coordinator->intent($operation);
            } catch (\Throwable) {
                continue;
            }
            if (! hash_equals($intent->actorId, $actorId) || $this->settle($operation, $actorId) !== null) {
                continue;
            }
            $pending[] = ['id' => $operation->id, 'expires_at' => $operation->expiresAt,
                'requested_at' => $intent->preparedAt, 'preview' => $this->preview($operation)];
            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    /** The pending request this actor may decide on, or null. */
    public function pendingRequest(string $id, string $actorId): ?OperationIntent
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $id)) {
            return null;
        }
        $operation = $this->store->find($id);
        if ($operation === null || $operation->state !== OperationState::AwaitingApproval
            || $operation->expiresAt <= now()->getTimestamp()) {
            return null;
        }
        $intent = $this->coordinator->intent($operation);

        // Owner binding: only the person the call acts as may decide on it.
        return hash_equals($intent->actorId, $actorId) ? $intent : null;
    }

    /** @throws ApprovalRefused */
    public function approve(string $id, string $actorId, string $via = 'control_panel'): void
    {
        $this->decide($id, $actorId, OperationState::Ready, $via);
    }

    /** @throws ApprovalRefused */
    public function reject(string $id, string $actorId, string $via = 'control_panel'): void
    {
        $this->decide($id, $actorId, OperationState::Cancelled, $via);
    }

    /**
     * Run an approved call. The caller has already authorized it again.
     *
     * @param  callable(): array<string, mixed>  $write
     * @param  callable(): ?string  $effects
     *
     * @return array{status: 'executed'|'replayed', result: array<string, mixed>}|array{status: 'pending'|'rejected'|'stale'|'mismatch'|'expired'|'spent'|'unknown'}
     */
    public function execute(string $id, OperationIntent $intent, ?string $currentVersion, callable $write, callable $effects): array
    {
        $operation = preg_match('/^[a-f0-9]{64}$/D', $id) ? $this->store->find($id) : null;
        if ($operation === null) {
            return ['status' => 'expired'];
        }
        if (! hash_equals($operation->bindingDigest, $intent->bindingDigest)) {
            return ['status' => 'mismatch'];
        }

        switch ($operation->state) {
            case OperationState::AwaitingApproval:
                if ($operation->expiresAt <= now()->getTimestamp()) {
                    return ['status' => 'expired'];
                }
                $settled = $this->settle($operation, $intent->actorId);
                if ($settled === null) {
                    return ['status' => 'pending'];
                }
                // Re-read: the answer was recorded on the stored approval, not on $operation.
                if (! in_array($this->store->find($id)?->state, [OperationState::Ready, OperationState::Cancelled], true)) {
                    return ['status' => 'pending'];
                }

                return $this->execute($id, $intent, $currentVersion, $write, $effects);
            case OperationState::Cancelled:
                return ['status' => 'rejected'];
            case OperationState::Ready:
                if ($operation->expiresAt <= now()->getTimestamp()) {
                    return ['status' => 'expired'];
                }
                $expected = $this->preview($operation)['expected_version'] ?? null;
                if (is_string($expected) && $currentVersion !== $expected) {
                    // Approved for a record that has changed since: ask again.
                    if ($this->store->transition($operation, $operation->transition(OperationState::Cancelled))) {
                        $this->emit(Events\ApprovalRejected::class, $id, $intent->actionId, reason: 'stale');
                    }

                    return ['status' => 'stale'];
                }

                $outcome = $this->writes->runReady($operation, $write, $effects);
                if ($outcome['status'] === 'executed') {
                    $this->emit(Events\ApprovalConsumed::class, $id, $intent->actionId);
                }

                return $outcome;
            default:
                $outcome = $this->writes->outcome($operation, $intent->bindingDigest);

                return $outcome['status'] === 'reused' ? ['status' => 'mismatch'] : $outcome;
        }
    }

    /** @return array<string, mixed> */
    public function preview(Operation $operation): array
    {
        if ($operation->encryptedPreview === null) {
            return [];
        }
        $json = $this->encrypter->decrypt($operation->encryptedPreview, false);
        $data = is_string($json) ? json_decode($json, true, 64, JSON_THROW_ON_ERROR) : null;
        if (! is_array($data)) {
            return [];
        }
        $preview = [];
        foreach ($data as $key => $value) {
            $preview[(string) $key] = $value;
        }

        return $preview;
    }

    /**
     * Ask the authorities whether the person answered elsewhere (on their phone),
     * and record the answer the same way the Control Panel does.
     *
     * @return bool|null true approved, false rejected, null no answer
     */
    private function settle(Operation $operation, string $actorId): ?bool
    {
        if (! $this->channels->hasAuthorities() || $operation->expiresAt <= now()->getTimestamp()) {
            return null;
        }
        $answer = $this->channels->settle($operation->id);
        if ($answer === null) {
            return null;
        }
        try {
            $answer ? $this->approve($operation->id, $actorId, 'authority') : $this->reject($operation->id, $actorId, 'authority');
        } catch (ApprovalRefused) {
            // Decided meanwhile in the Control Panel: that answer stands.
        }

        return $answer;
    }

    /** @param  array<string, mixed>  $preview */
    private function pendingApproval(Operation $operation, string $actorId, array $preview): PendingApproval
    {
        $string = static fn (mixed $value): string => is_string($value) ? $value : '';

        return new PendingApproval($operation->id, substr($actorId, strlen('user:')), $string($preview['description'] ?? null),
            $string($preview['tool'] ?? null), $string($preview['action'] ?? null),
            is_string($preview['site'] ?? null) ? $preview['site'] : null, $operation->expiresAt,
            cp_route('statamic-mcp.approvals.index'));
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    private function decide(string $id, string $actorId, OperationState $next, string $via): void
    {
        $intent = $this->pendingRequest($id, $actorId);
        if ($intent === null) {
            throw new ApprovalRefused('This approval is not waiting for you. It may have expired, been decided already, or belong to someone else.');
        }
        $operation = $this->store->find($id);
        if ($operation === null || $operation->state !== OperationState::AwaitingApproval
            || ! $this->store->transition($operation, $operation->transition($next))) {
            throw new ApprovalRefused('This approval changed while you decided. Reload and try again.');
        }
        $this->emit($next === OperationState::Ready ? Events\ApprovalApproved::class : Events\ApprovalRejected::class,
            $id, $intent->actionId, $via, $next === OperationState::Ready ? null : 'person');
    }

    /** @param  class-string<Events\ApprovalEvent>  $event */
    private function emit(string $event, string $approvalId, string $actionId, ?string $via = null, ?string $reason = null): void
    {
        // After the state change is durable: an observer can never veto or undo it.
        LifecycleEvents::dispatch(new $event((string) Str::uuid(), $approvalId, $actionId, gmdate('Y-m-d\TH:i:s\Z'), $via, $reason));
    }
}
