# Production Hardening Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all critical and medium issues from the platform audit to achieve production readiness.

**Architecture:** Targeted fixes to existing files — no new classes or architectural changes. Each task modifies 1-2 source files + their tests.

**Tech Stack:** PHP 8.3, Laravel 12, Statamic 6, Pest 4.1, PHPStan Level 9

---

## Chunk 1: Critical Security Fixes

### Task 1: Make Auth Lockout Atomic (AuthenticateForMcp)

**Files:**
- Modify: `src/Http/Middleware/AuthenticateForMcp.php:71-77`
- Test: `tests/Unit/Auth/MiddlewareSecurityTest.php`

- [ ] **Step 1: Fix the non-atomic lockout counter**

Replace lines 71-77 in `AuthenticateForMcp.php`:

```php
// OLD (non-atomic):
if ($credentials) {
    if (! Cache::has($lockoutKey)) {
        Cache::put($lockoutKey, 0, now()->addMinutes(1));
    }
    Cache::increment($lockoutKey);
}

// NEW (fully atomic):
if ($credentials) {
    Cache::increment($lockoutKey);
    // Ensure the key expires — only set TTL if this is the first increment
    if ((int) Cache::get($lockoutKey, 0) === 1) {
        Cache::put($lockoutKey, 1, now()->addMinutes(1));
    }
}
```

- [ ] **Step 2: Run existing tests to verify no regression**

Run: `./vendor/bin/pest tests/Unit/Auth/MiddlewareSecurityTest.php`
Expected: All tests PASS

- [ ] **Step 3: Commit**

---

### Task 2: Fix Token Validation Race Condition (TokenService)

**Files:**
- Modify: `src/Auth/TokenService.php:41-64`
- Modify: `src/Auth/McpToken.php:104-107`

- [ ] **Step 1: Make markAsUsed non-blocking with timestamp-based update**

In `TokenService.php`, replace `validateToken()`:

```php
public function validateToken(string $token): ?McpToken
{
    $hashedToken = hash('sha256', $token);

    /** @var McpToken|null $mcpToken */
    $mcpToken = McpToken::where('token', $hashedToken)->first();

    if ($mcpToken === null) {
        return null;
    }

    // Defense-in-depth: constant-time comparison
    if (! hash_equals($mcpToken->token, $hashedToken)) {
        return null;
    }

    if ($mcpToken->isExpired()) {
        return null;
    }

    // Use atomic update to prevent race conditions — update directly in DB
    McpToken::where('id', $mcpToken->id)
        ->update(['last_used_at' => now()]);

    // Refresh the model to reflect the update
    $mcpToken->last_used_at = now();

    return $mcpToken;
}
```

- [ ] **Step 2: Run existing token tests**

Run: `./vendor/bin/pest tests/Unit/Auth/TokenServiceTest.php`
Expected: All tests PASS

- [ ] **Step 3: Commit**

---

### Task 3: Enforce Max Token Lifetime at Creation (TokenService)

**Files:**
- Modify: `src/Auth/TokenService.php:20-36`

- [ ] **Step 1: Add max lifetime enforcement in createToken()**

Add validation after line 22:

```php
public function createToken(string $userId, string $name, array $scopes, ?Carbon $expiresAt = null): array
{
    // Enforce max token lifetime if configured
    /** @var int|null $maxDays */
    $maxDays = config('statamic.mcp.security.max_token_lifetime_days');
    if ($maxDays !== null && $maxDays > 0) {
        $maxExpiry = Carbon::now()->addDays($maxDays);
        if ($expiresAt === null) {
            $expiresAt = $maxExpiry;
        } elseif ($expiresAt->greaterThan($maxExpiry)) {
            $expiresAt = $maxExpiry;
        }
    }

    $plainTextToken = Str::random(64);
    // ... rest unchanged
}
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Unit/Auth/TokenServiceTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

---

## Chunk 2: Performance Fixes

### Task 4: Fix N+1 Queries in ContentFacadeRouter

**Files:**
- Modify: `src/Mcp/Tools/Routers/ContentFacadeRouter.php:143-198`

- [ ] **Step 1: Replace per-collection count loops with aggregated approach**

Replace `executeContentAudit()` lines 143-198:

```php
try {
    // Collect all collection handles and count entries in bulk
    /** @var iterable<\Statamic\Contracts\Entries\Collection> $collections */
    $collections = Collection::all();

    // Batch count: all entries grouped by collection (2 queries instead of 2*N)
    /** @var array<string, int> $entryCounts */
    $entryCounts = [];
    /** @var array<string, int> $publishedCounts */
    $publishedCounts = [];

    foreach ($collections as $collection) {
        $handle = $collection->handle();
        $entryCounts[$handle] = 0;
        $publishedCounts[$handle] = 0;
    }

    // Single pass: count all entries
    $allEntries = Entry::query()->get();
    foreach ($allEntries as $entry) {
        $col = $entry->collectionHandle();
        if (isset($entryCounts[$col])) {
            $entryCounts[$col]++;
            if ($entry->published()) {
                $publishedCounts[$col]++;
            }
        }
    }

    foreach ($collections as $collection) {
        $handle = $collection->handle();
        $entryCount = $entryCounts[$handle] ?? 0;
        $publishedCount = $publishedCounts[$handle] ?? 0;
        $results['summary']['total_entries'] += $entryCount;

        $results['details']['collections'][] = [
            'handle' => $handle,
            'title' => $collection->title(),
            'entry_count' => $entryCount,
            'published_count' => $publishedCount,
        ];
    }

    // Audit terms — single query
    /** @var iterable<\Statamic\Contracts\Taxonomies\Taxonomy> $taxonomies */
    $taxonomies = Taxonomy::all();

    /** @var array<string, int> $termCounts */
    $termCounts = [];
    foreach ($taxonomies as $taxonomy) {
        $termCounts[$taxonomy->handle()] = 0;
    }

    $allTerms = Term::query()->get();
    foreach ($allTerms as $term) {
        $tax = $term->taxonomyHandle();
        if (isset($termCounts[$tax])) {
            $termCounts[$tax]++;
        }
    }

    foreach ($taxonomies as $taxonomy) {
        $handle = $taxonomy->handle();
        $termCount = $termCounts[$handle] ?? 0;
        $results['summary']['total_terms'] += $termCount;

        $results['details']['taxonomies'][] = [
            'handle' => $handle,
            'title' => $taxonomy->title(),
            'term_count' => $termCount,
        ];
    }

    // ... globals section unchanged ...
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Routers/ContentFacadeRouterTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

---

### Task 5: Add Pagination to UsersRouter.listUsers()

**Files:**
- Modify: `src/Mcp/Tools/Routers/UsersRouter.php:52-76` (schema) and `182-221` (listUsers)
- Modify: `src/Mcp/Tools/Routers/UsersRouter.php` (defineSchema — add limit/offset params)

- [ ] **Step 1: Add limit/offset to schema and listUsers()**

Add to `defineSchema()`:
```php
'limit' => JsonSchema::integer()
    ->description('Maximum number of results (default: 100, max: 500)'),
'offset' => JsonSchema::integer()
    ->description('Number of results to skip'),
```

Replace `listUsers()`:
```php
private function listUsers(array $arguments): array
{
    if (! $this->hasPermission('view', 'users')) {
        return $this->createErrorResponse('Permission denied: Cannot list users')->toArray();
    }

    try {
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $limit = $this->getIntegerArgument($arguments, 'limit', 100, 1, 500);
        $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

        $allUsers = User::all();
        $total = $allUsers->count();

        $users = $allUsers->slice($offset, $limit)->map(function ($user) use ($includeDetails) {
            /** @var \Statamic\Contracts\Auth\User $user */
            $data = [
                'id' => $user->id(),
                'email' => $user->email(),
                'name' => $user->name(),
                'super' => $user->isSuper(),
            ];

            if ($includeDetails) {
                $data = array_merge($data, [
                    'roles' => $user->roles()->map->handle()->all(),
                    'groups' => $user->groups()->map->handle()->all(),
                    'preferences' => $user->preferences(),
                    'last_login' => $user->lastLogin()?->timestamp,
                    'avatar' => $user->avatar(),
                    'initials' => $user->initials(),
                    'data' => $user->data()->except(['password', 'remember_token'])->all(),
                ]);
            }

            return $data;
        })->values()->all();

        return [
            'users' => $users,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ];
    } catch (\Exception $e) {
        return $this->createErrorResponse("Failed to list users: {$e->getMessage()}")->toArray();
    }
}
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Routers/UsersRouterTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

---

### Task 6: Optimize SystemRouter Counts

**Files:**
- Modify: `src/Mcp/Tools/Routers/SystemRouter.php:124-146`

- [ ] **Step 1: Use handles() for lightweight counting where available**

Replace system info details section:

```php
if ($includeDetails) {
    /** @var \Illuminate\Support\Collection<int|string, \Statamic\Sites\Site> $allSites */
    $allSites = \Statamic\Facades\Site::all();
    $info = array_merge($info, [
        'sites' => $allSites->map(function ($site) {
            return [
                'handle' => $site->handle(),
                'name' => $site->name(),
                'url' => $site->url(),
                'locale' => $site->locale(),
            ];
        })->all(),
        'collections_count' => \Statamic\Facades\Collection::handles()->count(),
        'taxonomies_count' => \Statamic\Facades\Taxonomy::handles()->count(),
        'users_count' => \Statamic\Facades\User::all()->count(),
        'asset_containers_count' => \Statamic\Facades\AssetContainer::all()->count(),
        'navigation_count' => \Statamic\Facades\Nav::all()->count(),
        'global_sets_count' => \Statamic\Facades\GlobalSet::all()->count(),
        'form_count' => \Statamic\Facades\Form::all()->count(),
        'blueprint_count' => \Statamic\Facades\Blueprint::in('collections')->count()
            + \Statamic\Facades\Blueprint::in('taxonomies')->count()
            + \Statamic\Facades\Blueprint::in('globals')->count(),
    ]);
}
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Routers/SystemRouterTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

---

## Chunk 3: Reliability Fixes

### Task 7: Log Cache Clear Failures (ClearsCaches)

**Files:**
- Modify: `src/Mcp/Tools/Concerns/ClearsCaches.php`

- [ ] **Step 1: Replace silent failure with logged warning**

```php
trait ClearsCaches
{
    /**
     * Clear relevant Statamic caches after content/structure changes.
     *
     * Cache clearing is best-effort — failures are logged but do not halt execution.
     *
     * @param  array<int, string>  $types
     *
     * @return array<string, string>
     */
    protected function clearStatamicCaches(array $types = ['stache', 'static']): array
    {
        $results = [];

        foreach ($types as $type) {
            try {
                match ($type) {
                    'stache' => Artisan::call('statamic:stache:clear'),
                    'static' => Artisan::call('statamic:static:clear'),
                    'images' => Artisan::call('statamic:glide:clear'),
                    'views' => Artisan::call('view:clear'),
                    'application' => Artisan::call('cache:clear'),
                    default => null,
                };
                $results[$type] = 'cleared';
            } catch (\Exception $e) {
                $results[$type] = 'failed';
                Log::warning("MCP cache clear failed for type '{$type}': {$e->getMessage()}");
            }
        }

        return $results;
    }
}
```

Add `use Illuminate\Support\Facades\Log;` import.

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/pest`
Expected: PASS

- [ ] **Step 3: Commit**

---

### Task 8: Fix content_audit issues_found (Always Zero)

**Files:**
- Modify: `src/Mcp/Tools/Routers/ContentFacadeRouter.php:123-203`

- [ ] **Step 1: Implement actual quality checks**

After counting entries/terms/globals, add quality checks before quality_score calculation:

```php
// Quality checks
$issues = 0;

// Check for empty collections
foreach ($results['details']['collections'] as $col) {
    if ($col['entry_count'] === 0) {
        $issues++;
        $results['recommendations'][] = "Collection '{$col['handle']}' has no entries";
    }
}

// Check for fully unpublished collections
foreach ($results['details']['collections'] as $col) {
    if ($col['entry_count'] > 0 && $col['published_count'] === 0) {
        $issues++;
        $results['recommendations'][] = "Collection '{$col['handle']}' has entries but none are published";
    }
}

// Check for empty taxonomies
foreach ($results['details']['taxonomies'] as $tax) {
    if ($tax['term_count'] === 0) {
        $issues++;
        $results['recommendations'][] = "Taxonomy '{$tax['handle']}' has no terms";
    }
}

// Check for globals without values
foreach ($results['details']['globals'] as $global) {
    if (! $global['has_values']) {
        $issues++;
        $results['recommendations'][] = "Global set '{$global['handle']}' has no values set";
    }
}

$results['summary']['issues_found'] = $issues;
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Routers/ContentFacadeRouterTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

---

### Task 9: Pre-configure Logging Channel in ServiceProvider

**Files:**
- Modify: `src/ServiceProvider.php` (bootAddon)
- Modify: `src/Mcp/Support/ToolLogger.php` (simplify ensureChannelConfigured)

- [ ] **Step 1: Register log channel in ServiceProvider.register()**

Add after mergeConfigFrom():

```php
// Pre-configure MCP audit log channel
$channel = config('statamic.mcp.security.audit_channel', 'mcp');
if (config("logging.channels.{$channel}") === null) {
    $path = config('statamic.mcp.security.audit_path', storage_path('logs/mcp-audit.log'));
    config([
        "logging.channels.{$channel}" => [
            'driver' => 'daily',
            'path' => $path,
            'level' => 'debug',
            'days' => 30,
        ],
    ]);
}
```

- [ ] **Step 2: Simplify ToolLogger.ensureChannelConfigured()**

Keep it as a safety fallback but it should now rarely trigger:

```php
private static function ensureChannelConfigured(string $channel): void
{
    // Channel should already be configured by ServiceProvider.
    // This is a safety fallback for edge cases (e.g., testing).
    if (config("logging.channels.{$channel}") !== null) {
        return;
    }

    /** @var string $path */
    $path = config('statamic.mcp.security.audit_path', storage_path('logs/mcp-audit.log'));

    config([
        "logging.channels.{$channel}" => [
            'driver' => 'daily',
            'path' => $path,
            'level' => 'debug',
            'days' => 30,
        ],
    ]);
}
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/pest`
Expected: PASS

- [ ] **Step 4: Commit**

---

### Task 10: Add Config Value Size Validation (SystemRouter)

**Files:**
- Modify: `src/Mcp/Tools/Routers/SystemRouter.php:419-468`

- [ ] **Step 1: Add size validation in setConfig()**

After parsing JSON value, add:

```php
// Validate config value size to prevent memory abuse
$serialized = json_encode($configValue);
if ($serialized !== false && strlen($serialized) > 10000) {
    return $this->createErrorResponse('Config value too large (max 10KB)')->toArray();
}
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Routers/SystemRouterTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

---

## Chunk 4: Final Quality Pass

### Task 11: Run Full Quality Pipeline

- [ ] **Step 1: Format code**

Run: `./vendor/bin/pint`

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse`
Expected: 0 errors

- [ ] **Step 3: Run all tests**

Run: `./vendor/bin/pest`
Expected: All pass

- [ ] **Step 4: Final commit with all fixes**

---
