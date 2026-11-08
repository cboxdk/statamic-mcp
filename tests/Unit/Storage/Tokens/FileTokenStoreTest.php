<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Tokens;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\TokenStoreContractTests;
use PHPUnit\Framework\TestCase;

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

    public function test_mark_as_used_replaces_the_token_file_instead_of_rewriting_it_in_place(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $token = $store->create('user-1', 'Atomic Test', 'hash_atomic', ['*'], null);
        $path = $this->tempDir . '/' . $token->id . '.yaml';
        $before = (string) file_get_contents($path);

        // A request that opened the token file before another request recorded
        // its use must still read the whole record. An in-place rewrite
        // truncates the file it is reading, which is how a concurrent request
        // ended up parsing an empty file.
        $reader = fopen($path, 'r');
        $this->assertNotFalse($reader);

        $store->markAsUsed($token->id);

        $this->assertSame($before, stream_get_contents($reader));
        fclose($reader);

        $found = $store->findByHash('hash_atomic');
        $this->assertNotNull($found);
        $this->assertNotNull($found->lastUsedAt);
    }

    public function test_writes_leave_no_temporary_files_behind(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $token = $store->create('user-1', 'Tmp Test', 'hash_tmp', ['*'], null);
        $store->markAsUsed($token->id);
        $store->update($token->id, ['name' => 'Renamed']);

        $files = array_values(array_diff(scandir($this->tempDir) ?: [], ['.', '..']));
        sort($files);

        $this->assertSame(['.index', $token->id . '.yaml'], $files);
        $this->assertSame('Renamed', $store->find($token->id)?->name);
    }

    public function test_an_empty_token_file_is_treated_as_missing(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $token = $store->create('user-1', 'Empty Test', 'hash_empty', ['*'], null);
        $path = $this->tempDir . '/' . $token->id . '.yaml';
        file_put_contents($path, '');

        $this->assertNull($store->find($token->id));
        $this->assertNull($store->findByHash('hash_empty'));
        $this->assertNull($store->update($token->id, ['name' => 'Renamed']));
        $this->assertSame('', file_get_contents($path));
    }

    public function test_mark_as_used_never_writes_a_record_from_an_unreadable_file(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $token = $store->create('user-1', 'Unreadable Test', 'hash_unreadable', ['*'], null);
        $path = $this->tempDir . '/' . $token->id . '.yaml';
        file_put_contents($path, '');

        // Writing back a record that holds only last_used_at would leave a
        // token file that never authenticates again.
        $store->markAsUsed($token->id);

        $this->assertSame('', file_get_contents($path));
    }

    public function test_deleting_an_unreadable_token_file_removes_it(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $token = $store->create('user-1', 'Delete Test', 'hash_delete_unreadable', ['*'], null);
        $path = $this->tempDir . '/' . $token->id . '.yaml';
        file_put_contents($path, '');

        $this->assertTrue($store->delete($token->id));
        $this->assertFileDoesNotExist($path);
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
