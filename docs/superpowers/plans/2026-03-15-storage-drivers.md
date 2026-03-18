# Storage Driver Abstraction Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce swappable storage drivers (file + database) for tokens and audit logs, following Statamic's config class binding pattern.

**Architecture:** Contract interfaces (`TokenStore`, `AuditStore`) with config-driven class bindings. `TokenService` and `ToolLogger` retain their APIs but delegate to the configured driver. File drivers are default; database drivers use existing Eloquent models.

**Tech Stack:** PHP 8.3, Laravel 12, Statamic v6, Pest 4, PHPStan Level 8, Symfony YAML

**Spec:** `docs/superpowers/specs/2026-03-15-storage-drivers-design.md`

---

## Chunk 1: DTOs and Contracts (Foundation)

### Task 1: Create McpTokenData DTO

**Files:**
- Create: `src/Storage/Tokens/McpTokenData.php`
- Test: `tests/Unit/Storage/McpTokenDataTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;

it('creates a token data object with all fields', function (): void {
    $now = Carbon::now();
    $token = new McpTokenData(
        id: 'uuid-1',
        userId: 'user-1',
        name: 'Test Token',
        tokenHash: 'hash123',
        scopes: ['content:read', 'content:write'],
        lastUsedAt: $now,
        expiresAt: $now->copy()->addDays(30),
        createdAt: $now,
        updatedAt: $now,
    );

    expect($token->id)->toBe('uuid-1');
    expect($token->userId)->toBe('user-1');
    expect($token->name)->toBe('Test Token');
    expect($token->tokenHash)->toBe('hash123');
    expect($token->scopes)->toBe(['content:read', 'content:write']);
    expect($token->lastUsedAt)->toBeInstanceOf(Carbon::class);
    expect($token->expiresAt)->toBeInstanceOf(Carbon::class);
    expect($token->createdAt)->toBeInstanceOf(Carbon::class);
    expect($token->updatedAt)->toBeInstanceOf(Carbon::class);
});

it('allows nullable fields', function (): void {
    $token = new McpTokenData(
        id: 'uuid-2',
        userId: 'user-1',
        name: 'Minimal Token',
        tokenHash: 'hash456',
        scopes: ['*'],
        lastUsedAt: null,
        expiresAt: null,
        createdAt: Carbon::now(),
    );

    expect($token->lastUsedAt)->toBeNull();
    expect($token->expiresAt)->toBeNull();
    expect($token->updatedAt)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/McpTokenDataTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Tokens;

use Carbon\Carbon;

/**
 * Immutable data transfer object for MCP tokens.
 *
 * Pure data — no business logic. Scope checking and expiry
 * validation stay in TokenService.
 */
class McpTokenData
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $name,
        public readonly string $tokenHash,
        public readonly array $scopes,
        public readonly ?Carbon $lastUsedAt,
        public readonly ?Carbon $expiresAt,
        public readonly Carbon $createdAt,
        public readonly ?Carbon $updatedAt = null,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Storage/McpTokenDataTest.php`
Expected: PASS

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Storage/Tokens/McpTokenData.php`
Expected: 0 errors

- [ ] **Step 6: Commit**

```bash
git add src/Storage/Tokens/McpTokenData.php tests/Unit/Storage/McpTokenDataTest.php
git commit -m "feat: add McpTokenData DTO for storage driver abstraction"
```

---

### Task 2: Create AuditResult DTO

**Files:**
- Create: `src/Storage/Audit/AuditResult.php`
- Test: `tests/Unit/Storage/AuditResultTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Storage\Audit\AuditResult;

it('creates an audit result with pagination metadata', function (): void {
    $entries = [
        ['tool' => 'statamic-entries', 'status' => 'success', 'timestamp' => '2026-03-15T12:00:00Z'],
        ['tool' => 'statamic-blueprints', 'status' => 'failed', 'timestamp' => '2026-03-15T12:01:00Z'],
    ];

    $result = new AuditResult(
        entries: $entries,
        total: 50,
        currentPage: 1,
        lastPage: 25,
        perPage: 2,
    );

    expect($result->entries)->toHaveCount(2);
    expect($result->total)->toBe(50);
    expect($result->currentPage)->toBe(1);
    expect($result->lastPage)->toBe(25);
    expect($result->perPage)->toBe(2);
});

it('handles empty results', function (): void {
    $result = new AuditResult(
        entries: [],
        total: 0,
        currentPage: 1,
        lastPage: 1,
        perPage: 25,
    );

    expect($result->entries)->toBeEmpty();
    expect($result->total)->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditResultTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write the DTO**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Audit;

/**
 * Paginated result set from audit store queries.
 */
class AuditResult
{
    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $perPage,
    ) {}
}
```

- [ ] **Step 4: Run test + PHPStan**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditResultTest.php && ./vendor/bin/phpstan analyse src/Storage/Audit/AuditResult.php`
Expected: PASS, 0 errors

- [ ] **Step 5: Commit**

```bash
git add src/Storage/Audit/AuditResult.php tests/Unit/Storage/AuditResultTest.php
git commit -m "feat: add AuditResult DTO for storage driver abstraction"
```

---

### Task 3: Create TokenStore contract

**Files:**
- Create: `src/Contracts/TokenStore.php`

- [ ] **Step 1: Write the contract**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Contracts;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Collection;

interface TokenStore
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function create(
        string $userId,
        string $name,
        string $tokenHash,
        array $scopes,
        ?Carbon $expiresAt
    ): McpTokenData;

    public function findByHash(string $tokenHash): ?McpTokenData;

    public function find(string $id): ?McpTokenData;

    /**
     * Update token fields. Supported keys: name, scopes, expiresAt, tokenHash.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): ?McpTokenData;

    public function delete(string $id): bool;

    public function deleteForUser(string $userId): int;

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listForUser(string $userId): Collection;

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listAll(): Collection;

    /**
     * Import an existing token preserving all fields including ID.
     * Used by mcp:migrate-store to copy tokens between drivers.
     */
    public function import(McpTokenData $token): McpTokenData;

    /**
     * Delete expired tokens. Returns count deleted.
     */
    public function pruneExpired(): int;

    public function markAsUsed(string $id): void;
}
```

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Contracts/TokenStore.php`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add src/Contracts/TokenStore.php
git commit -m "feat: add TokenStore contract interface"
```

---

### Task 4: Create AuditStore contract

**Files:**
- Create: `src/Contracts/AuditStore.php`

- [ ] **Step 1: Write the contract**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Contracts;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Audit\AuditResult;

interface AuditStore
{
    /**
     * Write an audit entry to the store.
     *
     * @param  array{level: string, message: string, tool?: string, action?: string, status?: string, correlation_id?: string, duration_ms?: float, timestamp: string, metadata?: array<string, mixed>}  $entry
     */
    public function write(array $entry): void;

    /**
     * Query audit entries with optional filtering and pagination.
     */
    public function query(
        ?string $tool,
        ?string $status,
        int $page,
        int $perPage
    ): AuditResult;

    /**
     * Purge entries older than the given date. Returns count deleted.
     * If null, purges all entries.
     */
    public function purge(?Carbon $before = null): int;
}
```

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Contracts/AuditStore.php`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add src/Contracts/AuditStore.php
git commit -m "feat: add AuditStore contract interface"
```

---

## Chunk 2: File Drivers (Default Implementations)

### Task 5: Create FileAuditStore

**Files:**
- Create: `src/Storage/Audit/FileAuditStore.php`
- Create: `tests/Unit/Storage/Audit/FileAuditStoreTest.php`
- Create: `tests/Concerns/AuditStoreContractTests.php`

- [ ] **Step 1: Write the contract test trait**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\AuditStore;

trait AuditStoreContractTests
{
    abstract protected function createStore(): AuditStore;

    public function test_write_and_query(): void
    {
        $store = $this->createStore();

        $store->write([
            'level' => 'info',
            'message' => 'MCP Tool Started',
            'tool' => 'statamic-entries',
            'status' => 'started',
            'timestamp' => now()->toIso8601String(),
        ]);

        $store->write([
            'level' => 'info',
            'message' => 'MCP Tool Success',
            'tool' => 'statamic-entries',
            'status' => 'success',
            'duration_ms' => 42.5,
            'timestamp' => now()->toIso8601String(),
        ]);

        $result = $store->query(null, null, 1, 25);

        $this->assertCount(2, $result->entries);
        $this->assertEquals(2, $result->total);
        $this->assertEquals(1, $result->currentPage);
    }

    public function test_query_filters_by_tool(): void
    {
        $store = $this->createStore();

        $store->write(['level' => 'info', 'message' => 'A', 'tool' => 'statamic-entries', 'status' => 'success', 'timestamp' => now()->toIso8601String()]);
        $store->write(['level' => 'info', 'message' => 'B', 'tool' => 'statamic-blueprints', 'status' => 'success', 'timestamp' => now()->toIso8601String()]);

        $result = $store->query('entries', null, 1, 25);

        $this->assertCount(1, $result->entries);
        $this->assertEquals('statamic-entries', $result->entries[0]['tool']);
    }

    public function test_query_filters_by_status(): void
    {
        $store = $this->createStore();

        $store->write(['level' => 'info', 'message' => 'A', 'tool' => 'statamic-entries', 'status' => 'success', 'timestamp' => now()->toIso8601String()]);
        $store->write(['level' => 'error', 'message' => 'B', 'tool' => 'statamic-entries', 'status' => 'failed', 'timestamp' => now()->toIso8601String()]);

        $result = $store->query(null, 'failed', 1, 25);

        $this->assertCount(1, $result->entries);
        $this->assertEquals('failed', $result->entries[0]['status']);
    }

    public function test_query_paginates(): void
    {
        $store = $this->createStore();

        for ($i = 0; $i < 5; $i++) {
            $store->write(['level' => 'info', 'message' => "Entry {$i}", 'tool' => 'test', 'status' => 'success', 'timestamp' => now()->toIso8601String()]);
        }

        $page1 = $store->query(null, null, 1, 2);
        $this->assertCount(2, $page1->entries);
        $this->assertEquals(5, $page1->total);
        $this->assertEquals(3, $page1->lastPage);

        $page2 = $store->query(null, null, 2, 2);
        $this->assertCount(2, $page2->entries);
    }

    public function test_query_returns_newest_first(): void
    {
        $store = $this->createStore();

        $store->write(['level' => 'info', 'message' => 'First', 'tool' => 'test', 'status' => 'success', 'timestamp' => '2026-03-15T10:00:00Z']);
        $store->write(['level' => 'info', 'message' => 'Second', 'tool' => 'test', 'status' => 'success', 'timestamp' => '2026-03-15T11:00:00Z']);

        $result = $store->query(null, null, 1, 25);

        $this->assertEquals('Second', $result->entries[0]['message']);
        $this->assertEquals('First', $result->entries[1]['message']);
    }

    public function test_purge_removes_entries_before_date(): void
    {
        $store = $this->createStore();

        $store->write(['level' => 'info', 'message' => 'Old', 'tool' => 'test', 'status' => 'success', 'timestamp' => '2026-01-01T00:00:00Z']);
        $store->write(['level' => 'info', 'message' => 'New', 'tool' => 'test', 'status' => 'success', 'timestamp' => '2026-03-15T00:00:00Z']);

        $purged = $store->purge(Carbon::parse('2026-03-01'));

        $this->assertEquals(1, $purged);

        $result = $store->query(null, null, 1, 25);
        $this->assertCount(1, $result->entries);
        $this->assertEquals('New', $result->entries[0]['message']);
    }

    public function test_purge_all_when_no_date(): void
    {
        $store = $this->createStore();

        $store->write(['level' => 'info', 'message' => 'A', 'tool' => 'test', 'status' => 'success', 'timestamp' => now()->toIso8601String()]);
        $store->write(['level' => 'info', 'message' => 'B', 'tool' => 'test', 'status' => 'success', 'timestamp' => now()->toIso8601String()]);

        $purged = $store->purge(null);

        $this->assertEquals(2, $purged);

        $result = $store->query(null, null, 1, 25);
        $this->assertCount(0, $result->entries);
    }

    public function test_empty_query(): void
    {
        $store = $this->createStore();

        $result = $store->query(null, null, 1, 25);

        $this->assertCount(0, $result->entries);
        $this->assertEquals(0, $result->total);
        $this->assertEquals(1, $result->lastPage);
    }
}
```

- [ ] **Step 2: Write the FileAuditStore test**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Audit;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Tests\Concerns\AuditStoreContractTests;
use Cboxdk\StatamicMcp\Tests\TestCase;

class FileAuditStoreTest extends TestCase
{
    use AuditStoreContractTests;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/mcp-test-audit-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }
        parent::tearDown();
    }

    protected function createStore(): AuditStore
    {
        return new FileAuditStore($this->tempPath);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Storage/Audit/FileAuditStoreTest.php`
Expected: FAIL — FileAuditStore class not found

- [ ] **Step 4: Implement FileAuditStore**

Create `src/Storage/Audit/FileAuditStore.php`. Moves the JSONL write logic from `ToolLogger` and the read/parse logic from `AuditController` into this driver. Key details:

- Constructor reads path from `config('statamic.mcp.storage.audit_path')` with fallback to `storage_path('statamic-mcp/audit.log')`. Optionally accepts `?string $path = null` for testing override.
- `write()`: appends JSON line with `FILE_APPEND | LOCK_EX`
- `query()`: reads file, reverses lines (newest first), filters by tool (substring match) and status (exact match), paginates, returns `AuditResult`
- `purge()`: reads all lines, filters out entries before the date, rewrites file. If `$before` is null, truncates the file.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Storage/Audit/FileAuditStoreTest.php`
Expected: PASS (8 tests)

- [ ] **Step 6: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Storage/Audit/FileAuditStore.php`
Expected: 0 errors

- [ ] **Step 7: Commit**

```bash
git add src/Storage/Audit/FileAuditStore.php tests/Unit/Storage/Audit/FileAuditStoreTest.php tests/Concerns/AuditStoreContractTests.php
git commit -m "feat: add FileAuditStore with contract test suite"
```

---

### Task 6: Create FileTokenStore

**Files:**
- Create: `src/Storage/Tokens/FileTokenStore.php`
- Create: `tests/Unit/Storage/Tokens/FileTokenStoreTest.php`
- Create: `tests/Concerns/TokenStoreContractTests.php`

- [ ] **Step 1: Write the contract test trait**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\TokenStore;

trait TokenStoreContractTests
{
    abstract protected function createStore(): TokenStore;

    public function test_create_and_find_by_hash(): void
    {
        $store = $this->createStore();

        $token = $store->create('user-1', 'My Token', 'hash_abc', ['content:read'], Carbon::now()->addDays(30));

        $this->assertEquals('user-1', $token->userId);
        $this->assertEquals('My Token', $token->name);
        $this->assertEquals('hash_abc', $token->tokenHash);
        $this->assertEquals(['content:read'], $token->scopes);
        $this->assertNotNull($token->expiresAt);
        $this->assertNotEmpty($token->id);

        $found = $store->findByHash('hash_abc');
        $this->assertNotNull($found);
        $this->assertEquals($token->id, $found->id);
    }

    public function test_find_by_id(): void
    {
        $store = $this->createStore();

        $token = $store->create('user-1', 'Token', 'hash_1', ['*'], null);
        $found = $store->find($token->id);

        $this->assertNotNull($found);
        $this->assertEquals($token->id, $found->id);
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

        $token = $store->create('user-1', 'Original', 'hash_u', ['content:read'], null);

        $updated = $store->update($token->id, [
            'name' => 'Updated Name',
            'scopes' => ['content:read', 'content:write'],
        ]);

        $this->assertNotNull($updated);
        $this->assertEquals('Updated Name', $updated->name);
        $this->assertEquals(['content:read', 'content:write'], $updated->scopes);
    }

    public function test_update_token_hash(): void
    {
        $store = $this->createStore();

        $token = $store->create('user-1', 'Token', 'old_hash', ['*'], null);

        $store->update($token->id, ['tokenHash' => 'new_hash']);

        $this->assertNull($store->findByHash('old_hash'));
        $this->assertNotNull($store->findByHash('new_hash'));
    }

    public function test_update_returns_null_for_missing(): void
    {
        $store = $this->createStore();

        $this->assertNull($store->update('nonexistent', ['name' => 'X']));
    }

    public function test_delete(): void
    {
        $store = $this->createStore();

        $token = $store->create('user-1', 'Token', 'hash_d', ['*'], null);

        $this->assertTrue($store->delete($token->id));
        $this->assertNull($store->find($token->id));
        $this->assertNull($store->findByHash('hash_d'));
    }

    public function test_delete_returns_false_for_missing(): void
    {
        $store = $this->createStore();

        $this->assertFalse($store->delete('nonexistent'));
    }

    public function test_delete_for_user(): void
    {
        $store = $this->createStore();

        $store->create('user-1', 'T1', 'hash_1', ['*'], null);
        $store->create('user-1', 'T2', 'hash_2', ['*'], null);
        $store->create('user-2', 'T3', 'hash_3', ['*'], null);

        $deleted = $store->deleteForUser('user-1');

        $this->assertEquals(2, $deleted);
        $this->assertNull($store->findByHash('hash_1'));
        $this->assertNull($store->findByHash('hash_2'));
        $this->assertNotNull($store->findByHash('hash_3'));
    }

    public function test_list_for_user(): void
    {
        $store = $this->createStore();

        $store->create('user-1', 'T1', 'hash_l1', ['*'], null);
        $store->create('user-1', 'T2', 'hash_l2', ['*'], null);
        $store->create('user-2', 'T3', 'hash_l3', ['*'], null);

        $tokens = $store->listForUser('user-1');

        $this->assertCount(2, $tokens);
        $tokens->each(fn ($t) => $this->assertEquals('user-1', $t->userId));
    }

    public function test_list_all(): void
    {
        $store = $this->createStore();

        $store->create('user-1', 'T1', 'hash_a1', ['*'], null);
        $store->create('user-2', 'T2', 'hash_a2', ['*'], null);

        $tokens = $store->listAll();

        $this->assertCount(2, $tokens);
    }

    public function test_prune_expired(): void
    {
        $store = $this->createStore();

        $store->create('user-1', 'Expired', 'hash_e1', ['*'], Carbon::now()->subDay());
        $store->create('user-1', 'Valid', 'hash_e2', ['*'], Carbon::now()->addDay());
        $store->create('user-1', 'No Expiry', 'hash_e3', ['*'], null);

        $pruned = $store->pruneExpired();

        $this->assertEquals(1, $pruned);
        $this->assertNull($store->findByHash('hash_e1'));
        $this->assertNotNull($store->findByHash('hash_e2'));
        $this->assertNotNull($store->findByHash('hash_e3'));
    }

    public function test_mark_as_used(): void
    {
        $store = $this->createStore();

        $token = $store->create('user-1', 'Token', 'hash_m', ['*'], null);

        $this->assertNull($token->lastUsedAt);

        $store->markAsUsed($token->id);

        $refreshed = $store->find($token->id);
        $this->assertNotNull($refreshed);
        $this->assertNotNull($refreshed->lastUsedAt);
    }
}
```

- [ ] **Step 2: Write the FileTokenStore test**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Tokens;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\TokenStoreContractTests;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\File;

class FileTokenStoreTest extends TestCase
{
    use TokenStoreContractTests;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/mcp-test-tokens-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    protected function createStore(): TokenStore
    {
        return new FileTokenStore($this->tempDir);
    }

    public function test_index_rebuilds_after_deletion(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $store->create('user-1', 'Token', 'hash_idx', ['*'], null);

        // Delete the index file to simulate corruption
        $indexPath = $this->tempDir . '/.index';
        if (file_exists($indexPath)) {
            unlink($indexPath);
        }

        // Should still find by hash after index rebuild
        $found = $store->findByHash('hash_idx');
        $this->assertNotNull($found);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Storage/Tokens/FileTokenStoreTest.php`
Expected: FAIL — FileTokenStore class not found

- [ ] **Step 4: Implement FileTokenStore**

Create `src/Storage/Tokens/FileTokenStore.php`. Key details:

- Constructor reads path from `config('statamic.mcp.storage.tokens_path')` with fallback to `storage_path('statamic-mcp/tokens')`. Optionally accepts `?string $storagePath = null` for testing override.
- Creates directory if it doesn't exist
- Tokens stored as `{uuid}.yaml` files using Symfony YAML
- Hash index at `.index` (JSON file mapping hash → id)
- All index operations use `flock()` for read-modify-write atomicity
- `create()`: generates UUID, writes YAML file, updates index
- `findByHash()`: reads index to get ID, then reads YAML file. If index missing, calls `rebuildIndex()`
- `find()`: reads `{id}.yaml` directly
- `update()`: reads, merges data, writes YAML. If `tokenHash` changed, updates index (remove old, add new)
- `delete()`: removes YAML file, removes from index
- `listForUser()/listAll()`: scans all YAML files, filters, returns Collection of McpTokenData
- `pruneExpired()`: scans all files, deletes expired ones, updates index
- `markAsUsed()`: updates `lastUsedAt` in the YAML file
- `rebuildIndex()`: scans all YAML files, builds hash → id map, writes index

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Storage/Tokens/FileTokenStoreTest.php`
Expected: PASS (15 tests)

- [ ] **Step 6: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Storage/Tokens/FileTokenStore.php`
Expected: 0 errors

- [ ] **Step 7: Commit**

```bash
git add src/Storage/Tokens/FileTokenStore.php tests/Unit/Storage/Tokens/FileTokenStoreTest.php tests/Concerns/TokenStoreContractTests.php
git commit -m "feat: add FileTokenStore with YAML storage and hash index"
```

---

## Chunk 3: Database Drivers

### Task 7: Create McpAuditEntry Eloquent model and migration

**Files:**
- Create: `src/Storage/Audit/McpAuditEntry.php`
- Create: `database/migrations/audit/create_mcp_audit_logs_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 20);
            $table->string('message');
            $table->string('tool')->nullable()->index();
            $table->string('action')->nullable();
            $table->string('status', 20)->nullable()->index();
            $table->string('correlation_id', 36)->nullable()->index();
            $table->float('duration_ms')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
```

- [ ] **Step 2: Create the Eloquent model**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $level
 * @property string $message
 * @property string|null $tool
 * @property string|null $action
 * @property string|null $status
 * @property string|null $correlation_id
 * @property float|null $duration_ms
 * @property array<string, mixed>|null $context
 * @property \Illuminate\Support\Carbon $logged_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class McpAuditEntry extends Model
{
    protected $table = 'mcp_audit_logs';

    /** @var list<string> */
    protected $fillable = [
        'level',
        'message',
        'tool',
        'action',
        'status',
        'correlation_id',
        'duration_ms',
        'context',
        'logged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'duration_ms' => 'float',
            'logged_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 3: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Storage/Audit/McpAuditEntry.php`
Expected: 0 errors

- [ ] **Step 4: Commit**

```bash
git add src/Storage/Audit/McpAuditEntry.php database/migrations/audit/
git commit -m "feat: add McpAuditEntry model and audit_logs migration"
```

---

### Task 8: Create DatabaseAuditStore

**Files:**
- Create: `src/Storage/Audit/DatabaseAuditStore.php`
- Create: `tests/Unit/Storage/Audit/DatabaseAuditStoreTest.php`

- [ ] **Step 1: Write the test (reuses contract trait)**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Audit;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Tests\Concerns\AuditStoreContractTests;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseAuditStoreTest extends TestCase
{
    use AuditStoreContractTests;
    use RefreshDatabase;

    protected function createStore(): AuditStore
    {
        return new DatabaseAuditStore();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations/audit');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/Audit/DatabaseAuditStoreTest.php`
Expected: FAIL — DatabaseAuditStore class not found

- [ ] **Step 3: Implement DatabaseAuditStore**

Create `src/Storage/Audit/DatabaseAuditStore.php`. Key details:

- `write()`: creates `McpAuditEntry` record, maps entry array keys to model columns. Extra keys go into `context` JSON. `logged_at` parsed from entry `timestamp`.
- `query()`: uses Eloquent query builder. Filters `tool` with `LIKE %term%`, `status` with exact match. Orders by `logged_at DESC`. Paginates with `skip/take`. Returns `AuditResult`.
- `purge()`: `McpAuditEntry::where('logged_at', '<', $before)->delete()`. If `$before` is null, `McpAuditEntry::truncate()`. Returns count.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Storage/Audit/DatabaseAuditStoreTest.php`
Expected: PASS (8 tests)

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Storage/Audit/DatabaseAuditStore.php`
Expected: 0 errors

- [ ] **Step 6: Commit**

```bash
git add src/Storage/Audit/DatabaseAuditStore.php tests/Unit/Storage/Audit/DatabaseAuditStoreTest.php
git commit -m "feat: add DatabaseAuditStore with Eloquent backend"
```

---

### Task 9: Create DatabaseTokenStore

**Files:**
- Create: `src/Storage/Tokens/DatabaseTokenStore.php`
- Create: `tests/Unit/Storage/Tokens/DatabaseTokenStoreTest.php`

- [ ] **Step 1: Write the test (reuses contract trait)**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Tokens;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\TokenStoreContractTests;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseTokenStoreTest extends TestCase
{
    use TokenStoreContractTests;
    use RefreshDatabase;

    protected function createStore(): TokenStore
    {
        return new DatabaseTokenStore();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations/tokens');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/Tokens/DatabaseTokenStoreTest.php`
Expected: FAIL — DatabaseTokenStore class not found

- [ ] **Step 3: Implement DatabaseTokenStore**

Create `src/Storage/Tokens/DatabaseTokenStore.php`. Key details:

- Wraps existing `McpToken` Eloquent model
- `create()`: uses `McpToken::create()`, returns `McpTokenData` via private `toData()` conversion method
- `findByHash()`: `McpToken::where('token', $hash)->first()`, converts to McpTokenData
- `find()`: `McpToken::find($id)`, converts
- `update()`: finds model, maps DTO field names to model columns (`tokenHash` → `token`, `expiresAt` → `expires_at`, etc.), saves
- `delete()`: `McpToken::where('id', $id)->delete()` returns bool
- `deleteForUser()`: `McpToken::where('user_id', $userId)->delete()`
- `listForUser()`: `McpToken::where('user_id', ...)->orderByDesc('created_at')->get()`, maps to Collection of McpTokenData
- `listAll()`: `McpToken::orderByDesc('created_at')->get()`, maps
- `pruneExpired()`: `McpToken::where('expires_at', '<=', now())->delete()`
- `markAsUsed()`: atomic raw query `UPDATE mcp_tokens SET last_used_at = ? WHERE id = ?` (preserves existing race condition fix from TokenService)
- Private `toData(McpToken $model): McpTokenData` converter

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Storage/Tokens/DatabaseTokenStoreTest.php`
Expected: PASS (15 tests)

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Storage/Tokens/DatabaseTokenStore.php`
Expected: 0 errors

- [ ] **Step 6: Commit**

```bash
git add src/Storage/Tokens/DatabaseTokenStore.php tests/Unit/Storage/Tokens/DatabaseTokenStoreTest.php
git commit -m "feat: add DatabaseTokenStore wrapping McpToken Eloquent model"
```

---

### Task 10: Move existing token migrations to `database/migrations/tokens/`

**Files:**
- Move: `database/migrations/create_mcp_tokens_table.php` → `database/migrations/tokens/`
- Move: `database/migrations/add_unique_token_index_to_mcp_tokens_table.php` → `database/migrations/tokens/`

- [ ] **Step 1: Move migration files**

```bash
mkdir -p database/migrations/tokens
git mv database/migrations/create_mcp_tokens_table.php database/migrations/tokens/
git mv database/migrations/add_unique_token_index_to_mcp_tokens_table.php database/migrations/tokens/
```

- [ ] **Step 2: Run existing tests to verify nothing broke**

Run: `./vendor/bin/pest tests/Unit/Auth/TokenServiceTest.php`
Expected: PASS (tests load migrations from the TestCase, which should still work)

Note: If tests fail because migration paths changed, update the TestCase or test setUp to load from the new path. Check `tests/TestCase.php` for how migrations are loaded.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/
git commit -m "refactor: move token migrations to database/migrations/tokens/"
```

---

## Chunk 4: Refactor Existing Code to Use Contracts

### Task 11: Refactor TokenService to use TokenStore contract

**Files:**
- Modify: `src/Auth/TokenService.php`
- Modify: `tests/Unit/Auth/TokenServiceTest.php`

This is the most impactful refactor. `TokenService` retains its public method signatures but the return types change from `McpToken` to `McpTokenData`.

- [ ] **Step 1: Update TokenService**

Key changes to `src/Auth/TokenService.php`:
- Add constructor injection of `TokenStore` (replace direct `McpToken` usage)
- `createToken()`: generate hash, call `$this->tokenStore->create()`, return `['token' => $plainText, 'model' => McpTokenData]` (preserves existing key names — `TokenController::store()` line 74 accesses `$result['token']` for plain text and `$result['model']` for the data object)
- `validateToken()`: hash input, call `$this->tokenStore->findByHash()`, verify with `hash_equals()`, check expiry, call `markAsUsed()`. Returns `?McpTokenData` (was `?McpToken`)
- `updateToken()`: call `$this->tokenStore->update()`, returns `?McpTokenData`
- `regenerateToken()`: generate new hash, call `$this->tokenStore->update()` with new `tokenHash`, return `['token' => $newPlainText, 'model' => McpTokenData]|null` (preserves existing key names)
- `revokeToken()`: call `$this->tokenStore->delete()`
- `revokeAllForUser()`: call `$this->tokenStore->deleteForUser()`
- `listTokensForUser()`: call `$this->tokenStore->listForUser()`
- `listAllTokens()`: call `$this->tokenStore->listAll()`
- `pruneExpired()`: call `$this->tokenStore->pruneExpired()`
- Add `isExpired(McpTokenData $token): bool` — `return $token->expiresAt !== null && now()->greaterThan($token->expiresAt)`
- Add `hasScope(McpTokenData $token, TokenScope $scope): bool` — `return in_array('*', $token->scopes, true) || in_array($scope->value, $token->scopes, true)`

- [ ] **Step 2: Update TokenServiceTest**

Update `tests/Unit/Auth/TokenServiceTest.php` to work with `McpTokenData` instead of `McpToken`. The test structure remains the same but assertions change from Eloquent model property access (`$token->user_id`) to DTO property access (`$token->userId`).

Also add tests for the new helper methods:

```php
it('checks token expiry via isExpired', function (): void {
    $service = app(TokenService::class);
    $expired = new McpTokenData('id', 'user', 'name', 'hash', ['*'], null, Carbon::now()->subDay(), Carbon::now());
    $valid = new McpTokenData('id', 'user', 'name', 'hash', ['*'], null, Carbon::now()->addDay(), Carbon::now());
    $noExpiry = new McpTokenData('id', 'user', 'name', 'hash', ['*'], null, null, Carbon::now());

    expect($service->isExpired($expired))->toBeTrue();
    expect($service->isExpired($valid))->toBeFalse();
    expect($service->isExpired($noExpiry))->toBeFalse();
});

it('checks token scope via hasScope', function (): void {
    $service = app(TokenService::class);
    $wildcard = new McpTokenData('id', 'user', 'name', 'hash', ['*'], null, null, Carbon::now());
    $scoped = new McpTokenData('id', 'user', 'name', 'hash', ['content:read'], null, null, Carbon::now());

    expect($service->hasScope($wildcard, TokenScope::ContentRead))->toBeTrue();
    expect($service->hasScope($scoped, TokenScope::ContentRead))->toBeTrue();
    expect($service->hasScope($scoped, TokenScope::ContentWrite))->toBeFalse();
});
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/pest tests/Unit/Auth/TokenServiceTest.php`
Expected: PASS

- [ ] **Step 4: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Auth/TokenService.php`
Expected: 0 errors

- [ ] **Step 5: Commit**

```bash
git add src/Auth/TokenService.php tests/Unit/Auth/TokenServiceTest.php
git commit -m "refactor: TokenService delegates to TokenStore contract"
```

---

### Task 12: Update McpTokenGuard and middleware for McpTokenData

**Files:**
- Modify: `src/Auth/McpTokenGuard.php:40-68` (user() method)
- Modify: `src/Http/Middleware/AuthenticateForMcp.php:49-59` (token storage in request)
- Modify: `src/Http/Middleware/RequireMcpPermission.php:31-34` (type hints)

- [ ] **Step 1: Update McpTokenGuard**

In `src/Auth/McpTokenGuard.php`:
- Change `user()` to work with `McpTokenData` from `TokenService::validateToken()`
- Access `$mcpToken->userId` instead of `$mcpToken->user_id`
- Store `McpTokenData` (not `McpToken`) for scope checking

- [ ] **Step 2: Update AuthenticateForMcp**

In `src/Http/Middleware/AuthenticateForMcp.php`:
- Line 49-59: `validateToken()` now returns `McpTokenData` (not `McpToken`)
- Line 52: Replace `$mcpToken->statamicUser()` with `User::find($mcpToken->userId)` — the DTO has no `statamicUser()` method
- Line 55: `$request->attributes->set('statamic_user', ...)` — use `User::find($mcpToken->userId)` instead of `$mcpToken->statamicUser()`
- Line 56: `$request->attributes->set('mcp_token', $mcpToken)` — type is now `McpTokenData`
- Update all `McpToken` imports to `McpTokenData`

- [ ] **Step 3: Update RequireMcpPermission**

In `src/Http/Middleware/RequireMcpPermission.php`:
- Line 31: change type hint from `McpToken|null` to `McpTokenData|null`
- Line 34: change `$mcpToken->isExpired()` to `$this->tokenService->isExpired($mcpToken)` (inject `TokenService`) or use inline check `$mcpToken->expiresAt !== null && now()->greaterThan($mcpToken->expiresAt)`

- [ ] **Step 4: Run related tests**

Run: `./vendor/bin/pest tests/Unit/Auth/McpTokenGuardTest.php tests/Unit/Auth/MiddlewareSecurityTest.php tests/Unit/Auth/WebSecurityMiddlewareTest.php`
Expected: PASS (may need test updates for new types)

- [ ] **Step 5: Run PHPStan on all changed files**

Run: `./vendor/bin/phpstan analyse src/Auth/McpTokenGuard.php src/Http/Middleware/AuthenticateForMcp.php src/Http/Middleware/RequireMcpPermission.php`
Expected: 0 errors

- [ ] **Step 6: Commit**

```bash
git add src/Auth/McpTokenGuard.php src/Http/Middleware/AuthenticateForMcp.php src/Http/Middleware/RequireMcpPermission.php
git commit -m "refactor: update guard and middleware for McpTokenData"
```

---

### Task 13: Update controllers for McpTokenData

**Files:**
- Modify: `src/Http/Controllers/CP/DashboardController.php:54-108`
- Modify: `src/Http/Controllers/CP/TokenController.php:29-234`

- [ ] **Step 1: Update DashboardController**

In `src/Http/Controllers/CP/DashboardController.php`:
- `serializeToken()` (lines 88-108): change parameter type from `McpToken` to `McpTokenData`
- Replace `$token->statamicUser()` with `User::find($token->userId)`
- Replace `$token->last_used_at?->toIso8601String()` with `$token->lastUsedAt?->toIso8601String()`
- Replace `$token->expires_at` with `$token->expiresAt`
- Replace `$token->is_expired` or `$token->isExpired()` with `$token->expiresAt !== null && now()->greaterThan($token->expiresAt)`
- Update closures at line 61: `fn (McpToken $token): array =>` → `fn (McpTokenData $token): array =>`
- Update all `McpToken` imports to `McpTokenData`

- [ ] **Step 2: Update TokenController**

In `src/Http/Controllers/CP/TokenController.php`:
- Update `store()`: `$result['model']` is now `McpTokenData` — access `$result['model']->id` (unchanged), `$result['model']->name` (unchanged)
- Update `update()` (lines 140-167): property access changes — `$updated->expires_at` → `$updated->expiresAt`, `$updated->last_used_at` → `$updated->lastUsedAt`, `$updated->created_at` → `$updated->createdAt`, `$updated->isExpired()` → `$updated->expiresAt !== null && now()->greaterThan($updated->expiresAt)`
- Update `regenerate()`: `$result['model']` is now `McpTokenData` — same property name changes as above
- Update `destroy()`: minimal changes (just type references)
- Replace `serializeToken()` and similar closures that type-hint `McpToken` with `McpTokenData`
- Update all `McpToken` imports to `McpTokenData`

- [ ] **Step 3: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Http/Controllers/CP/DashboardController.php src/Http/Controllers/CP/TokenController.php`
Expected: 0 errors

- [ ] **Step 4: Commit**

```bash
git add src/Http/Controllers/CP/DashboardController.php src/Http/Controllers/CP/TokenController.php
git commit -m "refactor: update CP controllers for McpTokenData"
```

---

### Task 14: Refactor ToolLogger and AuditController to use AuditStore

**Files:**
- Modify: `src/Mcp/Support/ToolLogger.php`
- Modify: `src/Http/Controllers/CP/AuditController.php`

- [ ] **Step 1: Update ToolLogger**

In `src/Mcp/Support/ToolLogger.php`:
- The `log()` method now resolves `AuditStore` from the container and calls `write()`
- PII redaction stays in ToolLogger (applied before passing to store)
- `getLogPath()` remains for backward compatibility but is no longer the primary storage mechanism
- Static methods preserved — resolve `AuditStore` via `app(AuditStore::class)` inside `log()`

- [ ] **Step 2: Update AuditController**

In `src/Http/Controllers/CP/AuditController.php`:
- Inject `AuditStore` via constructor or method injection
- Replace all file-reading logic with `$this->auditStore->query($tool, $status, $page, $perPage)`
- Return `AuditResult` as JSON response

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/pest tests/Feature/ToolLoggerTest.php`
Expected: PASS (may need updates to bind AuditStore in test)

- [ ] **Step 4: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Support/ToolLogger.php src/Http/Controllers/CP/AuditController.php`
Expected: 0 errors

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/Support/ToolLogger.php src/Http/Controllers/CP/AuditController.php
git commit -m "refactor: ToolLogger and AuditController delegate to AuditStore"
```

---

### Task 15: Update config and ServiceProvider bindings

**Files:**
- Modify: `config/statamic/mcp.php`
- Modify: `src/ServiceProvider.php:81-92` (register method)
- Modify: `src/Auth/AuthServiceProvider.php:18-23` (singleton registrations)

- [ ] **Step 1: Update config**

Add `stores` and `storage` sections to `config/statamic/mcp.php`. Keep `security.audit_path` and `security.audit_channel` as deprecated keys with comments.

- [ ] **Step 2: Update AuthServiceProvider**

In `src/Auth/AuthServiceProvider.php`:
- Bind `TokenStore` contract: file drivers read path from config internally, so a simple class binding works for all drivers:
  ```php
  $this->app->singleton(TokenStore::class, config('statamic.mcp.stores.tokens'));
  $this->app->singleton(AuditStore::class, config('statamic.mcp.stores.audit'));
  ```
- **Important**: `FileTokenStore` and `FileAuditStore` constructors must read their storage paths from config internally (`config('statamic.mcp.storage.tokens_path')` and `config('statamic.mcp.storage.audit_path')`) rather than accepting constructor arguments. This ensures bare class bindings work without closures. For testing, override config values in setUp().

- [ ] **Step 3: Update ServiceProvider for conditional migrations**

In `src/ServiceProvider.php`:
- Add conditional migration loading using `is_a()` for database drivers
- Update migration publish tag to include both subdirectories
- **Critical**: Update rate limiter closure (line 44) that checks `$mcpToken instanceof McpToken` — change to `$mcpToken instanceof McpTokenData` and access `$mcpToken->id` (public property on DTO). Without this, token-based rate limiting silently falls back to IP-based.

- [ ] **Step 4: Refactor PruneExpiredTokensCommand**

In `src/Console/PruneExpiredTokensCommand.php`:
- Inject `TokenStore` instead of `TokenService` for pruning, OR keep using `TokenService::pruneExpired()` which now delegates to `TokenStore`

- [ ] **Step 5: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All tests PASS

- [ ] **Step 6: Run PHPStan on entire project**

Run: `./vendor/bin/phpstan analyse`
Expected: 0 errors

- [ ] **Step 7: Commit**

```bash
git add config/statamic/mcp.php src/ServiceProvider.php src/Auth/AuthServiceProvider.php src/Console/PruneExpiredTokensCommand.php
git commit -m "feat: wire up storage driver bindings and conditional migrations"
```

---

## Chunk 5: CLI Commands

### Task 16: Create PruneAuditCommand

**Files:**
- Create: `src/Console/PruneAuditCommand.php`
- Create: `tests/Feature/Commands/PruneAuditCommandTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Commands;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Tests\TestCase;

class PruneAuditCommandTest extends TestCase
{
    public function test_prune_audit_with_days_option(): void
    {
        $store = app(AuditStore::class);

        $store->write(['level' => 'info', 'message' => 'Old', 'tool' => 'test', 'status' => 'success', 'timestamp' => '2025-01-01T00:00:00Z']);
        $store->write(['level' => 'info', 'message' => 'New', 'tool' => 'test', 'status' => 'success', 'timestamp' => now()->toIso8601String()]);

        $this->artisan('mcp:prune-audit', ['--days' => 30])
            ->expectsOutputToContain('Pruned')
            ->assertSuccessful();
    }

    public function test_prune_audit_all(): void
    {
        $store = app(AuditStore::class);

        $store->write(['level' => 'info', 'message' => 'A', 'tool' => 'test', 'status' => 'success', 'timestamp' => now()->toIso8601String()]);

        $this->artisan('mcp:prune-audit', ['--all' => true])
            ->expectsOutputToContain('Pruned')
            ->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Commands/PruneAuditCommandTest.php`
Expected: FAIL

- [ ] **Step 3: Implement PruneAuditCommand**

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Illuminate\Console\Command;

class PruneAuditCommand extends Command
{
    /** @var string */
    protected $signature = 'mcp:prune-audit {--days=30 : Number of days to keep} {--all : Purge all entries}';

    /** @var string */
    protected $description = 'Prune old MCP audit log entries';

    public function handle(AuditStore $store): int
    {
        $before = $this->option('all') ? null : Carbon::now()->subDays((int) $this->option('days'));

        $count = $store->purge($before);

        $this->info("Pruned {$count} audit log entries.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register command in ServiceProvider**

Add `PruneAuditCommand::class` to the commands array in `ServiceProvider`.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/pest tests/Feature/Commands/PruneAuditCommandTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Console/PruneAuditCommand.php tests/Feature/Commands/PruneAuditCommandTest.php src/ServiceProvider.php
git commit -m "feat: add mcp:prune-audit command"
```

---

### Task 17: Create MigrateStoreCommand

**Files:**
- Create: `src/Console/MigrateStoreCommand.php`
- Create: `tests/Feature/Commands/MigrateStoreCommandTest.php`

- [ ] **Step 1: Write the test**

Test file should cover:
- `mcp:migrate-store tokens --from=file --to=database` (creates tokens in file store, migrates, verifies in database)
- `mcp:migrate-store audit --from=file --to=database` (same for audit)
- Invalid store name shows error
- Same `--from` and `--to` shows error

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Commands/MigrateStoreCommandTest.php`
Expected: FAIL

- [ ] **Step 3: Implement MigrateStoreCommand**

Key details:
- Signature: `mcp:migrate-store {store : tokens or audit} {--from= : Source driver (file or database)} {--to= : Target driver (file or database)}`
- Maps `file`/`database` to concrete classes using a driver map
- Instantiates both drivers, reads all from source, writes to target
- Shows confirmation prompt with record count
- Displays progress bar during migration
- For tokens: reads `listAll()` from source, writes to target preserving IDs. Use a dedicated `import(McpTokenData $token): McpTokenData` method on `TokenStore` that preserves the original ID, hash, timestamps, and all fields. This avoids generating new UUIDs during migration. Add `import()` to the `TokenStore` contract.
- For audit: reads `query(null, null, 1, PHP_INT_MAX)` from source, calls `write()` on target for each
- Reads storage paths from `config('statamic.mcp.storage.tokens_path')` and `config('statamic.mcp.storage.audit_path')`

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest tests/Feature/Commands/MigrateStoreCommandTest.php`
Expected: PASS

- [ ] **Step 5: Register command in ServiceProvider**

Add `MigrateStoreCommand::class` to the commands array.

- [ ] **Step 6: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Console/MigrateStoreCommand.php`
Expected: 0 errors

- [ ] **Step 7: Commit**

```bash
git add src/Console/MigrateStoreCommand.php tests/Feature/Commands/MigrateStoreCommandTest.php src/ServiceProvider.php
git commit -m "feat: add mcp:migrate-store command for driver migration"
```

---

## Chunk 6: Final Integration and Validation

### Task 18: Run full test suite and fix any failures

- [ ] **Step 1: Run all tests**

Run: `./vendor/bin/pest`
Expected: All tests PASS. If failures, investigate and fix. Common issues:
- Tests that create `McpToken` directly need to use the `TokenStore` or bind the right driver
- Tests referencing `McpToken` properties need to switch to `McpTokenData` properties
- TestCase may need to bind default `TokenStore` and `AuditStore` for tests

- [ ] **Step 2: Run full PHPStan analysis**

Run: `./vendor/bin/phpstan analyse`
Expected: 0 errors

- [ ] **Step 3: Run Pint formatting**

Run: `./vendor/bin/pint`

- [ ] **Step 4: Commit any fixes**

```bash
git add <specific changed files>
git commit -m "fix: resolve integration test failures after storage driver refactor"
```

---

### Task 19: Run quality pipeline and final validation

- [ ] **Step 1: Run full quality pipeline**

Run: `composer quality`
Expected: All checks pass (pint + stan + test)

- [ ] **Step 2: Verify file driver works end-to-end**

Ensure config defaults to file drivers and all tests pass without a database for token operations.

- [ ] **Step 3: Verify database driver works end-to-end**

Temporarily set config to database drivers and run relevant tests.

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete storage driver abstraction with file and database drivers"
```
