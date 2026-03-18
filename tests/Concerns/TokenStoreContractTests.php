<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;

/**
 * Shared contract tests for all TokenStore implementations.
 *
 * Classes using this trait must implement createStore().
 */
trait TokenStoreContractTests
{
    abstract protected function createStore(): TokenStore;

    public function test_create_and_find_by_hash(): void
    {
        $store = $this->createStore();
        $expiresAt = Carbon::now()->addDays(30);

        $token = $store->create(
            userId: 'user-1',
            name: 'My Token',
            tokenHash: 'hash_abc',
            scopes: ['content:read', 'content:write'],
            expiresAt: $expiresAt,
        );

        $this->assertInstanceOf(McpTokenData::class, $token);
        $this->assertSame('user-1', $token->userId);
        $this->assertSame('My Token', $token->name);
        $this->assertSame('hash_abc', $token->tokenHash);
        $this->assertSame(['content:read', 'content:write'], $token->scopes);
        $this->assertNotNull($token->expiresAt);
        $this->assertInstanceOf(Carbon::class, $token->createdAt);

        $found = $store->findByHash('hash_abc');
        $this->assertNotNull($found);
        $this->assertSame($token->id, $found->id);
        $this->assertSame('user-1', $found->userId);
        $this->assertSame('My Token', $found->name);
        $this->assertSame('hash_abc', $found->tokenHash);
        $this->assertSame(['content:read', 'content:write'], $found->scopes);
    }

    public function test_find_by_id(): void
    {
        $store = $this->createStore();

        $token = $store->create(
            userId: 'user-1',
            name: 'Test Token',
            tokenHash: 'hash_find_id',
            scopes: ['*'],
            expiresAt: null,
        );

        $found = $store->find($token->id);
        $this->assertNotNull($found);
        $this->assertSame($token->id, $found->id);
        $this->assertSame('Test Token', $found->name);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $store = $this->createStore();

        $this->assertNull($store->find('nonexistent'));
        $this->assertNull($store->findByHash('nonexistent'));
    }

    public function test_update(): void
    {
        $store = $this->createStore();

        $token = $store->create(
            userId: 'user-1',
            name: 'Original',
            tokenHash: 'hash_update',
            scopes: ['content:read'],
            expiresAt: null,
        );

        $updated = $store->update($token->id, [
            'name' => 'Updated Name',
            'scopes' => ['content:read', 'content:write'],
        ]);

        $this->assertNotNull($updated);
        $this->assertSame('Updated Name', $updated->name);
        $this->assertSame(['content:read', 'content:write'], $updated->scopes);
        $this->assertNotNull($updated->updatedAt);
    }

    public function test_update_token_hash(): void
    {
        $store = $this->createStore();

        $token = $store->create(
            userId: 'user-1',
            name: 'Hash Update',
            tokenHash: 'old_hash',
            scopes: ['*'],
            expiresAt: null,
        );

        $updated = $store->update($token->id, [
            'tokenHash' => 'new_hash',
        ]);

        $this->assertNotNull($updated);
        $this->assertSame('new_hash', $updated->tokenHash);

        // Old hash should no longer work
        $this->assertNull($store->findByHash('old_hash'));

        // New hash should work
        $found = $store->findByHash('new_hash');
        $this->assertNotNull($found);
        $this->assertSame($token->id, $found->id);
    }

    public function test_update_returns_null_for_missing(): void
    {
        $store = $this->createStore();

        $result = $store->update('nonexistent', ['name' => 'Nope']);
        $this->assertNull($result);
    }

    public function test_delete(): void
    {
        $store = $this->createStore();

        $token = $store->create(
            userId: 'user-1',
            name: 'Delete Me',
            tokenHash: 'hash_delete',
            scopes: ['*'],
            expiresAt: null,
        );

        $result = $store->delete($token->id);
        $this->assertTrue($result);
        $this->assertNull($store->find($token->id));
        $this->assertNull($store->findByHash('hash_delete'));
    }

    public function test_delete_returns_false_for_missing(): void
    {
        $store = $this->createStore();

        $this->assertFalse($store->delete('nonexistent'));
    }

    public function test_delete_for_user(): void
    {
        $store = $this->createStore();

        $store->create('user-1', 'Token A', 'hash_a', ['*'], null);
        $store->create('user-1', 'Token B', 'hash_b', ['*'], null);
        $token3 = $store->create('user-2', 'Token C', 'hash_c', ['*'], null);

        $deleted = $store->deleteForUser('user-1');
        $this->assertSame(2, $deleted);

        $all = $store->listAll();
        $this->assertCount(1, $all);
        $this->assertSame($token3->id, $all->first()->id);
    }

    public function test_list_for_user(): void
    {
        $store = $this->createStore();

        $store->create('user-1', 'Token A', 'hash_lu_a', ['*'], null);
        $store->create('user-1', 'Token B', 'hash_lu_b', ['*'], null);
        $store->create('user-2', 'Token C', 'hash_lu_c', ['*'], null);

        $user1Tokens = $store->listForUser('user-1');
        $this->assertCount(2, $user1Tokens);

        $user2Tokens = $store->listForUser('user-2');
        $this->assertCount(1, $user2Tokens);
    }

    public function test_list_all(): void
    {
        $store = $this->createStore();

        $store->create('user-1', 'Token A', 'hash_la_a', ['*'], null);
        $store->create('user-2', 'Token B', 'hash_la_b', ['*'], null);

        $all = $store->listAll();
        $this->assertCount(2, $all);
    }

    public function test_prune_expired(): void
    {
        $store = $this->createStore();

        // Expired token
        $store->create('user-1', 'Expired', 'hash_exp', ['*'], Carbon::now()->subDay());
        // Valid token
        $store->create('user-1', 'Valid', 'hash_val', ['*'], Carbon::now()->addDay());
        // No expiry token
        $store->create('user-1', 'Forever', 'hash_for', ['*'], null);

        $pruned = $store->pruneExpired();
        $this->assertSame(1, $pruned);

        $all = $store->listAll();
        $this->assertCount(2, $all);

        // Expired token should be gone
        $this->assertNull($store->findByHash('hash_exp'));
        // Others remain
        $this->assertNotNull($store->findByHash('hash_val'));
        $this->assertNotNull($store->findByHash('hash_for'));
    }

    public function test_mark_as_used(): void
    {
        $store = $this->createStore();

        $token = $store->create('user-1', 'Use Me', 'hash_use', ['*'], null);
        $this->assertNull($token->lastUsedAt);

        $store->markAsUsed($token->id);

        $found = $store->find($token->id);
        $this->assertNotNull($found);
        $this->assertNotNull($found->lastUsedAt);
        $this->assertInstanceOf(Carbon::class, $found->lastUsedAt);
    }

    public function test_import_preserves_id(): void
    {
        $store = $this->createStore();
        $now = Carbon::now();

        $original = new McpTokenData(
            id: 'custom-uuid-123',
            userId: 'user-1',
            name: 'Imported Token',
            tokenHash: 'hash_import',
            scopes: ['content:read', 'entries:write'],
            lastUsedAt: $now->copy()->subHour(),
            expiresAt: $now->copy()->addDays(60),
            createdAt: $now->copy()->subDays(10),
            updatedAt: $now->copy()->subDays(5),
        );

        $imported = $store->import($original);
        $this->assertSame('custom-uuid-123', $imported->id);
        $this->assertSame('user-1', $imported->userId);
        $this->assertSame('Imported Token', $imported->name);
        $this->assertSame('hash_import', $imported->tokenHash);
        $this->assertSame(['content:read', 'entries:write'], $imported->scopes);
        $this->assertNotNull($imported->lastUsedAt);
        $this->assertNotNull($imported->expiresAt);

        $found = $store->find('custom-uuid-123');
        $this->assertNotNull($found);
        $this->assertSame('Imported Token', $found->name);
    }
}
