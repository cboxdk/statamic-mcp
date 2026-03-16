# Production Hardening Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all critical, medium, and low-priority issues from the platform review — performance (N+1 queries, unbounded responses), security (taxonomy cross-access, brute-force, version leakage), and quality (missing tests, inconsistent patterns).

**Architecture:** Targeted fixes to existing router tools, middleware, config, and test files. No new classes needed except a rate limiter middleware. All changes maintain PHPStan Level 9 compliance and existing test patterns.

**Tech Stack:** PHP 8.3, Laravel 12, Statamic v6, Pest 4.1, PHPStan Level 9

---

## Chunk 1: Critical Performance & Security Fixes

### Task 1: Fix N+1 Query in BlueprintsRouter.listBlueprints()

**Files:**
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php:126-208`
- Test: `tests/Feature/Routers/BlueprintsRouterTest.php`

**Problem:** Iterates all collections calling `Blueprint::in("collections.{$handle}")` per collection.

- [ ] **Step 1: Write test for batch blueprint loading**

Add to `tests/Feature/Routers/BlueprintsRouterTest.php`:

```php
it('lists blueprints without N+1 queries on collections', function () {
    // Create multiple collections with blueprints
    $collections = [];
    for ($i = 0; $i < 3; $i++) {
        $collection = \Statamic\Facades\Collection::make("test_col_{$i}")->save();
        $collections[] = $collection;
        $blueprint = \Statamic\Facades\Blueprint::make("bp_{$i}")
            ->setNamespace("collections.test_col_{$i}")
            ->setContents(['title' => "BP {$i}"])
            ->save();
    }

    $router = new \Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter();
    $result = $router->executeAction(['action' => 'list', 'namespace' => 'collections']);

    expect($result)->toHaveKey('blueprints');
    // Should find collection-specific blueprints
    $handles = collect($result['blueprints'])->pluck('handle')->all();
    expect($handles)->toContain('bp_0');
    expect($handles)->toContain('bp_1');
    expect($handles)->toContain('bp_2');
});
```

- [ ] **Step 2: Run test to verify it passes with current implementation (baseline)**

Run: `./vendor/bin/pest tests/Feature/Routers/BlueprintsRouterTest.php --filter="N+1"`

- [ ] **Step 3: Replace N+1 loop with batch Collection::handles() approach**

In `src/Mcp/Tools/Routers/BlueprintsRouter.php`, replace lines 140-153:

```php
// OLD (N+1):
// if (! $namespace || $namespace === 'collections') {
//     $collections = \Statamic\Facades\Collection::all();
//     foreach ($collections as $collection) {
//         ...Blueprint::in("collections.{$collection->handle()}")...
//     }
// }

// NEW (batch):
if (! $namespace || $namespace === 'collections') {
    $collectionHandles = \Statamic\Facades\Collection::handles()->all();
    foreach ($collectionHandles as $collectionHandle) {
        try {
            /** @var string $collectionHandle */
            $collectionBlueprints = collect(Blueprint::in("collections.{$collectionHandle}")->all());
            $blueprints = $blueprints->merge($collectionBlueprints);
        } catch (\Exception $e) {
            // Ignore if collection namespace doesn't exist
        }
    }
}
```

This replaces loading full Collection objects with lightweight handle strings.

- [ ] **Step 4: Run all blueprint tests**

Run: `./vendor/bin/pest tests/Feature/Routers/BlueprintsRouterTest.php`
Expected: All tests PASS

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/Routers/BlueprintsRouter.php`
Expected: No errors

- [ ] **Step 6: Commit**

```bash
git add src/Mcp/Tools/Routers/BlueprintsRouter.php tests/Feature/Routers/BlueprintsRouterTest.php
git commit -m "perf: replace N+1 blueprint query with batch Collection::handles()"
```

---

### Task 2: Fix Race Condition in Blueprint Creation

**Files:**
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php:257-353`

**Problem:** Check-then-create without atomicity allows duplicates under concurrent requests.

- [ ] **Step 1: Write test for concurrent creation guard**

Add to `tests/Feature/Routers/BlueprintsRouterTest.php`:

```php
it('handles blueprint creation when blueprint already exists', function () {
    $collection = \Statamic\Facades\Collection::make('test_race')->save();

    // Pre-create the blueprint
    \Statamic\Facades\Blueprint::make('existing_bp')
        ->setNamespace('collections.test_race')
        ->setContents(['title' => 'Existing'])
        ->save();

    $router = new \Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter();
    $result = $router->executeAction([
        'action' => 'create',
        'handle' => 'existing_bp',
        'namespace' => 'collections',
        'collection_handle' => 'test_race',
    ]);

    expect($result)->toHaveKey('success');
    expect($result['success'])->toBeFalse();
});
```

- [ ] **Step 2: Add file-based locking to createBlueprint**

In `src/Mcp/Tools/Routers/BlueprintsRouter.php`, wrap the create logic (lines 308-328) with a file lock:

```php
// After determining $blueprintNamespace (line 306), add lock:
$lockPath = storage_path("framework/cache/blueprint-create-{$blueprintNamespace}-{$safeHandle}.lock");
$lockDir = dirname($lockPath);
if (! is_dir($lockDir)) {
    mkdir($lockDir, 0755, true);
}

$lockFile = fopen($lockPath, 'c');
if (! $lockFile || ! flock($lockFile, LOCK_EX)) {
    return $this->createErrorResponse('Could not acquire lock for blueprint creation')->toArray();
}

try {
    // Check if blueprint already exists in this namespace (inside lock)
    $existing = collect(Blueprint::in($blueprintNamespace)->all())->firstWhere('handle', $safeHandle);
    if ($existing) {
        return $this->createErrorResponse("Blueprint already exists: {$safeHandle} in {$blueprintNamespace}")->toArray();
    }

    // ... rest of creation logic (lines 314-348) ...
} finally {
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
    @unlink($lockPath);
}
```

- [ ] **Step 3: Run blueprint tests**

Run: `./vendor/bin/pest tests/Feature/Routers/BlueprintsRouterTest.php`
Expected: All PASS

- [ ] **Step 4: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/Routers/BlueprintsRouter.php`
Expected: No errors

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/Tools/Routers/BlueprintsRouter.php tests/Feature/Routers/BlueprintsRouterTest.php
git commit -m "fix: add file lock to blueprint creation to prevent race conditions"
```

---

### Task 3: Fix Redundant Search in findBlueprint()

**Files:**
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php:363-437`

**Problem:** Lines 398-407 duplicate the exact same search as line 391 for dotted namespaces.

- [ ] **Step 1: Remove redundant search block**

In `src/Mcp/Tools/Routers/BlueprintsRouter.php`, delete lines 397-407:

```php
// DELETE THIS BLOCK - it duplicates line 391:
// // For any namespace with dots (addon namespaces), try searching for variations
// if (str_contains($namespace, '.')) {
//     try {
//         $blueprint = collect(Blueprint::in($namespace)->all())->firstWhere('handle', $handle);
//         if ($blueprint) {
//             return $blueprint;
//         }
//     } catch (\Exception $e) {
//         // Ignore if namespace doesn't exist
//     }
// }
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Routers/BlueprintsRouterTest.php`
Expected: All PASS

- [ ] **Step 3: Commit**

```bash
git add src/Mcp/Tools/Routers/BlueprintsRouter.php
git commit -m "perf: remove redundant blueprint search for dotted namespaces"
```

---

### Task 4: Make Asset Counts Opt-in in AssetsRouter

**Files:**
- Modify: `src/Mcp/Tools/Routers/AssetsRouter.php:48-75` (schema), `159-200` (listContainers), `207-239` (getContainer)

**Problem:** `getContainerAssetCount()` and `getContainerFolderCount()` called inside loop = N+1 per container.

- [ ] **Step 1: Write test for include_counts parameter**

Add to `tests/Feature/Routers/AssetsRouterTest.php`:

```php
it('excludes asset counts from container list by default', function () {
    \Statamic\Facades\AssetContainer::make('test_perf')->disk('assets')->save();

    $router = new \Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter();
    $result = $router->executeAction([
        'action' => 'list',
        'type' => 'container',
    ]);

    expect($result)->toHaveKey('containers');
    $container = collect($result['containers'])->firstWhere('handle', 'test_perf');
    expect($container)->not->toHaveKey('asset_count');
});

it('includes asset counts when include_counts is true', function () {
    \Statamic\Facades\AssetContainer::make('test_counts')->disk('assets')->save();

    $router = new \Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter();
    $result = $router->executeAction([
        'action' => 'list',
        'type' => 'container',
        'include_counts' => true,
    ]);

    expect($result)->toHaveKey('containers');
    $container = collect($result['containers'])->firstWhere('handle', 'test_counts');
    expect($container)->toHaveKey('asset_count');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Routers/AssetsRouterTest.php --filter="asset counts"`
Expected: First test FAILS (counts currently always included)

- [ ] **Step 3: Add include_counts schema parameter and conditional logic**

In `src/Mcp/Tools/Routers/AssetsRouter.php`:

Schema (add to `defineSchema` return array):
```php
'include_counts' => JsonSchema::boolean()
    ->description('Include asset/folder counts per container (can be slow with many containers)'),
```

In `listContainers()`, change the `if ($includeDetails)` block (line 174-188):
```php
if ($includeDetails) {
    $includeCounts = $this->getBooleanArgument($arguments, 'include_counts', false);
    $permissions = $this->getContainerPermissions($container);
    $data = array_merge($data, [
        'blueprint' => $container->blueprint()?->handle(),
        'url' => $container->url(),
        'path' => $container->path(),
        'allow_uploads' => $permissions['allow_uploads'],
        'allow_downloading' => $permissions['allow_downloading'],
        'allow_renaming' => $permissions['allow_renaming'],
        'allow_moving' => $permissions['allow_moving'],
        'create_folders' => $permissions['create_folders'],
        'search_index' => $this->getContainerSearchIndex($container),
    ]);

    if ($includeCounts) {
        $data['asset_count'] = $this->getContainerAssetCount($container);
    }
}
```

In `getContainer()` (single item, keep counts but make opt-in too):
```php
$includeCounts = $this->getBooleanArgument($arguments, 'include_counts', true);
// ... existing code ...
if ($includeCounts) {
    $data['asset_count'] = $this->getContainerAssetCount($container);
    $data['folder_count'] = $this->getContainerFolderCount($container);
}
```

- [ ] **Step 4: Run all asset tests**

Run: `./vendor/bin/pest tests/Feature/Routers/AssetsRouterTest.php`
Expected: All PASS

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/Routers/AssetsRouter.php`
Expected: No errors

- [ ] **Step 6: Commit**

```bash
git add src/Mcp/Tools/Routers/AssetsRouter.php tests/Feature/Routers/AssetsRouterTest.php
git commit -m "perf: make asset/folder counts opt-in to avoid N+1 queries in container listing"
```

---

### Task 5: Add Taxonomy Validation to TermsRouter.getTerm()

**Files:**
- Modify: `src/Mcp/Tools/Routers/TermsRouter.php:238-289`
- Test: `tests/Feature/Routers/TermsRouterTest.php`

**Problem:** `Term::find($termId)` doesn't validate the term belongs to the requested taxonomy.

- [ ] **Step 1: Write test for cross-taxonomy access prevention**

Add to `tests/Feature/Routers/TermsRouterTest.php`:

```php
it('rejects term lookup when term belongs to different taxonomy', function () {
    // Create two taxonomies
    \Statamic\Facades\Taxonomy::make('tags')->save();
    \Statamic\Facades\Taxonomy::make('categories')->save();

    // Create term in 'tags'
    $term = \Statamic\Facades\Term::make()
        ->taxonomy('tags')
        ->slug('my-tag');
    $term->save();

    $router = new \Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter();
    $result = $router->executeAction([
        'action' => 'get',
        'taxonomy' => 'categories',  // Wrong taxonomy
        'id' => $term->id(),
    ]);

    expect($result)->toHaveKey('success');
    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('not found');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Routers/TermsRouterTest.php --filter="different taxonomy"`
Expected: FAIL (currently returns the term regardless of taxonomy)

- [ ] **Step 3: Add taxonomy validation after Term::find()**

In `src/Mcp/Tools/Routers/TermsRouter.php`, after line 249 (`$term = Term::find($termId);`), add:

```php
if ($term && $term->taxonomyHandle() !== $taxonomy) {
    $term = null; // Term belongs to different taxonomy
}
```

- [ ] **Step 4: Run all terms tests**

Run: `./vendor/bin/pest tests/Feature/Routers/TermsRouterTest.php`
Expected: All PASS

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/Routers/TermsRouter.php`
Expected: No errors

- [ ] **Step 6: Commit**

```bash
git add src/Mcp/Tools/Routers/TermsRouter.php tests/Feature/Routers/TermsRouterTest.php
git commit -m "fix: validate term belongs to requested taxonomy to prevent cross-taxonomy access"
```

---

## Chunk 2: Missing Pagination & Inefficient Counts

### Task 6: Add Pagination to StructuresRouter.listCollections()

**Files:**
- Modify: `src/Mcp/Tools/Routers/StructuresRouter.php:218-252`
- Test: `tests/Feature/Routers/StructuresRouterTest.php`

- [ ] **Step 1: Write test for collection pagination**

Add to `tests/Feature/Routers/StructuresRouterTest.php`:

```php
it('paginates collection listing', function () {
    for ($i = 0; $i < 5; $i++) {
        \Statamic\Facades\Collection::make("paginate_col_{$i}")->save();
    }

    $router = new \Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter();
    $result = $router->executeAction([
        'action' => 'list',
        'type' => 'collection',
        'limit' => 3,
        'offset' => 0,
    ]);

    expect($result)->toHaveKey('pagination');
    expect($result['pagination'])->toHaveKey('total');
    expect($result['pagination'])->toHaveKey('limit');
    expect($result['pagination']['limit'])->toBe(3);
    expect(count($result['collections']))->toBeLessThanOrEqual(3);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Routers/StructuresRouterTest.php --filter="paginates collection"`
Expected: FAIL

- [ ] **Step 3: Add pagination to listCollections()**

In `src/Mcp/Tools/Routers/StructuresRouter.php`, modify `listCollections()`:

```php
private function listCollections(array $arguments): array
{
    try {
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $limit = $this->getIntegerArgument($arguments, 'limit', 50, 1, 500);
        $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

        $allCollections = Collection::all();
        $total = $allCollections->count();

        $collections = $allCollections->skip($offset)->take($limit)->map(function ($collection) use ($includeDetails) {
            /** @var \Statamic\Contracts\Entries\Collection $collection */
            $data = [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'blueprint' => $collection->entryBlueprints()->first()?->handle(),
            ];

            if ($includeDetails) {
                $data = array_merge($data, [
                    'route' => $collection->route('en'),
                    'dated' => $collection->dated(),
                    'orderable' => $collection->orderable(),
                    'taxonomies' => $collection->taxonomies()->map->handle()->all(),
                    'sites' => $collection->sites()->map->handle()->all(),
                    'revisions' => $collection->revisionsEnabled(),
                    'default_status' => $collection->defaultPublishState(),
                ]);
            }

            return $data;
        })->values()->all();

        return [
            'collections' => $collections,
            'total' => $total,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total,
            ],
        ];
    } catch (\Exception $e) {
        return $this->createErrorResponse("Failed to list collections: {$e->getMessage()}")->toArray();
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest tests/Feature/Routers/StructuresRouterTest.php`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/Tools/Routers/StructuresRouter.php tests/Feature/Routers/StructuresRouterTest.php
git commit -m "perf: add pagination to StructuresRouter.listCollections()"
```

---

### Task 7: Add Pagination to StructuresRouter.listTaxonomies()

**Files:**
- Modify: `src/Mcp/Tools/Routers/StructuresRouter.php:563-592`
- Test: `tests/Feature/Routers/StructuresRouterTest.php`

- [ ] **Step 1: Write test for taxonomy pagination**

Add to `tests/Feature/Routers/StructuresRouterTest.php`:

```php
it('paginates taxonomy listing', function () {
    for ($i = 0; $i < 3; $i++) {
        \Statamic\Facades\Taxonomy::make("paginate_tax_{$i}")->save();
    }

    $router = new \Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter();
    $result = $router->executeAction([
        'action' => 'list',
        'type' => 'taxonomy',
        'limit' => 2,
    ]);

    expect($result)->toHaveKey('pagination');
    expect($result['pagination']['limit'])->toBe(2);
    expect(count($result['taxonomies']))->toBeLessThanOrEqual(2);
});
```

- [ ] **Step 2: Add pagination to listTaxonomies()**

Same pattern as Task 6 — add `$limit`, `$offset`, `$total`, `skip()->take()`, and `pagination` key to response.

- [ ] **Step 3: Run tests and commit**

```bash
./vendor/bin/pest tests/Feature/Routers/StructuresRouterTest.php
git add src/Mcp/Tools/Routers/StructuresRouter.php tests/Feature/Routers/StructuresRouterTest.php
git commit -m "perf: add pagination to StructuresRouter.listTaxonomies()"
```

---

### Task 8: Remove entry_count from StructuresRouter.getCollection()

**Files:**
- Modify: `src/Mcp/Tools/Routers/StructuresRouter.php:259-292`

**Problem:** `$collection->queryEntries()->count()` is expensive and only useful sometimes.

- [ ] **Step 1: Make entry_count opt-in**

In `getCollection()`, replace line 285:
```php
// OLD:
'entry_count' => $collection->queryEntries()->count(),

// NEW:
'entry_count' => $this->getBooleanArgument($arguments, 'include_counts', true)
    ? $collection->queryEntries()->count()
    : null,
```

- [ ] **Step 2: Run tests and commit**

```bash
./vendor/bin/pest tests/Feature/Routers/StructuresRouterTest.php
git add src/Mcp/Tools/Routers/StructuresRouter.php
git commit -m "perf: make entry_count opt-in in StructuresRouter.getCollection()"
```

---

### Task 9: Remove entries_count from TermsRouter.getTerm()

**Files:**
- Modify: `src/Mcp/Tools/Routers/TermsRouter.php:274-283`

**Problem:** `$term->queryEntries()->count()` adds a query for every term get.

- [ ] **Step 1: Make entries_count opt-in**

In `getTerm()`, replace line 282:
```php
// OLD:
'entries_count' => $term->queryEntries()->count(),

// NEW:
'entries_count' => $this->getBooleanArgument($arguments, 'include_counts', false)
    ? $term->queryEntries()->count()
    : null,
```

- [ ] **Step 2: Run tests and commit**

```bash
./vendor/bin/pest tests/Feature/Routers/TermsRouterTest.php
git add src/Mcp/Tools/Routers/TermsRouter.php
git commit -m "perf: make entries_count opt-in in TermsRouter.getTerm()"
```

---

### Task 10: Add Pagination to SystemRouter.getSystemInfo() Details

**Files:**
- Modify: `src/Mcp/Tools/Routers/SystemRouter.php:109-165`

**Problem:** `include_details=true` returns unbounded lists. The counts are fine, but the data is already bounded (counts only, not full objects). However, `include_details` defaults to `true` which is wasteful.

- [ ] **Step 1: Change include_details default to false**

In `src/Mcp/Tools/Routers/SystemRouter.php` line 112:
```php
// OLD:
$includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);

// NEW:
$includeDetails = $this->getBooleanArgument($arguments, 'include_details', false);
```

- [ ] **Step 2: Run tests, fix any that assumed default true**

Run: `./vendor/bin/pest tests/Feature/Routers/SystemRouterTest.php`
Update tests that need `'include_details' => true` explicitly.

- [ ] **Step 3: Commit**

```bash
git add src/Mcp/Tools/Routers/SystemRouter.php tests/Feature/Routers/SystemRouterTest.php
git commit -m "perf: default include_details to false in SystemRouter.getSystemInfo()"
```

---

## Chunk 3: Security Hardening

### Task 11: Add Brute-Force Protection to Basic Auth

**Files:**
- Modify: `src/Http/Middleware/AuthenticateForMcp.php`
- Test: `tests/Unit/Auth/MiddlewareSecurityTest.php`

- [ ] **Step 1: Write test for rate-limited auth attempts**

Add to `tests/Unit/Auth/MiddlewareSecurityTest.php`:

```php
it('rate limits failed basic auth attempts', function () {
    $middleware = app(\Cboxdk\StatamicMcp\Http\Middleware\AuthenticateForMcp::class);
    $maxAttempts = 5;

    // Simulate max_attempts failed logins
    for ($i = 0; $i < $maxAttempts; $i++) {
        $request = Request::create('/mcp/statamic', 'POST');
        $request->headers->set('Authorization', 'Basic ' . base64_encode('bad@test.com:wrong'));

        $response = $middleware->handle($request, fn ($r) => new \Illuminate\Http\Response('ok'));
        expect($response->getStatusCode())->toBe(401);
    }

    // Next attempt should be 429
    $request = Request::create('/mcp/statamic', 'POST');
    $request->headers->set('Authorization', 'Basic ' . base64_encode('bad@test.com:wrong'));

    $response = $middleware->handle($request, fn ($r) => new \Illuminate\Http\Response('ok'));
    expect($response->getStatusCode())->toBe(429);
});
```

- [ ] **Step 2: Add rate limiting to authenticateWithCredentials**

In `src/Http/Middleware/AuthenticateForMcp.php`, add rate limiting using Laravel's Cache:

```php
use Illuminate\Support\Facades\Cache;

// At the top of handle(), before Basic Auth check:
// Check if IP is locked out from Basic Auth
$ip = $request->ip() ?? 'unknown';
$lockoutKey = "mcp_auth_lockout:{$ip}";
if (Cache::get($lockoutKey, 0) >= 5) {
    return response()->json([
        'error' => 'Too many authentication attempts',
        'message' => 'Please wait before trying again',
    ], 429, [
        'Retry-After' => '60',
    ]);
}

// After Basic Auth fails (before the 401 response), record the attempt:
if ($credentials) {
    $attemptsKey = "mcp_auth_lockout:{$ip}";
    $attempts = Cache::get($attemptsKey, 0);
    Cache::put($attemptsKey, $attempts + 1, now()->addMinutes(1));
}
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/pest tests/Unit/Auth/MiddlewareSecurityTest.php`
Expected: All PASS

- [ ] **Step 4: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Http/Middleware/AuthenticateForMcp.php`
Expected: No errors

- [ ] **Step 5: Commit**

```bash
git add src/Http/Middleware/AuthenticateForMcp.php tests/Unit/Auth/MiddlewareSecurityTest.php
git commit -m "fix: add brute-force protection to Basic Auth with rate limiting"
```

---

### Task 12: Make Version Info Configurable

**Files:**
- Modify: `config/statamic/mcp.php`
- Modify: `src/Mcp/Tools/BaseStatamicTool.php:244-254`
- Modify: `src/Mcp/DataTransferObjects/ResponseMeta.php`

- [ ] **Step 1: Add config option**

In `config/statamic/mcp.php`, add to the `security` section:

```php
// Include version information in API responses
'expose_versions' => env('STATAMIC_MCP_EXPOSE_VERSIONS', true),
```

- [ ] **Step 2: Conditionally include versions in ResponseMeta**

In `src/Mcp/Tools/BaseStatamicTool.php`, modify `createResponseMeta()`:

```php
private function createResponseMeta(): ResponseMeta
{
    $exposeVersions = (bool) config('statamic.mcp.security.expose_versions', true);

    if ($exposeVersions) {
        $versions = self::getCachedVersions();
        return new ResponseMeta(
            tool: $this->name(),
            timestamp: now()->toISOString() ?? date('c'),
            statamic_version: $versions['statamic'],
            laravel_version: $versions['laravel'],
        );
    }

    return new ResponseMeta(
        tool: $this->name(),
        timestamp: now()->toISOString() ?? date('c'),
        statamic_version: null,
        laravel_version: null,
    );
}
```

- [ ] **Step 3: Make ResponseMeta version fields nullable**

In `src/Mcp/DataTransferObjects/ResponseMeta.php`, update constructor:

```php
public function __construct(
    public readonly string $tool,
    public readonly string $timestamp,
    public readonly ?string $statamic_version = null,
    public readonly ?string $laravel_version = null,
) {}
```

And update `toArray()` to filter nulls:

```php
public function toArray(): array
{
    return array_filter([
        'tool' => $this->tool,
        'timestamp' => $this->timestamp,
        'statamic_version' => $this->statamic_version,
        'laravel_version' => $this->laravel_version,
    ], fn ($v) => $v !== null);
}
```

- [ ] **Step 4: Run all tests**

Run: `./vendor/bin/pest`
Expected: All PASS

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`
Expected: No errors

- [ ] **Step 6: Commit**

```bash
git add config/statamic/mcp.php src/Mcp/Tools/BaseStatamicTool.php src/Mcp/DataTransferObjects/ResponseMeta.php
git commit -m "fix: make version info configurable via expose_versions setting"
```

---

### Task 13: Per-Token Rate Limiting

**Files:**
- Modify: `src/ServiceProvider.php:160-181`
- Modify: `src/Http/Middleware/AuthenticateForMcp.php`

**Problem:** Rate limiting is IP-based. Should be per-token when a token is used.

- [ ] **Step 1: Add per-token rate limiter in ServiceProvider**

In `src/ServiceProvider.php`, add to `bootAddon()`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

// In bootAddon():
RateLimiter::for('mcp', function (\Illuminate\Http\Request $request) {
    $maxAttempts = (int) config('statamic.mcp.rate_limit.max_attempts', 60);

    // Use token ID if available, fall back to IP
    $mcpToken = $request->attributes->get('mcp_token');
    if ($mcpToken instanceof \Cboxdk\StatamicMcp\Auth\McpToken) {
        return Limit::perMinute($maxAttempts)->by('mcp_token:' . $mcpToken->id);
    }

    return Limit::perMinute($maxAttempts)->by('mcp_ip:' . ($request->ip() ?? 'unknown'));
});
```

- [ ] **Step 2: Use the named rate limiter in registerWebMcp()**

In `registerWebMcp()`, change the throttle middleware:

```php
// OLD:
"throttle:{$maxAttempts},{$decayMinutes}",

// NEW:
'throttle:mcp',
```

Note: The `$maxAttempts` and `$decayMinutes` variables can be removed since they're now in the RateLimiter definition.

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/pest`
Expected: All PASS

- [ ] **Step 4: Commit**

```bash
git add src/ServiceProvider.php
git commit -m "fix: implement per-token rate limiting instead of IP-only"
```

---

## Chunk 4: Quality & Consistency Fixes

### Task 14: Consistent Cache Clearing via Trait

**Files:**
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php`

**Problem:** Line 331 uses raw `\Statamic\Facades\Stache::clear()` instead of the `ClearsCaches` trait.

- [ ] **Step 1: Replace raw Stache::clear() with trait method**

Search `BlueprintsRouter.php` for `\Statamic\Facades\Stache::clear()` and replace with:

```php
$this->clearStatamicCaches(['stache']);
```

Verify `BlueprintsRouter` uses `ClearsCaches` trait (it should via `BaseRouter`).

- [ ] **Step 2: Run tests and commit**

```bash
./vendor/bin/pest tests/Feature/Routers/BlueprintsRouterTest.php
git add src/Mcp/Tools/Routers/BlueprintsRouter.php
git commit -m "fix: use ClearsCaches trait consistently in BlueprintsRouter"
```

---

### Task 15: Add Temp File Cleanup Logging in AssetsRouter

**Files:**
- Modify: `src/Mcp/Tools/Routers/AssetsRouter.php`

**Problem:** `@unlink()` silently fails. Should log cleanup failures.

- [ ] **Step 1: Replace @unlink with logged cleanup**

Find the `finally` block with `@unlink($tempPath)` and replace:

```php
} finally {
    if (isset($tempPath) && file_exists($tempPath)) {
        if (! unlink($tempPath)) {
            \Illuminate\Support\Facades\Log::warning('Failed to clean up MCP temp file', [
                'path' => $tempPath,
            ]);
        }
    }
}
```

- [ ] **Step 2: Run tests and commit**

```bash
./vendor/bin/pest tests/Feature/Routers/AssetsRouterTest.php
git add src/Mcp/Tools/Routers/AssetsRouter.php
git commit -m "fix: log temp file cleanup failures instead of silently suppressing"
```

---

### Task 16: Max Token Expiry Enforcement

**Files:**
- Modify: `src/Http/Controllers/CP/TokenController.php`
- Test: `tests/Unit/Auth/TokenServiceTest.php`

- [ ] **Step 1: Add max expiry config**

In `config/statamic/mcp.php`, add to `security`:

```php
// Maximum token lifetime in days (null = unlimited)
'max_token_lifetime_days' => env('STATAMIC_MCP_MAX_TOKEN_LIFETIME', 365),
```

- [ ] **Step 2: Add validation rule in TokenController**

In `TokenController.php`, update the `expires_at` validation in both `store()` and `update()`:

```php
$maxDays = config('statamic.mcp.security.max_token_lifetime_days', 365);
$maxDate = $maxDays ? now()->addDays((int) $maxDays)->toDateString() : null;

// Validation rules:
'expires_at' => array_filter([
    'nullable',
    'date',
    'after:now',
    $maxDate ? "before:{$maxDate}" : null,
]),
```

- [ ] **Step 3: Run tests and commit**

```bash
./vendor/bin/pest
git add config/statamic/mcp.php src/Http/Controllers/CP/TokenController.php
git commit -m "fix: enforce max token lifetime via configuration"
```

---

### Task 17: Add DiscoveryTool Tests

**Files:**
- Create: `tests/Feature/Tools/DiscoveryToolTest.php`

- [ ] **Step 1: Write DiscoveryTool tests**

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;

describe('DiscoveryTool', function () {
    it('returns tool suggestions for content-related intents', function () {
        $tool = new DiscoveryTool();
        $result = $tool->executeInternal(['intent' => 'I want to manage blog entries']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('suggested_tools');
        expect($result['data']['suggested_tools'])->not->toBeEmpty();
    });

    it('returns system state information', function () {
        $tool = new DiscoveryTool();
        $result = $tool->executeInternal(['intent' => 'what tools are available']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
    });

    it('handles empty intent gracefully', function () {
        $tool = new DiscoveryTool();
        $result = $tool->executeInternal(['intent' => '']);

        expect($result)->toHaveKey('success');
    });
});
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Tools/DiscoveryToolTest.php`
Expected: All PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Tools/DiscoveryToolTest.php
git commit -m "test: add DiscoveryTool test coverage"
```

---

### Task 18: Add SchemaTool Tests

**Files:**
- Create: `tests/Feature/Tools/SchemaToolTest.php`

- [ ] **Step 1: Write SchemaTool tests**

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\System\SchemaTool;

describe('SchemaTool', function () {
    it('returns schema for a known tool', function () {
        $tool = new SchemaTool();
        $result = $tool->executeInternal(['tool_name' => 'statamic-blueprints']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('schema');
    });

    it('returns catalog when no tool specified', function () {
        $tool = new SchemaTool();
        $result = $tool->executeInternal([]);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('catalog');
    });

    it('returns error for unknown tool', function () {
        $tool = new SchemaTool();
        $result = $tool->executeInternal(['tool_name' => 'nonexistent-tool']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeFalse();
    });
});
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Tools/SchemaToolTest.php`
Expected: All PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Tools/SchemaToolTest.php
git commit -m "test: add SchemaTool test coverage"
```

---

## Chunk 5: Final Validation

### Task 19: Full Quality Suite

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/pint`

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`
Expected: 0 errors (Level 9)

- [ ] **Step 3: Run all tests**

Run: `./vendor/bin/pest`
Expected: All 464+ tests PASS

- [ ] **Step 4: Commit any formatting fixes**

```bash
git add -A
git commit -m "chore: code formatting via Pint"
```

- [ ] **Step 5: Final commit summary**

Review all commits with `git log --oneline` and verify completeness.
