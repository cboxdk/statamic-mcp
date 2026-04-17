# Confirmation Tokens & Resource Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add stateless HMAC confirmation tokens for destructive operations and granular resource-level authorization with field filtering to the Statamic MCP addon.

**Architecture:** Two new services (`ConfirmationTokenManager` and `ResourcePolicy`) in `src/Auth/`, integrated into the router flow via two new traits in `src/Mcp/Tools/Concerns/`. Config extended with `confirmation` section and per-domain `resources`/`denied_fields`. Both services are registered as singletons in `ServiceProvider`. Environment-aware defaults: confirmation auto-enabled in production, disabled in local/dev/testing.

**Tech Stack:** PHP 8.3, Laravel 12, Statamic v6, Pest 4, PHPStan Level 8

**Spec:** `docs/superpowers/specs/2026-04-17-confirmation-tokens-and-resource-policy-design.md`

---

## File Map

### New Files
| File | Responsibility |
|------|---------------|
| `src/Auth/ConfirmationTokenManager.php` | Stateless HMAC-SHA256 token generation and validation |
| `src/Auth/ResourcePolicy.php` | Glob-based resource access checks + recursive field filtering |
| `src/Mcp/Tools/Concerns/RequiresConfirmation.php` | Trait: confirmation flow logic for routers |
| `src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php` | Trait: resource access + field filtering for routers |
| `tests/Unit/Auth/ConfirmationTokenManagerTest.php` | Unit tests for token generation/validation/expiry/tampering |
| `tests/Unit/Auth/ResourcePolicyTest.php` | Unit tests for glob matching, field filtering, defaults |
| `tests/Integration/ConfirmationFlowTest.php` | Integration tests: confirmation flow through routers |
| `tests/Integration/ResourcePolicyEnforcementTest.php` | Integration tests: resource policy through routers |

### Modified Files
| File | Change |
|------|--------|
| `config/statamic/mcp.php` | Add `confirmation` section; add `resources` + `denied_fields` to each tool domain |
| `src/ServiceProvider.php` | Register `ConfirmationTokenManager` and `ResourcePolicy` as singletons |
| `src/Mcp/Tools/BaseRouter.php` | Use new traits; wire confirmation + resource policy into `executeInternal()` |
| `src/Mcp/Tools/Routers/BlueprintsRouter.php` | Remove old `confirm` parameter from schema and `deleteBlueprint()` |

---

## Task 1: ConfirmationTokenManager Service

**Files:**
- Create: `src/Auth/ConfirmationTokenManager.php`
- Test: `tests/Unit/Auth/ConfirmationTokenManagerTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Auth/ConfirmationTokenManagerTest.php`:

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\ConfirmationTokenManager;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    Config::set('statamic.mcp.confirmation.ttl', 300);
});

// ---------------------------------------------------------------------------
// Generation
// ---------------------------------------------------------------------------

it('generates a non-empty base64url token', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);

    expect($token)->toBeString()->not->toBeEmpty();
});

it('generates different tokens for different arguments', function (): void {
    $manager = new ConfirmationTokenManager;

    $token1 = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);
    $token2 = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'contact']);

    expect($token1)->not->toBe($token2);
});

it('generates different tokens for different tools', function (): void {
    $manager = new ConfirmationTokenManager;

    $token1 = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);
    $token2 = $manager->generate('statamic-blueprints', ['action' => 'delete', 'handle' => 'about']);

    expect($token1)->not->toBe($token2);
});

// ---------------------------------------------------------------------------
// Validation — success
// ---------------------------------------------------------------------------

it('validates a token that was just generated', function (): void {
    $manager = new ConfirmationTokenManager;

    $args = ['action' => 'delete', 'handle' => 'about'];
    $token = $manager->generate('statamic-entries', $args);

    expect($manager->validate($token, 'statamic-entries', $args))->toBeTrue();
});

it('strips confirmation_token key before validating', function (): void {
    $manager = new ConfirmationTokenManager;

    $args = ['action' => 'delete', 'handle' => 'about'];
    $token = $manager->generate('statamic-entries', $args);

    // Agent sends back arguments including the token itself
    $argsWithToken = array_merge($args, ['confirmation_token' => $token]);

    expect($manager->validate($token, 'statamic-entries', $argsWithToken))->toBeTrue();
});

it('validates with arguments in different key order', function (): void {
    $manager = new ConfirmationTokenManager;

    $args1 = ['action' => 'delete', 'handle' => 'about'];
    $token = $manager->generate('statamic-entries', $args1);

    // Same arguments, different key order
    $args2 = ['handle' => 'about', 'action' => 'delete'];

    expect($manager->validate($token, 'statamic-entries', $args2))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Validation — failure
// ---------------------------------------------------------------------------

it('rejects a tampered token', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);
    $tampered = $token . 'x';

    expect($manager->validate($tampered, 'statamic-entries', ['action' => 'delete', 'handle' => 'about']))->toBeFalse();
});

it('rejects a token with modified arguments', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);

    // Different handle
    expect($manager->validate($token, 'statamic-entries', ['action' => 'delete', 'handle' => 'contact']))->toBeFalse();
});

it('rejects a token with wrong tool name', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);

    expect($manager->validate($token, 'statamic-blueprints', ['action' => 'delete', 'handle' => 'about']))->toBeFalse();
});

it('rejects an expired token', function (): void {
    Config::set('statamic.mcp.confirmation.ttl', 1); // 1 second TTL

    $manager = new ConfirmationTokenManager;

    $args = ['action' => 'delete', 'handle' => 'about'];
    $token = $manager->generate('statamic-entries', $args);

    // Wait for expiry
    sleep(2);

    expect($manager->validate($token, 'statamic-entries', $args))->toBeFalse();
});

it('rejects an empty string token', function (): void {
    $manager = new ConfirmationTokenManager;

    expect($manager->validate('', 'statamic-entries', ['action' => 'delete']))->toBeFalse();
});

it('rejects a malformed token without separator', function (): void {
    $manager = new ConfirmationTokenManager;

    expect($manager->validate('notavalidtoken', 'statamic-entries', ['action' => 'delete']))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Environment-aware enabled check
// ---------------------------------------------------------------------------

it('reports enabled in production when config is null', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', null);
    app()['env'] = 'production';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeTrue();
});

it('reports disabled in local when config is null', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', null);
    app()['env'] = 'local';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeFalse();
});

it('reports disabled in testing when config is null', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', null);
    app()['env'] = 'testing';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeFalse();
});

it('respects explicit true override regardless of environment', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', true);
    app()['env'] = 'local';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeTrue();
});

it('respects explicit false override regardless of environment', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', false);
    app()['env'] = 'production';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Auth/ConfirmationTokenManagerTest.php`
Expected: FAIL — class `ConfirmationTokenManager` does not exist.

- [ ] **Step 3: Implement ConfirmationTokenManager**

Create `src/Auth/ConfirmationTokenManager.php`:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

class ConfirmationTokenManager
{
    /**
     * Generate an HMAC-signed confirmation token for a specific tool + arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function generate(string $tool, array $arguments): string
    {
        $timestamp = time();
        $payload = $this->buildPayload($tool, $arguments, $timestamp);

        $signature = hash_hmac('sha256', $payload, $this->getKey());

        return base64_encode($timestamp . '.' . $signature);
    }

    /**
     * Validate a confirmation token against a tool + arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function validate(string $token, string $tool, array $arguments): bool
    {
        if ($token === '') {
            return false;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $dotPos = strpos($decoded, '.');
        if ($dotPos === false) {
            return false;
        }

        $timestampStr = substr($decoded, 0, $dotPos);
        $signature = substr($decoded, $dotPos + 1);

        if (! is_numeric($timestampStr) || $signature === '') {
            return false;
        }

        $timestamp = (int) $timestampStr;

        // Check expiry
        /** @var int $ttl */
        $ttl = config('statamic.mcp.confirmation.ttl', 300);
        if ((time() - $timestamp) > $ttl) {
            return false;
        }

        // Rebuild payload and compare signatures
        $expectedPayload = $this->buildPayload($tool, $arguments, $timestamp);
        $expectedSignature = hash_hmac('sha256', $expectedPayload, $this->getKey());

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Check if confirmation tokens are enabled for the current environment.
     */
    public function isEnabled(): bool
    {
        /** @var bool|null $enabled */
        $enabled = config('statamic.mcp.confirmation.enabled');

        if ($enabled !== null) {
            return (bool) $enabled;
        }

        // Auto-detect: enabled in production only
        return app()->environment('production');
    }

    /**
     * Build the canonical payload string for HMAC signing.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function buildPayload(string $tool, array $arguments, int $timestamp): string
    {
        // Strip the confirmation_token from arguments before canonicalizing
        unset($arguments['confirmation_token']);

        // Sort keys for canonical ordering
        ksort($arguments);

        $canonical = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $tool . '|' . $canonical . '|' . $timestamp;
    }

    /**
     * Get the application key used for HMAC signing.
     */
    private function getKey(): string
    {
        /** @var string $key */
        $key = config('app.key', '');

        // Strip the base64: prefix if present
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false ? $decoded : $key;
        }

        return $key;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Auth/ConfirmationTokenManagerTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Auth/ConfirmationTokenManager.php --level 8`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Auth/ConfirmationTokenManager.php tests/Unit/Auth/ConfirmationTokenManagerTest.php
git commit -m "feat(auth): add stateless HMAC confirmation token manager

Adds ConfirmationTokenManager with generate/validate/isEnabled methods.
Tokens are cryptographically bound to tool + arguments, expire via TTL,
and auto-detect production vs development environments."
```

---

## Task 2: ResourcePolicy Service

**Files:**
- Create: `src/Auth/ResourcePolicy.php`
- Test: `tests/Unit/Auth/ResourcePolicyTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Auth/ResourcePolicyTest.php`:

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    // Default config: everything open
    Config::set('statamic.mcp.tools', [
        'entries' => [
            'enabled' => true,
            'resources' => ['read' => ['*'], 'write' => ['*']],
            'denied_fields' => [],
        ],
        'blueprints' => [
            'enabled' => true,
            'resources' => ['read' => ['*'], 'write' => ['*']],
            'denied_fields' => [],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// canAccess — defaults (everything open)
// ---------------------------------------------------------------------------

it('allows read access with wildcard default', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'blog', 'read'))->toBeTrue();
});

it('allows write access with wildcard default', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'blog', 'write'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// canAccess — restricted resources
// ---------------------------------------------------------------------------

it('allows write when handle matches glob pattern', function (): void {
    Config::set('statamic.mcp.tools.entries.resources.write', ['blog*', 'pages']);

    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'blog', 'write'))->toBeTrue();
    expect($policy->canAccess('entries', 'blog-archive', 'write'))->toBeTrue();
    expect($policy->canAccess('entries', 'pages', 'write'))->toBeTrue();
});

it('denies write when handle does not match any glob', function (): void {
    Config::set('statamic.mcp.tools.entries.resources.write', ['blog*', 'pages']);

    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'products', 'write'))->toBeFalse();
    expect($policy->canAccess('entries', 'my-blog', 'write'))->toBeFalse();
});

it('allows read even when write is restricted', function (): void {
    Config::set('statamic.mcp.tools.entries.resources.write', ['blog*']);
    Config::set('statamic.mcp.tools.entries.resources.read', ['*']);

    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'products', 'read'))->toBeTrue();
    expect($policy->canAccess('entries', 'products', 'write'))->toBeFalse();
});

it('denies access when resource list is empty', function (): void {
    Config::set('statamic.mcp.tools.entries.resources.write', []);

    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'blog', 'write'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// canAccess — unconfigured domain falls back to allow all
// ---------------------------------------------------------------------------

it('allows access for unconfigured domain', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->canAccess('terms', 'tags', 'write'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// getDeniedFields
// ---------------------------------------------------------------------------

it('returns empty array when no denied fields configured', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->getDeniedFields('entries'))->toBe([]);
});

it('returns configured denied fields', function (): void {
    Config::set('statamic.mcp.tools.entries.denied_fields', ['internal_notes', 'api_key']);

    $policy = new ResourcePolicy;

    expect($policy->getDeniedFields('entries'))->toBe(['internal_notes', 'api_key']);
});

it('returns empty array for unconfigured domain', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->getDeniedFields('terms'))->toBe([]);
});

// ---------------------------------------------------------------------------
// filterFields — flat data
// ---------------------------------------------------------------------------

it('strips denied fields from flat data', function (): void {
    Config::set('statamic.mcp.tools.entries.denied_fields', ['secret', 'internal']);

    $policy = new ResourcePolicy;

    $data = ['title' => 'Hello', 'secret' => 'hidden', 'internal' => 'notes', 'slug' => 'hello'];
    $filtered = $policy->filterFields('entries', $data);

    expect($filtered)->toBe(['title' => 'Hello', 'slug' => 'hello']);
});

it('returns data unchanged when no denied fields', function (): void {
    $policy = new ResourcePolicy;

    $data = ['title' => 'Hello', 'slug' => 'hello'];
    $filtered = $policy->filterFields('entries', $data);

    expect($filtered)->toBe($data);
});

// ---------------------------------------------------------------------------
// filterFields — nested data (Bard/Replicator/Grid)
// ---------------------------------------------------------------------------

it('strips denied fields from nested arrays recursively', function (): void {
    Config::set('statamic.mcp.tools.entries.denied_fields', ['secret']);

    $policy = new ResourcePolicy;

    $data = [
        'title' => 'Post',
        'content' => [
            ['type' => 'text', 'secret' => 'hidden', 'text' => 'visible'],
            ['type' => 'image', 'url' => '/img.png'],
        ],
        'secret' => 'top-level-hidden',
    ];

    $filtered = $policy->filterFields('entries', $data);

    expect($filtered)->toBe([
        'title' => 'Post',
        'content' => [
            ['type' => 'text', 'text' => 'visible'],
            ['type' => 'image', 'url' => '/img.png'],
        ],
    ]);
});

it('handles deeply nested structures', function (): void {
    Config::set('statamic.mcp.tools.entries.denied_fields', ['price']);

    $policy = new ResourcePolicy;

    $data = [
        'title' => 'Product',
        'sections' => [
            'main' => [
                'fields' => [
                    'name' => 'Widget',
                    'price' => 99.99,
                    'details' => [
                        'price' => 'also hidden',
                        'description' => 'A widget',
                    ],
                ],
            ],
        ],
    ];

    $filtered = $policy->filterFields('entries', $data);

    expect($filtered['sections']['main']['fields'])->not->toHaveKey('price');
    expect($filtered['sections']['main']['fields']['details'])->not->toHaveKey('price');
    expect($filtered['sections']['main']['fields']['details']['description'])->toBe('A widget');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Auth/ResourcePolicyTest.php`
Expected: FAIL — class `ResourcePolicy` does not exist.

- [ ] **Step 3: Implement ResourcePolicy**

Create `src/Auth/ResourcePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

class ResourcePolicy
{
    /**
     * Check if a specific resource is accessible for the given mode.
     */
    public function canAccess(string $domain, string $resource, string $mode): bool
    {
        /** @var array<int, string>|null $patterns */
        $patterns = config("statamic.mcp.tools.{$domain}.resources.{$mode}");

        // Unconfigured domain/mode defaults to allow all
        if ($patterns === null) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $resource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the list of denied fields for a domain.
     *
     * @return array<int, string>
     */
    public function getDeniedFields(string $domain): array
    {
        /** @var array<int, string> $fields */
        $fields = config("statamic.mcp.tools.{$domain}.denied_fields", []);

        return $fields;
    }

    /**
     * Recursively strip denied fields from data.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    public function filterFields(string $domain, array $data): array
    {
        $deniedFields = $this->getDeniedFields($domain);

        if ($deniedFields === []) {
            return $data;
        }

        return $this->recursiveFilter($data, $deniedFields);
    }

    /**
     * Recursively remove denied keys from an array structure.
     *
     * @param  array<mixed>  $data
     * @param  array<int, string>  $deniedFields
     *
     * @return array<mixed>
     */
    private function recursiveFilter(array $data, array $deniedFields): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            // Strip denied string keys
            if (is_string($key) && in_array($key, $deniedFields, true)) {
                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->recursiveFilter($value, $deniedFields);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Auth/ResourcePolicyTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Auth/ResourcePolicy.php --level 8`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Auth/ResourcePolicy.php tests/Unit/Auth/ResourcePolicyTest.php
git commit -m "feat(auth): add ResourcePolicy for granular resource authorization

Glob-based resource allowlists (read/write per domain) and recursive
field deny list filtering. Unconfigured domains default to allow-all
for backwards compatibility."
```

---

## Task 3: Config Extension

**Files:**
- Modify: `config/statamic/mcp.php`

- [ ] **Step 1: Add confirmation section to config**

In `config/statamic/mcp.php`, add the `confirmation` section after the `security` section (after line 110, before the `rate_limit` section at line 121):

```php
    /*
    |--------------------------------------------------------------------------
    | Confirmation Tokens
    |--------------------------------------------------------------------------
    |
    | Require a two-step confirmation flow for destructive operations.
    | Uses stateless HMAC-SHA256 tokens bound to the exact operation.
    |
    | When enabled is null (default), confirmation is auto-detected:
    | enabled in production, disabled in local/development/testing/staging.
    | Set to true/false to override.
    |
    */
    'confirmation' => [
        'enabled' => env('STATAMIC_MCP_CONFIRMATION_ENABLED', null),
        'ttl' => (int) env('STATAMIC_MCP_CONFIRMATION_TTL', 300),
    ],
```

- [ ] **Step 2: Add resources and denied_fields to each tool domain**

Replace the entire `tools` section (lines 162-190) with:

```php
    'tools' => [
        'blueprints' => [
            'enabled' => env('STATAMIC_MCP_TOOL_BLUEPRINTS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'entries' => [
            'enabled' => env('STATAMIC_MCP_TOOL_ENTRIES_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'terms' => [
            'enabled' => env('STATAMIC_MCP_TOOL_TERMS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'globals' => [
            'enabled' => env('STATAMIC_MCP_TOOL_GLOBALS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'structures' => [
            'enabled' => env('STATAMIC_MCP_TOOL_STRUCTURES_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'assets' => [
            'enabled' => env('STATAMIC_MCP_TOOL_ASSETS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'users' => [
            'enabled' => env('STATAMIC_MCP_TOOL_USERS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'system' => [
            'enabled' => env('STATAMIC_MCP_TOOL_SYSTEM_ENABLED', true),
        ],
        'content-facade' => [
            'enabled' => env('STATAMIC_MCP_TOOL_CONTENT_FACADE_ENABLED', true),
        ],
    ],
```

- [ ] **Step 3: Verify config loads correctly**

Run: `./vendor/bin/pest tests/Unit/Auth/ConfirmationTokenManagerTest.php tests/Unit/Auth/ResourcePolicyTest.php`
Expected: All tests still PASS (they use `Config::set()` in beforeEach).

- [ ] **Step 4: Commit**

```bash
git add config/statamic/mcp.php
git commit -m "config: add confirmation tokens and resource policy settings

Adds confirmation section (enabled auto-detect, 300s TTL) and extends
each tool domain with resources (read/write glob lists) and denied_fields.
Defaults are fully backwards compatible (everything open)."
```

---

## Task 4: Register Services in ServiceProvider

**Files:**
- Modify: `src/ServiceProvider.php:118-161`

- [ ] **Step 1: Add imports and singleton registrations**

Add imports at the top of `src/ServiceProvider.php` (after the existing `use` statements around line 13):

```php
use Cboxdk\StatamicMcp\Auth\ConfirmationTokenManager;
use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
```

Add singleton registrations in the `register()` method, after the CIMD resolver registration (after line 157, before line 159 `$this->app->register(AuthServiceProvider::class)`):

```php
        // Register confirmation token manager
        $this->app->singleton(ConfirmationTokenManager::class);

        // Register resource policy
        $this->app->singleton(ResourcePolicy::class);
```

- [ ] **Step 2: Run existing tests to verify no regression**

Run: `./vendor/bin/pest tests/Unit/Auth/`
Expected: All tests PASS.

- [ ] **Step 3: Run PHPStan on ServiceProvider**

Run: `./vendor/bin/phpstan analyse src/ServiceProvider.php --level 8`
Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add src/ServiceProvider.php
git commit -m "feat: register ConfirmationTokenManager and ResourcePolicy singletons"
```

---

## Task 5: RequiresConfirmation Trait

**Files:**
- Create: `src/Mcp/Tools/Concerns/RequiresConfirmation.php`
- Test: `tests/Integration/ConfirmationFlowTest.php`

- [ ] **Step 1: Write the integration tests**

Create `tests/Integration/ConfirmationFlowTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

class ConfirmationFlowTest extends TestCase
{
    use CreatesTestContent;
    use RefreshDatabase;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/tokens');

        // Enable web context + confirmation
        Config::set('statamic.mcp.web.enabled', true);
        Config::set('statamic.mcp.confirmation.enabled', true);
        Config::set('statamic.mcp.confirmation.ttl', 300);
        request()->headers->set('X-MCP-Remote', 'true');

        $this->collectionHandle = 'confirm_test_blog';
        $this->createTestCollection($this->collectionHandle);
        $this->createTestBlueprint($this->collectionHandle);

        // Authenticate with full access
        $user = $this->createMockSuperUser();
        auth()->shouldUse('web');
        auth()->setUser($user);

        $token = $this->createTokenWithScopes([TokenScope::FullAccess->value]);
        request()->attributes->set('mcp_token', $token);
    }

    protected function tearDown(): void
    {
        request()->headers->remove('X-MCP-Remote');
        request()->attributes->remove('mcp_token');

        parent::tearDown();
    }

    public function test_delete_entry_requires_confirmation_token(): void
    {
        // Create an entry first
        $router = new EntriesRouter;
        $createResult = $router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'To Be Deleted'],
        ]);
        $this->assertTrue($createResult['success']);
        $slug = $createResult['data']['slug'];

        // Try to delete without confirmation token
        $deleteResult = $router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'slug' => $slug,
        ]);

        // Should get confirmation request, not an error
        $this->assertFalse($deleteResult['success']);
        $this->assertTrue($deleteResult['data']['requires_confirmation'] ?? false);
        $this->assertArrayHasKey('confirmation_token', $deleteResult['data']);
        $this->assertArrayHasKey('description', $deleteResult['data']);
        $this->assertArrayHasKey('expires_in', $deleteResult['data']);
    }

    public function test_delete_entry_succeeds_with_valid_confirmation_token(): void
    {
        $router = new EntriesRouter;
        $createResult = $router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Confirm Delete'],
        ]);
        $this->assertTrue($createResult['success']);
        $slug = $createResult['data']['slug'];

        // First call: get confirmation token
        $firstResult = $router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'slug' => $slug,
        ]);
        $confirmationToken = $firstResult['data']['confirmation_token'];

        // Second call: provide the token
        $deleteResult = $router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'slug' => $slug,
            'confirmation_token' => $confirmationToken,
        ]);

        $this->assertTrue($deleteResult['success']);
    }

    public function test_delete_entry_skips_confirmation_in_cli_context(): void
    {
        // Switch to CLI context
        request()->headers->remove('X-MCP-Remote');
        request()->attributes->remove('mcp_token');
        Config::set('statamic.mcp.security.force_web_mode', false);

        $router = new EntriesRouter;
        $createResult = $router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'CLI Delete'],
        ]);
        $slug = $createResult['data']['slug'];

        // Should delete directly without confirmation
        $deleteResult = $router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'slug' => $slug,
        ]);

        $this->assertTrue($deleteResult['success']);
    }

    public function test_delete_entry_skips_confirmation_when_disabled(): void
    {
        Config::set('statamic.mcp.confirmation.enabled', false);

        $router = new EntriesRouter;
        $createResult = $router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'No Confirm Delete'],
        ]);
        $slug = $createResult['data']['slug'];

        $deleteResult = $router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'slug' => $slug,
        ]);

        $this->assertTrue($deleteResult['success']);
    }

    public function test_blueprint_create_requires_confirmation(): void
    {
        $router = new BlueprintsRouter;

        $result = $router->execute([
            'action' => 'create',
            'handle' => 'confirm_test_bp',
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['data']['requires_confirmation'] ?? false);
    }

    /**
     * Create a mock super admin user.
     */
    private function createMockSuperUser(): Authenticatable
    {
        return new class implements Authenticatable
        {
            public function isSuper(): bool { return true; }

            public function hasPermission(string $permission): bool { return true; }

            public function getAuthIdentifierName(): string { return 'id'; }

            public function getAuthIdentifier(): string { return 'confirm-test-user'; }

            public function getAuthPasswordName(): string { return 'password'; }

            public function getAuthPassword(): string { return 'hashed'; }

            public function getRememberToken(): ?string { return null; }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string { return 'remember_token'; }
        };
    }

    /**
     * @param  array<int, string>  $scopeValues
     */
    private function createTokenWithScopes(array $scopeValues): McpTokenData
    {
        $model = McpToken::create([
            'user_id' => 'confirm-test-user',
            'name' => 'Test Token',
            'token' => hash('sha256', 'test-token-' . bin2hex(random_bytes(8))),
            'scopes' => $scopeValues,
        ]);

        /** @var \DateTimeInterface $createdAt */
        $createdAt = $model->created_at;

        return new McpTokenData(
            id: $model->id,
            userId: $model->user_id,
            name: $model->name,
            tokenHash: $model->token,
            scopes: $model->scopes,
            lastUsedAt: $model->last_used_at instanceof \DateTimeInterface ? Carbon::instance($model->last_used_at) : null,
            expiresAt: $model->expires_at instanceof \DateTimeInterface ? Carbon::instance($model->expires_at) : null,
            createdAt: Carbon::instance($createdAt),
            updatedAt: $model->updated_at instanceof \DateTimeInterface ? Carbon::instance($model->updated_at) : null,
        );
    }
}
```

- [ ] **Step 2: Create the RequiresConfirmation trait**

Create `src/Mcp/Tools/Concerns/RequiresConfirmation.php`:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Auth\ConfirmationTokenManager;

/**
 * Provides confirmation token flow for destructive router actions.
 *
 * Routers using this trait call handleConfirmation() early in action methods.
 * Returns a confirmation request array if no valid token is provided, or null
 * if confirmation was successful (proceed with execution).
 */
trait RequiresConfirmation
{
    /**
     * Check if the given action requires confirmation for this router.
     */
    protected function requiresConfirmation(string $action): bool
    {
        // All routers: delete requires confirmation
        if ($action === 'delete') {
            return true;
        }

        // BlueprintsRouter: create and update also require confirmation
        if ($this->getDomain() === 'blueprints' && in_array($action, ['create', 'update'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Handle the confirmation flow for a destructive action.
     *
     * Returns a confirmation request array if the agent needs to confirm,
     * or null if confirmation is valid or not required (proceed with action).
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    protected function handleConfirmation(string $action, array $arguments): ?array
    {
        // Skip if confirmation is not required for this action
        if (! $this->requiresConfirmation($action)) {
            return null;
        }

        /** @var ConfirmationTokenManager $manager */
        $manager = app(ConfirmationTokenManager::class);

        // Skip if confirmation is disabled (env-aware)
        if (! $manager->isEnabled()) {
            return null;
        }

        // Skip in CLI context
        if ($this->isCliContext()) {
            return null;
        }

        // Check if a valid confirmation token was provided
        $token = $arguments['confirmation_token'] ?? null;
        if (is_string($token) && $token !== '') {
            $toolName = $this->name();
            if ($manager->validate($token, $toolName, $arguments)) {
                return null; // Token valid, proceed
            }

            // Invalid or expired token
            return $this->buildConfirmationErrorResponse(
                'Invalid or expired confirmation token. Request a new one by calling without confirmation_token.',
            );
        }

        // No token provided — generate one and return confirmation request
        $toolName = $this->name();
        $confirmationToken = $manager->generate($toolName, $arguments);

        /** @var int $ttl */
        $ttl = config('statamic.mcp.confirmation.ttl', 300);

        return $this->buildConfirmationResponse(
            $confirmationToken,
            $this->buildConfirmationDescription($action, $arguments),
            $ttl,
        );
    }

    /**
     * Build a human-readable description of what the confirmed action will do.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function buildConfirmationDescription(string $action, array $arguments): string
    {
        $domain = $this->getDomain();
        $handle = $arguments['handle'] ?? $arguments['slug'] ?? $arguments['id'] ?? 'unknown';
        $handle = is_string($handle) ? $handle : 'unknown';

        $resourceContext = '';
        if (isset($arguments['collection']) && is_string($arguments['collection'])) {
            $resourceContext = " from collection '{$arguments['collection']}'";
        } elseif (isset($arguments['taxonomy']) && is_string($arguments['taxonomy'])) {
            $resourceContext = " from taxonomy '{$arguments['taxonomy']}'";
        } elseif (isset($arguments['namespace']) && is_string($arguments['namespace'])) {
            $resourceContext = " in namespace '{$arguments['namespace']}'";
        } elseif (isset($arguments['container']) && is_string($arguments['container'])) {
            $resourceContext = " from container '{$arguments['container']}'";
        }

        $actionLabel = match ($action) {
            'delete' => "Delete {$domain} '{$handle}'{$resourceContext}. This action cannot be undone.",
            'create' => "Create {$domain} '{$handle}'{$resourceContext}.",
            'update' => "Update {$domain} '{$handle}'{$resourceContext}.",
            default => ucfirst($action) . " {$domain} '{$handle}'{$resourceContext}.",
        };

        return $actionLabel;
    }

    /**
     * Build the confirmation request response.
     *
     * @return array<string, mixed>
     */
    private function buildConfirmationResponse(string $token, string $description, int $ttl): array
    {
        return $this->createErrorResponse(
            "This operation requires confirmation. Resubmit with the provided confirmation_token to proceed.",
            [
                'requires_confirmation' => true,
                'confirmation_token' => $token,
                'description' => $description,
                'expires_in' => $ttl,
            ],
        )->toArray();
    }

    /**
     * Build error response for invalid confirmation token.
     *
     * @return array<string, mixed>
     */
    private function buildConfirmationErrorResponse(string $message): array
    {
        return $this->createErrorResponse($message)->toArray();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail (trait exists but not wired)**

Run: `./vendor/bin/pest tests/Integration/ConfirmationFlowTest.php`
Expected: FAIL — routers don't call `handleConfirmation()` yet (tests expecting `requires_confirmation` will fail).

- [ ] **Step 4: Run PHPStan on the trait**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/Concerns/RequiresConfirmation.php --level 8`
Expected: No errors (or address any type issues).

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/Tools/Concerns/RequiresConfirmation.php tests/Integration/ConfirmationFlowTest.php
git commit -m "feat: add RequiresConfirmation trait and integration tests

Trait provides handleConfirmation() for routers to call before
destructive actions. Not yet wired into BaseRouter."
```

---

## Task 6: EnforcesResourcePolicy Trait

**Files:**
- Create: `src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php`
- Test: `tests/Integration/ResourcePolicyEnforcementTest.php`

- [ ] **Step 1: Write the integration tests**

Create `tests/Integration/ResourcePolicyEnforcementTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

class ResourcePolicyEnforcementTest extends TestCase
{
    use CreatesTestContent;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/tokens');

        // Enable web context, disable confirmation for these tests
        Config::set('statamic.mcp.web.enabled', true);
        Config::set('statamic.mcp.confirmation.enabled', false);
        request()->headers->set('X-MCP-Remote', 'true');

        // Create test collections
        $this->createTestCollection('rp_blog');
        $this->createTestBlueprint('rp_blog');
        $this->createTestCollection('rp_products');
        $this->createTestBlueprint('rp_products');

        // Authenticate with full access
        $user = $this->createMockSuperUser();
        auth()->shouldUse('web');
        auth()->setUser($user);

        $token = $this->createTokenWithScopes([TokenScope::FullAccess->value]);
        request()->attributes->set('mcp_token', $token);
    }

    protected function tearDown(): void
    {
        request()->headers->remove('X-MCP-Remote');
        request()->attributes->remove('mcp_token');

        parent::tearDown();
    }

    public function test_write_denied_for_restricted_collection(): void
    {
        Config::set('statamic.mcp.tools.entries.resources.write', ['rp_blog']);

        $router = new EntriesRouter;

        $result = $router->execute([
            'action' => 'create',
            'collection' => 'rp_products',
            'data' => ['title' => 'Should Be Denied'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not permitted', $result['errors'][0] ?? '');
    }

    public function test_write_allowed_for_permitted_collection(): void
    {
        Config::set('statamic.mcp.tools.entries.resources.write', ['rp_blog']);

        $router = new EntriesRouter;

        $result = $router->execute([
            'action' => 'create',
            'collection' => 'rp_blog',
            'data' => ['title' => 'Allowed Entry'],
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_read_allowed_even_when_write_restricted(): void
    {
        Config::set('statamic.mcp.tools.entries.resources.read', ['*']);
        Config::set('statamic.mcp.tools.entries.resources.write', ['rp_blog']);

        $router = new EntriesRouter;

        $result = $router->execute([
            'action' => 'list',
            'collection' => 'rp_products',
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_denied_fields_stripped_from_output(): void
    {
        Config::set('statamic.mcp.tools.entries.denied_fields', ['title']);

        $router = new EntriesRouter;

        // Create entry first
        $createResult = $router->execute([
            'action' => 'create',
            'collection' => 'rp_blog',
            'data' => ['title' => 'Field Filter Test'],
        ]);
        $this->assertTrue($createResult['success']);
        $slug = $createResult['data']['slug'];

        // Get entry — title field should be stripped from response
        $getResult = $router->execute([
            'action' => 'get',
            'collection' => 'rp_blog',
            'slug' => $slug,
        ]);

        $this->assertTrue($getResult['success']);
        $this->assertArrayNotHasKey('title', $getResult['data']['data'] ?? []);
    }

    public function test_resource_policy_applies_in_cli_context(): void
    {
        // Switch to CLI context
        request()->headers->remove('X-MCP-Remote');
        request()->attributes->remove('mcp_token');
        Config::set('statamic.mcp.security.force_web_mode', false);

        Config::set('statamic.mcp.tools.entries.resources.write', ['rp_blog']);

        $router = new EntriesRouter;

        // CLI should still enforce resource policy
        $result = $router->execute([
            'action' => 'create',
            'collection' => 'rp_products',
            'data' => ['title' => 'CLI Denied'],
        ]);

        $this->assertFalse($result['success']);
    }

    /**
     * Create a mock super admin user.
     */
    private function createMockSuperUser(): Authenticatable
    {
        return new class implements Authenticatable
        {
            public function isSuper(): bool { return true; }

            public function hasPermission(string $permission): bool { return true; }

            public function getAuthIdentifierName(): string { return 'id'; }

            public function getAuthIdentifier(): string { return 'rp-test-user'; }

            public function getAuthPasswordName(): string { return 'password'; }

            public function getAuthPassword(): string { return 'hashed'; }

            public function getRememberToken(): ?string { return null; }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string { return 'remember_token'; }
        };
    }

    /**
     * @param  array<int, string>  $scopeValues
     */
    private function createTokenWithScopes(array $scopeValues): McpTokenData
    {
        $model = McpToken::create([
            'user_id' => 'rp-test-user',
            'name' => 'Test Token',
            'token' => hash('sha256', 'test-token-' . bin2hex(random_bytes(8))),
            'scopes' => $scopeValues,
        ]);

        /** @var \DateTimeInterface $createdAt */
        $createdAt = $model->created_at;

        return new McpTokenData(
            id: $model->id,
            userId: $model->user_id,
            name: $model->name,
            tokenHash: $model->token,
            scopes: $model->scopes,
            lastUsedAt: $model->last_used_at instanceof \DateTimeInterface ? Carbon::instance($model->last_used_at) : null,
            expiresAt: $model->expires_at instanceof \DateTimeInterface ? Carbon::instance($model->expires_at) : null,
            createdAt: Carbon::instance($createdAt),
            updatedAt: $model->updated_at instanceof \DateTimeInterface ? Carbon::instance($model->updated_at) : null,
        );
    }
}
```

- [ ] **Step 2: Create the EnforcesResourcePolicy trait**

Create `src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;

/**
 * Enforces resource-level access control and field filtering on router actions.
 *
 * Resource policy is a site-wide admin config — it applies in ALL contexts
 * (CLI and web), unlike token scopes which are web-only.
 */
trait EnforcesResourcePolicy
{
    /**
     * Check if the current action is allowed on the target resource.
     *
     * Returns an error response array if denied, or null if allowed.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    protected function checkResourceAccess(string $action, array $arguments): ?array
    {
        $handle = $this->resolveResourceHandle($arguments);

        // No handle to check (e.g., list without collection filter) — allow
        if ($handle === null) {
            return null;
        }

        $mode = $this->isWriteAction($action) ? 'write' : 'read';

        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        if (! $policy->canAccess($this->getDomain(), $handle, $mode)) {
            return $this->createErrorResponse(
                ucfirst($mode) . " access to '{$handle}' is not permitted by resource policy"
            )->toArray();
        }

        return null;
    }

    /**
     * Filter denied fields from input arguments.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function filterInputFields(array $arguments): array
    {
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        $domain = $this->getDomain();

        if ($policy->getDeniedFields($domain) === []) {
            return $arguments;
        }

        // Filter 'data' key if present (entries, terms, globals)
        if (isset($arguments['data']) && is_array($arguments['data'])) {
            $arguments['data'] = $policy->filterFields($domain, $arguments['data']);
        }

        // Filter 'fields' key if present (blueprints)
        if (isset($arguments['fields']) && is_array($arguments['fields'])) {
            $arguments['fields'] = $policy->filterFields($domain, $arguments['fields']);
        }

        return $arguments;
    }

    /**
     * Filter denied fields from output data.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    protected function filterOutputFields(array $result): array
    {
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        $domain = $this->getDomain();

        if ($policy->getDeniedFields($domain) === []) {
            return $result;
        }

        // Filter the 'data' key in the result
        if (isset($result['data']) && is_array($result['data'])) {
            $result['data'] = $policy->filterFields($domain, $result['data']);
        }

        return $result;
    }

    /**
     * Extract the resource handle from arguments for policy evaluation.
     *
     * Returns null for actions that don't target a specific resource
     * (e.g., list without a filter). When null, resource-level check is skipped.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveResourceHandle(array $arguments): ?string
    {
        // Try common argument names in priority order
        foreach (['collection', 'taxonomy', 'container', 'handle', 'navigation'] as $key) {
            if (isset($arguments[$key]) && is_string($arguments[$key]) && $arguments[$key] !== '') {
                return $arguments[$key];
            }
        }

        return null;
    }

    /**
     * Check if an action is a write action.
     */
    private function isWriteAction(string $action): bool
    {
        return in_array($action, [
            'create', 'update', 'delete', 'publish', 'unpublish',
            'activate', 'deactivate', 'assign_role', 'remove_role',
            'move', 'copy', 'upload', 'configure',
            'cache_clear', 'cache_warm', 'config_set',
        ], true);
    }
}
```

- [ ] **Step 3: Run PHPStan on the trait**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php --level 8`
Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php tests/Integration/ResourcePolicyEnforcementTest.php
git commit -m "feat: add EnforcesResourcePolicy trait and integration tests

Trait provides resource access checks (glob-based) and field filtering
(input + output) for routers. Not yet wired into BaseRouter."
```

---

## Task 7: Wire Traits Into BaseRouter

**Files:**
- Modify: `src/Mcp/Tools/BaseRouter.php`
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php`

- [ ] **Step 1: Add traits to BaseRouter**

In `src/Mcp/Tools/BaseRouter.php`, add imports:

```php
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\EnforcesResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RequiresConfirmation;
```

Add the trait uses inside the class (after line 18, alongside existing traits):

```php
    use EnforcesResourcePolicy;
    use RequiresConfirmation;
```

- [ ] **Step 2: Update executeInternal() to call new checks**

Replace the `executeInternal()` method in `BaseRouter.php` (lines 85-102) with:

```php
    protected function executeInternal(array $arguments): array
    {
        if ($this->isWebContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse(
                'Permission denied: ' . ucfirst($this->getDomain()) . ' tool is disabled for web access'
            )->toArray();
        }

        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        if ($this->isWebContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Resource policy check (applies in ALL contexts — site-wide admin policy)
        $resourceError = $this->checkResourceAccess($action, $arguments);
        if ($resourceError) {
            return $resourceError;
        }

        // Confirmation check (skipped in CLI and when disabled)
        $confirmationResponse = $this->handleConfirmation($action, $arguments);
        if ($confirmationResponse) {
            return $confirmationResponse;
        }

        // Filter denied fields from input
        $arguments = $this->filterInputFields($arguments);

        // Execute the action
        $result = $this->executeAction($arguments);

        // Filter denied fields from output
        return $this->filterOutputFields($result);
    }
```

- [ ] **Step 3: Remove old confirm parameter from BlueprintsRouter**

In `src/Mcp/Tools/Routers/BlueprintsRouter.php`, remove the `confirm` parameter usage in `deleteBlueprint()` (lines 694-698). Replace:

```php
            $confirm = $this->getBooleanArgument($arguments, 'confirm', false);

            if (! $confirm) {
                return $this->createErrorResponse('Deletion requires explicit confirmation (set confirm to true)')->toArray();
            }
```

With nothing (just remove those 4 lines). The confirmation is now handled by the trait in `BaseRouter::executeInternal()` before `deleteBlueprint()` is called.

Also remove the `'confirm'` reference from `defineSchema()` if it exists as a schema field. Search for any `confirm` field in the schema definition and remove it.

- [ ] **Step 4: Run all integration tests**

Run: `./vendor/bin/pest tests/Integration/ConfirmationFlowTest.php tests/Integration/ResourcePolicyEnforcementTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Run existing tests to verify no regressions**

Run: `./vendor/bin/pest`
Expected: All existing tests PASS. Note: some tests may need adjustment if they test the old `confirm: true` parameter on BlueprintsRouter — update those tests to use the new confirmation token flow or disable confirmation in their setUp (`Config::set('statamic.mcp.confirmation.enabled', false)`).

- [ ] **Step 6: Run PHPStan on modified files**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/BaseRouter.php src/Mcp/Tools/Routers/BlueprintsRouter.php --level 8`
Expected: No errors.

- [ ] **Step 7: Commit**

```bash
git add src/Mcp/Tools/BaseRouter.php src/Mcp/Tools/Routers/BlueprintsRouter.php
git commit -m "feat: wire confirmation tokens and resource policy into router flow

BaseRouter now calls checkResourceAccess(), handleConfirmation(),
filterInputFields(), and filterOutputFields() for all routers.
Removes old confirm parameter from BlueprintsRouter."
```

---

## Task 8: Fix Existing Tests

**Files:**
- Modify: Various existing test files

- [ ] **Step 1: Identify broken tests**

Run: `./vendor/bin/pest`
Identify any tests that break due to:
- BlueprintsRouter tests that pass `confirm: true` (old pattern removed)
- Tests running in web context that now hit confirmation requirements

- [ ] **Step 2: Fix broken tests**

For each broken test:
- If it tests BlueprintsRouter delete with `confirm: true`: either disable confirmation (`Config::set('statamic.mcp.confirmation.enabled', false)`) in setUp or adapt to the new two-step flow.
- If it tests write operations that now require confirmation: add `Config::set('statamic.mcp.confirmation.enabled', false)` in setUp.

The key principle: existing tests that aren't specifically testing confirmation should disable it to avoid false failures. Confirmation-specific tests are in `ConfirmationFlowTest.php`.

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All tests PASS.

- [ ] **Step 4: Run full PHPStan**

Run: `./vendor/bin/phpstan analyse --level 8`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "test: fix existing tests for confirmation token and resource policy changes

Disable confirmation in setUp for tests not specifically testing the
confirmation flow to avoid false failures."
```

---

## Task 9: Update CLAUDE.md Documentation

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add Confirmation Tokens section**

Add a new section after "### Middleware" in CLAUDE.md:

```markdown
### Confirmation Tokens
Destructive MCP operations require a two-step confirmation flow in production:
- **ConfirmationTokenManager** (`src/Auth/ConfirmationTokenManager.php`) — Stateless HMAC-SHA256 tokens bound to tool + arguments
- **RequiresConfirmation trait** (`src/Mcp/Tools/Concerns/RequiresConfirmation.php`) — Integrated into BaseRouter
- **Operations requiring confirmation:** All `delete` actions + blueprint `create`/`update`/`delete`
- **Environment-aware:** Auto-enabled in production, disabled in local/dev/testing (configurable via `STATAMIC_MCP_CONFIRMATION_ENABLED`)
- **CLI bypass:** Confirmation is skipped in CLI context
```

- [ ] **Step 2: Add Resource Policy section**

Add after the Confirmation Tokens section:

```markdown
### Resource Policy
Granular resource-level access control configured in `config/statamic/mcp.php`:
- **ResourcePolicy** (`src/Auth/ResourcePolicy.php`) — Glob-based resource allowlists + field deny lists
- **EnforcesResourcePolicy trait** (`src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php`) — Integrated into BaseRouter
- **Per-domain config:** `resources.read`/`resources.write` (glob patterns) + `denied_fields` (field names to strip)
- **Applies everywhere:** Resource policy is enforced in both CLI and web contexts (site-wide admin policy)
- **Field filtering:** Denied fields silently stripped from both input and output
```

- [ ] **Step 3: Update Authorization Evaluation Order**

Add to the existing security documentation:

```markdown
### Authorization Evaluation Order (Web Context)
1. Tool enabled? → `config: tools.{domain}.enabled`
2. Token scope? → `TokenScope: {domain}:{read|write}`
3. Resource allowed? → `ResourcePolicy::canAccess(domain, handle, mode)`
4. Statamic permissions? → `User::hasPermission()`
5. Confirmation required? → `ConfirmationTokenManager` (deletes + blueprint writes)
6. Field filtering → `ResourcePolicy::filterFields()` on input + output
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: add confirmation tokens and resource policy to CLAUDE.md"
```

---

## Task 10: Final Verification

- [ ] **Step 1: Run full quality pipeline**

Run: `composer quality`
Expected: Pint, PHPStan, and all tests pass with zero errors.

- [ ] **Step 2: Verify new file count matches spec**

Expected new files:
- `src/Auth/ConfirmationTokenManager.php`
- `src/Auth/ResourcePolicy.php`
- `src/Mcp/Tools/Concerns/RequiresConfirmation.php`
- `src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php`
- `tests/Unit/Auth/ConfirmationTokenManagerTest.php`
- `tests/Unit/Auth/ResourcePolicyTest.php`
- `tests/Integration/ConfirmationFlowTest.php`
- `tests/Integration/ResourcePolicyEnforcementTest.php`

Run: `git diff --stat main` to verify scope.

- [ ] **Step 3: Smoke test — manual verification**

Verify the two-step flow works end-to-end by running:

```php
// In tinker or a test:
$manager = app(\Cboxdk\StatamicMcp\Auth\ConfirmationTokenManager::class);
$token = $manager->generate('statamic-entries', ['action' => 'delete', 'slug' => 'test']);
$valid = $manager->validate($token, 'statamic-entries', ['action' => 'delete', 'slug' => 'test']);
// $valid should be true

$policy = app(\Cboxdk\StatamicMcp\Auth\ResourcePolicy::class);
$allowed = $policy->canAccess('entries', 'blog', 'write');
// $allowed should be true (default config)
```
