<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;

beforeEach(function () {
    $this->auditPath = sys_get_temp_dir() . '/statamic-mcp-audit-test-' . uniqid() . '.log';

    $store = new FileAuditStore($this->auditPath);
    $this->app->singleton(AuditStore::class, fn () => $store);
});

afterEach(function () {
    if (file_exists($this->auditPath)) {
        unlink($this->auditPath);
    }
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Write an audit entry with a specific timestamp.
 */
function writeAuditEntry(AuditStore $store, string $timestamp): void
{
    $store->write([
        'level' => 'info',
        'message' => 'test entry',
        'timestamp' => $timestamp,
    ]);
}

// ---------------------------------------------------------------------------
// --all flag
// ---------------------------------------------------------------------------

describe('--all flag', function () {
    it('purges all entries and reports the count', function () {
        $store = app(AuditStore::class);
        writeAuditEntry($store, '2025-01-01T00:00:00Z');
        writeAuditEntry($store, '2025-06-15T12:00:00Z');
        writeAuditEntry($store, '2026-01-01T00:00:00Z');

        $this->artisan('mcp:prune-audit', ['--all' => true])
            ->expectsOutput('Pruned 3 audit log entries.')
            ->assertExitCode(0);

        $result = $store->query(null, null, 1, 100);
        expect($result->total)->toBe(0);
    });

    it('reports zero when log is already empty', function () {
        $this->artisan('mcp:prune-audit', ['--all' => true])
            ->expectsOutput('Pruned 0 audit log entries.')
            ->assertExitCode(0);
    });
});

// ---------------------------------------------------------------------------
// --days option (default: 30)
// ---------------------------------------------------------------------------

describe('--days option', function () {
    it('prunes entries older than the specified number of days', function () {
        Carbon::setTestNow('2026-03-15T12:00:00Z');

        $store = app(AuditStore::class);

        // 60 days ago — should be pruned with default --days=30
        writeAuditEntry($store, Carbon::now()->subDays(60)->toIso8601String());
        // 31 days ago — should be pruned
        writeAuditEntry($store, Carbon::now()->subDays(31)->toIso8601String());
        // 29 days ago — should be kept
        writeAuditEntry($store, Carbon::now()->subDays(29)->toIso8601String());
        // today — should be kept
        writeAuditEntry($store, Carbon::now()->toIso8601String());

        $this->artisan('mcp:prune-audit')
            ->expectsOutput('Pruned 2 audit log entries.')
            ->assertExitCode(0);

        $result = $store->query(null, null, 1, 100);
        expect($result->total)->toBe(2);

        Carbon::setTestNow();
    });

    it('prunes entries older than a custom --days value', function () {
        Carbon::setTestNow('2026-03-15T12:00:00Z');

        $store = app(AuditStore::class);

        writeAuditEntry($store, Carbon::now()->subDays(10)->toIso8601String());
        writeAuditEntry($store, Carbon::now()->subDays(5)->toIso8601String());
        writeAuditEntry($store, Carbon::now()->subDays(1)->toIso8601String());

        $this->artisan('mcp:prune-audit', ['--days' => 7])
            ->expectsOutput('Pruned 1 audit log entries.')
            ->assertExitCode(0);

        $result = $store->query(null, null, 1, 100);
        expect($result->total)->toBe(2);

        Carbon::setTestNow();
    });

    it('reports zero when no entries are old enough to prune', function () {
        Carbon::setTestNow('2026-03-15T12:00:00Z');

        $store = app(AuditStore::class);

        writeAuditEntry($store, Carbon::now()->subDays(1)->toIso8601String());
        writeAuditEntry($store, Carbon::now()->toIso8601String());

        $this->artisan('mcp:prune-audit')
            ->expectsOutput('Pruned 0 audit log entries.')
            ->assertExitCode(0);

        $result = $store->query(null, null, 1, 100);
        expect($result->total)->toBe(2);

        Carbon::setTestNow();
    });
});

// ---------------------------------------------------------------------------
// Exit code
// ---------------------------------------------------------------------------

it('always returns success exit code', function () {
    $this->artisan('mcp:prune-audit', ['--all' => true])
        ->assertExitCode(0);
});
