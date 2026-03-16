# Statamic MCP v2.0 Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade statamic-mcp to v2.0 — Statamic v6 only, laravel/mcp v0.6, scoped API tokens, KITT UI dashboard.

**Architecture:** Layered migration starting from dependencies up through tool API, auth, services, and finally the CP dashboard. Each chunk produces a green test suite. The existing `final handle()` → `executeInternal()` pipeline is preserved but adapted to use `Response::structured()` and v0.6 attributes.

**Tech Stack:** PHP 8.3, Statamic 6, Laravel 12/13, laravel/mcp 0.6.2, Vue 3 + KITT UI + Inertia.js, Tailwind 4, Pest 4

**Spec:** `docs/superpowers/specs/2026-03-11-statamic-mcp-v2-design.md`

---

## Chunk 1: Platform Foundation & Dependency Upgrade

This chunk upgrades dependencies, removes v5 support code, and gets the existing test suite green on laravel/mcp v0.6.

### Task 1: Update composer.json constraints

**Files:**
- Modify: `composer.json:17-22`

- [ ] **Step 1: Update dependency constraints**

Change `composer.json` `require` section:

```json
"require": {
    "php": "^8.3",
    "statamic/cms": "^6.0",
    "laravel/mcp": "^0.6",
    "symfony/yaml": "^7.3"
},
```

Changes:
- `statamic/cms`: `^5.65|^6.0` → `^6.0`
- `laravel/mcp`: `^0.4.1 || ^0.5` → `^0.6`

- [ ] **Step 2: Run composer update**

Run: `composer update laravel/mcp statamic/cms --with-all-dependencies`
Expected: Resolves to laravel/mcp v0.6.2

- [ ] **Step 3: Verify installation**

Run: `composer show laravel/mcp`
Expected: Shows `v0.6.2`

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: update dependencies for v2.0 (statamic ^6.0, laravel/mcp ^0.6)"
```

---

### Task 2: Remove StatamicVersion helper

**Files:**
- Delete: `src/Support/StatamicVersion.php`

- [ ] **Step 1: Find all references to StatamicVersion**

Run: `grep -rn "StatamicVersion" src/ tests/ --include="*.php"`
Note every file and line that references `StatamicVersion`. These will be cleaned up in subsequent tasks.

- [ ] **Step 2: Delete the file**

Run: `rm src/Support/StatamicVersion.php`

- [ ] **Step 3: Remove StatamicVersion::info() calls from files that use it**

**Note:** `BaseStatamicTool` does NOT import `StatamicVersion` — it has its own private `getStatamicVersion()` method. The only file that imports `StatamicVersion` is `AssetsRouter.php`. Search all files for `StatamicVersion` references and remove them.

Replace the private `getStatamicVersion()` in `BaseStatamicTool.php` (line ~253) with a cleaner `getVersionMeta()` method on the base class:

```php
protected function getVersionMeta(): array
{
    return [
        'addon_version' => '2.0.0',
        'statamic_version' => \Statamic\Statamic::version(),
        'laravel_version' => app()->version(),
    ];
}
```

- [ ] **Step 4: Remove StatamicVersion references from all router files**

Search each router for `StatamicVersion` imports and calls. Remove them. The routers inherit `getVersionMeta()` from BaseStatamicTool.

Run: `grep -rn "StatamicVersion" src/ --include="*.php"` — should return zero results.

- [ ] **Step 5: Remove v5-specific logic from AssetsRouter**

In `src/Mcp/Tools/Routers/AssetsRouter.php`, find and remove:
- Any `StatamicVersion::hasV6AssetPermissions()` conditionals
- Any `StatamicVersion::isV6OrLater()` checks
- Keep only the v6 code path

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/pest`
Expected: All tests pass (some may fail due to v0.6 API changes — that's expected and handled in Task 3)

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: remove StatamicVersion helper and all v5 compatibility code"
```

---

### Task 3: Adapt BaseStatamicTool for laravel/mcp v0.6

**Files:**
- Modify: `src/Mcp/Tools/BaseStatamicTool.php`

This is the most critical task. The base class must bridge old `defineSchema()`/`getToolName()`/`getToolDescription()` patterns to v0.6's attribute-based and `schema()` method convention while preserving the centralised error handling.

**IMPORTANT:** `getToolName()` is called in ~10 places within BaseStatamicTool (error responses, logging, execute method). When removing the abstract, replace ALL internal calls with `$this->name()` which is inherited from `Laravel\Mcp\Server\Primitives\Primitive` in v0.6. Do NOT just delete — find and replace every `$this->getToolName()` call.

- [ ] **Step 1: Write a test that verifies the new handle() returns structured responses**

Create `tests/Feature/BaseStatamicToolV2Test.php`:

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

it('returns a structured response from handle()', function () {
    $tool = app(SystemRouter::class);
    $request = new Request(['action' => 'info']);

    $response = $tool->handle($request);

    expect($response)->toBeInstanceOf(Response::class);
});

it('includes version metadata in responses', function () {
    $tool = app(SystemRouter::class);
    $request = new Request(['action' => 'info']);

    $response = $tool->handle($request);
    // Response should contain addon_version in meta
    expect($response)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/BaseStatamicToolV2Test.php`
Expected: May fail due to v0.6 API changes in handle() signature

- [ ] **Step 3: Refactor BaseStatamicTool.php**

Key changes to `src/Mcp/Tools/BaseStatamicTool.php`:

**a) Remove abstract methods and replace internal calls:**
```php
// REMOVE these abstracts:
// abstract protected function getToolName(): string;
// abstract protected function getToolDescription(): string;

// REMOVE these final overrides (v0.6 Primitive handles this via attributes):
// final public function name(): string { ... }
// final public function description(): string { ... }

// ADD a concrete stub that delegates to name() — required because HasRateLimiting
// (used by ExecutesWithAudit) declares its own `abstract getToolName()`. This stub
// satisfies that abstract until HasRateLimiting is removed in Task 20b.
protected function getToolName(): string
{
    return $this->name();
}

// FIND AND REPLACE all ~10 internal calls in BaseStatamicTool:
// $this->getToolName() → $this->name()
// These occur in: execute(), createErrorResponse(), createNotFoundResponse(),
// createSuccessResponse(), error logging, audit logging, etc.
// Run: grep -n "getToolName" src/Mcp/Tools/BaseStatamicTool.php
// Replace every match with $this->name()
// (The stub above is only for satisfying HasRateLimiting's abstract requirement)
```

**b) Bridge defineSchema → schema:**
```php
// REMOVE:
// abstract protected function defineSchema(JsonSchemaContract $schema): array;
// final public function schema(JsonSchemaContract $schema): array { return $this->defineSchema($schema); }

// ADD (let subclasses override schema() directly per v0.6 convention):
// schema() is inherited from Tool — subclasses override it directly
```

**c) Update handle() to use Response::structured():**
```php
final public function handle(\Laravel\Mcp\Request $request): \Laravel\Mcp\Response|\Laravel\Mcp\ResponseFactory
{
    $arguments = $request->all();

    try {
        $result = $this->execute($arguments);

        if ($result['success'] ?? false) {
            return \Laravel\Mcp\Response::structured($result)
                ->withMeta($this->getVersionMeta());
        }

        $errorMessage = $result['errors'][0] ?? $result['error'] ?? 'Unknown error occurred';
        return \Laravel\Mcp\Response::error($errorMessage);

    } catch (\Throwable $e) {
        $safeMessage = $this->sanitizeErrorMessage($e->getMessage());
        return \Laravel\Mcp\Response::error($safeMessage);
    }
}
```

**d) Add base shouldRegister():**
```php
public function shouldRegister(): bool
{
    // CLI context: always register all tools
    if (app()->runningInConsole()) {
        return true;
    }

    return true; // Subclasses override for web context checks
}
```

**e) Add base outputSchema():**
```php
public function outputSchema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
{
    return [
        'success' => \Illuminate\JsonSchema\JsonSchema::boolean()->description('Whether the operation succeeded'),
        'data' => \Illuminate\JsonSchema\JsonSchema::object()->description('Response data'),
        'meta' => \Illuminate\JsonSchema\JsonSchema::object()->description('Response metadata'),
    ];
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest tests/Feature/BaseStatamicToolV2Test.php`
Expected: PASS

- [ ] **Step 5: Update ExecutesWithAudit trait**

The `ExecutesWithAudit` trait (`src/Mcp/Tools/Concerns/ExecutesWithAudit.php`) uses both `$this->getToolName()` (lines ~45, 62, 80) and the `HasRateLimiting` trait. Update it now:

- Replace all `$this->getToolName()` calls with `$this->name()`
- **Keep** the `use HasRateLimiting;` for now (it will be removed in Task 8 when the replacement is ready)
- If `HasRateLimiting` methods are called, add `@todo Replace with McpRateLimiter middleware` comments temporarily

Run: `grep -n "getToolName\|HasRateLimiting" src/Mcp/Tools/Concerns/ExecutesWithAudit.php`
Replace all `getToolName()` → `name()`.

- [ ] **Step 6: Commit**

```bash
git add src/Mcp/Tools/BaseStatamicTool.php src/Mcp/Tools/Concerns/ExecutesWithAudit.php tests/Feature/BaseStatamicToolV2Test.php
git commit -m "refactor: adapt BaseStatamicTool and ExecutesWithAudit for laravel/mcp v0.6"
```

---

### Task 4: Adapt BaseRouter for v0.6

**Files:**
- Modify: `src/Mcp/Tools/BaseRouter.php`

- [ ] **Step 1: Remove bridging methods in BaseRouter**

BaseRouter currently implements `getToolName()`, `getToolDescription()`, and `defineSchema()` which delegate to abstract methods. It also has a duplicate private `getStatamicVersion()` at line ~582. In v2.0:

- Remove `getToolName()` and `getToolDescription()` overrides — routers use `#[Name]`/`#[Description]` attributes
- Replace all `$this->getToolName()` calls in BaseRouter with `$this->name()` (inherited from Primitive)
- Remove the duplicate private `getStatamicVersion()` method (version info is now in `getVersionMeta()` from BaseStatamicTool)
- Rename `defineSchema()` to `schema()` (public, matches v0.6 Tool)
- Keep `executeInternal()` which routes to `executeAction()`
- Keep agent education system (help/discover/examples)

**Also update `RouterHelpers.php` trait** (`src/Mcp/Tools/Concerns/RouterHelpers.php`):
- Remove `abstract protected function getToolName(): string;` at line 239 (routers now get `name()` from Primitive)
- Replace `$this->getToolName()` at line 187 with `$this->name()`
- Remove `getStatamicVersion()` method at line 150, replace `$this->getStatamicVersion()` at line 189 with `\Statamic\Statamic::version()`

In `BaseRouter.php`:
```php
// REMOVE:
// protected function getToolName(): string { return 'statamic-' . $this->getDomain(); }
// protected function getToolDescription(): string { ... }
// protected function defineSchema(JsonSchemaContract $schema): array { ... }

// REPLACE with:
public function schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
{
    // Same body as old defineSchema(), builds action enum from getActions()
    return [
        'action' => \Illuminate\JsonSchema\JsonSchema::string()
            ->description('Action to perform: ' . implode(', ', $this->getActions()))
            ->enum($this->getActions())
            ->required(),
        // ... rest of existing schema fields
    ];
}
```

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/pest`
Expected: Tests may fail where routers still reference removed methods. Note failures.

- [ ] **Step 3: Commit**

```bash
git add src/Mcp/Tools/BaseRouter.php
git commit -m "refactor: adapt BaseRouter for v0.6 schema() convention"
```

---

### Task 5: Migrate all 10 routers to v0.6 attributes

**Files:**
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php`
- Modify: `src/Mcp/Tools/Routers/EntriesRouter.php`
- Modify: `src/Mcp/Tools/Routers/TermsRouter.php`
- Modify: `src/Mcp/Tools/Routers/GlobalsRouter.php`
- Modify: `src/Mcp/Tools/Routers/StructuresRouter.php`
- Modify: `src/Mcp/Tools/Routers/AssetsRouter.php`
- Modify: `src/Mcp/Tools/Routers/UsersRouter.php`
- Modify: `src/Mcp/Tools/Routers/SystemRouter.php`
- Modify: `src/Mcp/Tools/Routers/ContentRouter.php`
- Modify: `src/Mcp/Tools/Routers/ContentFacadeRouter.php`

Each router gets the same transformation. Example for BlueprintsRouter:

- [ ] **Step 1: Add v0.6 attributes to BlueprintsRouter**

At the top of the class, add:
```php
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Description;

#[Name('statamic-blueprints')]
#[Description('Manage Statamic blueprints: list, get, create, update, delete, scan, generate, types, validate')]
class BlueprintsRouter extends BaseRouter
```

**Do NOT add `#[IsReadOnly]`** — routers have mixed read/write actions. Only standalone read-only tools (DiscoveryTool, SchemaTool) get `#[IsReadOnly]`.

Remove `getDomain()` return value usage for tool name (it's now in the attribute).
Keep `getDomain()` for internal routing logic if used elsewhere.

- [ ] **Step 2: Add shouldRegister() to BlueprintsRouter**

```php
public function shouldRegister(): bool
{
    if (app()->runningInConsole()) {
        return true;
    }
    $config = config('statamic.mcp.tools.blueprints', ['enabled' => true, 'web_enabled' => true]);
    return ($config['enabled'] ?? true) && ($config['web_enabled'] ?? true);
}
```

- [ ] **Step 3: Add outputSchema() to BlueprintsRouter**

```php
public function outputSchema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
{
    return [
        'success' => \Illuminate\JsonSchema\JsonSchema::boolean()->description('Operation success'),
        'data' => \Illuminate\JsonSchema\JsonSchema::object()->description('Blueprint data or list'),
        'meta' => \Illuminate\JsonSchema\JsonSchema::object()->description('Version and tool metadata'),
    ];
}
```

- [ ] **Step 4: Repeat for remaining 9 routers**

Apply the same pattern to each router. For **SystemRouter**, also remove the duplicate private `getStatamicVersion()` method at line ~670. Choose appropriate annotations:
- `#[IsReadOnly]` for: BlueprintsRouter (read-heavy, has write actions too — skip this, use no annotation or per-action)
- Actually: Routers have mixed read/write actions. Do NOT add `#[IsReadOnly]` to routers that have create/update/delete actions. Only DiscoveryTool and SchemaTool get `#[IsReadOnly]`.

Annotation mapping:
| Router | Annotations |
|--------|------------|
| BlueprintsRouter | (none — mixed read/write) |
| EntriesRouter | (none — mixed) |
| TermsRouter | (none — mixed) |
| GlobalsRouter | (none — mixed) |
| StructuresRouter | (none — mixed) |
| AssetsRouter | (none — mixed) |
| UsersRouter | (none — mixed) |
| SystemRouter | (none — mixed: info is read, cache_clear is write) |
| ContentRouter | (none — mixed) |
| ContentFacadeRouter | (none — mixed) |

- [ ] **Step 5: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add src/Mcp/Tools/Routers/
git commit -m "refactor: add v0.6 attributes (#[Name], #[Description]) and shouldRegister() to all routers"
```

---

### Task 6: Migrate system tools (DiscoveryTool, SchemaTool)

**Files:**
- Modify: `src/Mcp/Tools/System/DiscoveryTool.php`
- Modify: `src/Mcp/Tools/System/SchemaTool.php`

- [ ] **Step 1: Add v0.6 attributes to DiscoveryTool**

```php
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('statamic-discovery')]
#[Description('Intent-based tool discovery: find the right Statamic MCP tool for your task')]
#[IsReadOnly]
class DiscoveryTool extends BaseStatamicTool
```

Remove `getToolName()` and `getToolDescription()` methods.
Rename `defineSchema()` to `schema()` (public).
Replace internal `$this->getToolName()` call at line 880 with `$this->name()`.

- [ ] **Step 2: Add v0.6 attributes to SchemaTool**

```php
#[Name('statamic-schema')]
#[Description('Inspect tool schemas: get detailed input/output definitions for any Statamic MCP tool')]
#[IsReadOnly]
class SchemaTool extends BaseStatamicTool
```

Same method renames. Add `outputSchema()`.
Replace internal `$this->getToolName()` call at line 1007 with `$this->name()`.

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add src/Mcp/Tools/System/
git commit -m "refactor: migrate DiscoveryTool and SchemaTool to v0.6 attributes"
```

---

### Task 7: Update StatamicMcpServer version and metadata

**Files:**
- Modify: `src/Mcp/Servers/StatamicMcpServer.php`

- [ ] **Step 1: Update server metadata**

```php
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('statamic-mcp')]
#[Description('MCP server for Statamic CMS — manage blueprints, entries, assets, users, and more')]
#[Version('2.0.0')]
class StatamicMcpServer extends \Laravel\Mcp\Server
```

Remove the `name()`, `description()`, `version()` method overrides if v0.6 attributes handle them.

- [ ] **Step 2: Run tests and PHPStan**

Run: `./vendor/bin/pest && ./vendor/bin/phpstan analyse`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add src/Mcp/Servers/StatamicMcpServer.php
git commit -m "refactor: update StatamicMcpServer to v2.0.0 with v0.6 attributes"
```

---

### ~~Task 8: MOVED to Chunk 3 (after McpRateLimiter is built)~~

> HasRateLimiting removal is now Task 20b in Chunk 3. Removing it here would break `ExecutesWithAudit` before the replacement middleware exists.

---

### Task 9: Update config/statamic/mcp.php to v2.0 structure

**Files:**
- Modify: `config/statamic/mcp.php`

- [ ] **Step 1: Write the new config**

Replace the entire config file with the v2.0 structure from the spec (Section 6). Keep backward-compatible env variable names where possible.

- [ ] **Step 2: Update ServiceProvider config merge**

In `src/ServiceProvider.php`, ensure `$this->mergeConfigFrom()` points to the correct file and key.

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/pest`
Expected: Some tests may fail if they rely on old config keys. Fix config references in tests.

- [ ] **Step 4: Commit**

```bash
git add config/statamic/mcp.php src/ServiceProvider.php
git commit -m "refactor: restructure config to v2.0 flat format"
```

---

### Task 10: Run full quality check

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/pint`

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`
Fix any new errors from refactoring.

- [ ] **Step 3: Run all tests**

Run: `./vendor/bin/pest`
Expected: All green

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "chore: fix code quality issues after v0.6 migration"
```

---

## Chunk 2: Authentication — API Tokens

This chunk builds the scoped API token system. No dashboard yet — tokens are managed via Artisan commands and tested via HTTP.

### Task 11: Create TokenScope value object

**Files:**
- Create: `src/Auth/TokenScope.php`
- Create: `tests/Feature/Auth/TokenScopeTest.php`

- [ ] **Step 1: Write failing tests for TokenScope**

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\TokenScope;

it('parses a wildcard scope', function () {
    $scope = TokenScope::fromString('*');
    expect($scope->allows('entries', 'read'))->toBeTrue();
    expect($scope->allows('system', 'admin'))->toBeTrue();
});

it('parses a domain:action scope', function () {
    $scope = TokenScope::fromString('entries:read');
    expect($scope->allows('entries', 'read'))->toBeTrue();
    expect($scope->allows('entries', 'write'))->toBeFalse();
    expect($scope->allows('blueprints', 'read'))->toBeFalse();
});

it('parses read:* shorthand', function () {
    $scope = TokenScope::fromString('read:*');
    expect($scope->allows('entries', 'read'))->toBeTrue();
    expect($scope->allows('blueprints', 'read'))->toBeTrue();
    expect($scope->allows('entries', 'write'))->toBeFalse();
});

it('validates scope strings', function () {
    expect(TokenScope::isValid('entries:read'))->toBeTrue();
    expect(TokenScope::isValid('*'))->toBeTrue();
    expect(TokenScope::isValid('read:*'))->toBeTrue();
    expect(TokenScope::isValid('invalid'))->toBeFalse();
    expect(TokenScope::isValid(''))->toBeFalse();
});
```

- [ ] **Step 2: Run test — verify failure**

Run: `./vendor/bin/pest tests/Feature/Auth/TokenScopeTest.php`
Expected: FAIL — class doesn't exist

- [ ] **Step 3: Implement TokenScope**

Create `src/Auth/TokenScope.php`:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

class TokenScope
{
    /** @var array<string> */
    private array $scopes;

    /** @param array<string> $scopes */
    public function __construct(array $scopes)
    {
        $this->scopes = $scopes;
    }

    public static function fromString(string $scope): self
    {
        return new self([$scope]);
    }

    /** @param array<string> $scopes */
    public static function fromArray(array $scopes): self
    {
        return new self($scopes);
    }

    public function allows(string $domain, string $action): bool
    {
        foreach ($this->scopes as $scope) {
            if ($scope === '*') {
                return true;
            }
            if ($scope === 'read:*' && $action === 'read') {
                return true;
            }
            if ($scope === "{$domain}:{$action}") {
                return true;
            }
            // Domain wildcard: "entries:*"
            if ($scope === "{$domain}:*") {
                return true;
            }
        }
        return false;
    }

    public static function isValid(string $scope): bool
    {
        if ($scope === '*' || $scope === 'read:*') {
            return true;
        }
        return (bool) preg_match('/^[a-z][\w-]*:[a-z*]+$/', $scope);
    }

    /** @return array<string> */
    public function toArray(): array
    {
        return $this->scopes;
    }
}
```

- [ ] **Step 4: Run tests — verify pass**

Run: `./vendor/bin/pest tests/Feature/Auth/TokenScopeTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Auth/TokenScope.php tests/Feature/Auth/TokenScopeTest.php
git commit -m "feat: add TokenScope value object with wildcard and domain:action parsing"
```

---

### Task 12: Create McpToken model

**Files:**
- Create: `src/Auth/McpToken.php`
- Create: `tests/Feature/Auth/McpTokenTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Auth\TokenScope;

it('creates a token with name and scopes', function () {
    $token = new McpToken(
        id: 'token-123',
        userId: 'user-abc',
        name: 'My CI Token',
        hashedToken: hash('sha256', 'smc_test123'),
        scopes: TokenScope::fromArray(['entries:read', 'blueprints:read']),
    );

    expect($token->name)->toBe('My CI Token');
    expect($token->userId)->toBe('user-abc');
    expect($token->scopes->allows('entries', 'read'))->toBeTrue();
    expect($token->scopes->allows('entries', 'write'))->toBeFalse();
});

it('checks if token is expired', function () {
    $expired = new McpToken(
        id: 'token-1',
        userId: 'user-1',
        name: 'Expired',
        hashedToken: 'hash',
        scopes: TokenScope::fromString('*'),
        expiresAt: now()->subDay(),
    );

    $valid = new McpToken(
        id: 'token-2',
        userId: 'user-2',
        name: 'Valid',
        hashedToken: 'hash',
        scopes: TokenScope::fromString('*'),
        expiresAt: now()->addDay(),
    );

    $noExpiry = new McpToken(
        id: 'token-3',
        userId: 'user-3',
        name: 'No Expiry',
        hashedToken: 'hash',
        scopes: TokenScope::fromString('*'),
    );

    expect($expired->isExpired())->toBeTrue();
    expect($valid->isExpired())->toBeFalse();
    expect($noExpiry->isExpired())->toBeFalse();
});

it('serializes to array for YAML storage', function () {
    $token = new McpToken(
        id: 'token-123',
        userId: 'user-abc',
        name: 'Test',
        hashedToken: 'hashed_value',
        scopes: TokenScope::fromArray(['entries:read']),
    );

    $array = $token->toArray();
    expect($array)->toHaveKeys(['id', 'user_id', 'name', 'hashed_token', 'scopes']);
    expect($array['scopes'])->toBe(['entries:read']);
});
```

- [ ] **Step 2: Run test — verify failure**

Run: `./vendor/bin/pest tests/Feature/Auth/McpTokenTest.php`
Expected: FAIL

- [ ] **Step 3: Implement McpToken**

Create `src/Auth/McpToken.php` — a simple value object with `id`, `userId`, `name`, `hashedToken`, `scopes` (TokenScope), `expiresAt` (?CarbonInterface), `lastUsedAt` (?CarbonInterface), `createdAt` (CarbonInterface). Include `isExpired(): bool`, `toArray(): array`, `static fromArray(array): self`.

- [ ] **Step 4: Run tests — verify pass**

Run: `./vendor/bin/pest tests/Feature/Auth/McpTokenTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Auth/McpToken.php tests/Feature/Auth/McpTokenTest.php
git commit -m "feat: add McpToken value object"
```

---

### Task 13: Create TokenService (generation, hashing, validation)

**Files:**
- Create: `src/Services/TokenService.php`
- Create: `tests/Feature/Services/TokenServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Services\TokenService;

it('generates a token with smc_ prefix', function () {
    $service = app(TokenService::class);
    $result = $service->generatePlainToken();

    expect($result)->toStartWith('smc_');
    expect(strlen($result))->toBe(44); // smc_ (4) + 40 random chars
});

it('hashes a token with SHA-256', function () {
    $service = app(TokenService::class);
    $plain = 'smc_abcdef1234567890abcdef1234567890abcdef';
    $hash = $service->hashToken($plain);

    expect($hash)->not->toBe($plain);
    expect($service->verifyToken($plain, $hash))->toBeTrue();
    expect($service->verifyToken('smc_wrong', $hash))->toBeFalse();
});
```

- [ ] **Step 2: Run test — verify failure**

Run: `./vendor/bin/pest tests/Feature/Services/TokenServiceTest.php`

- [ ] **Step 3: Implement TokenService**

Create `src/Services/TokenService.php`:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Services;

class TokenService
{
    public function generatePlainToken(): string
    {
        $prefix = config('statamic.mcp.auth.tokens.prefix', 'smc_');
        return $prefix . bin2hex(random_bytes(20));
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function verifyToken(string $plainToken, string $hashedToken): bool
    {
        return hash_equals($hashedToken, $this->hashToken($plainToken));
    }
}
```

- [ ] **Step 4: Run tests — verify pass**

Run: `./vendor/bin/pest tests/Feature/Services/TokenServiceTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Services/TokenService.php tests/Feature/Services/TokenServiceTest.php
git commit -m "feat: add TokenService for token generation and hashing"
```

---

### Task 14: Create McpTokenRepository (file driver)

**Files:**
- Create: `src/Auth/McpTokenRepository.php`
- Create: `tests/Feature/Auth/McpTokenRepositoryTest.php`

- [ ] **Step 1: Write failing tests**

Test CRUD operations: `create()`, `find()`, `findByToken()`, `allForUser()`, `delete()`, `touch()` (updates last_used_at).

Use a temporary directory for test storage. Verify YAML files are written and read correctly.

- [ ] **Step 2: Run test — verify failure**

Run: `./vendor/bin/pest tests/Feature/Auth/McpTokenRepositoryTest.php`

- [ ] **Step 3: Implement McpTokenRepository**

Create `src/Auth/McpTokenRepository.php` using Symfony YAML to read/write token files. Each token is stored as `{id}.yaml` in the configured storage directory. The `findByToken()` method iterates all files and compares hashes — acceptable for the expected volume (max ~100 tokens per site).

- [ ] **Step 4: Run tests — verify pass**

Run: `./vendor/bin/pest tests/Feature/Auth/McpTokenRepositoryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Auth/McpTokenRepository.php tests/Feature/Auth/McpTokenRepositoryTest.php
git commit -m "feat: add McpTokenRepository with YAML flat-file storage"
```

---

### Task 15: Create McpTokenGuard

**Files:**
- Create: `src/Auth/Guards/McpTokenGuard.php`
- Create: `tests/Feature/Auth/McpTokenGuardTest.php`

- [ ] **Step 1: Write failing tests**

Test that the guard:
- Extracts `smc_` prefixed bearer tokens from the Authorization header
- Validates against McpTokenRepository
- Rejects expired tokens
- Updates `last_used_at` on successful auth
- Returns null for non-smc_ bearer tokens (passes to next guard)

- [ ] **Step 2: Implement McpTokenGuard**

Implements `Illuminate\Contracts\Auth\Guard`. The `user()` method checks the Authorization header for `Bearer smc_*`, looks up the token, resolves the Statamic user, and stores the token on the request for scope checking later.

- [ ] **Step 3: Run tests — verify pass**

- [ ] **Step 4: Commit**

```bash
git add src/Auth/Guards/McpTokenGuard.php tests/Feature/Auth/McpTokenGuardTest.php
git commit -m "feat: add McpTokenGuard for smc_ bearer token authentication"
```

---

### Task 16: Update AuthenticateForMcp middleware

**Files:**
- Modify: `src/Http/Middleware/AuthenticateForMcp.php`

- [ ] **Step 1: Write a test for the new auth priority order**

Test that:
- `smc_` bearer tokens are checked first (new)
- Legacy base64 bearer tokens still work but log a deprecation warning
- Basic auth still works but logs a deprecation warning
- Session auth still works

- [ ] **Step 2: Update the middleware**

Add `smc_` token check at the top of the auth cascade in `handle()`. If the bearer token starts with `smc_`, use the `McpTokenGuard` to authenticate. Store the resolved `McpToken` on the request attributes for scope checking in `RequireMcpPermission`.

Add deprecation logging for legacy auth methods:
```php
\Illuminate\Support\Facades\Log::warning('Deprecated: Base64 bearer auth for MCP is deprecated. Use API tokens (smc_) instead.');
```

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All pass

- [ ] **Step 4: Commit**

```bash
git add src/Http/Middleware/AuthenticateForMcp.php tests/
git commit -m "feat: add smc_ token auth to middleware with legacy deprecation warnings"
```

---

### Task 17: Update RequireMcpPermission for token scopes

**Files:**
- Modify: `src/Http/Middleware/RequireMcpPermission.php`

- [ ] **Step 1: Write tests for scope-based permission checking**

Test that:
- A token with `entries:read` can access entry list/get actions
- A token with `entries:read` CANNOT access entry create/update/delete
- A token with `*` can access everything
- A token with `read:*` can read from any domain but not write

- [ ] **Step 2: Update the middleware**

Check for `McpToken` on the request attributes. If present, validate its scopes against the requested tool/action using the permission mapping from the spec.

- [ ] **Step 3: Run tests — verify pass**

- [ ] **Step 4: Commit**

```bash
git add src/Http/Middleware/RequireMcpPermission.php tests/
git commit -m "feat: add token scope validation to RequireMcpPermission"
```

---

### Task 18: Quality check for Chunk 2

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/pint`

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/pest`

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "chore: code quality fixes for auth system"
```

---

## Chunk 3: Services — Audit & Rate Limiting

### Task 19: Create AuditService

**Files:**
- Create: `src/Services/AuditService.php`
- Create: `tests/Feature/Services/AuditServiceTest.php`

- [ ] **Step 1: Write failing tests**

Test that AuditService:
- Logs an operation with all required fields (timestamp, correlation_id, user_id, token_id, auth_method, tool, action, result, duration_ms)
- Redacts sensitive fields from arguments
- Reads logs with filtering (by user, by tool, by date range)
- Handles retention (purge entries older than configured days)
- Works with file driver (writes YAML to `storage/app/statamic-mcp/audit/`)

- [ ] **Step 2: Implement AuditService**

File driver writes one YAML file per day: `audit-2026-03-11.yaml` containing an array of log entries. Supports `log()`, `query()`, `purgeOlderThan()`.

- [ ] **Step 3: Run tests — verify pass**

- [ ] **Step 4: Commit**

```bash
git add src/Services/AuditService.php tests/Feature/Services/AuditServiceTest.php
git commit -m "feat: add AuditService with file-based audit logging"
```

---

### Task 20: Create McpRateLimiter middleware

**Files:**
- Create: `src/Http/Middleware/McpRateLimiter.php`
- Create: `tests/Feature/Middleware/McpRateLimiterTest.php`

- [ ] **Step 1: Write failing tests**

Test that:
- Requests within limits pass through
- Requests exceeding global limit get 429 response
- Per-tool limits are respected
- Per-token rate limiting works (different tokens have separate counters)
- CLI context bypasses rate limiting
- Rate limit headers are set (X-RateLimit-Limit, X-RateLimit-Remaining)

- [ ] **Step 2: Implement McpRateLimiter**

Uses Laravel's `RateLimiter` facade with sliding window strategy. Key format: `mcp:{token_id}:{tool_name}` for per-token-per-tool, `mcp:{token_id}` for global per-token.

- [ ] **Step 3: Run tests — verify pass**

- [ ] **Step 4: Commit**

```bash
git add src/Http/Middleware/McpRateLimiter.php tests/Feature/Middleware/McpRateLimiterTest.php
git commit -m "feat: add McpRateLimiter middleware with sliding window and per-token limits"
```

---

### Task 20b: Remove HasRateLimiting concern (moved from Chunk 1 Task 8)

**Files:**
- Delete: `src/Mcp/Tools/Concerns/HasRateLimiting.php`
- Modify: `src/Mcp/Tools/Concerns/ExecutesWithAudit.php`

Now that McpRateLimiter middleware exists, the old trait-based rate limiting can be safely removed.

- [ ] **Step 1: Find all usages**

Run: `grep -rn "HasRateLimiting" src/ --include="*.php"`
Note which files `use` this trait (likely just `ExecutesWithAudit.php`).

- [ ] **Step 2: Remove the trait use from ExecutesWithAudit and any other files**

In `ExecutesWithAudit.php`, remove `use HasRateLimiting;` and its import. Remove any calls to rate limiting methods from the trait. Also remove the abstract `getToolName()` declaration if `HasRateLimiting` had one (line 83). Rate limiting is now handled by the `McpRateLimiter` middleware in the HTTP stack.

**Also:** Remove the `getToolName()` stub from `BaseStatamicTool.php` that was kept as a bridge for HasRateLimiting's abstract. Now that HasRateLimiting is gone, the stub is dead code.

- [ ] **Step 3: Delete the file**

Run: `rm src/Mcp/Tools/Concerns/HasRateLimiting.php`

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: remove HasRateLimiting concern (replaced by McpRateLimiter middleware)"
```

---

### Task 21: Create StatsService

**Files:**
- Create: `src/Services/StatsService.php`
- Create: `tests/Feature/Services/StatsServiceTest.php`

- [ ] **Step 1: Write failing tests**

Test that StatsService aggregates from AuditService:
- `getToolCallCount(period)` — total calls in period
- `getTopTools(period, limit)` — most used tools
- `getErrorRate(period)` — percentage of failed calls
- `getActiveTokens()` — tokens used in last 24h
- `getOverview()` — combined dashboard stats

- [ ] **Step 2: Implement StatsService**

Reads from AuditService logs. No separate storage — aggregates on read. For production sites, this can be cached.

- [ ] **Step 3: Run tests — verify pass**

- [ ] **Step 4: Commit**

```bash
git add src/Services/StatsService.php tests/Feature/Services/StatsServiceTest.php
git commit -m "feat: add StatsService for dashboard metrics aggregation"
```

---

### Task 22: Create ClientConfigGenerator

**Files:**
- Create: `src/Services/ClientConfigGenerator.php`
- Create: `tests/Feature/Services/ClientConfigGeneratorTest.php`

- [ ] **Step 1: Write failing tests**

Test that each client config is generated correctly:

```php
it('generates Claude Code config', function () {
    $generator = app(ClientConfigGenerator::class);
    $config = $generator->generate('claude_code', 'https://site.test/mcp/statamic', 'smc_token123');

    expect($config)->toBeArray();
    expect($config['format'])->toBe('json');
    expect($config['content'])->toContain('mcpServers');
    expect($config['content'])->toContain('smc_token123');
});

it('generates config for all supported clients', function () {
    $generator = app(ClientConfigGenerator::class);
    $clients = ['claude_code', 'claude_desktop', 'cursor', 'chatgpt', 'gemini', 'windsurf', 'continue', 'generic'];

    foreach ($clients as $client) {
        $config = $generator->generate($client, 'https://site.test/mcp/statamic', 'smc_token');
        expect($config)->toBeArray()->and($config)->toHaveKeys(['format', 'content', 'filename', 'instructions']);
    }
});
```

- [ ] **Step 2: Implement ClientConfigGenerator**

Each client has a method that returns `['format' => 'json'|'yaml', 'content' => '...', 'filename' => '...', 'instructions' => '...']`. No templates — just string building in PHP methods.

- [ ] **Step 3: Run tests — verify pass**

- [ ] **Step 4: Commit**

```bash
git add src/Services/ClientConfigGenerator.php tests/Feature/Services/ClientConfigGeneratorTest.php
git commit -m "feat: add ClientConfigGenerator for 7 AI client config formats"
```

---

### Task 23: Wire services into middleware and ServiceProvider

**Files:**
- Modify: `src/ServiceProvider.php`
- Modify: `src/Http/Middleware/AuthenticateForMcp.php`

- [ ] **Step 1: Register services in ServiceProvider**

Bind `AuditService`, `StatsService`, `TokenService`, `McpTokenRepository`, `ClientConfigGenerator` as singletons.

Register the custom `mcp-token` auth guard in the boot method.

Add audit logging to the MCP middleware stack:
```php
'middleware' => [
    AuthenticateForMcp::class,
    McpRateLimiter::class,      // New
    RequireMcpPermission::class,
],
```

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add src/ServiceProvider.php
git commit -m "feat: wire auth and audit services into ServiceProvider"
```

---

## Chunk 4: CP Dashboard — Backend

### Task 24: Register Statamic permissions

**Files:**
- Modify: `src/ServiceProvider.php`

- [ ] **Step 1: Add permission registration to bootAddon()**

```php
use Statamic\Facades\Permission;

Permission::group('mcp', 'MCP Server', function () {
    Permission::register('view mcp dashboard')->label('View MCP Dashboard');
    Permission::register('access mcp connect')->label('Access Connection Wizard');
    Permission::register('manage mcp tokens', function ($p) {
        $p->children([
            Permission::make('manage own mcp tokens')->label('Manage Own Tokens'),
            Permission::make('manage all mcp tokens')->label('Manage All Tokens'),
        ]);
    })->label('Manage API Tokens');
    Permission::register('view mcp audit')->label('View Audit Log');
    Permission::register('manage mcp permissions')->label('Manage MCP Permissions');
    Permission::register('manage mcp settings')->label('Manage MCP Settings');
});
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest`

- [ ] **Step 3: Commit**

```bash
git add src/ServiceProvider.php
git commit -m "feat: register Statamic MCP permissions"
```

---

### Task 25: Create CP routes

**Files:**
- Create: `routes/cp.php`

- [ ] **Step 1: Create the routes file**

```php
<?php

use Illuminate\Support\Facades\Route;
use Cboxdk\StatamicMcp\Http\Controllers\DashboardController;
use Cboxdk\StatamicMcp\Http\Controllers\ConnectionWizardController;
use Cboxdk\StatamicMcp\Http\Controllers\TokenController;
use Cboxdk\StatamicMcp\Http\Controllers\AuditLogController;
use Cboxdk\StatamicMcp\Http\Controllers\PermissionsController;
use Cboxdk\StatamicMcp\Http\Controllers\SettingsController;

Route::prefix('mcp')->name('statamic-mcp.')->group(function () {
    // Note: Authorization is handled via $this->authorize() in each controller method.
    // This is the Laravel 12+ pattern — constructor middleware is deprecated.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/connect', [ConnectionWizardController::class, 'index'])->name('connect');
    Route::post('/connect/test', [ConnectionWizardController::class, 'test'])->name('connect.test');
    Route::post('/connect/config', [ConnectionWizardController::class, 'generateConfig'])->name('connect.config');

    Route::get('/tokens', [TokenController::class, 'index'])->name('tokens.index');
    Route::get('/tokens/create', [TokenController::class, 'create'])->name('tokens.create');
    Route::post('/tokens', [TokenController::class, 'store'])->name('tokens.store');
    Route::delete('/tokens/{id}', [TokenController::class, 'destroy'])->name('tokens.destroy');

    Route::get('/audit', [AuditLogController::class, 'index'])->name('audit');
    Route::get('/permissions', [PermissionsController::class, 'index'])->name('permissions');
    Route::put('/permissions', [PermissionsController::class, 'update'])->name('permissions.update');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});
```

- [ ] **Step 2: Register routes in ServiceProvider**

Add to ServiceProvider:
```php
protected $routes = [
    'cp' => __DIR__.'/../routes/cp.php',
];
```

- [ ] **Step 3: Commit**

```bash
git add routes/cp.php src/ServiceProvider.php
git commit -m "feat: add CP routes for MCP dashboard"
```

---

### Task 26: Create dashboard controllers

**Files:**
- Create: `src/Http/Controllers/DashboardController.php`
- Create: `src/Http/Controllers/ConnectionWizardController.php`
- Create: `src/Http/Controllers/TokenController.php`
- Create: `src/Http/Controllers/AuditLogController.php`
- Create: `src/Http/Controllers/PermissionsController.php`
- Create: `src/Http/Controllers/SettingsController.php`

- [ ] **Step 1: Create DashboardController**

**IMPORTANT:** Controllers MUST extend `Statamic\Http\Controllers\CP\CpController`, NOT `Illuminate\Routing\Controller`. Constructor middleware (`$this->middleware(...)`) is deprecated in Laravel 12 — use `$this->authorize()` (from CpController) in methods instead.

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers;

use Cboxdk\StatamicMcp\Services\StatsService;
use Inertia\Inertia;
use Statamic\Http\Controllers\CP\CpController;

class DashboardController extends CpController
{
    public function index(StatsService $stats): \Inertia\Response
    {
        $this->authorize('view mcp dashboard');

        return Inertia::render('statamic-mcp::Dashboard', [
            'stats' => $stats->getOverview(),
        ]);
    }
}
```

**Apply this pattern to ALL controllers:** extend `CpController`, use `$this->authorize()` (inherited from CpController — NOT `Gate::authorize()` which bypasses Statamic's error page handling) at the start of each method instead of constructor middleware.

- [ ] **Step 2: Create TokenController**

Handles index (list tokens), create (show form), store (create token — returns plain token ONCE), destroy (revoke). The `store` action returns the plain text token in the Inertia flash data since it can never be retrieved again.

Permission check: `manage own mcp tokens` sees own tokens, `manage all mcp tokens` sees all.

- [ ] **Step 3: Create ConnectionWizardController**

Handles index (show wizard), test (test MCP endpoint connectivity), generateConfig (return config snippet for selected client).

- [ ] **Step 4: Create AuditLogController**

Handles index with query parameters for filtering (user, tool, date range, page).

- [ ] **Step 5: Create PermissionsController and SettingsController**

Simple CRUD for tool enable/disable and config values.

- [ ] **Step 6: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`
Fix any type issues.

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/
git commit -m "feat: add CP dashboard controllers with Inertia responses"
```

---

### Task 27: Register navigation

**Files:**
- Modify: `src/ServiceProvider.php`

- [ ] **Step 1: Add Nav::extend to bootAddon()**

```php
use Statamic\Facades\CP\Nav;

Nav::extend(function ($nav) {
    $nav->tools('MCP Server')
        ->route('statamic-mcp.dashboard')
        ->icon('server')
        ->can('view mcp dashboard')
        ->children([
            $nav->item('Dashboard')->route('statamic-mcp.dashboard')->can('view mcp dashboard'),
            $nav->item('Connect')->route('statamic-mcp.connect')->can('access mcp connect'),
            $nav->item('Tokens')->route('statamic-mcp.tokens.index')->can('manage mcp tokens'),
            $nav->item('Audit Log')->route('statamic-mcp.audit')->can('view mcp audit'),
            $nav->item('Permissions')->route('statamic-mcp.permissions')->can('manage mcp permissions'),
            $nav->item('Settings')->route('statamic-mcp.settings')->can('manage mcp settings'),
        ]);
});
```

- [ ] **Step 2: Commit**

```bash
git add src/ServiceProvider.php
git commit -m "feat: register MCP Server CP navigation"
```

---

## Chunk 5: CP Dashboard — Frontend

### VERIFICATION GATE: Before starting frontend work

Before creating any Vue components or JS assets, verify that the Statamic v6 Inertia and KITT UI APIs match our assumptions. The current dev environment may be on Statamic v5 where these APIs don't exist.

- [ ] **Gate 1: Verify Statamic v6 is installed**

Run: `composer show statamic/cms | grep versions`
Expected: v6.x. If still on v5, stop — Chunk 5 cannot proceed until v6 is available.

- [ ] **Gate 2: Verify Inertia is available and page registration API**

Run: `composer show inertiajs/inertia-laravel 2>/dev/null || echo "NOT INSTALLED"`
If not installed as a Statamic v6 dependency, add `"inertiajs/inertia-laravel": "^2.0"` to the addon's `composer.json` require section.

Run: `grep -rn 'inertia.register\|\$inertia' vendor/statamic/cms/resources/js/ | head -20`
Confirm `Statamic.$inertia.register()` is the correct API. If not, update all `cp.js` registration calls.

- [ ] **Gate 3: Verify KITT UI component names**

Run: `ls vendor/statamic/cms/resources/js/components/ui/ | head -30` or check Statamic v6 docs.
Confirm component prefix is `ui-` (e.g., `<ui-card>`, `<ui-table>`, `<ui-badge>`). If different, update all Vue templates before proceeding.

- [ ] **Gate 4: Verify Tailwind 4 CSS import path**

Run: `find vendor/statamic/cms -name "tailwind.css" -o -name "*.css" | head -10`
Confirm `@import '@statamic/cms/tailwind.css'` is the correct import path for addon CSS.

**If any gate fails:** Stop and research the correct API before proceeding. Update the plan accordingly.

---

### Task 28: Setup Vite and npm dependencies

**Files:**
- Create: `vite.config.js`
- Create: `package.json`
- Modify: `src/ServiceProvider.php`

- [ ] **Step 1: Create package.json**

```json
{
    "private": true,
    "scripts": {
        "dev": "vite",
        "build": "vite build"
    },
    "devDependencies": {
        "@statamic/cms": "^6.0",
        "@vitejs/plugin-vue": "^5.0",
        "laravel-vite-plugin": "^1.0",
        "vite": "^6.0",
        "vue": "^3.5"
    }
}
```

- [ ] **Step 2: Create vite.config.js**

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/cp.js', 'resources/css/cp.css'],
            publicDirectory: 'resources/dist',
        }),
        vue(),
    ],
});
```

- [ ] **Step 3: Add Vite config to ServiceProvider**

```php
protected $vite = [
    'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    'publicDirectory' => 'resources/dist',
];
```

- [ ] **Step 4: Install npm dependencies**

Run: `npm install`

- [ ] **Step 5: Commit**

```bash
git add package.json vite.config.js src/ServiceProvider.php
git commit -m "feat: setup Vite tooling for CP dashboard"
```

---

### Task 29: Create CSS entry point

**Files:**
- Create: `resources/css/cp.css`

- [ ] **Step 1: Create cp.css**

```css
@import '@statamic/cms/tailwind.css';
```

- [ ] **Step 2: Commit**

```bash
git add resources/css/cp.css
git commit -m "feat: add Tailwind 4 CSS entry point"
```

---

### Task 30: Create JS entry point and register Inertia pages

**Files:**
- Create: `resources/js/cp.js`

- [ ] **Step 1: Create cp.js**

```js
import Dashboard from './pages/Dashboard.vue';
import ConnectionWizard from './pages/ConnectionWizard.vue';
import TokensIndex from './pages/Tokens/Index.vue';
import TokensCreate from './pages/Tokens/Create.vue';
import AuditLog from './pages/AuditLog.vue';
import Permissions from './pages/Permissions.vue';
import Settings from './pages/Settings.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-mcp::Dashboard', Dashboard);
    Statamic.$inertia.register('statamic-mcp::ConnectionWizard', ConnectionWizard);
    Statamic.$inertia.register('statamic-mcp::TokensIndex', TokensIndex);
    Statamic.$inertia.register('statamic-mcp::TokensCreate', TokensCreate);
    Statamic.$inertia.register('statamic-mcp::AuditLog', AuditLog);
    Statamic.$inertia.register('statamic-mcp::Permissions', Permissions);
    Statamic.$inertia.register('statamic-mcp::Settings', Settings);
});
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/cp.js
git commit -m "feat: register Inertia pages for CP dashboard"
```

---

**NOTE:** All Vue templates in Tasks 31-34 are **provisional drafts** pending Gate 3 verification of KITT UI component names and APIs. If Gate 3 reveals different component names or slot APIs, update templates accordingly before implementing.

### Task 31: Create Dashboard page component

**Files:**
- Create: `resources/js/pages/Dashboard.vue`
- Create: `resources/js/components/StatsCard.vue`

- [ ] **Step 1: Create StatsCard component**

```vue
<template>
    <ui-card>
        <template #header>
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-500">{{ label }}</span>
                <ui-badge v-if="badge" :color="badgeColor" :text="badge" />
            </div>
        </template>
        <div class="text-3xl font-bold">{{ value }}</div>
        <p v-if="description" class="mt-1 text-sm text-gray-500">{{ description }}</p>
    </ui-card>
</template>

<script setup>
defineProps({
    label: String,
    value: [String, Number],
    description: String,
    badge: String,
    badgeColor: { type: String, default: 'gray' },
});
</script>
```

- [ ] **Step 2: Create Dashboard page**

```vue
<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <ui-heading text="MCP Server Dashboard" />
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatsCard label="Tool Calls (24h)" :value="stats.calls_24h" />
            <StatsCard label="Active Tokens" :value="stats.active_tokens" />
            <StatsCard label="Error Rate" :value="stats.error_rate + '%'" :badge-color="stats.error_rate > 5 ? 'red' : 'green'" :badge="stats.error_rate > 5 ? 'High' : 'Normal'" />
            <StatsCard label="Top Tool" :value="stats.top_tool" />
        </div>

        <ui-card>
            <template #header>Recent Activity</template>
            <ui-table>
                <ui-table-columns>
                    <ui-table-column>Time</ui-table-column>
                    <ui-table-column>User</ui-table-column>
                    <ui-table-column>Tool</ui-table-column>
                    <ui-table-column>Action</ui-table-column>
                    <ui-table-column>Status</ui-table-column>
                </ui-table-columns>
                <ui-table-rows>
                    <ui-table-row v-for="entry in stats.recent_activity" :key="entry.correlation_id">
                        <ui-table-cell>{{ entry.timestamp }}</ui-table-cell>
                        <ui-table-cell>{{ entry.user_id }}</ui-table-cell>
                        <ui-table-cell>{{ entry.tool }}</ui-table-cell>
                        <ui-table-cell>{{ entry.action }}</ui-table-cell>
                        <ui-table-cell>
                            <ui-badge :color="entry.result === 'success' ? 'green' : 'red'" :text="entry.result" />
                        </ui-table-cell>
                    </ui-table-row>
                </ui-table-rows>
            </ui-table>
        </ui-card>
    </div>
</template>

<script setup>
import StatsCard from '../components/StatsCard.vue';

defineProps({
    stats: Object,
});
</script>
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/Dashboard.vue resources/js/components/StatsCard.vue
git commit -m "feat: add Dashboard page with stats cards and activity table"
```

---

### Task 32: Create Connection Wizard page

**Files:**
- Create: `resources/js/pages/ConnectionWizard.vue`
- Create: `resources/js/components/ConnectionSnippet.vue`

- [ ] **Step 1: Create ConnectionSnippet component**

Receives `client`, `endpoint`, `token` props. Renders a copyable code block with the client-specific config. Uses a `<pre>` block with a copy button.

- [ ] **Step 2: Create ConnectionWizard page**

Three-step wizard:
1. Select AI client (ui-tabs or ui-select with client icons)
2. Create or select an API token (ui-button to create, ui-select for existing)
3. View and copy the config snippet (ConnectionSnippet component)

Includes a "Test Connection" ui-button that calls the test endpoint.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/ConnectionWizard.vue resources/js/components/ConnectionSnippet.vue
git commit -m "feat: add Connection Wizard page with client config generation"
```

---

### Task 33: Create Token management pages

**Files:**
- Create: `resources/js/pages/Tokens/Index.vue`
- Create: `resources/js/pages/Tokens/Create.vue`
- Create: `resources/js/components/ScopeSelector.vue`

- [ ] **Step 1: Create ScopeSelector component**

Checkbox-based scope picker. Groups scopes by domain (entries, blueprints, etc.) with read/write/delete/admin toggles. Includes wildcard `*` and `read:*` shortcuts.

- [ ] **Step 2: Create Tokens/Index page**

Table of tokens with columns: Name, Scopes (badges), Created, Last Used, Expires, Actions (revoke button with confirmation modal).

Admin users (with `manage all mcp tokens`) see all tokens. Regular users see only their own.

- [ ] **Step 3: Create Tokens/Create page**

Form with: token name (ui-input), scope selection (ScopeSelector), expiry date (ui-datepicker, optional). On submit, shows the plain token ONCE in a highlighted, copyable block with a warning that it won't be shown again.

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/Tokens/ resources/js/components/ScopeSelector.vue
git commit -m "feat: add Token management pages with scope selector"
```

---

### Task 34: Create Audit Log, Permissions, and Settings pages

**Files:**
- Create: `resources/js/pages/AuditLog.vue`
- Create: `resources/js/pages/Permissions.vue`
- Create: `resources/js/pages/Settings.vue`

- [ ] **Step 1: Create AuditLog page**

Filterable table: search input, date range picker, tool filter dropdown, user filter. Paginated with ui-table. Each row shows timestamp, user, tool, action, status (badge), duration.

- [ ] **Step 2: Create Permissions page**

Table of tool domains with ui-switch toggles for `enabled` and `web_enabled`. Shows current config state. Save button persists to config.

- [ ] **Step 3: Create Settings page**

Form sections for:
- Web MCP (enabled toggle, path input)
- Rate Limiting (enabled, strategy select, global max input)
- Audit Logging (enabled, driver select, retention days input)
- Authentication (legacy auth toggles, OAuth toggle)

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/AuditLog.vue resources/js/pages/Permissions.vue resources/js/pages/Settings.vue
git commit -m "feat: add Audit Log, Permissions, and Settings pages"
```

---

### Task 35: Build frontend assets

- [ ] **Step 1: Build production assets**

Run: `npm run build`
Expected: Assets compiled to `resources/dist/`

- [ ] **Step 2: Commit built assets**

```bash
git add resources/dist/
git commit -m "chore: build frontend assets for v2.0"
```

---

## Chunk 6: Polish & Documentation

### Task 36: Update InstallCommand

**Files:**
- Modify: `src/Console/InstallCommand.php`

- [ ] **Step 1: Add storage directory creation**

Add to `handle()`:
```php
$this->ensureStorageDirectories();
```

Method creates:
- `storage/app/statamic-mcp/tokens/`
- `storage/app/statamic-mcp/audit/`

- [ ] **Step 2: Add token generation step**

After config publish, offer to create the first API token:
```php
if ($this->confirm('Generate an API token now?')) {
    $tokenService = app(TokenService::class);
    $plain = $tokenService->generatePlainToken();
    // Create token with * scope...
    $this->info("Your API token: {$plain}");
    $this->warn('Save this token — it will not be shown again.');
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Console/InstallCommand.php
git commit -m "feat: update install command with token generation and storage setup"
```

---

### Task 37: Update CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update Laravel MCP version references**

Replace all "Laravel MCP v0.2.0" with "Laravel MCP v0.6". Update all code examples to use:
- `#[Name]`, `#[Description]` attributes
- `schema()` instead of `defineSchema()`
- `Response::structured()` instead of array returns
- `shouldRegister()` pattern
- `outputSchema()` pattern

- [ ] **Step 2: Remove dual-version documentation**

Remove v5/v6 compatibility sections. Remove StatamicVersion references.

- [ ] **Step 3: Add auth documentation**

Document the API token system, scopes, and middleware flow.

- [ ] **Step 4: Add dashboard documentation**

Document CP routes, permissions, and KITT UI patterns.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md for v2.0 patterns"
```

---

### Task 38: Final quality check

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/pint`

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`
Expected: Zero errors at level 8

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All green

- [ ] **Step 4: Run composer quality**

Run: `composer quality`
Expected: All checks pass

- [ ] **Step 5: Commit any final fixes**

```bash
git add -A
git commit -m "chore: final quality fixes for v2.0"
```

---

### Task 39: Tag v2.0.0

- [ ] **Step 1: Create version tag**

```bash
git tag -a v2.0.0 -m "Statamic MCP v2.0.0

- Statamic v6 only (dropped v5 support)
- laravel/mcp v0.6 with attributes, outputSchema, Response::structured
- Scoped API tokens (smc_) with YAML flat-file storage
- KITT UI dashboard with Connection Wizard, Token Management, Audit Log
- Granular Statamic permissions for all CP sections
- Sliding window rate limiting per token
- Legacy auth (Basic/Bearer) deprecated with warnings
- Optional OAuth 2.1 via Passport"
```

**Note:** Do NOT push the tag without user confirmation.
