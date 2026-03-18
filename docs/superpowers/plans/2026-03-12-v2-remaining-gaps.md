# v2.0 Remaining Gaps Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close all remaining v2.0 spec gaps: fix broken audit log, implement `shouldRegister()`, consolidate duplicate logging, add `outputSchema()`, wire rate limiting, add tool annotations, and clean dead config.

**Architecture:** Eight independent tasks targeting specific gaps. Each task is self-contained and testable. ToolLogger becomes the single audit/logging service with a dedicated `mcp` log channel. AuditService and McpRateLimiter are removed as dead code.

**Tech Stack:** PHP 8.3, Laravel MCP v0.6, Statamic v6, PHPStan Level 8, Pest 4

---

## File Structure

### Files to Create
- None — all changes modify existing files

### Files to Modify
- `src/Mcp/Support/ToolLogger.php` — Add dedicated `mcp` channel logging + absorb AuditService's sensitive key redaction
- `src/Http/Controllers/CP/AuditController.php` — Read from `mcp` log channel path
- `config/statamic/mcp.php` — Add `audit.channel` config, remove stale `tools.content`
- `src/Mcp/Tools/BaseStatamicTool.php` — Add `shouldRegister()`, `outputSchema()`, preserve correlation ID in `handle()`
- `src/Mcp/Tools/BaseRouter.php` — Use ToolLogger instead of Log facade for audit
- `src/Mcp/Servers/StatamicMcpServer.php` — No changes needed (shouldRegister is per-tool)
- `src/Auth/AuthServiceProvider.php` — Remove AuditService + McpRateLimiter singleton registrations
- `src/Mcp/Tools/Routers/BlueprintsRouter.php` — Add `#[IsReadOnly]` annotation
- `src/Mcp/Tools/Routers/EntriesRouter.php` — No class-level annotation (mixed read/write)
- `src/Mcp/Tools/Routers/TermsRouter.php` — No class-level annotation (mixed read/write)
- `src/Mcp/Tools/Routers/GlobalsRouter.php` — No class-level annotation (mixed read/write)
- `src/Mcp/Tools/Routers/ContentFacadeRouter.php` — No class-level annotation (mixed read/write)
- `src/Mcp/Tools/Routers/StructuresRouter.php` — No class-level annotation (mixed read/write)
- `src/Mcp/Tools/Routers/AssetsRouter.php` — No class-level annotation (mixed read/write)
- `src/Mcp/Tools/Routers/UsersRouter.php` — No class-level annotation (mixed read/write)
- `src/Mcp/Tools/Routers/SystemRouter.php` — Add `#[IsReadOnly]` annotation
- `src/Mcp/Tools/System/DiscoveryTool.php` — Add `#[IsReadOnly]`, `shouldRegister()`, `outputSchema()`
- `src/Mcp/Tools/System/SchemaTool.php` — Add `#[IsReadOnly]`, `shouldRegister()`, `outputSchema()`

### Files to Delete
- `src/Services/AuditService.php` — Dead code, functionality absorbed by ToolLogger
- `src/Services/McpRateLimiter.php` — Dead code, never wired into any tool

### Test Files to Create
- `tests/AuditLogChannelTest.php` — Verify ToolLogger writes to `mcp` channel and AuditController reads it
- `tests/ShouldRegisterTest.php` — Verify tools respect config toggles
- `tests/OutputSchemaTest.php` — Verify all tools return valid output schemas

---

## Chunk 1: Fix Audit Log Channel + Consolidate Logging

### Task 1: Configure dedicated `mcp` log channel

**Files:**
- Modify: `config/statamic/mcp.php`

- [ ] **Step 1: Add audit channel config**

In `config/statamic/mcp.php`, add `audit.channel` and `audit.path` inside the `security` section:

```php
'security' => [
    'force_web_mode' => env('STATAMIC_MCP_FORCE_WEB_MODE', false),
    'audit_logging' => env('STATAMIC_MCP_AUDIT_LOGGING', true),
    'audit_channel' => env('STATAMIC_MCP_AUDIT_CHANNEL', 'mcp'),
    'audit_path' => env('STATAMIC_MCP_AUDIT_PATH', storage_path('logs/mcp-audit.log')),
],
```

- [ ] **Step 2: Remove stale `tools.content` config**

Remove the `'content' => ['enabled' => true]` entry from the `tools` array — ContentRouter was deleted in the token optimization phase.

- [ ] **Step 3: Verify config loads**

Run: `php -l config/statamic/mcp.php`
Expected: No syntax errors

---

### Task 2: Upgrade ToolLogger to write to dedicated channel

**Files:**
- Modify: `src/Mcp/Support/ToolLogger.php`

- [ ] **Step 1: Add channel-aware logging method**

Replace direct `Log::info()` / `Log::error()` / `Log::warning()` / `Log::debug()` calls with a private static `log()` method that uses the configured `mcp` channel. Also absorb AuditService's `SENSITIVE_KEYS` list (it has `credential` and `authorization` that ToolLogger lacks):

```php
<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Structured logging for MCP tools.
 *
 * Writes to a dedicated 'mcp' log channel (storage/logs/mcp-audit.log)
 * so the CP Activity tab can read entries.
 */
class ToolLogger
{
    /**
     * Sensitive keys that should be redacted from log output.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'key',
        'api_key',
        'access_token',
        'refresh_token',
        'private_key',
        'credential',
        'authorization',
    ];

    /**
     * Log tool execution start.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function toolStarted(string $toolName, array $arguments, ?string $correlationId = null): string
    {
        $correlationId = $correlationId ?: Str::uuid()->toString();

        self::log('info', 'MCP Tool Started', [
            'tool' => $toolName,
            'correlation_id' => $correlationId,
            'status' => 'started',
            'arguments' => self::sanitizeArguments($arguments),
            'timestamp' => now()->toIso8601String(),
        ]);

        return $correlationId;
    }

    /**
     * Log tool execution success.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function toolSuccess(string $toolName, string $correlationId, ?float $duration = null, array $metadata = []): void
    {
        self::log('info', 'MCP Tool Success', [
            'tool' => $toolName,
            'correlation_id' => $correlationId,
            'status' => 'success',
            'duration_ms' => $duration ? round($duration * 1000, 2) : null,
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log tool execution failure.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function toolFailed(string $toolName, string $correlationId, \Throwable $exception, ?float $duration = null, array $metadata = []): void
    {
        self::log('error', 'MCP Tool Failed', [
            'tool' => $toolName,
            'correlation_id' => $correlationId,
            'status' => 'failed',
            'duration_ms' => $duration ? round($duration * 1000, 2) : null,
            'error' => [
                'message' => $exception->getMessage(),
                'class' => get_class($exception),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
            'trace' => $exception->getTraceAsString(),
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log security warning.
     *
     * @param  array<string, mixed>  $details
     */
    public static function securityWarning(string $toolName, string $warning, array $details = []): void
    {
        self::log('warning', 'Security Warning', [
            'tool' => $toolName,
            'status' => 'warning',
            'warning' => $warning,
            'details' => $details,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log performance warning.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function performanceWarning(string $toolName, string $warning, float $duration, array $metadata = []): void
    {
        self::log('warning', 'Performance Warning', [
            'tool' => $toolName,
            'status' => 'warning',
            'warning' => $warning,
            'duration_ms' => round($duration * 1000, 2),
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log cache events.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function cacheEvent(string $toolName, string $event, string $key, array $metadata = []): void
    {
        self::log('debug', 'Cache Event', [
            'tool' => $toolName,
            'event' => $event,
            'cache_key' => $key,
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Check if audit logging is enabled.
     */
    public static function isEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('statamic.mcp.security.audit_logging', true);

        return $enabled;
    }

    /**
     * Get the configured audit log file path.
     */
    public static function getLogPath(): string
    {
        /** @var string $path */
        $path = config('statamic.mcp.security.audit_path', storage_path('logs/mcp-audit.log'));

        return $path;
    }

    /**
     * Write to the dedicated MCP log channel.
     *
     * @param  array<string, mixed>  $context
     */
    private static function log(string $level, string $message, array $context): void
    {
        if (! self::isEnabled()) {
            return;
        }

        /** @var string $channel */
        $channel = config('statamic.mcp.security.audit_channel', 'mcp');

        // Ensure the channel exists in logging config at runtime
        self::ensureChannelConfigured($channel);

        Log::channel($channel)->{$level}($message, $context);
    }

    /**
     * Ensure the MCP log channel is configured.
     */
    private static function ensureChannelConfigured(string $channel): void
    {
        if (config("logging.channels.{$channel}") !== null) {
            return;
        }

        /** @var string $path */
        $path = config('statamic.mcp.security.audit_path', storage_path('logs/mcp-audit.log'));

        config([
            "logging.channels.{$channel}" => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'debug',
            ],
        ]);
    }

    /**
     * Sanitize arguments by removing sensitive data.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private static function sanitizeArguments(array $arguments): array
    {
        $sanitized = [];

        foreach ($arguments as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (self::isSensitiveKey($lowerKey)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $sanitized[$key] = self::sanitizeArguments($value);
            } elseif (is_string($value) && strlen($value) > 1000) {
                $sanitized[$key] = substr($value, 0, 1000) . '... [TRUNCATED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if a key name indicates sensitive data.
     */
    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Support/ToolLogger.php --level=8`
Expected: 0 errors

---

### Task 3: Fix AuditController to read from configured path

**Files:**
- Modify: `src/Http/Controllers/CP/AuditController.php`

- [ ] **Step 1: Use ToolLogger::getLogPath() instead of hardcoded path**

Replace line 28:
```php
$logPath = storage_path('logs/mcp-audit.log');
```
With:
```php
$logPath = \Cboxdk\StatamicMcp\Mcp\Support\ToolLogger::getLogPath();
```

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Http/Controllers/CP/AuditController.php --level=8`
Expected: 0 errors

---

### Task 4: Update BaseRouter audit logging to use ToolLogger

**Files:**
- Modify: `src/Mcp/Tools/BaseRouter.php`

- [ ] **Step 1: Replace Log facade with ToolLogger in executeWithSafety()**

In `executeWithSafety()`, replace the two `Log::info()` / `Log::error()` calls with `ToolLogger` calls. Remove the `use Illuminate\Support\Facades\Log;` import if no longer needed.

Replace lines 119-124:
```php
if (config('statamic.mcp.security.audit_logging', true)) {
    Log::info('MCP operation started', [
        'action' => $action,
        'tool' => $this->name(),
        'context' => $this->isCliContext() ? 'cli' : 'web',
    ]);
}
```
With:
```php
ToolLogger::auditOperation($this->name(), $action, $this->isCliContext() ? 'cli' : 'web');
```

And add a new static method to ToolLogger:
```php
public static function auditOperation(string $toolName, string $action, string $context): void
{
    self::log('info', 'MCP operation', [
        'tool' => $toolName,
        'action' => $action,
        'status' => 'started',
        'context' => $context,
        'timestamp' => now()->toIso8601String(),
    ]);
}
```

Similarly replace lines 137-143 error logging with ToolLogger.

- [ ] **Step 2: Remove Log import from BaseRouter if unused**

Check if `Log` facade is still used anywhere in BaseRouter. If not, remove `use Illuminate\Support\Facades\Log;`.

- [ ] **Step 3: Run PHPStan on BaseRouter**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/BaseRouter.php --level=8`
Expected: 0 errors

---

### Task 5: Delete dead code (AuditService + McpRateLimiter)

**Files:**
- Delete: `src/Services/AuditService.php`
- Delete: `src/Services/McpRateLimiter.php`
- Modify: `src/Auth/AuthServiceProvider.php`

- [ ] **Step 1: Remove singleton registrations from AuthServiceProvider**

Remove these lines from `register()`:
```php
$this->app->singleton(AuditService::class);
$this->app->singleton(McpRateLimiter::class);
```

Remove the corresponding `use` imports:
```php
use Cboxdk\StatamicMcp\Services\AuditService;
use Cboxdk\StatamicMcp\Services\McpRateLimiter;
```

- [ ] **Step 2: Delete AuditService.php**

Run: `rm src/Services/AuditService.php`

- [ ] **Step 3: Delete McpRateLimiter.php**

Run: `rm src/Services/McpRateLimiter.php`

- [ ] **Step 4: Verify no remaining references**

Run: `grep -r "AuditService\|McpRateLimiter" src/ --include="*.php"`
Expected: No matches

- [ ] **Step 5: Run full PHPStan**

Run: `./vendor/bin/phpstan analyse --level=8`
Expected: 0 errors

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "fix: consolidate audit logging into ToolLogger with dedicated mcp channel

- Add dedicated 'mcp' log channel writing to storage/logs/mcp-audit.log
- Fix AuditController to read from configured log path (Activity tab was broken)
- Add audit_channel and audit_path config options
- Move sensitive key redaction from AuditService into ToolLogger
- Replace Log facade in BaseRouter with ToolLogger
- Delete dead code: AuditService, McpRateLimiter
- Remove stale tools.content config entry"
```

---

## Chunk 2: Implement shouldRegister() on All Tools

### Task 6: Add shouldRegister() to BaseStatamicTool

**Files:**
- Modify: `src/Mcp/Tools/BaseStatamicTool.php`

- [ ] **Step 1: Add shouldRegister() method**

Add after `clearVersionCache()` (around line 239):

```php
/**
 * Determine if this tool should be registered.
 *
 * Checks the per-domain tool configuration. Tools whose domain
 * is disabled in config will not be exposed to MCP clients.
 */
public function shouldRegister(): bool
{
    $domain = $this->getToolDomain();

    if ($domain === null) {
        return true;
    }

    /** @var bool $enabled */
    $enabled = config("statamic.mcp.tools.{$domain}.enabled", true);

    return $enabled;
}

/**
 * Get the domain key for config lookup.
 *
 * Override in subclasses to map to config keys.
 * Returns null if tool has no domain toggle.
 */
protected function getToolDomain(): ?string
{
    return null;
}
```

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/BaseStatamicTool.php --level=8`
Expected: 0 errors

---

### Task 7: Add getToolDomain() to BaseRouter and all routers

**Files:**
- Modify: `src/Mcp/Tools/BaseRouter.php`
- Modify: All 9 router files in `src/Mcp/Tools/Routers/`

- [ ] **Step 1: Override getToolDomain() in BaseRouter**

Add to BaseRouter, using the existing `getDomain()` method:

```php
protected function getToolDomain(): ?string
{
    return $this->getDomain();
}
```

- [ ] **Step 2: Verify each router's getDomain() maps to config keys**

Verify these mappings exist (they should from existing code):
- `EntriesRouter::getDomain()` → `'entries'`
- `TermsRouter::getDomain()` → `'terms'`
- `GlobalsRouter::getDomain()` → `'globals'`
- `BlueprintsRouter::getDomain()` → `'blueprints'`
- `StructuresRouter::getDomain()` → `'structures'`
- `AssetsRouter::getDomain()` → `'assets'`
- `UsersRouter::getDomain()` → `'users'`
- `SystemRouter::getDomain()` → `'system'`
- `ContentFacadeRouter::getDomain()` → `'content'` (NOTE: this needs to change — `tools.content` was just removed in Task 1)

- [ ] **Step 3: Fix ContentFacadeRouter domain**

ContentFacadeRouter's `getDomain()` likely returns `'content'`. Since we removed `tools.content` from config, either:
a) Add a `tools.content_facade.enabled` key to config, OR
b) Override `getToolDomain()` in ContentFacadeRouter to return `null` (always enabled)

Choose option (b) — ContentFacadeRouter is a workflow facade, not a domain. Add to ContentFacadeRouter:
```php
protected function getToolDomain(): ?string
{
    return null; // Facade tool is always available
}
```

- [ ] **Step 4: Add shouldRegister() overrides to education tools**

Add to `DiscoveryTool.php` and `SchemaTool.php`:
```php
protected function getToolDomain(): ?string
{
    return null; // Education tools are always available
}
```

Wait — they extend BaseStatamicTool (not BaseRouter), so they already inherit the `null` default. No changes needed.

- [ ] **Step 5: Run PHPStan**

Run: `./vendor/bin/phpstan analyse --level=8`
Expected: 0 errors

- [ ] **Step 6: Write test for shouldRegister**

Create `tests/ShouldRegisterTest.php`:

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;

it('registers tools when domain is enabled', function () {
    config(['statamic.mcp.tools.entries.enabled' => true]);

    $tool = app(EntriesRouter::class);

    expect($tool->shouldRegister())->toBeTrue();
});

it('skips tools when domain is disabled', function () {
    config(['statamic.mcp.tools.entries.enabled' => false]);

    $tool = app(EntriesRouter::class);

    expect($tool->shouldRegister())->toBeFalse();
});

it('registers tools when domain config is missing', function () {
    // Remove config entirely
    config(['statamic.mcp.tools.blueprints' => null]);

    $tool = app(BlueprintsRouter::class);

    expect($tool->shouldRegister())->toBeTrue();
});

it('always registers education tools', function () {
    $tool = app(DiscoveryTool::class);

    expect($tool->shouldRegister())->toBeTrue();
});
```

- [ ] **Step 7: Run tests**

Run: `./vendor/bin/pest tests/ShouldRegisterTest.php`
Expected: All 4 tests pass

- [ ] **Step 8: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: implement shouldRegister() for config-driven tool registration

- Add shouldRegister() to BaseStatamicTool using per-domain config
- Add getToolDomain() bridge in BaseRouter using getDomain()
- Education tools and ContentFacadeRouter always register (no domain toggle)
- Add tests for enabled/disabled/missing config scenarios"
```

---

## Chunk 3: Add outputSchema() to All Tools

### Task 8: Add outputSchema() to BaseStatamicTool

**Files:**
- Modify: `src/Mcp/Tools/BaseStatamicTool.php`

- [ ] **Step 1: Add base outputSchema()**

The base class provides a default output schema that matches the standardized response contract. Add after `schema()` method:

```php
/**
 * Define the output schema for this tool's results.
 *
 * Returns the standard MCP response envelope used by all tools.
 *
 * @param  \Illuminate\Contracts\JsonSchema\JsonSchema  $schema
 * @return array<string, mixed>
 */
public function outputSchema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
{
    return [
        'success' => JsonSchema::boolean()->description('Whether the operation succeeded'),
        'data' => JsonSchema::object()->description('Operation result data'),
        'meta' => JsonSchema::object()->description('Response metadata (tool, timestamp, versions)'),
        'errors' => JsonSchema::array()->description('Error messages if operation failed'),
        'warnings' => JsonSchema::array()->description('Warning messages'),
    ];
}
```

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/BaseStatamicTool.php --level=8`
Expected: 0 errors

- [ ] **Step 3: Write test for outputSchema**

Create `tests/OutputSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\SchemaTool;
use Illuminate\JsonSchema\JsonSchema;

it('returns output schema from all tools', function (string $toolClass) {
    $tool = app($toolClass);
    $schema = $tool->outputSchema(new JsonSchema());

    expect($schema)->toBeArray()
        ->and($schema)->toHaveKey('success')
        ->and($schema)->toHaveKey('data');
})->with([
    EntriesRouter::class,
    BlueprintsRouter::class,
    SystemRouter::class,
    DiscoveryTool::class,
    SchemaTool::class,
]);
```

- [ ] **Step 4: Run test**

Run: `./vendor/bin/pest tests/OutputSchemaTest.php`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add outputSchema() to BaseStatamicTool for MCP v0.6 compliance

- Define standard response envelope schema matching output contract
- All 11 tools inherit outputSchema() via BaseStatamicTool
- Add tests verifying schema presence on representative tools"
```

---

## Chunk 4: Preserve Correlation IDs in Error Responses

### Task 9: Fix handle() to include correlation ID in error responses

**Files:**
- Modify: `src/Mcp/Tools/BaseStatamicTool.php`

- [ ] **Step 1: Update handle() method**

Currently `handle()` loses the correlation ID when returning `Response::error()`. Since `Response::error()` only accepts a string, embed the correlation ID in the error message:

Replace lines 53-68:
```php
public function handle(\Laravel\Mcp\Request $request): Response|ResponseFactory
{
    $arguments = $request->all();
    $result = $this->execute($arguments);

    if ($result['success'] ?? false) {
        return Response::structured($result);
    }

    $errors = $result['errors'] ?? null;
    $firstError = is_array($errors) ? ($errors[0] ?? null) : null;
    $errorRaw = $firstError ?? $result['error'] ?? 'Unknown error occurred';
    $errorMessage = is_string($errorRaw) ? $errorRaw : 'Unknown error occurred';

    $correlationId = $result['correlation_id'] ?? null;
    if (is_string($correlationId)) {
        $errorMessage .= " [correlation_id: {$correlationId}]";
    }

    return Response::error($errorMessage);
}
```

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/BaseStatamicTool.php --level=8`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "fix: preserve correlation IDs in MCP error responses

- Append correlation_id to error message string since Response::error()
  only accepts a string parameter
- Enables tracing failed operations back to audit log entries"
```

---

## Chunk 5: Add Tool Annotations to Routers

### Task 10: Add #[IsReadOnly] to read-only tools

**Files:**
- Modify: `src/Mcp/Tools/Routers/BlueprintsRouter.php`
- Modify: `src/Mcp/Tools/Routers/SystemRouter.php`
- Modify: `src/Mcp/Tools/System/DiscoveryTool.php`
- Modify: `src/Mcp/Tools/System/SchemaTool.php`

- [ ] **Step 1: Determine annotation strategy**

Router tools that mix read and write actions (Entries, Terms, Globals, Assets, Users, Structures, ContentFacade) should NOT have class-level annotations — the annotation describes the tool as a whole.

Read-only tools get `#[IsReadOnly]`:
- `BlueprintsRouter` — has create/update/delete actions, so NOT read-only. Skip.
- `SystemRouter` — has cache clear action, so NOT read-only. Skip.
- `DiscoveryTool` — pure read. Add `#[IsReadOnly]`.
- `SchemaTool` — pure read. Add `#[IsReadOnly]`.

After analysis: only 2 tools are purely read-only.

- [ ] **Step 2: Add #[IsReadOnly] to DiscoveryTool**

Add import and attribute:
```php
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('statamic-system-discover')]
#[Description('...')]
#[IsReadOnly]
class DiscoveryTool extends BaseStatamicTool
```

- [ ] **Step 3: Add #[IsReadOnly] to SchemaTool**

Same pattern:
```php
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('statamic-system-schema')]
#[Description('...')]
#[IsReadOnly]
class SchemaTool extends BaseStatamicTool
```

- [ ] **Step 4: Run PHPStan on modified files**

Run: `./vendor/bin/phpstan analyse src/Mcp/Tools/System/ --level=8`
Expected: 0 errors

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add tool annotations (#[IsReadOnly]) to education tools

- DiscoveryTool and SchemaTool marked #[IsReadOnly] (pure read operations)
- Router tools omitted: they mix read/write actions at the class level
- Annotations inform MCP clients about tool behavior characteristics"
```

---

## Chunk 6: Final Cleanup and Validation

### Task 11: Remove rate_limit config if no longer used

**Files:**
- Modify: `config/statamic/mcp.php`

- [ ] **Step 1: Assess rate_limit config**

The `rate_limit` section in config is read by the now-deleted `McpRateLimiter`. However, the ServiceProvider also applies `throttle:60,1` middleware to web MCP routes. The config values (`max_attempts`, `decay_minutes`) are NOT used by Laravel's built-in throttle middleware — that uses its own syntax.

Decision: Keep `rate_limit` config section for future use. It documents intent and could be wired to the throttle middleware later. No action needed.

---

### Task 12: Run full quality suite

- [ ] **Step 1: Format code**

Run: `./vendor/bin/pint`
Expected: Code formatted

- [ ] **Step 2: Run PHPStan**

Run: `./vendor/bin/phpstan analyse --level=8`
Expected: 0 errors

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All tests pass

- [ ] **Step 4: Final commit if pint made changes**

```bash
git add -A
git commit -m "chore: format code with Laravel Pint"
```

---

## Summary

| Task | Gap Addressed | Impact |
|------|--------------|--------|
| 1-5 | Audit log channel mismatch + consolidation | Activity tab fixed, single logging service |
| 6-7 | shouldRegister() implementation | Config-driven tool registration |
| 8 | outputSchema() on all tools | MCP v0.6 spec compliance |
| 9 | Correlation IDs in error responses | Error traceability |
| 10 | Tool annotations | MCP client behavior hints |
| 11-12 | Final cleanup and validation | Quality assurance |

**Dead code removed:** AuditService.php, McpRateLimiter.php, stale config
**New features:** shouldRegister(), outputSchema(), dedicated audit channel, #[IsReadOnly] annotations
