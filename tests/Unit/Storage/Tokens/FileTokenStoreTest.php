<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Tokens;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\TokenStoreContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FileTokenStoreTest extends TestCase
{
    use TokenStoreContractTests;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/statamic-mcp-token-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    protected function createStore(): TokenStore
    {
        return new FileTokenStore($this->tempDir);
    }

    public function test_index_rebuilds_after_deletion(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $token = $store->create('user-1', 'Index Test', 'hash_index', ['*'], null);

        // Delete the .index file manually
        $indexPath = $this->tempDir . '/.index';
        if (file_exists($indexPath)) {
            unlink($indexPath);
        }

        // findByHash should still work via index rebuild
        $found = $store->findByHash('hash_index');
        $this->assertNotNull($found);
        $this->assertSame($token->id, $found->id);

        // Verify index was rebuilt
        $this->assertFileExists($indexPath);
    }

    public function test_concurrent_usage_and_updates_cannot_resurrect_a_revoked_token(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $token = $store->create('user-1', 'Revoke race', 'hash_race', ['entries:read'], null);
        $lock = fopen($this->tempDir . '/.store.lock', 'c+b');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX));
        $workers = [];
        try {
            foreach (['used', 'update', 'delete'] as $action) {
                $worker = new Process([PHP_BINARY, __DIR__ . '/../../../Support/token-store-mutation.php', $this->tempDir, $token->id, $action]);
                $worker->setTimeout(10)->start();
                $workers[] = $worker;
                $this->assertTrue($worker->waitUntil(fn (): bool => str_contains($worker->getOutput(), "ready\n")));
            }
            // Every entry point must wait for the same lock. Without this,
            // last-used may read before revoke and write the credential back.
            usleep(100000);
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning());
                $this->assertSame("ready\n", $worker->getOutput());
            }
            flock($lock, LOCK_UN);
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            }
            $this->assertNull($store->find($token->id));
            $this->assertNull($store->findByHash('hash_race'));
            $this->assertCount(0, $store->listAll());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            foreach ($workers as $worker) {
                $worker->stop();
            }
        }
    }

    public function test_token_file_replacement_preserves_an_open_read_snapshot(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $token = $store->create('user-1', 'Before', 'old_hash', ['entries:read'], null);
        $path = $this->tempDir . '/' . $token->id . '.yaml';
        $reader = fopen($path, 'rb');
        $this->assertIsResource($reader);
        $before = file_get_contents($path);
        try {
            $store->update($token->id, ['name' => 'After', 'tokenHash' => 'new_hash']);
            $this->assertSame($before, stream_get_contents($reader));
            $this->assertSame('After', $store->find($token->id)->name);
            $this->assertNull($store->findByHash('old_hash'));
            $this->assertSame($token->id, $store->findByHash('new_hash')->id);
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertFileDoesNotExist($this->tempDir . '/.token.tmp');
        } finally {
            fclose($reader);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
