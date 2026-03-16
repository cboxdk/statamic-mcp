<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Stress;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use PHPUnit\Framework\TestCase;

class FileAuditStoreStressTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir() . '/statamic-mcp-stress-audit-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_1000_rapid_writes(): void
    {
        $store = new FileAuditStore($this->tempPath);

        for ($i = 0; $i < 1000; $i++) {
            $store->write([
                'level' => 'info',
                'message' => "Stress test entry {$i}",
                'tool' => 'statamic-entries',
                'action' => 'list',
                'status' => 'success',
                'timestamp' => Carbon::now()->toIso8601String(),
            ]);
        }

        $result = $store->query(null, null, 1, PHP_INT_MAX);
        $this->assertSame(1000, $result->total);
    }

    public function test_pagination_accuracy(): void
    {
        $store = new FileAuditStore($this->tempPath);

        for ($i = 0; $i < 50; $i++) {
            $store->write([
                'level' => 'info',
                'message' => "Entry {$i}",
                'tool' => 'statamic-entries',
                'status' => 'success',
                'timestamp' => Carbon::now()->addSeconds($i)->toIso8601String(),
            ]);
        }

        // Page 3 with 10 per page should return entries 21-30
        $result = $store->query(null, null, 3, 10);
        $this->assertSame(10, count($result->entries));
        $this->assertSame(50, $result->total);
        $this->assertSame(3, $result->currentPage);
        $this->assertSame(5, $result->lastPage);
    }

    public function test_purge_accuracy_with_date_range(): void
    {
        $store = new FileAuditStore($this->tempPath);

        // Write 100 entries spanning 10 days
        for ($i = 0; $i < 100; $i++) {
            $daysAgo = (int) floor($i / 10); // 10 entries per day, days 0-9
            $store->write([
                'level' => 'info',
                'message' => "Entry {$i}",
                'tool' => 'statamic-entries',
                'status' => 'success',
                'timestamp' => Carbon::now()->subDays($daysAgo)->toIso8601String(),
            ]);
        }

        // Purge entries older than 5 days — days 5,6,7,8,9 = 50 entries
        $purged = $store->purge(Carbon::now()->subDays(5));
        $this->assertSame(50, $purged);

        // Verify remaining entries
        $result = $store->query(null, null, 1, PHP_INT_MAX);
        $this->assertSame(50, $result->total);
    }

    public function test_filter_accuracy_by_tool(): void
    {
        $store = new FileAuditStore($this->tempPath);

        for ($i = 0; $i < 50; $i++) {
            $store->write([
                'level' => 'info',
                'message' => "Entry entries {$i}",
                'tool' => 'statamic-entries',
                'status' => 'success',
                'timestamp' => Carbon::now()->toIso8601String(),
            ]);
        }

        for ($i = 0; $i < 50; $i++) {
            $store->write([
                'level' => 'info',
                'message' => "Entry blueprints {$i}",
                'tool' => 'statamic-blueprints',
                'status' => 'success',
                'timestamp' => Carbon::now()->toIso8601String(),
            ]);
        }

        // Filter by "entries" — should match statamic-entries
        $result = $store->query('entries', null, 1, PHP_INT_MAX);
        $this->assertSame(50, $result->total);

        // Filter by "blueprints" — should match statamic-blueprints
        $result = $store->query('blueprints', null, 1, PHP_INT_MAX);
        $this->assertSame(50, $result->total);
    }
}
