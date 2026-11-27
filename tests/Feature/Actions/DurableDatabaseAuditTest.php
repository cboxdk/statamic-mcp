<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\McpAuditEntry;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->migration = require __DIR__ . '/../../../database/migrations/audit/create_mcp_audit_logs_table.php';
    $this->migration->up();
});

afterEach(function () {
    McpAuditEntry::flushEventListeners();
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
    $this->migration->down();
});

it('commits native SQL audit admission outside caller transactions', function () {
    expect(DB::connection()->transactionLevel())->toBe(0);
    (new DatabaseAuditStore)->writeDurable(['level' => 'info', 'message' => 'Admission', 'timestamp' => gmdate('c')]);
    expect(DB::connection()->transactionLevel())->toBe(0)->and(McpAuditEntry::count())->toBe(1);
});

it('refuses admission when a model listener leaves its record uncommitted', function () {
    McpAuditEntry::creating(function (): void {
        DB::connection()->beginTransaction();
    });
    expect(fn () => (new DatabaseAuditStore)->writeDurable(['level' => 'info', 'message' => 'Admission', 'timestamp' => gmdate('c')]))
        ->toThrow(LogicException::class, 'uncommitted');
    expect(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->rollBack();
    expect(McpAuditEntry::count())->toBe(0);
});
