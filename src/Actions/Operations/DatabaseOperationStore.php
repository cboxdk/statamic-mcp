<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/** Shared SQL storage; transitions use one compare-and-set statement. */
final class DatabaseOperationStore implements OperationStore
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function create(Operation $operation): bool
    {
        if ($operation->state !== OperationState::Prepared || $operation->version !== 1) {
            throw new \InvalidArgumentException('New operations must be prepared at version one.');
        }
        try {
            return $this->connection->table('mcp_operations')->insert([
                'id' => $operation->id, 'version' => $operation->version, 'payload' => $this->encode($operation),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function find(string $id): ?Operation
    {
        $payload = $this->connection->table('mcp_operations')->where('id', $id)->value('payload');
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
        if ($next->id !== $expected->id || $next->bindingDigest !== $expected->bindingDigest
            || $next->expiresAt !== $expected->expiresAt || $next->version !== $expected->version + 1
            || ! $expected->state->canTransitionTo($next->state)) {
            throw new \InvalidArgumentException('Invalid operation transition binding.');
        }
        if ($expected->expiresAt <= time() && in_array($next->state, [OperationState::Ready, OperationState::Executing], true)) {
            return false;
        }

        // Compare bytes in PHP: database text collations may ignore case or spaces.
        // The monotonically increasing version makes the subsequent claim atomic.
        $stored = $this->connection->table('mcp_operations')->where('id', $expected->id)->value('payload');
        if (! is_string($stored) || ! hash_equals($this->encode($expected), $stored)) {
            return false;
        }

        return $this->connection->table('mcp_operations')->where('id', $expected->id)
            ->where('version', $expected->version)->update([
                'version' => $next->version, 'payload' => $this->encode($next),
            ]) === 1;
    }

    private function encode(Operation $operation): string
    {
        return json_encode($operation->toArray(), JSON_THROW_ON_ERROR);
    }
}
