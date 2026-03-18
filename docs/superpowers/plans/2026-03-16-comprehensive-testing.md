# Comprehensive Testing Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add E2E, integration, and stress test suites plus GitHub Actions CI with PHP 8.3/8.4/8.5 × SQLite/MySQL matrix.

**Architecture:** Three new test directories (E2E, Integration, Stress) with shared test traits. All tests run as Pest tests via Orchestra Testbench. CI extends existing workflow with database matrix.

**Tech Stack:** PHP 8.3+, Pest 4, Orchestra Testbench 11, GitHub Actions

**Spec:** `docs/superpowers/specs/2026-03-16-comprehensive-testing-design.md`

---

## Task 1: Shared test traits

**Files:**
- Create: `tests/Concerns/CreatesTestContent.php`
- Create: `tests/Concerns/CreatesAuthenticatedUser.php`
- Create: `tests/Concerns/CreatesOAuthClient.php`

### CreatesTestContent
Creates a collection ("blog"), blueprint with text+markdown+terms fields, taxonomy ("tags"), global set ("settings"), and sample entries. Methods: `createTestCollection()`, `createTestTaxonomy()`, `createTestGlobalSet()`, `createTestEntry($data)`.

### CreatesAuthenticatedUser
Creates Statamic users. Methods: `createSuperAdmin()` returns User, `createRegularUser()` returns User, `actingAsAdmin()` calls `actingAs()` with super admin, `actingAsUser()` calls `actingAs()` with regular user.

Look at existing test patterns for how users are created — check `tests/Unit/Auth/TokenServiceTest.php` and `tests/Feature/OAuth/OAuthFlowTest.php` for examples.

### CreatesOAuthClient
OAuth helpers. Methods: `registerOAuthClient($name, $redirectUri)` returns OAuthClient, `generatePkce()` returns `['verifier' => ..., 'challenge' => ...]`, `getAuthCode($client, $pkce, $user)` completes authorize flow and returns code string, `exchangeForToken($client, $code, $pkce)` exchanges code and returns access_token string.

- [ ] Create all 3 traits
- [ ] Verify they work by using them in a minimal test
- [ ] PHPStan + Pint
- [ ] Commit: `git commit -m "test: add shared test traits for content, auth, and OAuth setup"`

---

## Task 2: E2E Test Suite

**Files:**
- Create: `tests/E2E/UserDashboardTest.php`
- Create: `tests/E2E/AdminDashboardTest.php`
- Create: `tests/E2E/TokenLifecycleTest.php`
- Create: `tests/E2E/ActivityLogTest.php`
- Create: `tests/E2E/PermissionGatingTest.php`

All extend `Cboxdk\StatamicMcp\Tests\TestCase`, use shared traits.

### UserDashboardTest (~4 tests)
- Authenticated user GET `/cp/mcp` → 200
- Unauthenticated → redirect
- Response props include tokens, availableScopes, clients, webEnabled, mcpEndpoint
- Response props do NOT include allTokens or systemStats

### AdminDashboardTest (~4 tests)
- Super admin GET `/cp/mcp/admin` → 200
- Non-admin → 403
- Unauthenticated → redirect
- Props include allTokens with user_name/user_email, availableUsers, availableTools, systemStats

### TokenLifecycleTest (~5 tests)
- POST create → token in response
- PUT update name/scopes
- POST regenerate → new token, old invalid
- DELETE → removed
- Create without name → validation error

### ActivityLogTest (~4 tests)
- Execute a tool → audit entry appears in GET /audit
- Filter by tool name
- Filter by status
- Entry has correct structure (tool, action, status, duration_ms, timestamp)

### PermissionGatingTest (~4 tests)
Focus ONLY on what's missing from existing permission tests:
- Admin page access control (non-admin → 403)
- Web tool disabled config → permission denied
- Expired token → 401
- Super admin bypasses all checks

IMPORTANT: Do NOT duplicate existing OAuth tests (42 tests in tests/Feature/OAuth/) or permission tests (37 tests in tests/Unit/Auth/RouterPermissionsTest.php).

- [ ] Write all E2E tests
- [ ] Run: `./vendor/bin/pest tests/E2E/`
- [ ] PHPStan + Pint
- [ ] Commit: `git commit -m "test: add E2E test suite for CP dashboard, tokens, activity, permissions"`

---

## Task 3: Integration Test Suite

**Files:**
- Create: `tests/Integration/ToolCallIntegrationTest.php`
- Create: `tests/Integration/BlueprintValidationTest.php`
- Create: `tests/Integration/EntryValidationTest.php`
- Create: `tests/Integration/ScopeEnforcementTest.php`
- Create: `tests/Integration/ErrorResponseQualityTest.php`

### ToolCallIntegrationTest (~16 tests)
For entries and blueprints routers (the most complex): list, get, create, update, delete with real Statamic data. Setup creates collection + blueprint + entries.

Instantiate router directly: `$router = app(EntriesRouter::class)` then `$router->execute([...])`.

### BlueprintValidationTest (~6 tests)
- Flat fields → instructive error
- Unknown fieldtype → available types listed
- Valid fields → success
- Duplicate handles → error
- Near-miss param suggestion
- Template injection stripped

### EntryValidationTest (~4 tests)
- Valid data → success
- Missing required field → validation error
- Partial update → only changed fields
- Data processed through fieldtype pipeline

### ScopeEnforcementTest (~4 tests)
Focus on cross-router scope boundaries NOT in existing tests:
- Set mcp_token on request attributes with limited scopes
- Content read token can list but not create
- Wildcard bypasses all
- Blueprint read cannot write

### ErrorResponseQualityTest (~5 tests)
- Every error has `success: false` or `error` key
- Missing param → error mentions param name
- Invalid action → lists valid actions
- Invalid resource_type → lists valid types
- Permission denied → explains needed permission

- [ ] Write all integration tests
- [ ] Run: `./vendor/bin/pest tests/Integration/`
- [ ] PHPStan + Pint
- [ ] Commit: `git commit -m "test: add integration test suite for tool calls, validation, scopes, error quality"`

---

## Task 4: Stress Test Suite

**Files:**
- Create: `tests/Stress/FileTokenStoreStressTest.php`
- Create: `tests/Stress/FileAuditStoreStressTest.php`
- Create: `tests/Stress/MigrationRoundtripTest.php`
- Create: `tests/Stress/LargeDatasetTest.php`

### FileTokenStoreStressTest (~5 tests)
- 500 rapid creates → count exactly 500, no index duplicates
- 500 tokens → listAll returns all
- 500 tokens → findByHash works for each
- Delete index → findByHash rebuilds
- 500 tokens, prune 250 expired → 250 remain

### FileAuditStoreStressTest (~4 tests)
- 1000 writes → query returns all
- Pagination at page 50 → correct slice
- Purge older entries → correct count remains
- Filter by tool → correct subset

### MigrationRoundtripTest (~2 tests)
- 100 tokens: file→database→file, verify identical data
- 100 audit entries: file→database→file, verify identical data
Needs database migrations loaded.

### LargeDatasetTest (~3 tests)
Use relative scaling:
```php
$time50 = microtime(true); /* create 50 */ $time50 = microtime(true) - $time50;
$time500 = microtime(true); /* create 500 */ $time500 = microtime(true) - $time500;
expect($time500)->toBeLessThan($time50 * 15); // Linear, not quadratic
```

- [ ] Write all stress tests
- [ ] Run: `./vendor/bin/pest tests/Stress/`
- [ ] PHPStan + Pint
- [ ] Commit: `git commit -m "test: add stress test suite for storage drivers and large datasets"`

---

## Task 5: GitHub Actions CI Workflow

**Files:**
- Modify: `.github/workflows/tests.yml`

Replace the existing workflow with the updated version from the spec. Key changes:
- PHP matrix: 8.3, 8.4, 8.5
- Split into `test-sqlite` and `test-mysql` jobs
- MySQL service with health check
- Preserve: Composer caching, Pint auto-push, PHPStan GitHub format, `--parallel`
- Remove: Statamic v5.65 matrix entry (v6 only now)
- Update `actions/cache` from v3 to v4

- [ ] Update `.github/workflows/tests.yml`
- [ ] Commit: `git commit -m "ci: PHP 8.3/8.4/8.5 × SQLite/MySQL matrix with split jobs"`

---

## Task 6: Full validation

- [ ] Run full test suite: `./vendor/bin/pest`
- [ ] Run PHPStan: `./vendor/bin/phpstan analyse`
- [ ] Run Pint: `./vendor/bin/pint --test`
- [ ] Commit any fixes
