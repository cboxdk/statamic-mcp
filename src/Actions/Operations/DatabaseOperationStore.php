<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/** Shared SQL storage; transitions use one compare-and-set statement. */
final class DatabaseOperationStore implements OperationStore
{
    public function __construct(private readonly Connection $connection, private readonly int $maxRecords = 256)
    {
        if ($maxRecords < 1) {
            throw new \InvalidArgumentException('Operation capacity must be positive.');
        }
    }

    public function create(Operation $operation): bool
    {
        return $this->committed(fn (): bool => $this->createCommitted($operation));
    }

    private function createCommitted(Operation $operation): bool
    {
        if ($operation->state !== OperationState::Prepared || $operation->version !== 1) {
            throw new \InvalidArgumentException('New operations must be prepared at version one.');
        }
        if ($this->connection->table('mcp_operations')->useWritePdo()->where('id', $operation->id)->exists()) {
            return false;
        }
        try {
            return $this->connection->transaction(function () use ($operation): bool {
                // The first statement is a write, also serializing SQLite reservations.
                $reserved = $this->connection->table('mcp_operation_capacity')->where('id', 1)
                    ->where('record_count', '<', $this->maxRecords)->increment('record_count');
                if ($reserved !== 1) {
                    throw new \RuntimeException('Operation storage is full or capacity metadata is missing.');
                }

                return $this->connection->table('mcp_operations')->insert([
                    'id' => $operation->id, 'version' => $operation->version, 'payload' => $this->encode($operation),
                    'state' => $operation->state->value, 'expires_at' => $operation->expiresAt,
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function find(string $id): ?Operation
    {
        return $this->committed(fn (): ?Operation => $this->findCommitted($id));
    }

    private function findCommitted(string $id): ?Operation
    {
        $payload = $this->connection->table('mcp_operations')->useWritePdo()->where('id', $id)->value('payload');
        if ($payload === null) {
            return null;
        }
        if (! is_string($payload)) {
            throw new \RuntimeException('Corrupt operation record.');
        }

        return Operation::fromJson($payload);
    }

    public function transition(Operation $expected, Operation $next): bool
    {
        return $this->committed(fn (): bool => $this->transitionCommitted($expected, $next));
    }

    private function transitionCommitted(Operation $expected, Operation $next): bool
    {
        if ($next->id !== $expected->id || $next->bindingDigest !== $expected->bindingDigest
            || $next->encryptedIntent !== $expected->encryptedIntent
            || ($next->encryptedReconciliation !== $expected->encryptedReconciliation
                && ! ($expected->state === OperationState::OutcomeUnknown && $next->state === OperationState::Reconciled))
            || ($next->expiresAt !== $expected->expiresAt
                && ! ($expected->state === OperationState::OutcomeUnknown && $next->state === OperationState::Reconciled
                    && $next->expiresAt >= $expected->expiresAt))
            || $next->version !== $expected->version + 1
            || ! $expected->state->canTransitionTo($next->state)) {
            throw new \InvalidArgumentException('Invalid operation transition binding.');
        }
        if ($expected->expiresAt <= now()->getTimestamp() && in_array($next->state, [OperationState::Ready, OperationState::Executing], true)) {
            return false;
        }

        // Compare bytes in PHP: database text collations may ignore case or spaces.
        // The monotonically increasing version makes the subsequent claim atomic.
        $stored = $this->connection->table('mcp_operations')->useWritePdo()->where('id', $expected->id)->value('payload');
        if (! is_string($stored) || ! hash_equals($this->encode($expected), $stored)) {
            return false;
        }

        return $this->connection->table('mcp_operations')->where('id', $expected->id)
            ->where('version', $expected->version)->update([
                'version' => $next->version, 'payload' => $this->encode($next), 'state' => $next->state->value,
                'expires_at' => $next->expiresAt,
            ]) === 1;
    }

    public function prune(int $limit = 100): int
    {
        return $this->committed(fn (): int => $this->pruneCommitted($limit));
    }

    private function pruneCommitted(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Prune batch must contain 1–1000 records.');
        }

        return $this->connection->transaction(function () use ($limit): int {
            // Serialize admission and cleanup without holding a lock during a CMS handler.
            $this->connection->table('mcp_operation_capacity')->where('id', 1)->increment('record_count', 0);
            $rows = $this->connection->table('mcp_operations')->where('expires_at', '<=', now()->getTimestamp())
                ->whereNotIn('state', [OperationState::Executing->value, OperationState::OutcomeUnknown->value])
                ->orderBy('expires_at')->limit($limit)->get(['id', 'version']);
            $deleted = 0;
            foreach ($rows as $row) {
                $deleted += $this->connection->table('mcp_operations')->where('id', $row->id)
                    ->where('version', $row->version)->delete();
            }
            if ($deleted > 0 && $this->connection->table('mcp_operation_capacity')->where('id', 1)
                ->where('record_count', '>=', $deleted)->decrement('record_count', $deleted) !== 1) {
                throw new \RuntimeException('Operation capacity metadata is corrupt.');
            }

            return $deleted;
        }, 3);
    }

    public function reconcileCapacity(): int
    {
        return $this->committed(fn (): int => $this->reconcileCapacityCommitted());
    }

    private function reconcileCapacityCommitted(): int
    {
        return $this->connection->transaction(function (): int {
            $this->connection->table('mcp_operation_capacity')->where('id', 1)->increment('record_count', 0);
            if (! $this->connection->table('mcp_operation_capacity')->where('id', 1)->exists()) {
                throw new \RuntimeException('Operation capacity metadata is missing.');
            }
            $count = $this->connection->table('mcp_operations')->count();
            $this->connection->table('mcp_operation_capacity')->where('id', 1)->update(['record_count' => $count]);

            return $count;
        }, 3);
    }

    /**
     * Never acknowledge a claim that unrelated host work can roll back.
     * Query listeners may begin a transaction too, hence the second check.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @return T
     */
    private function committed(callable $callback): mixed
    {
        $this->requireCommittedConnection();
        $result = $callback();
        $this->requireCommittedConnection();

        return $result;
    }

    private function requireCommittedConnection(): void
    {
        if ($this->connection->transactionLevel() !== 0 || $this->connection->getPdo()->inTransaction()) {
            throw new \LogicException('Operation storage requires an independent committed connection.');
        }
    }

    private function encode(Operation $operation): string
    {
        $payload = json_encode($operation->toArray(), JSON_THROW_ON_ERROR);
        if (strlen($payload) > 3_000_000) {
            throw new \RuntimeException('Operation record exceeds its storage budget.');
        }

        return $payload;
    }
}
