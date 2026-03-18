# Comprehensive Test Suite — Design Spec

## Problem

The addon has 649 unit/integration tests covering happy paths, but lacks:
- E2E tests for the CP dashboard (user/admin separation, token lifecycle, OAuth flow)
- MCP tool integration tests with real data (blueprint validation, scope enforcement, error quality)
- Storage driver stress tests (rapid writes, large datasets, migration roundtrips)
- GitHub Actions CI with PHP 8.3/8.4/8.5 × SQLite/MySQL matrix

## Solution

Three new test suites + CI workflow. All tests run as Pest tests via Orchestra Testbench — no Playwright, no subprocess forking, no external servers.

## Test Suite A: CP Feature Tests (E2E via HTTP)

Tests the full HTTP stack including middleware, controllers, Inertia props, and authorization.

### `tests/E2E/UserDashboardTest.php`
- GET `/cp/mcp` as authenticated user → 200, Inertia page is `McpPage`
- Props include `tokens` (array), `availableScopes`, `clients`, `webEnabled`, `mcpEndpoint`
- Props do NOT include `allTokens`, `systemStats` (admin-only data)
- GET `/cp/mcp` unauthenticated → redirect to login

### `tests/E2E/AdminDashboardTest.php`
- GET `/cp/mcp/admin` as super admin → 200, Inertia page is `McpAdminPage`
- Props include `allTokens` with user info (`user_name`, `user_email`), `availableUsers`, `availableTools`, `systemStats`
- GET `/cp/mcp/admin` as non-admin user → 403
- GET `/cp/mcp/admin` unauthenticated → redirect to login

### `tests/E2E/TokenLifecycleTest.php`
- POST `/cp/mcp/tokens` creates token → response has `token` (plain text) + `model` (id, name, scopes)
- PUT `/cp/mcp/tokens/{id}` updates name and scopes
- POST `/cp/mcp/tokens/{id}/regenerate` returns new plain text token, old token invalid
- DELETE `/cp/mcp/tokens/{id}` removes token
- Token creation with expired date → token exists but `is_expired` true
- Token creation with wildcard scope → scopes contains `*`
- Token creation without required name → validation error

### `tests/E2E/ActivityLogTest.php`
- Tool calls via MCP generate audit entries
- GET `/cp/mcp/audit` returns paginated entries with correct structure
- GET `/cp/mcp/audit?tool=entries` filters by tool name (substring)
- GET `/cp/mcp/audit?status=success` filters by exact status
- Audit entries include: tool, action, status, user, duration_ms, timestamp
- Mutation entries include: mutation.type, mutation.operation, mutation.resource_id

### `tests/E2E/OAuthConsentFlowTest.php`
- GET `/.well-known/oauth-protected-resource` → 200, JSON with correct resource URL and scopes
- GET `/.well-known/oauth-authorization-server` → 200, JSON with all endpoint URLs
- POST `/mcp/oauth/register` → 201, returns client_id
- POST `/mcp/oauth/register` with invalid redirect_uri → 400
- POST `/mcp/oauth/register` rate limiting works (11th request → 429)
- GET `/mcp/oauth/authorize` unauthenticated → redirect to CP login
- GET `/mcp/oauth/authorize` authenticated → 200, consent page with client name and scopes
- GET `/mcp/oauth/authorize` with invalid client_id → error
- GET `/mcp/oauth/authorize` without code_challenge → error
- POST `/mcp/oauth/authorize` approve → redirect with code + state
- POST `/mcp/oauth/authorize` deny → redirect with error=access_denied
- POST `/mcp/oauth/token` valid exchange → access_token, token_type, expires_in, scope
- POST `/mcp/oauth/token` expired code → invalid_grant
- POST `/mcp/oauth/token` wrong PKCE → invalid_request
- POST `/mcp/oauth/token` reused code → invalid_grant
- Full flow: register → authorize → approve → exchange → use token as Bearer → 200

### `tests/E2E/PermissionGatingTest.php`
- Non-admin user cannot access `/cp/mcp/admin`
- Non-admin user CAN access `/cp/mcp`
- Token with `content:read` scope → entries list works, entries create denied
- Token with `*` scope → everything works
- Super admin bypasses all permission checks
- Token with `blueprints:read` cannot create blueprints
- Web tool disabled in config → tool returns permission denied
- Expired token → 401

## Test Suite B: MCP Tool Integration Tests

Tests tool execution directly via PHP `$tool->execute($arguments)` with real Statamic data.

### `tests/Integration/ToolCallIntegrationTest.php`
For each router (entries, terms, globals, structures, assets, users, blueprints, system):
- `list` action returns expected structure
- `get` action with valid ID returns data
- `get` action with invalid ID returns error
- `create` action with valid data succeeds
- `update` action with valid data succeeds
- `delete` action removes the resource

Setup creates a collection, blueprint, taxonomy, global set, and entries for testing.

### `tests/Integration/BlueprintValidationTest.php`
- Create with flat fields (missing `field` key) → instructive error showing correct format
- Create with unknown fieldtype → error listing available types
- Create with valid `{handle, field: {type}}` structure → success
- Create with duplicate handles → error
- Create with near-miss param (`taxonomy` instead of `taxonomies`) → suggestion in error
- Create with template injection in field values → stripped
- Update preserves existing fields when adding new ones

### `tests/Integration/EntryValidationTest.php`
- Create entry with data matching blueprint → success
- Create entry with missing required field → validation error listing the field
- Create entry with wrong field type (string in number field) → validation error
- Update entry with partial data → only changed fields updated
- Entry data processed through fieldtype pipeline (verified by reading back)

### `tests/Integration/ScopeEnforcementTest.php`
- Set up web context with mcp_token on request attributes
- Token with `content:read` → entries list succeeds
- Token with `content:read` → entries create returns permission denied
- Token with `content:write` → entries create succeeds
- Token with `*` → all operations succeed
- Token with `blueprints:read` → blueprint list succeeds, create denied
- Expired token → operations denied

### `tests/Integration/ErrorResponseQualityTest.php`
- Every error response is a valid MCP error (has `success: false` or `error` key)
- Missing required param → error mentions the param name and expected format
- Invalid action → error lists valid actions
- Invalid resource_type → error lists valid types
- Permission denied → error explains which permission is needed

## Test Suite C: Storage Driver Stress Tests

### `tests/Stress/FileTokenStoreStressTest.php`
- Create 500 tokens rapidly → count is exactly 500, no duplicates in index
- Create 500 tokens → listAll returns exactly 500
- Create 500 tokens → findByHash works for each (index is consistent)
- Create 500 tokens, delete index → findByHash rebuilds and still works
- Create 500 tokens, prune 250 expired → exactly 250 remain
- markAsUsed on 100 tokens rapidly → all have lastUsedAt set

### `tests/Stress/FileAuditStoreStressTest.php`
- Write 1000 entries rapidly → query returns all 1000
- Write 1000 entries → query with pagination (page=50, perPage=20) returns correct slice
- Write 1000 entries → purge entries older than 500th → exactly 500 remain
- Write 1000 entries → query with tool filter returns correct subset
- Write 1000 entries → query with status filter returns correct subset

### `tests/Stress/MigrationRoundtripTest.php`
- Create 100 tokens in FileTokenStore
- Migrate to DatabaseTokenStore via `mcp:migrate-store tokens --from=file --to=database`
- Verify all 100 tokens exist in database with identical data (id, userId, name, hash, scopes, timestamps)
- Migrate back to FileTokenStore
- Verify all 100 tokens exist in files with identical data
- Same roundtrip for audit entries (100 entries file→database→file)

### `tests/Stress/LargeDatasetTest.php`
- Create 500 tokens → listAll completes in under 5 seconds
- Create 500 tokens → search by user completes in under 2 seconds
- Write 500 audit entries → query with filters completes in under 2 seconds
- Create 500 tokens → pruneExpired (250 expired) completes in under 3 seconds

## GitHub Actions Workflow

Extends the existing `.github/workflows/tests.yml`. Preserves: Composer caching, Pint auto-fix push, PHPStan with GitHub error format, `--parallel` on Pest. Adds: PHP 8.4/8.5 matrix, MySQL test job.

```yaml
name: Tests

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]

jobs:
  code-quality:
    runs-on: ubuntu-latest
    name: Code Quality
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite
      - uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-php-${{ hashFiles('**/composer.lock') }}
          restore-keys: ${{ runner.os }}-php-
      - run: composer install --prefer-dist --no-progress
      - run: ./vendor/bin/pint
      - name: Commit formatting changes
        run: |
          git config --local user.email "action@github.com"
          git config --local user.name "GitHub Action"
          git add -A
          git diff --staged --quiet || git commit -m "Auto-fix code formatting [skip ci]"
      - name: Push formatting changes
        if: github.event_name == 'push'
        uses: ad-m/github-push-action@master
        with:
          github_token: ${{ secrets.GITHUB_TOKEN }}
          branch: ${{ github.ref }}
      - run: ./vendor/bin/phpstan analyse --error-format=github

  test-sqlite:
    runs-on: ubuntu-latest
    needs: code-quality
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4', '8.5']
    name: PHP ${{ matrix.php }} - SQLite
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite
      - uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-php-${{ matrix.php }}-${{ hashFiles('**/composer.lock') }}
          restore-keys: ${{ runner.os }}-php-${{ matrix.php }}-
      - run: composer install --prefer-dist --no-progress
      - run: echo "APP_KEY=base64:SGk1bGF2ZWw=" > .env
      - run: ./vendor/bin/pest --parallel
        env:
          DB_CONNECTION: sqlite

  test-mysql:
    runs-on: ubuntu-latest
    needs: code-quality
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4', '8.5']
    name: PHP ${{ matrix.php }} - MySQL
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: password
          MYSQL_DATABASE: testing
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_mysql, pdo_sqlite
      - uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-php-${{ matrix.php }}-${{ hashFiles('**/composer.lock') }}
          restore-keys: ${{ runner.os }}-php-${{ matrix.php }}-
      - run: composer install --prefer-dist --no-progress
      - run: echo "APP_KEY=base64:SGk1bGF2ZWw=" > .env
      - run: ./vendor/bin/pest --parallel
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_PORT: 3306
          DB_DATABASE: testing
          DB_USERNAME: root
          DB_PASSWORD: password
```

- Split into `test-sqlite` and `test-mysql` jobs (MySQL service only runs in mysql job)
- Preserves Composer caching, Pint auto-push, PHPStan GitHub error format
- 7 total jobs: 1 code-quality + 3 SQLite + 3 MySQL
- PHP 8.3/8.4/8.5 all hard requirements (no allow_failures)

## phpunit.xml Test Suites

Keep the existing default suite that scans `./tests` (catches root-level test files like `OutputSchemaTest.php`, `ShouldRegisterTest.php`). New directories are automatically included.

No phpunit.xml changes needed — the default `<directory>./tests</directory>` already picks up all subdirectories.

## Test Overlap Notes

Existing tests that already cover some scenarios — do NOT duplicate these:
- `tests/Feature/OAuth/` (42 tests) — already covers discovery, registration, authorization, token exchange, full flow
- `tests/Unit/Auth/RouterPermissionsTest.php` (37 tests) — already covers scope enforcement per router
- `tests/Feature/ContentRouterPermissionsTest.php` (5 tests) — already covers content permissions

New E2E tests should focus on what's MISSING:
- `OAuthConsentFlowTest.php` — only add: rate limiting (429), mobile/edge cases, token expiry during session
- `PermissionGatingTest.php` — only add: admin page access control, web tool disabled config, expired token behavior
- `ScopeEnforcementTest.php` — only add: cross-router scope boundaries not covered by existing RouterPermissionsTest

## Stress Test Timing

Performance assertions use relative scaling rather than absolute times (CI runners vary):
```php
// Instead of: expect($time)->toBeLessThan(5.0);
// Use relative: 500 items should be < 10x the time of 50 items
$time50 = $this->benchmark(fn() => $store->listAll(), 50);
$time500 = $this->benchmark(fn() => $store->listAll(), 500);
expect($time500)->toBeLessThan($time50 * 15); // Linear, not quadratic
```

## Test Data Setup

Shared test traits for common setup:

### `tests/Concerns/CreatesTestContent.php`
Creates a collection, blueprint with various field types (text, markdown, bard, terms, assets), taxonomy, global set, and sample entries. Used by Integration and E2E tests.

### `tests/Concerns/CreatesAuthenticatedUser.php`
Creates a Statamic super admin user and non-admin user. Provides `actingAsAdmin()` and `actingAsUser()` helpers.

### `tests/Concerns/CreatesOAuthClient.php`
Registers an OAuth client and generates PKCE pairs. Provides `registerClient()`, `generatePkce()`, `getAuthCode()`, `exchangeForToken()` helpers.

## File Structure

```
tests/
  Concerns/
    CreatesTestContent.php
    CreatesAuthenticatedUser.php
    CreatesOAuthClient.php
  E2E/
    UserDashboardTest.php
    AdminDashboardTest.php
    TokenLifecycleTest.php
    ActivityLogTest.php
    OAuthConsentFlowTest.php
    PermissionGatingTest.php
  Integration/
    ToolCallIntegrationTest.php
    BlueprintValidationTest.php
    EntryValidationTest.php
    ScopeEnforcementTest.php
    ErrorResponseQualityTest.php
  Stress/
    FileTokenStoreStressTest.php
    FileAuditStoreStressTest.php
    MigrationRoundtripTest.php
    LargeDatasetTest.php
.github/
  workflows/
    tests.yml
```
