<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

use Cboxdk\StatamicMcp\Actions\Operations\Operation;

interface OperationStore
{
    /** Atomic insert; false means the id is already reserved. */
    public function create(Operation $operation): bool;

    public function find(string $id): ?Operation;

    /** Delete expired records, retaining executing and unknown outcomes for reconciliation. */
    public function prune(int $limit = 100): int;

    /** Recount occupied slots under the admission lock; never change operation outcomes. */
    public function reconcileCapacity(): int;

    /**
     * Operations awaiting a person's approval and not yet expired, oldest first.
     *
     * @return list<Operation>
     */
    public function awaitingApproval(int $limit = 100): array;

    /** Atomic transition; refuses stale versions and changes to immutable bindings. */
    public function transition(Operation $expected, Operation $next): bool;
}
