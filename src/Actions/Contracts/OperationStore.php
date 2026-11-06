<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

use Cboxdk\StatamicMcp\Actions\Operations\Operation;

interface OperationStore
{
    /** Atomic insert; false means the id is already reserved. */
    public function create(Operation $operation): bool;

    public function find(string $id): ?Operation;

    /** Atomic transition; refuses stale versions and changes to immutable bindings. */
    public function transition(Operation $expected, Operation $next): bool;
}
