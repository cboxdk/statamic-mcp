<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\McpAuditEntry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->migration = require __DIR__ . '/../../../database/migrations/operations/0001_create_mcp_operations_table.php';
    $this->migration->up();
    $this->store = new DatabaseOperationStore(DB::connection());
    $this->operation = new Operation(hash('sha256', 'durable-operation'), hash('sha256', 'binding'), OperationState::Prepared, 1, time() + 300);
    $this->store->create($this->operation);
    $this->ready = $this->operation->transition(OperationState::Ready);
    $this->store->transition($this->operation, $this->ready);
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
    if (DB::connection()->getPdo()->inTransaction()) {
        DB::connection()->getPdo()->rollBack();
    }
    $this->migration->down();
});

it('refuses store access within host transactions without changing the retained claim', function (string $method, string $transaction) {
    $connection = DB::connection();
    if ($transaction === 'pdo') {
        $connection->getPdo()->beginTransaction();
        expect($connection->transactionLevel())->toBe(0);
    } else {
        $connection->beginTransaction();
    }
    $call = match ($method) {
        'create' => fn () => $this->store->create(new Operation(hash('sha256', 'another'), $this->operation->bindingDigest, OperationState::Prepared, 1, time() + 300)),
        'find' => fn () => $this->store->find($this->operation->id),
        'transition' => fn () => $this->store->transition($this->ready, $this->ready->transition(OperationState::Executing)),
        'prune' => fn () => $this->store->prune(),
        'repair' => fn () => $this->store->reconcileCapacity(),
    };
    expect($call)->toThrow(LogicException::class, 'independent committed connection');
    expect($connection->getPdo()->inTransaction())->toBeTrue();
    if ($transaction === 'pdo') {
        $connection->getPdo()->rollBack();
    } else {
        $connection->rollBack();
    }
    expect($this->store->find($this->operation->id)->state)->toBe(OperationState::Ready)
        ->and($connection->table('mcp_operation_capacity')->value('record_count'))->toBe(1);
    expect($this->store->transition($this->ready, $this->ready->transition(OperationState::Executing)))->toBeTrue();
})->with(['create', 'find', 'transition', 'prune', 'repair'])->with(['laravel', 'pdo']);

it('does not acknowledge a claim if a query listener opens a transaction after the initial check', function () {
    $armed = true;
    DB::listen(function (QueryExecuted $event) use (&$armed): void {
        if ($armed && str_contains($event->sql, 'select') && str_contains($event->sql, 'mcp_operations')) {
            $armed = false;
            $event->connection->beginTransaction();
        }
    });
    expect(fn () => $this->store->transition($this->ready, $this->ready->transition(OperationState::Executing)))
        ->toThrow(LogicException::class, 'independent committed connection');
    expect(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->rollBack();
    expect($this->store->find($this->operation->id)->state)->toBe(OperationState::Ready);
});

it('rejects durable audit in a raw PDO transaction that Laravel does not track', function () {
    $migration = require __DIR__ . '/../../../database/migrations/audit/create_mcp_audit_logs_table.php';
    $migration->up();
    try {
        DB::connection()->getPdo()->beginTransaction();
        expect(DB::connection()->transactionLevel())->toBe(0);
        expect(fn () => (new DatabaseAuditStore)->writeDurable(['level' => 'info', 'message' => 'Admission', 'timestamp' => gmdate('c')]))
            ->toThrow(LogicException::class, 'uncommitted');
        DB::connection()->getPdo()->rollBack();
        expect(McpAuditEntry::count())->toBe(0);
    } finally {
        if (DB::connection()->getPdo()->inTransaction()) {
            DB::connection()->getPdo()->rollBack();
        }
        $migration->down();
    }
});
