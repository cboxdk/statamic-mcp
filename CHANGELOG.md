# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `shouldRegister()` — config-driven tool registration (`statamic.mcp.tools.{domain}.enabled`)
- `outputSchema()` — MCP v0.6 standard response envelope on all tools
- Dedicated `mcp` log channel writing to `storage/logs/mcp-audit.log`
- Correlation IDs preserved in MCP error responses for traceability

### Fixed
- Activity tab in CP dashboard now shows audit entries (was reading from wrong log path)
- Stale dot-notation tool names in agent education prompts
- Test config key mismatch in permission tests

### Removed
- `AuditService` — functionality consolidated into `ToolLogger`
- `McpRateLimiter` — dead code, never wired into tool execution
- `statamic-content` router — replaced by `statamic-entries`, `statamic-terms`, `statamic-globals`
- Stale `tools.content` config entry

## [2.0.0] - 2026-03-12

### Breaking Changes

#### Statamic v5 Support Removed
- **Statamic v6 only** — minimum requirement is now `statamic/cms:^6.0`
- **Laravel 12 only** — minimum requirement is now `laravel/framework:^12.0`
- Removed `StatamicVersion` dual-version detection helper
- Removed v5 compatibility shims and feature flags

#### Laravel MCP Upgraded to v0.6
- **laravel/mcp** upgraded from `^0.2` to `^0.6`
- Tools now use `#[Name]` and `#[Description]` attributes instead of methods
- `BaseStatamicTool` now wraps `handle()` — tools implement `executeInternal()`
- Response format changed to `Response::structured()` / `Response::error()`
- `ToolResult` and `ToolInputSchema` classes removed

#### Architecture: Single-Purpose Tools → Router Pattern
- **~10 domain routers** replace 140+ individual tools
- Each router handles multiple actions via `action` parameter
- Tool names changed from dot notation (`statamic.blueprints.list`) to hyphenated routers (`statamic-blueprints` with `action: list`)

### Added

#### Web MCP Endpoint
- Browser-accessible MCP endpoint at configurable path (default `/mcp/statamic`)
- Enable via `STATAMIC_MCP_WEB_ENABLED=true`
- Compatible with Claude, Cursor, ChatGPT, Windsurf, and other MCP clients
- Bearer token and Basic Auth authentication

#### Scoped API Token System
- 19 granular permission scopes via `TokenScope` enum
- SHA-256 hashed token storage with `McpToken` Eloquent model
- Custom `McpTokenGuard` registered as `mcp` auth guard
- Token creation, listing, and revocation via CP dashboard
- Configurable token expiry and max tokens per user
- `AuthenticateForMcp` middleware with Bearer + Basic Auth fallback
- `RequireMcpPermission` middleware for scope validation

#### CP Dashboard (Vue 3 + KITT UI)
- Unified single-page dashboard at Tools → MCP with 4 tabs:
  - **Connect** — Endpoint URL, copy-paste config snippets for Claude/Cursor/ChatGPT/Windsurf
  - **Tokens** — Create, list, revoke API tokens with scope selection
  - **Activity** — Audit log of MCP tool calls with filtering
  - **Settings** — System stats, endpoint status, rate limiting info
- Built with Statamic v6 KITT UI components (`ui-tabs`, `ui-tab-list`, etc.)
- Vite IIFE build with Vue externalized as `window.Vue`

#### Domain Routers
- `statamic-blueprints` — list, get, create, update, delete, scan, generate, types, validate
- `statamic-entries` — Entry operations with filtering, search, pagination, bulk ops
- `statamic-terms` — Taxonomy term operations with slug conflict prevention
- `statamic-globals` — Global set structure and values with multi-site support
- `statamic-structures` — Collection, taxonomy, navigation, site configuration
- `statamic-assets` — Asset container and file operations with metadata management
- `statamic-users` — User CRUD, role assignment, group management
- `statamic-system` — System info, health checks, cache management

#### Agent Education Tools
- `statamic-discovery` — Intent-based tool discovery for AI agents
- `statamic-schema` — Tool schema inspection

#### Workflow Facades
- `statamic-content-facade` — High-level workflow operations orchestrating multiple routers

#### Security
- Rate limiting per token (configurable max attempts and decay)
- Audit logging for all MCP operations
- Path traversal protection
- Force web mode option for production environments

#### Documentation
- `docs/WEB_MCP_SETUP.md` — Detailed web endpoint setup guide
- `docs/AI_ASSISTANT_SETUP.md` — Client-specific configuration for Claude, Cursor, ChatGPT, Windsurf
- Installation command: `php artisan mcp:statamic:install`

### Changed
- **PHP minimum**: `^8.3` (unchanged from v1.4)
- **Statamic**: `^5.65|^6.0` → `^6.0` (v6 only)
- **Laravel**: `^11.0|^12.0` → `^12.0` (via Statamic v6)
- **Laravel MCP**: `^0.2` → `^0.6`
- **Orchestra Testbench**: Updated to `^11.0`
- Config namespace standardized to `statamic.mcp.*`
- All tools include PHPStan Level 8 strict typing
- Comprehensive PHPDoc annotations on all public methods

### Removed
- Statamic v5 compatibility layer and version detection
- 140+ individual single-purpose tool classes (replaced by routers)
- Dot-notation tool names
- `getToolName()` / `getToolDescription()` method pattern
- Legacy `ToolResult` and `ToolInputSchema` imports

### Upgrade Guide

#### From 1.4.x to 2.0.0

**Prerequisites:**
1. Upgrade to Statamic v6 and Laravel 12 **before** updating this package
2. Ensure PHP 8.3+

**Update Steps:**
```bash
# Update the MCP server package
composer require cboxdk/statamic-mcp:^2.0

# Run the installation command (sets up config, migrations, assets)
php artisan mcp:statamic:install

# Run migrations for token storage
php artisan migrate

# Clear caches
php artisan cache:clear
php artisan statamic:stache:clear
```

**Enable Web MCP (optional):**
```env
STATAMIC_MCP_WEB_ENABLED=true
STATAMIC_MCP_WEB_PATH="/mcp/statamic"
```

**If using MCP tools programmatically:**
- Tool names changed: `statamic.blueprints.list` → call `statamic-blueprints` with `action: list`
- Update any custom integrations to use the router pattern

---

## [1.4.0] - 2025-01-19

### Added

#### 🎯 Statamic v6 Dual Version Support
- **Full compatibility** with both Statamic v5.65+ and v6.0+
- **Automatic version detection** - no code changes needed when upgrading
- **Zero breaking changes** - all tools work identically across versions
- **Asset permission compatibility** - handles both v5 and v6 permission models

#### 🔧 Version Detection System
- New `StatamicVersion` helper class for runtime version detection
- Methods: `isV6OrLater()`, `supportsV6OptIns()`, `hasV6AssetPermissions()`
- Automatic adaptation to installed Statamic version
- Feature detection for v6-specific capabilities

#### 📚 Documentation
- Comprehensive [Statamic v6 Migration Guide](docs/STATAMIC_V6_MIGRATION.md)
- Step-by-step upgrade instructions with testing checklist
- Troubleshooting guide for common migration issues
- Rollback procedures for safe migrations
- Updated README with version compatibility matrix
- Enhanced CLAUDE.md with v6 development patterns

#### 🧪 Testing Infrastructure
- GitHub Actions test matrix for PHP 8.3 × Statamic 5.65/6.0
- Automated dual-version validation in CI/CD
- Version-specific test execution capabilities
- Comprehensive test coverage maintained (149 tests, 1476 assertions)

### Changed

#### 📦 Dependencies
- **PHP**: Minimum version raised to `^8.3` (required for Pest v4 and Statamic v6)
- **Statamic CMS**: Updated to `^5.65|^6.0` (dual version support)
- **Laravel**: Support for `^11.0|^12.0` via Statamic
- **Orchestra Testbench**: Updated to `^9.0|^10.0|^11.0`
- **Pest**: Updated to `^4.1` (stable release with PHP 8.3 requirement)
- **Pest Plugin Laravel**: Updated to `^4.0` (stable release with PHP 8.3 requirement)
- **Laravel Pint**: Updated to `^1.17`
- **Larastan**: Updated to `^3.0`

#### 🏗️ Architecture
- All MCP tools now include version information in responses
- Asset tools automatically detect and use appropriate permission model
- Enhanced error handling with version-aware validation
- Improved cache invalidation with targeted clearing

#### 🎨 Code Quality
- **PHPStan Level 8**: All type errors resolved (100% compliance)
- Fixed type safety issues in ContentRouter (slug validation error handling)
- Laravel Pint formatting applied across all files
- Strict type declarations enforced project-wide
- **Composer Stability**: Changed `minimum-stability` from `dev` to `stable`
- **Production Dependencies**: Removed @dev flags from Pest packages for stable releases

### Upgrade Guide

#### From 1.3.x to 1.4.0

**Prerequisites:**
1. Upgrade to PHP 8.3+ before updating this package
2. Backup your Statamic installation

**Update Steps:**
```bash
# Update PHP requirement in your project
composer require "php:^8.3"

# Update the MCP server package
composer update cboxdk/statamic-mcp

# Clear caches
php artisan cache:clear
php artisan statamic:stache:clear

# Verify installation
composer show cboxdk/statamic-mcp
```

**Testing v6 Opt-In Features (Statamic v5.65+):**
```bash
# Enable v6 asset permissions in .env
STATAMIC_ASSETS_V6_PERMISSIONS=true

# Test your application thoroughly
php artisan test
```

**Upgrading to Statamic v6 (when available):**
```bash
# Update Statamic to v6
composer require "statamic/cms:^6.0"

# Follow the complete migration guide
# See: docs/STATAMIC_V6_MIGRATION.md
```

### Migration Notes

#### Breaking Changes
**None** - This release maintains 100% backward compatibility with existing code.

#### Behavioral Changes
- Asset tools automatically detect v6 permission model when enabled
- Version information included in all tool responses
- Cache clearing strategies optimized for dual version support

#### New Capabilities Available
- Runtime version detection via `StatamicVersion` helper
- v6 feature flags for conditional logic
- Enhanced asset permission handling

### Technical Details

#### Version Detection Example
```php
use Cboxdk\StatamicMcp\Support\StatamicVersion;

// Check current version
StatamicVersion::current();        // "5.69.0"
StatamicVersion::isV6OrLater();    // false
StatamicVersion::majorVersion();   // 5

// Check v6 features
StatamicVersion::supportsV6OptIns();      // true (v5.65+)
StatamicVersion::hasV6AssetPermissions(); // depends on config

// Get comprehensive info
StatamicVersion::info();
// Returns: [
//   'statamic_version' => '5.69.0',
//   'is_v6' => 'false',
//   'supports_v6_opt_ins' => 'true',
//   'v6_asset_permissions' => 'disabled'
// ]
```

#### Asset Permission Model Detection
The MCP server automatically detects and adapts to the asset permission model:
- **v5 Model**: Traditional folder-based permissions
- **v6 Model**: New permission system (when enabled)

Detection happens automatically - no code changes required.

### Testing Matrix

| PHP | Statamic | Laravel | Status |
|-----|----------|---------|--------|
| 8.3 | 5.65+    | 11      | ✅ Tested |
| 8.3 | 5.65+    | 12      | ✅ Tested |
| 8.3 | 6.0+     | 11/12   | 🚧 Ready when v6 releases |

### Support

- **Issues**: Report bugs at [GitHub Issues](https://github.com/cboxdk/statamic-mcp/issues)
- **Migration Guide**: See [docs/STATAMIC_V6_MIGRATION.md](docs/STATAMIC_V6_MIGRATION.md)
- **Statamic v6 Docs**: https://statamic.dev/upgrade-guide

---

## [1.3.0] - 2025-01-15

### Added
- Blueprint type analysis and generation tools
- Comprehensive global management (sets and values)
- Advanced template performance analysis
- Navigation structure management
- Enhanced cache management with selective clearing

### Changed
- Improved error handling across all tools
- Enhanced performance optimizations
- Better pagination support for large datasets

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

[Unreleased]: https://github.com/cboxdk/statamic-mcp/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.4.0...v2.0.0
[1.4.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/cboxdk/statamic-mcp/releases/tag/v1.0.0
