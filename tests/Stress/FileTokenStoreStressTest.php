<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Stress;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use PHPUnit\Framework\TestCase;

class FileTokenStoreStressTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/statamic-mcp-stress-token-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_500_rapid_creates(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $hashes = [];

        for ($i = 0; $i < 500; $i++) {
            $hash = 'hash_' . $i . '_' . bin2hex(random_bytes(8));
            $hashes[] = $hash;
            $store->create('user-stress', "Token {$i}", $hash, ['*'], null);
        }

        $all = $store->listAll();
        $this->assertSame(500, $all->count());

        // Assert no duplicate IDs
        $ids = $all->pluck('id')->toArray();
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_index_consistency_after_500_creates(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $hashes = [];

        for ($i = 0; $i < 500; $i++) {
            $hash = 'hash_consistency_' . $i;
            $hashes[] = $hash;
            $store->create('user-stress', "Token {$i}", $hash, ['content:read'], null);
        }

        // Random sample of 10 hashes should all be findable
        $sample = array_rand(array_flip($hashes), 10);

        foreach ($sample as $hash) {
            $found = $store->findByHash($hash);
            $this->assertNotNull($found, "findByHash failed for hash: {$hash}");
            $this->assertSame($hash, $found->tokenHash);
        }
    }

    public function test_index_rebuild_after_500_creates(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $hashes = [];

        for ($i = 0; $i < 500; $i++) {
            $hash = 'hash_rebuild_' . $i;
            $hashes[] = $hash;
            $store->create('user-stress', "Token {$i}", $hash, ['*'], null);
        }

        // Delete the index file
        $indexPath = $this->tempDir . '/.index';
        $this->assertFileExists($indexPath);
        unlink($indexPath);
        $this->assertFileDoesNotExist($indexPath);

        // findByHash should trigger rebuild and still work
        $sample = array_rand(array_flip($hashes), 10);

        foreach ($sample as $hash) {
            $found = $store->findByHash($hash);
            $this->assertNotNull($found, "findByHash failed after index rebuild for hash: {$hash}");
            $this->assertSame($hash, $found->tokenHash);
        }

        // Verify index was rebuilt
        $this->assertFileExists($indexPath);
    }

    public function test_prune_accuracy_with_mixed_expiry(): void
    {
        $store = new FileTokenStore($this->tempDir);

        // 250 expired tokens
        for ($i = 0; $i < 250; $i++) {
            $store->create(
                'user-stress',
                "Expired {$i}",
                'expired_hash_' . $i,
                ['*'],
                Carbon::now()->subHour(),
            );
        }

        // 250 valid tokens (no expiry)
        for ($i = 0; $i < 250; $i++) {
            $store->create(
                'user-stress',
                "Valid {$i}",
                'valid_hash_' . $i,
                ['*'],
                null,
            );
        }

        $this->assertSame(500, $store->listAll()->count());

        $pruned = $store->pruneExpired();
        $this->assertSame(250, $pruned);
        $this->assertSame(250, $store->listAll()->count());
    }

    public function test_rapid_mark_as_used(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $ids = [];

        for ($i = 0; $i < 100; $i++) {
            $token = $store->create('user-stress', "Token {$i}", 'used_hash_' . $i, ['*'], null);
            $ids[] = $token->id;
        }

        // Mark all as used rapidly
        foreach ($ids as $id) {
            $store->markAsUsed($id);
        }

        // Verify all have lastUsedAt set
        foreach ($ids as $id) {
            $token = $store->find($id);
            $this->assertNotNull($token);
            $this->assertNotNull($token->lastUsedAt, "Token {$id} should have lastUsedAt set");
        }
    }

    public function test_rapid_create_update_delete_cycles_maintain_index_consistency(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $cycleCount = 50;

        for ($i = 0; $i < $cycleCount; $i++) {
            $hash = 'cycle_hash_' . $i . '_' . bin2hex(random_bytes(4));

            // Create
            $token = $store->create('user-cycle', "Cycle Token {$i}", $hash, ['content:read'], null);
            $this->assertNotNull($store->findByHash($hash), "Token should be findable after create (cycle {$i})");

            // Update (rename) the token
            $updated = $store->update($token->id, ['name' => "Updated Cycle {$i}"]);
            $this->assertNotNull($updated, "Token should be updatable (cycle {$i})");
            $this->assertSame("Updated Cycle {$i}", $updated->name);

            // Token hash unchanged — still findable
            $this->assertNotNull($store->findByHash($hash), "Token should be findable after update (cycle {$i})");

            // Delete
            $deleted = $store->delete($token->id);
            $this->assertTrue($deleted, "Token should be deletable (cycle {$i})");

            // Index must no longer contain the hash
            $this->assertNull($store->findByHash($hash), "Token should not be findable after delete (cycle {$i})");
        }

        // After all cycles, the store should be empty
        $this->assertSame(0, $store->listAll()->count());
    }

    public function test_find_by_hash_during_index_rebuild_returns_correct_result(): void
    {
        $store = new FileTokenStore($this->tempDir);
        $hashes = [];

        // Populate store with tokens
        for ($i = 0; $i < 100; $i++) {
            $hash = 'rebuild_concurrent_' . $i;
            $hashes[] = $hash;
            $store->create('user-rebuild', "Token {$i}", $hash, ['*'], null);
        }

        // Remove the index to force a rebuild on the next findByHash
        $indexPath = $this->tempDir . '/.index';
        unlink($indexPath);
        $this->assertFileDoesNotExist($indexPath);

        // findByHash should trigger rebuildIndex internally and still return the token
        $targetHash = $hashes[array_rand($hashes)];
        $found = $store->findByHash($targetHash);

        $this->assertNotNull($found, 'findByHash should find token even when triggered during index rebuild');
        $this->assertSame($targetHash, $found->tokenHash);

        // Index must be recreated by the rebuild
        $this->assertFileExists($indexPath);

        // All other tokens must also remain findable post-rebuild
        $sample = array_rand(array_flip($hashes), 10);
        foreach ($sample as $hash) {
            $this->assertNotNull(
                $store->findByHash($hash),
                "findByHash should find token {$hash} after index rebuild"
            );
        }
    }

    public function test_atomic_tmp_file_not_left_behind_after_rebuild(): void
    {
        $store = new FileTokenStore($this->tempDir);

        for ($i = 0; $i < 20; $i++) {
            $store->create('user-atomic', "Token {$i}", 'atomic_hash_' . $i, ['*'], null);
        }

        // Force a rebuild
        $indexPath = $this->tempDir . '/.index';
        unlink($indexPath);

        $store->findByHash('atomic_hash_0');

        // The .index.tmp file must not be left behind after a successful rebuild
        $this->assertFileDoesNotExist($this->tempDir . '/.index.tmp');
        $this->assertFileExists($indexPath);
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
