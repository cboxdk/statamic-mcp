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

        // .mutation.lock persists on purpose: unlinking a lock file other
        // processes may hold open would let two writers lock different inodes.
        $this->assertSame(['.index', '.mutation.lock', $token->id . '.yaml'], $files);
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

    public function test_a_damaged_token_file_is_treated_as_missing(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $good = $store->create('user-1', 'Good', 'hash_good', ['*'], null);
        $damaged = $store->create('user-1', 'Damaged', 'hash_damaged', ['*'], null);
        file_put_contents($this->tempDir . '/' . $damaged->id . '.yaml', "id: [unclosed\nname: {\n");

        $this->assertNull($store->find($damaged->id));
        $this->assertNull($store->findByHash('hash_damaged'));
        $this->assertNull($store->update($damaged->id, ['name' => 'Renamed']));

        // An unknown hash rebuilds the index from every file, the damaged one included.
        $this->assertNull($store->findByHash('hash_unknown'));
        $this->assertSame($good->id, $store->findByHash('hash_good')?->id);
        $this->assertSame([$good->id], $store->listAll()->pluck('id')->all());
    }

    public function test_a_token_file_is_left_as_it_was_when_the_new_one_cannot_be_written(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to a read-only directory');
        }

        $store = new FileTokenStore($this->tempDir);

        $token = $store->create('user-1', 'Full Disk', 'hash_full_disk', ['*'], null);
        $path = $this->tempDir . '/' . $token->id . '.yaml';
        $before = (string) file_get_contents($path);

        // A directory that can't be written stands in for a full disk: the
        // temporary file can't be written, while the token file itself still could.
        chmod($this->tempDir, 0555);

        // The failed write raises a (suppressed) warning on purpose; keep it out of the test run.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $store->markAsUsed($token->id);
            $this->assertSame($before, file_get_contents($path));

            $this->expectException(\RuntimeException::class);
            $store->update($token->id, ['name' => 'Renamed']);
        } finally {
            restore_error_handler();
            chmod($this->tempDir, 0755);
            $this->assertSame($before, file_get_contents($path));
        }
    }

    public function test_a_usage_write_waits_for_a_mutation_in_progress(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $token = $store->create('user-1', 'Lock Test', 'hash_lock_used', ['*'], null);

        $this->assertNull($this->whileMutationLockIsHeld(
            "\$store->markAsUsed('{$token->id}');",
            fn () => $store->find($token->id)?->lastUsedAt,
        ));

        $this->assertNotNull($store->find($token->id)?->lastUsedAt);
    }

    public function test_a_revoke_waits_for_a_usage_write_in_progress(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $token = $store->create('user-1', 'Lock Test', 'hash_lock_delete', ['*'], null);

        // A usage write holds the lock between reading the record and
        // replacing it. A revoke landing in that window used to be undone by
        // the write; now it waits, and the write cannot bring the token back.
        $this->assertNotNull($this->whileMutationLockIsHeld(
            "\$store->delete('{$token->id}');",
            fn () => $store->find($token->id),
        ));

        $this->assertNull($store->find($token->id));
        $this->assertNull($store->findByHash('hash_lock_delete'));
    }

    /**
     * Hold the store's mutation lock, run $operation against the store in a
     * second PHP process, and return what $probe sees while the lock is still
     * held. The second process must have finished once the lock is released.
     *
     * @template T
     *
     * @param  callable(): T  $probe
     *
     * @return T
     */
    private function whileMutationLockIsHeld(string $operation, callable $probe): mixed
    {
        $handle = fopen($this->tempDir . '/.mutation.lock', 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX));

        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; $store = new %s(%s); %s',
            var_export($autoload, true),
            FileTokenStore::class,
            var_export($this->tempDir, true),
            $operation,
        );

        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        // Long enough for the second process to boot and reach the lock.
        usleep(500_000);
        $this->assertTrue(proc_get_status($process)['running'], 'The operation ran without waiting for the lock.');

        $seen = $probe();

        flock($handle, LOCK_UN);
        fclose($handle);

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);

        return $seen;
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
