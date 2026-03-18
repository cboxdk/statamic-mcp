# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - 2026-03-18

### Fixed
- Token expiry date validation no longer blocks submission — `max_token_lifetime_days` is now a default suggestion, not a hard server-side rejection
- Token form error feedback uses Statamic toast notifications and native `ui-error-message` components with red border highlighting

### Added
- Scope presets (Read Only, Content Editor, Full Access) in token create/edit form, matching documented common combinations
- Preset-aware badge display in admin token table — shows preset name instead of listing individual scopes
- Admin token form now uses Statamic-style grouped permission cards with per-group "Check All"

### Removed
- Internal development plans and specs (`docs/superpowers/`) accidentally included in v2.0.0

## [2.0.0] - 2026-03-18

### Breaking Changes

#### Statamic v5 Support Removed
- **Statamic v6.6+ only** — minimum requirement is now `statamic/cms:^6.6`
- **Laravel 12+ only** — supports `laravel/framework:^12.0` and `^13.0` (via Statamic v6)
- Removed `StatamicVersion` dual-version detection helper
- Removed v5 compatibility shims and feature flags

#### Laravel MCP Upgraded to v0.6
- **laravel/mcp** upgraded from `^0.2` to `^0.6`
- Tools now use `#[Name]` and `#[Description]` attributes instead of methods
- `BaseStatamicTool` now wraps `handle()` — tools implement `executeInternal()`
- Response format changed to `Response::structured()` / `Response::error()`
- `ToolResult` and `ToolInputSchema` classes removed

#### Architecture: Single-Purpose Tools → Router Pattern
- **11 MCP tools** replace 140+ individual tools
- Each router handles multiple actions via `action` parameter
- Tool names changed from dot notation (`statamic.blueprints.list`) to hyphenated routers (`statamic-blueprints` with `action: list`)
- `type` parameter renamed to `resource_type` (avoids JSON Schema keyword collision)

#### Config Restructured
- Per-tool config (`tools.statamic.content.web_enabled`) replaced with simple toggles (`tools.entries.enabled`)
- Per-tool rate limiting removed — single global `rate_limit.max_attempts`
- New sections: `stores`, `storage`, `oauth`, `security`, `dashboard`
- Re-publish required: `php artisan vendor:publish --tag=statamic-mcp-config --force`

### Added

#### Storage Driver Abstraction
- `TokenStore` and `AuditStore` contracts with config-based class binding
- File drivers (default): YAML tokens with hash index, JSONL audit with SplFileObject
- Database drivers: Eloquent with atomic operations
- `mcp:migrate-store` command for file↔database migration
- `mcp:prune-audit` and `mcp:prune-tokens` commands

#### OAuth 2.1 Authorization Server
- Discovery endpoints (`.well-known/oauth-protected-resource`, `.well-known/oauth-authorization-server`)
- Dynamic Client Registration (RFC 7591) with per-IP quotas
- Authorization Code + PKCE S256 with Blade consent screen
- Refresh token rotation (30-day TTL)
- Token revocation endpoint (RFC 7009)
- `OAuthDriver` interface with built-in file driver and database driver
- Live-tested with ChatGPT and Claude Desktop

#### Scoped API Token System
- 21 granular permission scopes via `TokenScope` backed string enum
- SHA-256 hashed token storage
- Custom `McpTokenGuard` registered as `mcp` auth guard
- `AuthenticateForMcp` middleware with Bearer + Basic Auth fallback
- `RequireMcpPermission` middleware for scope validation

#### CP Dashboard (Vue 3 + KITT UI)
- User page (`/cp/mcp`): Connect + My Tokens
- Admin page (`/cp/mcp/admin`): All Tokens, Activity log, System info
- Stateful tab URLs (`?tab=activity`)
- Connect panel with client config snippets for Claude/Cursor/ChatGPT/Windsurf

#### Domain Routers
- `statamic-blueprints` — list, get, create, update, delete, scan, generate, types, validate
- `statamic-entries` — list, get, create, update, delete, publish, unpublish
- `statamic-terms` — list, get, create, update, delete
- `statamic-globals` — list, get, update
- `statamic-structures` — list, get, create, update, delete, configure (collections, taxonomies, navigations, sites, global sets)
- `statamic-assets` — list, get, create, update, delete, move, copy, upload (containers + assets)
- `statamic-users` — list, get, search, create, update, delete, activate, deactivate, assign_role, remove_role
- `statamic-system` — info, health, cache_status, cache_clear, cache_warm, config_get, config_set
- `statamic-content-facade` — content_audit, cross_reference

#### Agent Education Tools
- `statamic-system-discover` — intent-based tool and action discovery
- `statamic-system-schema` — tool schema inspection

#### Security Hardening
- Centralized web context security guard in `BaseRouter`
- Path traversal protection on all file-based stores
- Recursive null-byte validation on tool arguments
- Atomic rate limiting per-IP and per-token
- OAuth scope injection prevention
- PKCE S256 strict enforcement with timing-safe comparison
- Constant-time Basic Auth to prevent user enumeration
- PII redaction in audit logs
- `config_set` restricted to CLI-only
- CORS wildcard rejected in production
- Directory permissions 0700 for token/OAuth storage
- Correlation ID validation (alphanumeric, max 128 chars)
- HTTPS error messages don't leak env variable names
- Per-IP OAuth client registration quota (default 5)

#### Audit Logging
- Single entry per tool call with user/token/IP context
- Mutation tracking (resource type, id, changed fields)
- Pluggable storage backends (file JSONL or database)
- Admin dashboard with filters and detail panel

#### Events
- `McpTokenSaved` and `McpTokenDeleted` for Statamic Git automation

#### Documentation
- Complete docs site: introduction, getting-started, authentication, configuration, tools
- UPGRADE.md migration guide from v1.x

### Changed
- `web.enabled` now defaults to `true` (was `false`)
- `symfony/yaml` constraint widened to `^7.0 || ^8.0`
- All error responses use standardized envelope format
- `parseBytes()` replaced with PHP 8.3 `ini_parse_quantity()`
- `DatabaseTokenStore` uses `fill()` instead of `forceFill()`

### Removed
- Statamic v5 compatibility layer and `StatamicVersion` helper
- 140+ individual single-purpose tool classes (replaced by 11 routers)
- Dot-notation tool names
- `getToolName()` / `getToolDescription()` method pattern
- `ToolResult` and `ToolInputSchema` imports
- `AuditService` (consolidated into `ToolLogger`)
- `McpRateLimiter` (dead code)
- `statamic-content` router (split into entries, terms, globals)
- `HandlesContainers` trait (dead code — shadowed by AssetsRouter)
- `decay_minutes` config key (was unused)
- `web.middleware` config key (never existed)
- Deprecated `ToolLogger` no-op methods
- Custom OAuth login page

---

## [1.4.0] - 2025-01-19

### Added

#### Statamic v6 Dual Version Support
- Full compatibility with both Statamic v5.65+ and v6.0+
- Automatic version detection — no code changes needed when upgrading
- Zero breaking changes — all tools work identically across versions
- Asset permission compatibility for both v5 and v6 models

#### Version Detection System
- `StatamicVersion` helper class for runtime version detection
- Methods: `isV6OrLater()`, `supportsV6OptIns()`, `hasV6AssetPermissions()`

#### Testing Infrastructure
- GitHub Actions test matrix for PHP 8.3 × Statamic 5.65/6.0
- Automated dual-version validation in CI/CD

### Changed
- **PHP**: Minimum version raised to `^8.3`
- **Statamic CMS**: Updated to `^5.65|^6.0`
- **Laravel**: Support for `^11.0|^12.0`
- **Pest**: Updated to `^4.1`
- PHPStan Level 8 compliance across all files
- Composer minimum-stability changed from `dev` to `stable`

---

## [1.3.0] - 2025-01-15

### Added
- Blueprint type analysis and generation tools
- Comprehensive global management (sets and values)
- Advanced template performance analysis
- Navigation structure management
- Enhanced cache management with selective clearing

---

## [1.2.0] - 2025-01-10

### Added
- Entry management tools (CRUD operations)
- Term management tools (taxonomy terms)
- Template validation and linting
- Development workflow tools

---

## [1.1.0] - 2025-01-05

### Added
- Collection and taxonomy management
- Blueprint scanning and validation
- System information and cache tools

---

## [1.0.0] - 2025-01-01

### Added
- Initial release
- Core MCP server functionality
- Basic blueprint and content management
- Laravel MCP v0.2.0 integration
- Comprehensive test suite

[2.0.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.4.0...v2.0.0
[1.4.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/cboxdk/statamic-mcp/releases/tag/v1.0.0
