<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Audit;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Tests\Concerns\AuditStoreContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FileAuditStoreTest extends TestCase
{
    use AuditStoreContractTests;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir() . '/statamic-mcp-audit-test-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }

        foreach ([$this->tempPath . '.lock', $this->tempPath . '.tmp'] as $artifact) {
            if (is_file($artifact)) {
                unlink($artifact);
            }
        }
        parent::tearDown();
    }

    protected function createStore(): AuditStore
    {
        return new FileAuditStore($this->tempPath);
    }

    public function test_purge_and_append_share_the_same_permanent_lock(): void
    {
        $store = new FileAuditStore($this->tempPath);
        $store->write(['level' => 'info', 'message' => 'Old', 'timestamp' => '2025-01-01T00:00:00Z']);
        $lock = fopen($this->tempPath . '.lock', 'c+b');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX));
        $workers = [];
        try {
            foreach (['purge', 'append'] as $action) {
                $worker = new Process([PHP_BINARY, __DIR__ . '/../../../Support/audit-store-worker.php', $this->tempPath, $action]);
                $worker->setTimeout(10)->start();
                $workers[] = $worker;
                $this->assertTrue($worker->waitUntil(fn (): bool => str_contains($worker->getOutput(), "ready\n")));
            }
            usleep(100000);
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning());
                $this->assertSame("ready\n", $worker->getOutput());
            }
            flock($lock, LOCK_UN);
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            }
            $entries = $store->query(null, null, 1, 10)->entries;
            $this->assertCount(1, $entries);
            $this->assertSame('Concurrent append', $entries[0]['message']);
            $this->assertSame(0600, fileperms($this->tempPath) & 0777);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function test_an_interrupted_tail_cannot_swallow_the_next_audit_record(): void
    {
        file_put_contents($this->tempPath, '{"partial":');
        $store = new FileAuditStore($this->tempPath);
        $store->write(['level' => 'info', 'message' => 'Complete', 'timestamp' => '2026-11-07T00:00:00Z']);
        $result = $store->query(null, null, 1, 10);
        $this->assertSame(1, $result->total);
        $this->assertSame('Complete', $result->entries[0]['message']);
        $this->assertStringStartsWith('{"partial":' . "\n", file_get_contents($this->tempPath));
    }

    public function test_pagination_does_not_load_a_large_audit_history_into_memory(): void
    {
        $handle = fopen($this->tempPath, 'wb');
        $this->assertIsResource($handle);
        try {
            for ($i = 0; $i < 12000; $i++) {
                fwrite($handle, json_encode(['level' => 'info', 'message' => str_repeat('a', 2000),
                    'tool' => 'statamic-entries', 'status' => 'success', 'sequence' => $i], JSON_THROW_ON_ERROR) . "\n");
            }
        } finally {
            fclose($handle);
        }
        $worker = new Process([PHP_BINARY, '-d', 'memory_limit=32M', __DIR__ . '/../../../Support/audit-store-worker.php', $this->tempPath, 'query']);
        $worker->setTimeout(15)->run();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(12000, $result['total']);
        $this->assertCount(10, $result['entries']);
        $this->assertSame(range(11989, 11980), array_column($result['entries'], 'sequence'));
    }
}
