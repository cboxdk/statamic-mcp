# Statamic MCP v2.0 — Design Specification

**Date:** 2026-03-11
**Status:** Draft (reviewed, v0.6.2 verified)
**Scope:** Major version upgrade — Statamic v6 only, laravel/mcp v0.6 adoption, API token auth, KITT UI dashboard

---

## 1. Platform Foundation

### Version Constraints

```
php: ^8.3
statamic/cms: ^6.0
laravel/framework: ^12.0|^13.0
laravel/mcp: ^0.6
```

**Note:** `illuminate/json-schema` is provided by the Laravel framework — no separate constraint needed. laravel/mcp v0.6.2 (released 2026-03-10) introduces `#[Description]`, `#[Name]`, `#[Title]` attributes, `outputSchema()`, and `Response::structured()` with `ResponseFactory`. All verified from v0.6.2 source code.

### What Gets Removed

- **StatamicVersion helper** (`src/Support/StatamicVersion.php`) — entire file
- All dual-version detection: `isV6OrLater()`, `supportsV6OptIns()`, `hasV6AssetPermissions()`
- V5-specific logic in AssetsRouter and other routers
- V5 test matrices and bootstrapping
- Orchestra Testbench v9 support
- laravel/mcp v0.4.x and v0.5.x compatibility code
- Outdated CLAUDE.md references to "Laravel MCP v0.2.0"

### Server Identity

- Package version: `2.0.0`
- StatamicMcpServer version: `2.0.0`
- MCP protocol version: `2025-11-25` (as supported by laravel/mcp v0.6)

---

## 2. Tool API Modernisation

### Architecture Decision: Evolve BaseStatamicTool for v0.6

The current `BaseStatamicTool` provides critical cross-cutting concerns that must be preserved:
- Centralised error handling with correlation IDs and performance monitoring
- Response standardisation (success/error wrapping)
- Argument validation and sanitisation

**In v2.0**, `BaseStatamicTool.handle()` remains `final` and owns the Request→Response conversion. But it now uses `Response::structured()` + `ResponseFactory.withMeta()` instead of `Response::text(json_encode())`. Tools continue to implement `executeInternal()` returning arrays — the base class wraps them.

The key v0.6 features adopted: `#[Description]`, `#[Name]` attributes for identity, `outputSchema()` for typed returns, `shouldRegister()` for conditional visibility, and tool annotations for behavior hints.

### What Changes

**Before (v1.x — current on v0.5.2):**
```php
class BlueprintsRouter extends BaseRouter
{
    protected function getToolName(): string { return 'statamic-blueprints'; }
    protected function getToolDescription(): string { return 'Manage blueprints'; }

    protected function defineSchema(JsonSchemaContract $schema): array {
        return [
            'action' => JsonSchema::string()->description('Action')->enum([...])->required(),
            'handle' => JsonSchema::string()->description('Blueprint handle'),
        ];
    }

    protected function executeInternal(array $arguments): array {
        return match ($arguments['action']) { ... };
    }
}
```

**After (v2.0 — targeting v0.6.2):**
```php
use Laravel\Mcp\Server\Attributes\{Name, Description};
use Laravel\Mcp\Server\Tools\Annotations\{IsReadOnly, IsIdempotent};

#[Name('statamic-blueprints')]
#[Description('Manage blueprints: list, get, create, update, delete, scan, generate, types, validate')]
#[IsReadOnly]
class BlueprintsRouter extends BaseRouter
{
    public function schema(JsonSchema $schema): array {
        return [
            'action' => JsonSchema::string()->description('Action')->enum([...])->required(),
            'handle' => JsonSchema::string()->description('Blueprint handle'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array {
        return [
            'success' => JsonSchema::boolean()->description('Operation success'),
            'data' => JsonSchema::object()->description('Response data'),
        ];
    }

    public function shouldRegister(): bool {
        // Container DI — no Request param (CLI-safe)
        if ($this->isCliContext()) {
            return true;
        }
        return $this->isToolEnabled('blueprints');
    }

    protected function executeInternal(array $arguments): array {
        return match ($arguments['action']) { ... };
    }
}
```

**Note:** Schema fields still use static `JsonSchema::string()` calls (not instance `$schema->string()`). This is the correct v0.6.2 pattern — the `$schema` parameter is passed for factory context but field definitions use static methods. Verified from v0.6.2 source: `JsonSchemaFactory::object($this->schema(...))`.

### Changes Applied to All 12 Tools (10 Routers + 2 System Tools)

1. **Metadata attributes** — `#[Name('...')]`, `#[Description('...')]` on all tools (new in v0.6, verified from source). Replaces `getToolName()`/`getToolDescription()` methods and `protected string $name/$description` properties.
2. **Tool annotations** — `#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`, `#[IsOpenWorld]` on all tools. Serialised in tool registration for MCP client hints.
3. **`schema()` method** — Renamed from `defineSchema()` to `schema()` (v0.6 convention). Now `public` instead of `protected`. BaseStatamicTool bridges this in its `final handle()`.
4. **`outputSchema()`** — Typed return schemas on all tools for AI client introspection. Uses same `JsonSchema::*` static methods.
5. **`shouldRegister()`** — Context-dependent registration: full access in CLI, permission-gated in web. Uses container DI (no Request parameter — CLI-safe). Checks tool enabled status from config and user permissions.
6. **Structured responses** — `BaseStatamicTool.handle()` updated to use `Response::structured()` → `ResponseFactory` with `->withMeta()` for version/tool metadata.
7. **Streaming** — For heavy operations (bulk import, content audit), `executeInternal()` can return a generator. `handle()` detects iterables and yields `Response::notification()` for progress updates.

### Tool Inventory (v2.0)

| # | Tool | Type | Status |
|---|------|------|--------|
| 1 | BlueprintsRouter | Router | Modernise |
| 2 | EntriesRouter | Router | Modernise |
| 3 | TermsRouter | Router | Modernise |
| 4 | GlobalsRouter | Router | Modernise |
| 5 | StructuresRouter | Router | Modernise |
| 6 | AssetsRouter | Router | Modernise (remove v5 compat) |
| 7 | UsersRouter | Router | Modernise |
| 8 | SystemRouter | Router | Modernise (add health checks) |
| 9 | ContentRouter | Router | **Evaluate**: deprecate or merge into domain routers |
| 10 | ContentFacadeRouter | Router | Modernise |
| 11 | DiscoveryTool | System | Modernise |
| 12 | SchemaTool | System | Modernise + outputSchema |

**Decision needed during implementation:** ContentRouter overlaps with domain routers. Evaluate whether to deprecate it in v2.0 or remove entirely.

### Retained from v1.x

- `final handle()` with centralised error handling, correlation IDs, performance monitoring
- Response metadata (tool, timestamp, version) — now via `Response::structured()->withMeta()`
- Dry-run / confirm safety protocols
- Audit logging trait (ExecutesWithAudit)
- Cache clearing trait (ClearsCaches)
- Agent education system (help/discover/examples) in BaseRouter

---

## 3. Authentication

### Layer 1: API Tokens (Default)

**Token model:**
- Statamic users create tokens via CP dashboard or CLI
- Each token has: name, scoped permissions, optional expiry, last_used_at
- Tokens stored hashed (SHA-256) in flat-file storage (Statamic convention)
- Format: `smc_` prefix + 40 chars random (e.g., `smc_a3f8b2c1d4e5...`)
- No database migration required

**Token scoping:**
- Per-domain: `blueprints:read`, `entries:write`, `system:admin`
- Wildcard: `*` for full access
- Read-only shorthand: `read:*`
- Scopes validated in `RequireMcpPermission` middleware against the requested tool/action

**Token storage:**
- Default: YAML flat-file in `storage/app/statamic-mcp/tokens/` (avoids conflict with `storage/statamic/` which may be cleared by `php artisan statamic:clear`)
- Alternative via config: Database driver for high-volume sites

**Token limits:**
- Max tokens per user: configurable (default: 10)
- Default expiry: configurable (default: none)

### Layer 2: OAuth 2.1 (Opt-in)

- Activated via `STATAMIC_MCP_OAUTH_ENABLED=true`
- Uses `Mcp::oauthRoutes()` + Laravel Passport
- Passport is NOT a dependency — users install it themselves
- **Guard:** ServiceProvider checks if Passport is installed before enabling OAuth routes. If `OAUTH_ENABLED=true` but Passport is missing, logs an error and falls back to token-only auth.
- Documented as enterprise feature

### Authentication Middleware Flow

```
Request → AuthenticateForMcp
  ├─ 1. Bearer token with smc_ prefix → Token lookup + scope validation
  ├─ 2. OAuth 2.1 token → Passport validation (only if enabled + installed)
  ├─ 3. Session (browser) → Statamic user auth
  ├─ 4. Bearer token (legacy base64) → Deprecated, log warning
  └─ 5. Basic Auth → Deprecated, log warning
→ RequireMcpPermission
  ├─ Check token scopes against requested tool/action
  └─ Check Statamic user permissions
→ Router execution
```

### Deprecation Path

- Base64 Bearer and Basic Auth work in v2.0 but log deprecation warnings
- Removed in v3.0

---

## 4. CP Dashboard

### Technology Stack

- **Vue 3** with Composition API
- **KITT UI** components (Statamic v6 native, `ui-` prefixed)
- **Inertia.js** for CP pages (Statamic v6 uses Inertia for CP, confirmed via docs: `Statamic.$inertia.register()` for page components, `Inertia::render()` from controllers)
- **Tailwind 4** via `@statamic/cms/tailwind.css`
- **Pinia** for state management (if needed)

### Frontend Structure

```
resources/
  js/
    cp.js                        # Entry point, registers Inertia pages via Statamic.$inertia.register()
    pages/
      Dashboard.vue              # Overview/stats
      ConnectionWizard.vue       # Onboarding flow
      Tokens/
        Index.vue                # Token management (admin: all, user: own)
        Create.vue               # Create token with scope picker
      AuditLog.vue               # Searchable operations log
      Permissions.vue            # Per-tool access control
      Settings.vue               # Configuration
    components/
      ConnectionSnippet.vue      # Config generator per client
      ScopeSelector.vue          # Permission scope picker
      StatsCard.vue              # Dashboard metric card
      ToolActivityChart.vue      # Activity visualization
  css/
    cp.css                       # Tailwind 4 via @statamic/cms/tailwind.css
```

### Service Provider Registration

```php
// Vite inputs
protected $vite = [
    'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    'publicDirectory' => 'resources/dist',
];

// CP routes
protected $routes = [
    'cp' => __DIR__.'/../routes/cp.php',
];

// Navigation
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

### Statamic Permissions

```php
Permission::group('mcp', 'MCP Server', function () {
    Permission::register('view mcp dashboard')
        ->label('View MCP Dashboard');

    Permission::register('access mcp connect')
        ->label('Access Connection Wizard');

    Permission::register('manage mcp tokens', function ($p) {
        $p->children([
            Permission::make('manage own mcp tokens')->label('Manage Own Tokens'),
            Permission::make('manage all mcp tokens')->label('Manage All Tokens'),
        ]);
    })->label('Manage API Tokens');

    Permission::register('view mcp audit')
        ->label('View Audit Log');

    Permission::register('manage mcp permissions')
        ->label('Manage MCP Permissions');

    Permission::register('manage mcp settings')
        ->label('Manage MCP Settings');
});
```

### Pages Overview

| Page | Role | KITT Components | Data Source |
|------|------|----------------|-------------|
| **Dashboard** | Admin | `ui-card`, `ui-table`, `ui-badge` | StatsService |
| **Connection Wizard** | User | `ui-select`, `ui-input`, `ui-button`, `ui-tabs` | ClientConfigGenerator |
| **Tokens (Index)** | Both | `ui-table`, `ui-badge`, `ui-button`, `ui-modal` | TokenRepository |
| **Tokens (Create)** | Both | `ui-input`, `ui-checkbox`, `ui-select`, `ui-button` | TokenService |
| **Audit Log** | Admin | `ui-table`, `ui-input`, `ui-select`, `ui-badge` | AuditService |
| **Permissions** | Admin | `ui-table`, `ui-switch`, `ui-select` | Config + Permissions |
| **Settings** | Admin | `ui-input`, `ui-switch`, `ui-select`, `ui-button` | Config |

### Connection Wizard Clients

Hardcoded generators for known clients + custom/generic option:

- Claude Code / Claude Desktop
- Cursor
- ChatGPT (Custom GPT via OpenAPI)
- Gemini (AI Studio)
- Windsurf
- Continue
- Generic/Custom (shows endpoint URL + token for manual config)

Each client selection generates a copy-paste config snippet with the user's endpoint and token pre-filled.

---

## 5. Safeguards & Observability

### Unified Permission Pipeline

```
Tool call → shouldRegister() (tool visibility per user context)
         → BaseStatamicTool::handle() [final]
           → resolvePermissions(arguments)
             ├─ CLI: Full access (no checks)
             ├─ Web + Token: Validate token scopes against action
             ├─ Web + Session: Validate Statamic user permissions
             └─ Web + OAuth: Validate OAuth scopes
           → execute() [error handling, correlation ID, perf monitoring]
             → executeInternal() [business logic]
           → auditLog()
           → Response::structured(result)->withMeta(metadata)
```

### Granular Permission Mapping

```php
// tool.action → required scope
'statamic-entries.list'       → 'entries:read'
'statamic-entries.create'     → 'entries:write'
'statamic-entries.delete'     → 'entries:delete'
'statamic-system.config_set'  → 'system:admin'
```

Token scopes map directly: a token with scope `entries:read` can only call list/get actions on EntriesRouter.

### Rate Limiting

Replaces the current `HasRateLimiting` concern with a dedicated middleware:

**New file:** `src/Http/Middleware/McpRateLimiter.php`

```php
'rate_limiting' => [
    'enabled' => true,
    'strategy' => 'sliding_window',  // sliding_window | fixed_window
    'global' => ['max' => 120, 'per_minutes' => 1],
    'per_tool' => [
        'statamic-entries' => ['max' => 60, 'per_minutes' => 1],
        'statamic-system'  => ['max' => 10, 'per_minutes' => 1],
    ],
    'per_token' => true,  // Rate limit per token, not per IP
],
```

For CLI context: rate limiting is disabled (local usage).

### Audit Logging

Each operation logs:

```php
[
    'timestamp'      => '2026-03-11T14:30:00Z',
    'correlation_id' => 'mcp_abc123',
    'user_id'        => 'user-uuid',
    'token_id'       => 'token-uuid',
    'auth_method'    => 'api_token',  // api_token | session | oauth | cli
    'tool'           => 'statamic-entries',
    'action'         => 'create',
    'arguments'      => [...],         // Sanitised (sensitive fields redacted)
    'result'         => 'success',     // success | error
    'duration_ms'    => 45,
    'ip'             => '192.168.1.1', // Web only
]
```

Storage: configurable — flat-file (default), database, or custom driver.
Retention: configurable (default: 30 days).

### Enhanced Dry-Run

All write operations return structured diffs in dry-run mode. The response is wrapped by `BaseStatamicTool.handle()` into:

```php
Response::structured([
    'success' => true,
    'data' => [
        'dry_run' => true,
        'changes' => [
            ['field' => 'title', 'from' => 'Old', 'to' => 'New'],
        ],
        'affected_resources' => ['entry::blog-post-1'],
        'requires_permissions' => ['entries:write'],
    ],
    'meta' => [...]
])
```

### Health Checks

`statamic-system` info/health actions include:
- MCP server status and version
- Active tokens (count, expired, last used)
- Rate limit status
- Audit log size
- Transport type (Streamable HTTP)
- OAuth 2.1 status (enabled/disabled)

---

## 6. Configuration

Single config file with sane defaults and env overrides:

```php
// config/statamic/mcp.php
return [
    'version' => '2.0.0',

    // ── Transport ─────────────────────────────
    'web' => [
        'enabled' => env('STATAMIC_MCP_WEB_ENABLED', false),
        'path'    => env('STATAMIC_MCP_WEB_PATH', '/mcp/statamic'),
    ],

    // ── Authentication ────────────────────────
    'auth' => [
        'tokens' => [
            'enabled'            => true,
            'storage'            => env('STATAMIC_MCP_TOKEN_STORAGE', 'file'),
            'prefix'             => 'smc_',
            'default_expiry_days'=> null,
            'max_tokens_per_user'=> 10,
        ],
        'oauth' => [
            'enabled' => env('STATAMIC_MCP_OAUTH_ENABLED', false),
        ],
        'legacy' => [
            'basic_auth'    => env('STATAMIC_MCP_LEGACY_BASIC_AUTH', true),
            'base64_bearer' => env('STATAMIC_MCP_LEGACY_BEARER', true),
        ],
    ],

    // ── Rate Limiting ─────────────────────────
    'rate_limiting' => [
        'enabled'   => env('STATAMIC_MCP_RATE_LIMITING', true),
        'strategy'  => 'sliding_window',
        'global'    => ['max' => 120, 'per_minutes' => 1],
        'per_tool'  => [],
        'per_token' => true,
    ],

    // ── Audit Logging ─────────────────────────
    'audit' => [
        'enabled'          => env('STATAMIC_MCP_AUDIT_ENABLED', true),
        'driver'           => env('STATAMIC_MCP_AUDIT_DRIVER', 'file'),
        'retention_days'   => 30,
        'log_arguments'    => true,
        'sensitive_fields' => ['password', 'token', 'secret'],
    ],

    // ── Tool Domains ──────────────────────────
    'tools' => [
        'blueprints'     => ['enabled' => true, 'web_enabled' => true],
        'entries'        => ['enabled' => true, 'web_enabled' => true],
        'terms'          => ['enabled' => true, 'web_enabled' => true],
        'globals'        => ['enabled' => true, 'web_enabled' => true],
        'structures'     => ['enabled' => true, 'web_enabled' => true],
        'assets'         => ['enabled' => true, 'web_enabled' => true],
        'users'          => ['enabled' => true, 'web_enabled' => false],
        'system'         => ['enabled' => true, 'web_enabled' => false],
        'content-facade' => ['enabled' => true, 'web_enabled' => false],
    ],

    // ── Dashboard ─────────────────────────────
    'dashboard' => [
        'enabled'              => env('STATAMIC_MCP_DASHBOARD_ENABLED', true),
        'stats_retention_days' => 90,
    ],

    // ── Connection Wizard ─────────────────────
    'clients' => [
        'claude_code', 'claude_desktop', 'cursor',
        'chatgpt', 'gemini', 'windsurf', 'continue',
    ],
];
```

### Design Principles

- Sane defaults — works out-of-the-box with `MCP_WEB_ENABLED=true`
- Everything can be disabled — granular per tool, per feature
- Env overrides for deployment-specific config
- No mandatory database migrations (flat-file default everywhere)
- `users` and `system` tools disabled for web by default (security)

### Config Migration

The install command detects existing v1.x config structure (`tools.statamic.content.*` nesting) and offers to migrate to the new flat structure. Existing configs continue to work via a compatibility shim in ServiceProvider that maps old keys to new ones, with a deprecation warning logged on boot.

---

## 7. Migration & File Changes

### Files Removed

| File | Reason |
|------|--------|
| `src/Support/StatamicVersion.php` | No v5 support |
| `src/Mcp/Tools/Concerns/HasRateLimiting.php` | Replaced by `McpRateLimiter` middleware |

### Files Refactored

| File | Changes |
|------|---------|
| `BaseStatamicTool.php` | `handle()` uses `Response::structured()` → `ResponseFactory.withMeta()`. Add `shouldRegister()` base implementation (CLI=true, web=check config). Remove `getToolName()`/`getToolDescription()`/`defineSchema()` — tools use `#[Name]`/`#[Description]` attributes and `schema()` method (v0.6 convention). |
| `BaseRouter.php` | Same attribute migration. Retain agent education. Add `outputSchema()` base. |
| All 10 routers | `#[Name]`/`#[Description]` attributes, tool annotations (`#[IsReadOnly]` etc.), `schema()`, `shouldRegister()`, `outputSchema()` |
| `DiscoveryTool.php` | Attribute migration, `shouldRegister()` |
| `SchemaTool.php` | Attribute migration, `outputSchema()` for typed introspection |
| `AuthenticateForMcp.php` | New `smc_` token validation, legacy deprecation warnings, OAuth guard |
| `RequireMcpPermission.php` | Scope-based permission check against token scopes |
| `ServiceProvider.php` | Dashboard routes, nav, permissions, Inertia, Vite, config migration shim |
| `StatamicMcpServer.php` | Version bump to 2.0.0, cleanup |
| `config/statamic/mcp.php` | New unified structure |

### New Files

```
src/
  Auth/
    McpToken.php                        # Token value object
    McpTokenRepository.php              # CRUD (file/database drivers)
    TokenScope.php                      # Scope definitions and validation
    Guards/
      McpTokenGuard.php                 # Custom auth guard for smc_ tokens
  Http/
    Controllers/
      DashboardController.php           # Stats, health overview
      ConnectionWizardController.php    # Client configs, test endpoint
      TokenController.php               # Token CRUD
      AuditLogController.php            # Log browsing, filtering
      PermissionsController.php         # Tool permission management
      SettingsController.php            # Config management
    Middleware/
      McpRateLimiter.php                # New: sliding window rate limiter
  Services/
    AuditService.php                    # Unified audit logging (file + database drivers)
    StatsService.php                    # Dashboard metrics aggregation
    ClientConfigGenerator.php           # Config snippets per AI client
    TokenService.php                    # Token generation, hashing, validation
routes/
  cp.php                                # Dashboard CP routes
resources/
  js/cp.js                              # Vue entry point
  js/pages/*.vue                        # 7 Inertia pages
  js/components/*.vue                   # Shared components
  css/cp.css                            # Tailwind 4
vite.config.js
package.json
```

### Test Migration

- Remove all v5-specific test cases
- Update TestCase base class (remove v5 bootstrapping)
- New tests for: token auth, token scopes, dashboard controllers, audit service, rate limiter, config migration shim
- Retain and update all existing router tests (response format changes from text to structured)

### Install Command

`php artisan statamic-mcp:install` updated to:
- Publish new config (with v1.x migration detection)
- Create `storage/app/statamic-mcp/tokens/` directory
- Create `storage/app/statamic-mcp/audit/` directory
- Optional: run database migrations (only if database driver chosen)
- Register MCP permissions in Statamic
- Generate first API token for the current user (interactive)

---

## 8. CLAUDE.md Updates

The project's CLAUDE.md must be updated as part of v2.0:
- Remove all "Laravel MCP v0.2.0" references
- Document actual v0.5+ patterns (property-based identity, annotations, outputSchema, shouldRegister)
- Update schema examples to current patterns
- Remove v5/v6 dual-version documentation
- Document new auth, dashboard, and config architecture

---

## 9. Out of Scope (v2.0)

- Custom client template system (hardcoded clients + generic is sufficient)
- Multi-tenant / multi-site token isolation
- Real-time WebSocket dashboard updates
- Plugin/extension API for custom tools
- Passport as a bundled dependency
- Full removal of legacy auth (deferred to v3.0)

These can be considered for v2.x minor releases based on community feedback.

---

## 10. Success Criteria

- [ ] All existing router tests pass with structured response format
- [ ] PHPStan Level 8 with zero errors
- [ ] Token auth flow works: create token via CP → use in Claude Code → tool call succeeds
- [ ] Token scoping works: token with `entries:read` cannot call `entries:create`
- [ ] Legacy auth logs deprecation warning but still works
- [ ] Dashboard loads with KITT UI on Statamic v6
- [ ] Connection Wizard generates valid config for all 7 clients
- [ ] Audit log captures all web MCP operations with correct auth_method
- [ ] Rate limiting enforced per-token with sliding window
- [ ] shouldRegister() hides tools based on user permissions in web, shows all in CLI
- [ ] OAuth toggle works: enabled with Passport = works, enabled without Passport = graceful error
- [ ] Config migration shim maps v1.x config to v2.0 structure with deprecation warning
- [ ] `composer quality` passes (pint + stan + test)

---

## 11. Review Corrections Applied

Issues identified during spec review, then re-verified against laravel/mcp v0.6.2 source code:

1. **laravel/mcp version** — Initial review claimed v0.6 didn't exist (only checked installed v0.5.2). **Re-verified:** v0.6.2 released 2026-03-10, confirmed compatible via `composer require --dry-run`. Spec targets `^0.6`.
2. **`#[Description]` attribute** — Initial review said it doesn't exist. **Re-verified from v0.6.2 source:** `Laravel\Mcp\Server\Attributes\Description` exists (new in v0.6). Also `#[Name]`, `#[Title]`, `#[Version]`, `#[Uri]`, `#[Instructions]`, `#[MimeType]`. Spec correctly uses these.
3. **Schema syntax** — Static `JsonSchema::string()` calls confirmed correct in both v0.5 and v0.6. Not instance methods. The `$schema` param is passed for factory context via first-class callable `$this->schema(...)`.
4. **`schema()` method** — Renamed from `defineSchema()` (protected) to `schema()` (public) in v0.6. BaseStatamicTool bridges this.
5. **BaseStatamicTool.handle() stays final** — Centralised error handling, correlation IDs, and performance monitoring preserved. Tools continue implementing `executeInternal()`.
6. **Response::structured() returns ResponseFactory** — Confirmed. `handle()` uses `Response::structured($data)->withMeta($meta)`.
7. **shouldRegister() is CLI-safe** — No `Request` parameter. Uses container DI. Base implementation checks `isCliContext()` first.
8. **illuminate/json-schema** — Removed from version constraints (provided by Laravel framework).
9. **Token storage path** — Changed from `storage/statamic/` to `storage/app/statamic-mcp/` to avoid conflicts with `php artisan statamic:clear`.
10. **Tool count corrected** — 12 tools (10 routers + 2 system), not 11. ContentRouter explicitly flagged for deprecation evaluation.
11. **OAuth guard** — Added Passport installation check to prevent runtime errors.
12. **Rate limiter implementation** — New `McpRateLimiter.php` middleware explicitly listed in new files.
13. **Config migration** — Added compatibility shim for v1.x configs with deprecation warnings.
14. **CLAUDE.md updates** — Added as explicit deliverable (Section 8).
15. **outputSchema()** — Confirmed exists in v0.6.2 Tool class. Uses same `JsonSchema::*` static pattern.
