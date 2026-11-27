<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Audit;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\McpAuditEntry;
use Cboxdk\StatamicMcp\Tests\Concerns\AuditStoreContractTests;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class DatabaseAuditStoreTest extends TestCase
{
    use AuditStoreContractTests;
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations/audit');
    }

    protected function createStore(): AuditStore
    {
        return new DatabaseAuditStore;
    }

    /**
     * Override the contract test to use DB ordering semantics (logged_at DESC).
     * The FileAuditStore reverses file lines (insertion order), while the
     * DatabaseAuditStore orders by the logged_at column descending.
     */
    public function test_query_returns_newest_first(): void
    {
        $store = $this->createStore();

        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T10:00:00+00:00'));
        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T12:00:00+00:00'));
        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T11:00:00+00:00'));

        $result = $store->query(null, null, 1, 25);

        $this->assertCount(3, $result->entries);
        // DB orders by logged_at DESC: 12:00, 11:00, 10:00
        $this->assertSame('2026-03-15T12:00:00+00:00', $result->entries[0]['timestamp']);
        $this->assertSame('2026-03-15T11:00:00+00:00', $result->entries[1]['timestamp']);
        $this->assertSame('2026-03-15T10:00:00+00:00', $result->entries[2]['timestamp']);
    }

    public function test_durable_audit_refuses_an_uncommitted_caller_transaction(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            try {
                (new DatabaseAuditStore)->writeDurable($this->makeEntry('statamic-entries', 'admitted'));
                $this->fail('Uncommitted audit must not admit a write.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('uncommitted', $exception->getMessage());
            }
            $this->assertSame(0, McpAuditEntry::count());
        } finally {
            $connection->rollBack();
        }
    }

    public function test_audit_model_veto_is_reported_as_a_failure(): void
    {
        McpAuditEntry::creating(fn (): bool => false);
        try {
            $this->expectException(\RuntimeException::class);
            (new DatabaseAuditStore)->write($this->makeEntry('statamic-entries', 'admitted'));
        } finally {
            McpAuditEntry::flushEventListeners();
        }
    }
}
