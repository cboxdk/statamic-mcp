<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\AuditStore;

/**
 * Shared contract tests for all AuditStore implementations.
 *
 * Classes using this trait must implement createStore().
 */
trait AuditStoreContractTests
{
    abstract protected function createStore(): AuditStore;

    /**
     * @return array<string, mixed>
     */
    private function makeEntry(string $tool = 'statamic-entries', string $status = 'success', string $timestamp = '2026-03-15T12:00:00+00:00'): array
    {
        return [
            'level' => 'info',
            'message' => 'Tool executed',
            'tool' => $tool,
            'status' => $status,
            'timestamp' => $timestamp,
        ];
    }

    public function test_write_and_query(): void
    {
        $store = $this->createStore();

        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T12:00:00+00:00'));
        $store->write($this->makeEntry('statamic-blueprints', 'success', '2026-03-15T12:01:00+00:00'));

        $result = $store->query(null, null, 1, 25);

        $this->assertCount(2, $result->entries);
        $this->assertSame(2, $result->total);
    }

    public function test_query_filters_by_tool(): void
    {
        $store = $this->createStore();

        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T12:00:00+00:00'));
        $store->write($this->makeEntry('statamic-blueprints', 'success', '2026-03-15T12:01:00+00:00'));
        $store->write($this->makeEntry('statamic-entries', 'failed', '2026-03-15T12:02:00+00:00'));

        $result = $store->query('entries', null, 1, 25);

        $this->assertCount(2, $result->entries);
        $this->assertSame(2, $result->total);
        foreach ($result->entries as $entry) {
            $this->assertStringContainsString('entries', $entry['tool']);
        }
    }

    public function test_query_filters_by_status(): void
    {
        $store = $this->createStore();

        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T12:00:00+00:00'));
        $store->write($this->makeEntry('statamic-blueprints', 'failed', '2026-03-15T12:01:00+00:00'));
        $store->write($this->makeEntry('statamic-terms', 'success', '2026-03-15T12:02:00+00:00'));

        $result = $store->query(null, 'success', 1, 25);

        $this->assertCount(2, $result->entries);
        $this->assertSame(2, $result->total);
        foreach ($result->entries as $entry) {
            $this->assertSame('success', $entry['status']);
        }
    }

    public function test_query_paginates(): void
    {
        $store = $this->createStore();

        for ($i = 1; $i <= 5; $i++) {
            $store->write($this->makeEntry('statamic-entries', 'success', "2026-03-15T12:0{$i}:00+00:00"));
        }

        $result = $store->query(null, null, 1, 2);

        $this->assertCount(2, $result->entries);
        $this->assertSame(5, $result->total);
        $this->assertSame(1, $result->currentPage);
        $this->assertSame(3, $result->lastPage);
        $this->assertSame(2, $result->perPage);
    }

    public function test_query_returns_newest_first(): void
    {
        $store = $this->createStore();

        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T10:00:00+00:00'));
        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T12:00:00+00:00'));
        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T11:00:00+00:00'));

        $result = $store->query(null, null, 1, 25);

        $this->assertCount(3, $result->entries);
        // Newest first — file order reversed means last written is first
        $this->assertSame('2026-03-15T11:00:00+00:00', $result->entries[0]['timestamp']);
        $this->assertSame('2026-03-15T12:00:00+00:00', $result->entries[1]['timestamp']);
        $this->assertSame('2026-03-15T10:00:00+00:00', $result->entries[2]['timestamp']);
    }

    public function test_purge_removes_entries_before_date(): void
    {
        $store = $this->createStore();

        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-01T12:00:00+00:00'));
        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-10T12:00:00+00:00'));
        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T12:00:00+00:00'));

        $purged = $store->purge(Carbon::parse('2026-03-12T00:00:00+00:00'));

        $this->assertSame(2, $purged);

        $result = $store->query(null, null, 1, 25);
        $this->assertSame(1, $result->total);
        $this->assertSame('2026-03-15T12:00:00+00:00', $result->entries[0]['timestamp']);
    }

    public function test_purge_all_when_no_date(): void
    {
        $store = $this->createStore();

        $store->write($this->makeEntry('statamic-entries', 'success', '2026-03-15T12:00:00+00:00'));
        $store->write($this->makeEntry('statamic-blueprints', 'success', '2026-03-15T12:01:00+00:00'));

        $purged = $store->purge(null);

        $this->assertSame(2, $purged);

        $result = $store->query(null, null, 1, 25);
        $this->assertSame(0, $result->total);
    }

    public function test_empty_query(): void
    {
        $store = $this->createStore();

        $result = $store->query(null, null, 1, 25);

        $this->assertCount(0, $result->entries);
        $this->assertSame(0, $result->total);
    }
}
