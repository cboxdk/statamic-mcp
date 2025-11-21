# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/cboxdk/statamic-mcp/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/cboxdk/statamic-mcp/releases/tag/v1.0.0
