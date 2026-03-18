# Storage Driver Abstraction — Design Spec

## Problem

The addon has two storage concerns with hardcoded backends:

1. **Tokens** — Eloquent-only (`McpToken` model). Requires database even for flat-file Statamic sites.
2. **Audit logs** — File-only (JSONL via `ToolLogger`). Doesn't work in horizontally scaled environments where each instance has its own filesystem.

Neither is swappable. Third-party developers cannot provide custom backends (Redis, S3, etc.).

## Solution

Introduce a contract-based storage driver system following Statamic's own pattern (as used by `statamic/eloquent-driver`). Each storage concern gets:

- A contract (interface)
- A file-based driver (default)
- A database driver
- Config-driven class binding
- CLI migration between drivers

## Architecture

### Pattern: Config Class Binding (Statamic-style)

```php
// config/statamic/mcp.php
'stores' => [
    'tokens' => \Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore::class,
    'audit'  => \Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore::class,
],
```

ServiceProvider binds contracts to the configured class:

```php
$this->app->singleton(TokenStore::class, config('statamic.mcp.stores.tokens'));
$this->app->singleton(AuditStore::class, config('statamic.mcp.stores.audit'));
```

Third parties swap drivers by changing the class in config — any class implementing the contract works.

## Contracts

### TokenStore

```php
namespace Cboxdk\StatamicMcp\Contracts;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Collection;

interface TokenStore
{
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
     * When tokenHash is updated, FileTokenStore MUST also update its hash index.
     *
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): ?McpTokenData;

    public function delete(string $id): bool;

    public function deleteForUser(string $userId): int;

    /** @return Collection<int, McpTokenData> */
    public function listForUser(string $userId): Collection;

    /** @return Collection<int, McpTokenData> */
    public function listAll(): Collection;

    public function pruneExpired(): int;

    public function markAsUsed(string $id): void;
}
```

### AuditStore

```php
namespace Cboxdk\StatamicMcp\Contracts;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Audit\AuditResult;

interface AuditStore
{
    /**
     * Write an audit entry.
     *
     * @param array{level: string, message: string, tool?: string, action?: string, status?: string, correlation_id?: string, duration_ms?: float, timestamp: string, metadata?: array<string, mixed>} $entry
     */
    public function write(array $entry): void;

    public function query(
        ?string $tool,
        ?string $status,
        int $page,
        int $perPage
    ): AuditResult;

    public function purge(?Carbon $before = null): int;
}
```

## DTOs

### McpTokenData

Pure data object. No business logic — scope checking and expiry logic stay in `TokenService`.

```php
namespace Cboxdk\StatamicMcp\Storage\Tokens;

use Carbon\Carbon;

class McpTokenData
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $name,
        public readonly string $tokenHash,
        /** @var array<int, string> */
        public readonly array $scopes,
        public readonly ?Carbon $lastUsedAt,
        public readonly ?Carbon $expiresAt,
        public readonly Carbon $createdAt,
        public readonly ?Carbon $updatedAt = null,
    ) {}
}
```

### AuditResult

```php
namespace Cboxdk\StatamicMcp\Storage\Audit;

class AuditResult
{
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $perPage,
    ) {}
}
```

## Implementations

### FileTokenStore (default)

- Stores tokens as individual YAML files in `storage/statamic-mcp/tokens/{id}.yaml`
- Maintains a hash-to-ID index file at `tokens/.index` for fast `findByHash()` lookup
- Index auto-rebuilds if missing or corrupt
- Index updates use advisory `flock()` around the full read-modify-write cycle to prevent corruption from concurrent writes
- When `update()` changes `tokenHash`, the index MUST be updated (remove old hash mapping, add new one)
- `pruneExpired()` scans all files and deletes expired ones

### DatabaseTokenStore

- Wraps existing `McpToken` Eloquent model
- Atomic `last_used_at` update via raw query (preserves current race condition fix)
- Migrations loaded conditionally only when this driver is active

### FileAuditStore (default)

- Writes JSONL to `storage/statamic-mcp/audit.log`
- `query()` reads file in reverse, filters by tool/status, returns paginated `AuditResult`
- `purge()` rewrites file excluding entries older than the given date
- Uses `FILE_APPEND | LOCK_EX` for atomic writes

### DatabaseAuditStore

- New `mcp_audit_logs` table: id, level, message, tool, action, status, correlation_id, duration_ms, context (JSON), timestamp
- `query()` via Eloquent with database-side filtering and pagination
- `purge()` via `DELETE WHERE timestamp < ?`
- New Eloquent model: `McpAuditEntry`
- Migration loaded conditionally only when this driver is active

## Refactoring Existing Code

### TokenService — Return Type Change (Breaking Internal Change)

`TokenService` retains its public method names. Business logic (hashing, lifetime enforcement, scope checking) stays. Storage calls change from direct Eloquent to injected `TokenStore`.

**Critical**: `TokenService` methods currently return `?McpToken` (Eloquent model). After refactoring, they return `?McpTokenData` (DTO). This is a breaking internal change that cascades through:

- **`McpTokenGuard`** — `user()` method accesses `$mcpToken->user_id`. Changes to `$mcpToken->userId`.
- **`AuthenticateForMcp`** — stores `$mcpToken` in request attributes. Type changes from `McpToken` to `McpTokenData`.
- **`RequireMcpPermission`** — type-hints `McpToken|null`, calls `$mcpToken->isExpired()`. Changes to `McpTokenData|null`, expiry check moves to `TokenService::isExpired(McpTokenData)`.
- **`DashboardController`** — `serializeToken()` accepts `McpToken`, calls `$token->statamicUser()`, `$token->last_used_at?->toIso8601String()`. Refactored to accept `McpTokenData`, use `User::find($token->userId)`, `$token->lastUsedAt?->toIso8601String()`.
- **`TokenController`** — similar serialization changes.

`TokenService` gains two helper methods that were previously on the model:
- `isExpired(McpTokenData $token): bool`
- `hasScope(McpTokenData $token, TokenScope $scope): bool`

```php
// Before:
$token = McpToken::where('token', $hash)->first();

// After:
$token = $this->tokenStore->findByHash($hash);
```

### ToolLogger

Becomes a thin wrapper that builds the entry array and delegates to `AuditStore::write()`. PII redaction stays in `ToolLogger` (applied before storage). Static API preserved for backward compatibility.

### AuditController

Injects `AuditStore`, calls `query()`, returns the `AuditResult` as JSON. File-parsing logic removed (moved to `FileAuditStore`).

### AuthServiceProvider

Binds `TokenStore` and `AuditStore` contracts from config class references.

### ServiceProvider

Conditional migration loading. Uses `is_a()` to support subclasses of the database drivers:

```php
$tokensDriver = config('statamic.mcp.stores.tokens');
$auditDriver = config('statamic.mcp.stores.audit');

if (is_string($tokensDriver) && is_a($tokensDriver, DatabaseTokenStore::class, true)) {
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations/tokens');
}
if (is_string($auditDriver) && is_a($auditDriver, DatabaseAuditStore::class, true)) {
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations/audit');
}
```

Migrations are also publishable via `php artisan vendor:publish --tag=statamic-mcp-migrations` for users who want to run them manually before switching drivers.

## Config Changes

```php
// config/statamic/mcp.php

// NEW — storage driver config
'stores' => [
    'tokens' => \Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore::class,
    'audit'  => \Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore::class,
],

'storage' => [
    'tokens_path' => storage_path('statamic-mcp/tokens'),
    'audit_path'  => storage_path('statamic-mcp/audit.log'),
],

// DEPRECATED — kept for backward compatibility, reads fall back to 'storage.audit_path'
// 'security.audit_path'    — deprecated, use 'storage.audit_path'
// 'security.audit_channel' — deprecated, no longer used (will be removed in next major)
```

## CLI Commands

### mcp:migrate-store (new)

```bash
php artisan mcp:migrate-store tokens --from=file --to=database
php artisan mcp:migrate-store audit --from=file --to=database
php artisan mcp:migrate-store tokens --from=database --to=file
```

Behavior:
1. Resolves driver classes from `--from`/`--to` names (`file` or `database`), reads storage paths from `storage` config
2. Reads all records from `--from` driver
3. Writes to `--to` driver with progress bar
4. Asks for confirmation before start
5. Does NOT delete source data — user verifies and cleans up manually

### mcp:prune-audit (new)

```bash
php artisan mcp:prune-audit --days=30
```

Calls `AuditStore::purge()` with a date threshold.

### mcp:prune-tokens (existing, refactored)

Refactored to call `TokenStore::pruneExpired()` via the injected contract instead of direct Eloquent.

## File Structure

```
src/
  Contracts/
    TokenStore.php
    AuditStore.php
  Storage/
    Tokens/
      FileTokenStore.php
      DatabaseTokenStore.php
      McpTokenData.php
    Audit/
      FileAuditStore.php
      DatabaseAuditStore.php
      AuditResult.php
      McpAuditEntry.php           # Eloquent model for database driver
  Console/
    MigrateStoreCommand.php       # mcp:migrate-store
    PruneAuditCommand.php         # mcp:prune-audit
    PruneExpiredTokensCommand.php # existing, refactored

database/
  migrations/
    tokens/
      create_mcp_tokens_table.php
      add_unique_token_index_to_mcp_tokens_table.php
    audit/
      create_mcp_audit_logs_table.php
```

## Testing Strategy

### Contract Tests (shared traits)

Each contract has a test trait that verifies all operations. Both drivers run the same tests:

```php
trait TokenStoreContractTests
{
    abstract protected function createStore(): TokenStore;

    public function test_create_and_find_by_hash(): void { ... }
    public function test_update(): void { ... }
    public function test_delete(): void { ... }
    public function test_list_for_user(): void { ... }
    public function test_prune_expired(): void { ... }
    public function test_mark_as_used(): void { ... }
}

class FileTokenStoreTest extends TestCase
{
    use TokenStoreContractTests;
    protected function createStore(): TokenStore
    {
        return new FileTokenStore($this->tempDir);
    }
}

class DatabaseTokenStoreTest extends TestCase
{
    use TokenStoreContractTests;
    protected function createStore(): TokenStore
    {
        return new DatabaseTokenStore();
    }
}
```

Same pattern for `AuditStoreContractTests`.

### Migration Command Tests

- File→database roundtrip: create records in file store, migrate, verify in database store
- Database→file roundtrip: same in reverse
- Audit migration in both directions

### Existing Test Refactoring

`TokenServiceTest`, `ToolLoggerTest`, `AuditControllerTest`, and router tests are refactored to use the contract interface. They should pass with both drivers.

## Constraints & Limitations

- **File-based tokens on horizontally scaled setups**: Each instance has its own token files. Document clearly that database driver is required for multi-instance deployments.
- **File-based audit on horizontally scaled setups**: Same limitation. Database driver required for centralized audit logs.
- **FileTokenStore index**: The `.index` file is a cache optimization. If corrupted, it rebuilds by scanning all token files. Slight performance cost on first request after corruption.
- **Migration is copy-only**: `mcp:migrate-store` copies data but does not delete source. User must manually clean up after verifying the migration and changing config.

## Upgrade Guide (from pre-driver versions)

### For users currently using database tokens (default before this change)

The default driver changes from database to file. To keep using the database:

```php
// config/statamic/mcp.php
'stores' => [
    'tokens' => \Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore::class,
],
```

Or migrate existing tokens to file:

```bash
php artisan mcp:migrate-store tokens --from=database --to=file
# Verify tokens work, then update config to FileTokenStore (default)
```

### For users with published config

`security.audit_path` and `security.audit_channel` are deprecated but still read as fallbacks. Move your custom audit path to `storage.audit_path`. The `audit_channel` key is no longer used and will be removed in the next major version.

### Internal API changes

`TokenService` methods now return `McpTokenData` instead of `McpToken`. Code that directly type-hints `McpToken` from `TokenService` must update to `McpTokenData`. This only affects code that bypasses `TokenService` and accesses the model directly — most addon users interact only through the MCP tools and CP dashboard, which are updated automatically.
