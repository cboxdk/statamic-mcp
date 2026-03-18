# MCP Tools Token Optimization & DX Refactor

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce MCP tool response token overhead by 30-40% and eliminate tool overlap that confuses AI agents.

**Architecture:** Strip redundant metadata from every response, remove the duplicate ContentRouter (EntriesRouter/TermsRouter/GlobalsRouter already cover everything), unify the two competing execution patterns into one, and move the help/discovery system out of BaseRouter into the dedicated DiscoveryTool/SchemaTool where it belongs.

**Tech Stack:** PHP 8.3, Laravel MCP v0.6, Pest 4, PHPStan Level 8

---

## File Structure

### Files to DELETE
- `src/Mcp/Tools/Routers/ContentRouter.php` (1,600 lines) — 100% redundant with EntriesRouter + TermsRouter + GlobalsRouter

### Files to MODIFY (Core — response pipeline)
- `src/Mcp/DataTransferObjects/BaseResponse.php` — Remove empty arrays from serialization
- `src/Mcp/DataTransferObjects/ResponseMeta.php` — Make fields optional, cache version
- `src/Mcp/Tools/BaseStatamicTool.php` — Cache version info, simplify response methods
- `src/Mcp/Support/ToolResponse.php` — Cache version info, trim response structure
- `src/Mcp/Tools/BaseRouter.php` — Remove help/discover/examples system, simplify meta
- `src/Mcp/Tools/Concerns/ClearsCaches.php` — Silent cache clearing (no verbose response)

### Files to MODIFY (Routers — remove help boilerplate)
- `src/Mcp/Tools/Routers/EntriesRouter.php` — Remove 6 abstract method implementations
- `src/Mcp/Tools/Routers/TermsRouter.php` — Remove 6 abstract method implementations
- `src/Mcp/Tools/Routers/GlobalsRouter.php` — Remove 6 abstract method implementations
- `src/Mcp/Tools/Routers/BlueprintsRouter.php` — Remove 6 abstract method implementations
- `src/Mcp/Tools/Routers/AssetsRouter.php` — Remove 6 abstract method implementations
- `src/Mcp/Tools/Routers/UsersRouter.php` — Remove 6 abstract method implementations
- `src/Mcp/Tools/Routers/StructuresRouter.php` — Remove 6 abstract method implementations
- `src/Mcp/Tools/Routers/SystemRouter.php` — Remove 6 abstract method implementations
- `src/Mcp/Tools/Routers/ContentFacadeRouter.php` — Remove 6 abstract method implementations

### Files to MODIFY (Registration & tests)
- `src/Mcp/Servers/StatamicMcpServer.php` — Remove ContentRouter from tool list
- `tests/Feature/Routers/ContentRouterTest.php` — Delete or migrate to test EntriesRouter/TermsRouter/GlobalsRouter
- `tests/Feature/StatamicMcpServerTest.php` — Update tool count assertions
- `tests/Feature/McpJsonSchemaValidationTest.php` — Update if needed
- `tests/Feature/ContentRouterPermissionsTest.php` — Migrate to EntriesRouter
- `tests/Tools/GlobalToolsIntegrationTest.php` — Update router reference

### Files NOT modified (Agent education — intentionally left as-is)
- `src/Mcp/Tools/System/DiscoveryTool.php` — Already provides intent-based discovery independently of BaseRouter's help system. The router help methods being removed were never called by DiscoveryTool — they were a parallel, redundant system.
- `src/Mcp/Tools/System/SchemaTool.php` — Already provides schema inspection via tool introspection. Does not depend on `getFeatures()`, `getPrimaryUse()`, etc.

---

## Chunk 1: Remove ContentRouter (eliminate duplication)

### Task 1: Verify ContentRouter is fully redundant

**Files:**
- Read: `src/Mcp/Tools/Routers/ContentRouter.php`
- Read: `src/Mcp/Tools/Routers/EntriesRouter.php`
- Read: `src/Mcp/Tools/Routers/TermsRouter.php`
- Read: `src/Mcp/Tools/Routers/GlobalsRouter.php`

- [ ] **Step 1: Diff the entry methods**

Compare these method pairs between ContentRouter and EntriesRouter:
- `listEntries()`, `getEntry()`, `createEntry()`, `updateEntry()`, `deleteEntry()`, `publishEntry()`, `unpublishEntry()`

Verify they are functionally identical. The analysis found 7/7 are exact duplicates.

- [ ] **Step 2: Diff the term methods**

Compare ContentRouter vs TermsRouter:
- `listTerms()`, `getTerm()`, `createTerm()`, `updateTerm()`, `deleteTerm()`

Verify 5/5 are exact duplicates.

- [ ] **Step 3: Diff the global methods**

Compare ContentRouter vs GlobalsRouter:
- `listGlobals()`, `getGlobal()`, `updateGlobal()`

Verify 3/3 are exact duplicates.

- [ ] **Step 4: Check for unique ContentRouter features**

Search for anything ContentRouter does that the specialized routers don't. Expected: nothing unique — it's a pure superset.

### Task 2: Remove ContentRouter from registration

**Files:**
- Modify: `src/Mcp/Servers/StatamicMcpServer.php:44-62`

- [ ] **Step 1: Remove ContentRouter from tools array**

```php
// REMOVE this line:
ContentRouter::class,
// REMOVE the import:
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentRouter;
```

- [ ] **Step 2: Update comments**

Change comment from `// === Specialized Content Router Tools (5) ===` to `(4)`.

- [ ] **Step 3: Run tests to see what breaks**

Run: `./vendor/bin/pest`
Expected: Some tests will fail that reference ContentRouter directly.

### Task 3: Migrate ContentRouter tests

**Files:**
- Modify: `tests/Feature/Routers/ContentRouterTest.php`
- Modify: `tests/Feature/ContentRouterPermissionsTest.php`
- Modify: `tests/Tools/GlobalToolsIntegrationTest.php`
- Modify: `tests/Feature/StatamicMcpServerTest.php`

- [ ] **Step 1: Rewrite ContentRouterTest to test specialized routers**

The existing `ContentRouterTest` tests 3 domains: entries (via `type: entry`), terms (via `type: term`), globals (via `type: global`). Split into tests that call EntriesRouter, TermsRouter, and GlobalsRouter directly.

Key change: Remove `'type' => 'entry'` parameter from arguments — the specialized routers don't need it. The `collection`, `taxonomy`, or `handle` parameter is the routing signal instead.

- [ ] **Step 2: Update ContentRouterPermissionsTest**

Change from `ContentRouter` to `EntriesRouter` (it tests entry permissions):
1. Replace `use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentRouter` with `EntriesRouter`
2. Replace `$this->contentRouter = new ContentRouter` with `new EntriesRouter`
3. Remove `'type' => 'entry'` from all test argument arrays
4. **CRITICAL**: Update config keys — change `Config::set('statamic.mcp.tools.statamic-content.web_enabled', ...)` to `Config::set('statamic.mcp.tools.statamic-entries.web_enabled', ...)` in both `beforeEach()` and the "rejects when web tool is disabled" test

- [ ] **Step 3: Update GlobalToolsIntegrationTest**

Change from `ContentRouter` to `GlobalsRouter`. Remove `type` parameter.

- [ ] **Step 4: Update StatamicMcpServerTest**

Update tool count expectations (12 → 11 tools). The test `test_tool_categories_are_properly_organized` has `$expectedCategories` containing `'content'` and checks it appears in `$coreCategories`. After removing ContentRouter, `'content'` may no longer be found — replace with `'entries'` or verify the categorization logic. Also fix the existing wrong comment in StatamicMcpServer.php: "Core System Router Tools (4)" actually lists 5 tools.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/pest`
Expected: All tests pass.

- [ ] **Step 6: Run quality checks**

Run: `composer quality`
Expected: Pint pass, PHPStan 0 errors, all tests pass.

### Task 4: Delete ContentRouter

**Files:**
- Delete: `src/Mcp/Tools/Routers/ContentRouter.php`

- [ ] **Step 1: Delete the file**

```bash
rm src/Mcp/Tools/Routers/ContentRouter.php
```

- [ ] **Step 2: Run PHPStan to check for dangling references**

Run: `./vendor/bin/phpstan analyse`
Expected: 0 errors. If any file still imports ContentRouter, fix the import.

- [ ] **Step 3: Run full quality check**

Run: `composer quality`
Expected: All pass.

- [ ] **Step 4: Commit**

```bash
git add src/Mcp/Servers/StatamicMcpServer.php src/Mcp/Tools/Routers/ContentRouter.php tests/
git commit -m "refactor: remove redundant ContentRouter (1,600 lines)

EntriesRouter, TermsRouter, and GlobalsRouter already implement
all 15 actions. Eliminates tool overlap that confused AI agents
choosing between statamic-content and statamic-entries/terms/globals."
```

---

## Chunk 2: Slim response envelope (token reduction)

### Task 5: Cache version info per request

**Files:**
- Modify: `src/Mcp/Tools/BaseStatamicTool.php:216-232`
- Modify: `src/Mcp/Support/ToolResponse.php:27-31,60-64`

- [ ] **Step 1: Add static cache to BaseStatamicTool**

Replace `createResponseMeta()` and `getStatamicVersion()` (lines 216-232):

```php
/** @var array{statamic: string, laravel: string}|null */
private static ?array $versionCache = null;

private static function getCachedVersions(): array
{
    if (self::$versionCache === null) {
        self::$versionCache = [
            'statamic' => \Statamic\Statamic::version() ?? 'unknown',
            'laravel' => app()->version(),
        ];
    }
    return self::$versionCache;
}

private function createResponseMeta(): ResponseMeta
{
    $versions = self::getCachedVersions();
    return new ResponseMeta(
        tool: $this->name(),
        timestamp: now()->toISOString() ?? date('c'),
        statamic_version: $versions['statamic'],
        laravel_version: $versions['laravel'],
    );
}

protected function getStatamicVersion(): string
{
    return self::getCachedVersions()['statamic'];
}
```

- [ ] **Step 2: Add static cache to ToolResponse**

Replace the inline version calls in `ToolResponse::success()` and `ToolResponse::error()`:

```php
private static ?array $versionCache = null;

private static function versions(): array
{
    if (self::$versionCache === null) {
        self::$versionCache = [
            'statamic_version' => \Statamic\Statamic::version(),
            'laravel_version' => app()->version(),
        ];
    }
    return self::$versionCache;
}
```

Then use `self::versions()` in the meta arrays instead of calling `\Statamic\Statamic::version()` and `app()->version()` directly.

- [ ] **Step 3: Add cache reset for test isolation**

Add to both `BaseStatamicTool` and `ToolResponse`:
```php
/** @internal For testing only */
public static function clearVersionCache(): void
{
    self::$versionCache = null;
}
```

In `tests/TestCase.php`, add to `tearDown()`:
```php
BaseStatamicTool::clearVersionCache();
ToolResponse::clearVersionCache();
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass (version caching is transparent).

### Task 6: Remove empty arrays from success responses

**Files:**
- Modify: `src/Mcp/DataTransferObjects/BaseResponse.php:24-33`

- [ ] **Step 1: Conditionally omit empty arrays in jsonSerialize**

Replace `jsonSerialize()`:

```php
public function jsonSerialize(): array
{
    $response = [
        'success' => $this->success,
        'data' => $this->data,
        'meta' => $this->meta->jsonSerialize(),
    ];

    if (!empty($this->errors)) {
        $response['errors'] = $this->errors;
    }

    if (!empty($this->warnings)) {
        $response['warnings'] = $this->warnings;
    }

    return $response;
}
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest`
Expected: Tests that assert `$result['errors']` on success responses may fail. Fix those tests to use `$result['errors'] ?? []` or remove the assertion.

- [ ] **Step 3: Fix any broken test assertions**

Search for tests that assert on `errors` or `warnings` keys in success responses and update them.

- [ ] **Step 4: Run quality checks**

Run: `composer quality`

### Task 7: Silent cache clearing

**Files:**
- Modify: `src/Mcp/Tools/Concerns/ClearsCaches.php`

- [ ] **Step 1: Change clearStatamicCaches to return void**

The current implementation returns a ~400-600 token array with detailed status per cache type. Change it to return `void` — just clear caches silently.

Replace `clearStatamicCaches()`:

```php
/**
 * Clear Statamic caches silently.
 *
 * @param  array<int, string>  $types
 */
protected function clearStatamicCaches(array $types = ['stache', 'static']): void
{
    foreach ($types as $type) {
        try {
            match ($type) {
                'stache' => \Illuminate\Support\Facades\Artisan::call('statamic:stache:clear'),
                'static' => \Illuminate\Support\Facades\Artisan::call('statamic:static:clear'),
                'views' => \Illuminate\Support\Facades\Artisan::call('view:clear'),
                default => null,
            };
        } catch (\Exception) {
            // Silent failure — cache clearing is best-effort
        }
    }
}
```

- [ ] **Step 2: Update all callers that use the return value**

Search for `$this->clearStatamicCaches(` across all routers. If any caller uses the return value (e.g., `$cacheResult = $this->clearStatamicCaches(...)`), remove the assignment.

Search also for places that include cache result in the response (e.g., `'cache' => $cacheResult`). Remove those response keys.

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/pest`

- [ ] **Step 4: Commit**

```bash
git add src/Mcp/Tools/BaseStatamicTool.php src/Mcp/Support/ToolResponse.php src/Mcp/DataTransferObjects/ src/Mcp/Tools/Concerns/ClearsCaches.php tests/
git commit -m "perf: reduce response token overhead ~30%

Cache version info per request instead of per call.
Remove empty errors/warnings arrays from success responses.
Silent cache clearing instead of verbose status in every write response."
```

---

## Chunk 3: Remove help system from BaseRouter (lazy discovery)

### Task 8: Strip help/discover/examples from BaseRouter

**Files:**
- Modify: `src/Mcp/Tools/BaseRouter.php`

The current BaseRouter (568 lines) has:
- `provideHelp()` (lines 169-208)
- `provideDiscovery()` (lines 217-247)
- `provideExamples()` (lines 256-274)
- `getActionsHelp()` (lines 377-395)
- `getTypesHelp()` (lines 400-416)
- `getExamplesHelp()` (lines 421-427)
- `getSafetyHelp()` (lines 432-451)
- `getPatternsHelp()` (lines 456-462)
- `getContextHelp()` (lines 467-473)
- `getWorkflowExamples()` (lines 478-481)
- `getSafetyExamples()` (lines 486-500)
- `getErrorHandlingExamples()` (lines 505-513)
- `getBestPractices()` (lines 518-526)
- `getActionPreview()` (lines 531-537)
- `getExpectedChanges()` (lines 544-547)
- `getActionRisks()` (lines 554-557)
- `getActionRecommendations()` (lines 564-567)

And 6 abstract methods that force EVERY router to implement help content:
- `getFeatures()` (line 321)
- `getPrimaryUse()` (line 326)
- `getDecisionTree()` (line 333)
- `getContextAwareness()` (line 340)
- `getWorkflowIntegration()` (line 347)
- `getCommonPatterns()` (line 354)

- [ ] **Step 1: Remove the 6 abstract method declarations from BaseRouter**

Delete lines 314-354 (the abstract method declarations).

- [ ] **Step 2: Remove all help methods from BaseRouter**

Delete:
- `provideHelp()`, `provideDiscovery()`, `provideExamples()`
- `getActionsHelp()`, `getTypesHelp()`, `getExamplesHelp()`
- `getSafetyHelp()`, `getPatternsHelp()`, `getContextHelp()`
- `getWorkflowExamples()`, `getSafetyExamples()`, `getErrorHandlingExamples()`, `getBestPractices()`
- `getActionPreview()`, `getExpectedChanges()`, `getActionRisks()`, `getActionRecommendations()`
- `getDependencies()`, `getRelatedTools()`

- [ ] **Step 3: Remove help/discover/examples from defineSchema action enum**

In `defineSchema()` (lines 52-78), remove these lines:
```php
$actions[] = 'help';
$actions[] = 'discover';
$actions[] = 'examples';
```

Also remove `help_topic` from the schema.

- [ ] **Step 4: Simplify executeInternal**

Replace lines 87-98:
```php
protected function executeInternal(array $arguments): array
{
    return $this->executeWithSafety($arguments);
}
```

- [ ] **Step 5: Simplify executeWithSafety meta**

In `executeWithSafety()` (lines 107-160), replace the verbose meta block (lines 145-154):
```php
$result['meta'] = [
    'tool' => $this->name(),
    'action' => $action,
];
```

Remove `timestamp`, `statamic_version`, `laravel_version`, `executed_at`, `dry_run`, `safety_checked` — these are already in the outer envelope from `wrapInStandardFormat()`.

- [ ] **Step 6: Run PHPStan to find broken references**

Run: `./vendor/bin/phpstan analyse`

Every router implements the 6 abstract methods. Since we removed the abstract declarations, the implementations become dead code. Don't remove them yet — PHPStan may flag them, but they're harmless. We'll remove them in the next task.

### Task 9: Remove help method implementations from all 9 routers

**Files:**
- Modify: All 9 routers in `src/Mcp/Tools/Routers/`

- [ ] **Step 1: Remove from EntriesRouter**

Delete these methods:
- `getFeatures()`, `getPrimaryUse()`, `getDecisionTree()`, `getContextAwareness()`, `getWorkflowIntegration()`, `getCommonPatterns()`
- `getActions()` — BUT KEEP if it's used by `executeAction()` for routing. Check if the router uses `getActions()` internally. If it only served BaseRouter's help system, delete it.
- `getTypes()` — same check as above.

**Important**: `getActions()` and `getTypes()` ARE used by BaseRouter's `defineSchema()` to build the action enum. After the BaseRouter changes, check if defineSchema still calls `$this->getActions()`. If yes, keep them. If defineSchema was simplified to not need them, they can go.

Actually — `defineSchema()` in BaseRouter still calls `array_keys($this->getActions())` and `array_keys($this->getTypes())` to build the enum. So `getActions()` and `getTypes()` MUST stay. Only remove: `getFeatures()`, `getPrimaryUse()`, `getDecisionTree()`, `getContextAwareness()`, `getWorkflowIntegration()`, `getCommonPatterns()`.

- [ ] **Step 2: Repeat for TermsRouter**

Same 6 methods removed.

- [ ] **Step 3: Repeat for GlobalsRouter**

Same 6 methods removed.

- [ ] **Step 4: Repeat for BlueprintsRouter**

Same 6 methods removed.

- [ ] **Step 5: Repeat for AssetsRouter**

Same 6 methods removed.

- [ ] **Step 6: Repeat for UsersRouter**

Same 6 methods removed.

- [ ] **Step 7: Repeat for StructuresRouter**

Same 6 methods removed.

- [ ] **Step 8: Repeat for SystemRouter**

Same 6 methods removed.

- [ ] **Step 9: Repeat for ContentFacadeRouter**

Same 6 methods removed.

- [ ] **Step 10: Run full quality check**

Run: `composer quality`
Expected: Pint pass, PHPStan 0 errors, all tests pass.

- [ ] **Step 11: Commit**

```bash
git add src/Mcp/Tools/BaseRouter.php src/Mcp/Tools/Routers/
git commit -m "refactor: remove help system from BaseRouter and all routers

Strip 6 abstract method implementations from each of 9 routers
(54 methods total). Help/discovery/examples are handled by the
dedicated DiscoveryTool and SchemaTool instead.

Reduces BaseRouter from 568 to ~160 lines. Each router loses
~80-120 lines of help boilerplate."
```

---

## Chunk 4: Trim schema descriptions & simplify BaseRouter schema

### Task 10: Simplify BaseRouter defineSchema

**Files:**
- Modify: `src/Mcp/Tools/BaseRouter.php`

- [ ] **Step 1: Remove dry_run, confirm, help_topic from base schema**

The `dry_run` and `confirm` parameters are only needed for destructive actions. Move them to individual routers that have destructive actions, or keep in BaseRouter but with shorter descriptions.

Simplified `defineSchema()`:

```php
protected function defineSchema(JsonSchemaContract $schema): array
{
    return [
        'action' => JsonSchema::string()
            ->description('Action to perform')
            ->enum(array_keys($this->getActions()))
            ->required(),
        'type' => JsonSchema::string()
            ->description('Resource type')
            ->enum(array_keys($this->getTypes())),
        'dry_run' => JsonSchema::boolean()
            ->description('Preview without executing'),
        'confirm' => JsonSchema::boolean()
            ->description('Confirm destructive operation'),
    ];
}
```

Removed: `help_topic` field entirely. Trimmed descriptions from multi-sentence to terse.

- [ ] **Step 2: Trim schema descriptions in routers**

For each router's `defineSchema()`, shorten verbose descriptions. Examples:

Before: `'Blueprint handle (required for get, update, delete, validate). For collections, this is the blueprint name (e.g., "article", "product"), NOT the collection handle.'`
After: `'Blueprint handle'`

Before: `'Blueprint namespace (collections, taxonomies, globals, forms, assets, users)'`
After: `'Namespace'` (enum values are self-documenting)

Apply across all 9 routers — focus on descriptions longer than 10 words.

- [ ] **Step 3: Run quality checks**

Run: `composer quality`

- [ ] **Step 4: Commit**

```bash
git add src/Mcp/Tools/BaseRouter.php src/Mcp/Tools/Routers/
git commit -m "perf: trim schema descriptions for token efficiency

Shorten verbose field descriptions to essential information.
Enum values are self-documenting — no need to repeat them in descriptions.
Reduces schema token overhead by ~300-500 tokens across all tools."
```

---

## Chunk 5: Unify execution pattern

### Context: Two distinct audit patterns exist

**Pattern A — Trait-based** (5 routers: Assets, Blueprints, Users, Structures, System):
- `executeAction()` → trait's `$this->executeWithAuditLog($action, $arguments)` → `$this->performDomainAction($action, $arguments)` (the actual work)
- The trait provides: rate limiting, AuditService logging, web permission checks

**Pattern B — Private method** (4 routers: Entries, Terms, Globals, ContentFacade):
- `executeAction()` → private `$this->executeWithAuditLog($action, $arguments)` → `$this->performAction($action, $arguments)` (the actual work)
- The private method provides: `\Log::info()` audit logging, no rate limiting

Both patterns are called from `executeAction()` which is called by BaseRouter's `executeWithSafety()`. The unified approach must replace BOTH patterns.

### Task 11: Unify audit logging into BaseRouter

**Files:**
- Modify: `src/Mcp/Tools/BaseRouter.php`
- Modify: `src/Mcp/Tools/Routers/AssetsRouter.php`
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php`
- Modify: `src/Mcp/Tools/Routers/UsersRouter.php`
- Modify: `src/Mcp/Tools/Routers/StructuresRouter.php`
- Modify: `src/Mcp/Tools/Routers/SystemRouter.php`
- Modify: `src/Mcp/Tools/Routers/EntriesRouter.php`
- Modify: `src/Mcp/Tools/Routers/TermsRouter.php`
- Modify: `src/Mcp/Tools/Routers/GlobalsRouter.php`
- Modify: `src/Mcp/Tools/Routers/ContentFacadeRouter.php`
- Delete: `src/Mcp/Tools/Concerns/ExecutesWithAudit.php`

- [ ] **Step 1: Add audit logging hook to BaseRouter.executeWithSafety()**

In `executeWithSafety()`, wrap the `$this->executeAction($arguments)` call with audit logging:

```php
protected function executeWithSafety(array $arguments): array
{
    // ... existing safety protocol checks ...

    if ($isDryRun) {
        return $this->simulateAction($arguments);
    }

    try {
        // Audit log if enabled
        $startTime = microtime(true);
        if (config('statamic.mcp.security.audit_logging', true)) {
            \Log::info("MCP {$this->getDomain()} operation started", [
                'action' => $action,
                'tool' => $this->name(),
                'context' => $this->isCliContext() ? 'cli' : 'web',
            ]);
        }

        $result = $this->executeAction($arguments);

        if (config('statamic.mcp.security.audit_logging', true)) {
            \Log::info("MCP {$this->getDomain()} operation completed", [
                'action' => $action,
                'tool' => $this->name(),
                'duration' => microtime(true) - $startTime,
            ]);
        }

        $result['meta'] = [
            'tool' => $this->name(),
            'action' => $action,
        ];

        return $result;
    } catch (\Exception $e) {
        if (config('statamic.mcp.security.audit_logging', true)) {
            \Log::error("MCP {$this->getDomain()} operation failed", [
                'action' => $action,
                'tool' => $this->name(),
                'error' => $e->getMessage(),
            ]);
        }
        return $this->createErrorResponse("Action failed: {$e->getMessage()}")->toArray();
    }
}
```

Note: `isCliContext()` comes from `RouterHelpers` trait which ALL routers use.

- [ ] **Step 2: Update Pattern A routers (trait-based: Assets, Blueprints, Users, Structures, System)**

For each of these 5 routers:
1. Remove `use ExecutesWithAudit;` trait import
2. Rename `performDomainAction()` to `executeAction()` (replacing the existing `executeAction()` that just delegates)
3. The new `executeAction()` should contain ONLY the domain logic (the match statement routing to specific handlers)

Example for AssetsRouter — before:
```php
protected function executeAction(array $arguments): array
{
    // ... permission checks ...
    return $this->executeWithAuditLog($action, $arguments); // trait method
}

protected function performDomainAction(string $action, array $arguments): array
{
    return match ($action) {
        'list' => $this->listAssets($arguments),
        // ...
    };
}
```

After:
```php
protected function executeAction(array $arguments): array
{
    // ... permission checks (keep these) ...
    $action = $arguments['action'] ?? '';
    return match ($action) {
        'list' => $this->listAssets($arguments),
        // ... (move content from performDomainAction here)
    };
}
```

- [ ] **Step 3: Update Pattern B routers (private method: Entries, Terms, Globals, ContentFacade)**

For each of these 4 routers:
1. Delete the private `executeWithAuditLog()` method (~50-60 lines each)
2. Delete the private `performAction()` method
3. Delete `sanitizeArgumentsForLogging()` if it exists
4. Move the match-statement routing from `performAction()` into `executeAction()`
5. Keep any permission/validation logic that was in `executeAction()` before the audit call

Example for EntriesRouter — before:
```php
protected function executeAction(array $arguments): array
{
    // ... permission checks, validation ...
    return $this->executeWithAuditLog($action, $arguments); // private method
}

private function executeWithAuditLog(string $action, array $arguments): array
{
    // ... ~60 lines of logging boilerplate ...
    $result = $this->performAction($action, $arguments);
    // ... more logging ...
    return $result;
}

private function performAction(string $action, array $arguments): array
{
    return match ($action) {
        'list' => $this->listEntries($arguments),
        // ...
    };
}
```

After:
```php
protected function executeAction(array $arguments): array
{
    // ... permission checks, validation (keep these) ...
    $action = $arguments['action'] ?? '';
    return match ($action) {
        'list' => $this->listEntries($arguments),
        // ... (move content from performAction here)
    };
}
```

- [ ] **Step 4: Delete ExecutesWithAudit trait**

```bash
rm src/Mcp/Tools/Concerns/ExecutesWithAudit.php
```

- [ ] **Step 5: Run PHPStan to check for dangling references**

Run: `./vendor/bin/phpstan analyse`

- [ ] **Step 6: Run full quality checks**

Run: `composer quality`

- [ ] **Step 7: Commit**

```bash
git add src/Mcp/Tools/BaseRouter.php src/Mcp/Tools/Routers/ src/Mcp/Tools/Concerns/ExecutesWithAudit.php
git commit -m "refactor: unify audit logging into BaseRouter.executeWithSafety()

Replace two competing audit patterns (ExecutesWithAudit trait and
private executeWithAuditLog methods) with single audit hook in
BaseRouter. Each router's executeAction() now contains only domain
logic. Removes ~500 lines of duplicated audit boilerplate."
```

---

## Chunk 6: Final cleanup and verification

### Task 12: Remove legacy response methods

**Files:**
- Modify: `src/Mcp/Tools/BaseStatamicTool.php`

- [ ] **Step 1: Check usage of deprecated errorResponse()**

Search for `->errorResponse(` across the codebase. If unused, delete the method (lines 353-360).

- [ ] **Step 2: Check usage of dual response patterns**

Search for `createStandardSuccessResponse`, `createStandardErrorResponse`. These delegate to `ToolResponse::success()` which is a DIFFERENT response format than `createSuccessResponse()` which uses the DTO pattern.

If both patterns are still in use, document which routers use which. If one pattern dominates, remove the other.

- [ ] **Step 3: Run quality checks**

Run: `composer quality`

### Task 13: Update documentation

**Files:**
- Modify: `docs/tools/overview.md`
- Modify: `README.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update tool overview**

Remove `statamic-content` from the tools list. Update tool count from 12 to 11. Remove ContentRouter section.

- [ ] **Step 2: Update README**

Update tool count and remove ContentRouter references.

- [ ] **Step 3: Update CLAUDE.md**

Remove ContentRouter references. Update architecture description.

- [ ] **Step 4: Commit**

```bash
git add docs/ README.md CLAUDE.md
git commit -m "docs: update for ContentRouter removal and token optimization"
```

### Task 14: Final verification

- [ ] **Step 1: Run complete quality pipeline**

Run: `composer quality`
Expected: Pint pass, PHPStan 0 errors, all tests pass.

- [ ] **Step 2: Count lines saved**

```bash
# Compare before/after
git diff --stat HEAD~6..HEAD
```

Expected savings:
- ContentRouter: -1,600 lines
- Help methods from 9 routers: ~-900 lines (6 methods × ~15 lines × 9 routers)
- BaseRouter help system: ~-400 lines
- ClearsCaches verbose output: ~-80 lines
- **Total: ~-2,980 lines removed**

- [ ] **Step 3: Verify token reduction**

Test a typical MCP call response and count tokens before/after:
- No more `errors: []` and `warnings: []` on success (saves ~50 tokens/call)
- No more duplicate meta in executeWithSafety + wrapInStandardFormat (saves ~85 tokens/call)
- No more verbose cache clearing response (saves ~400-600 tokens/write)
- Shorter schema descriptions (saves ~300-500 tokens total across all schemas)
- One less tool to choose from (saves schema overhead for ContentRouter)

---

## Summary

| Chunk | Tasks | Key Change | Impact |
|-------|-------|------------|--------|
| 1 | 1-4 | Remove ContentRouter | -1,600 lines, -1 tool, eliminates agent confusion |
| 2 | 5-7 | Slim response envelope | ~135 tokens saved per call |
| 3 | 8-9 | Remove help system from routers | ~-1,300 lines across BaseRouter + 9 routers |
| 4 | 10 | Trim schema descriptions | ~300-500 tokens saved in schema overhead |
| 5 | 11 | Unify execution pattern | Consistent codebase, remove ExecutesWithAudit |
| 6 | 12-14 | Cleanup + docs | Remove legacy methods, update docs |

**Total estimated reduction**: ~3,000 lines of code, 30-40% fewer tokens per MCP call.
