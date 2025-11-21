# Statamic MCP Server - V6 Migration Guide

This guide helps you migrate your Statamic installation and MCP server from Statamic v5 to v6.

## 📋 Table of Contents

- [Prerequisites](#prerequisites)
- [Version Compatibility](#version-compatibility)
- [Upgrade Steps](#upgrade-steps)
- [Testing Your Upgrade](#testing-your-upgrade)
- [V6 Features](#v6-features)
- [Troubleshooting](#troubleshooting)
- [Rollback Plan](#rollback-plan)

## Prerequisites

Before upgrading to Statamic v6, ensure your environment meets these requirements:

### Required Versions

- **PHP**: 8.3 or higher (required for Pest v4 testing framework)
- **Laravel**: 11.0 or 12.0
- **Statamic MCP Server**: Latest version with v6 support
- **Composer**: 2.x

### Backup Your Data

**CRITICAL**: Always backup before upgrading:

```bash
# Backup your database
php artisan statamic:export

# Backup your content files
tar -czf content-backup-$(date +%Y%m%d).tar.gz content/

# Backup your config
tar -czf config-backup-$(date +%Y%m%d).tar.gz config/
```

### Check Current Installation

```bash
# Check your current Statamic version
php artisan statamic:version

# Verify PHP version
php -v

# Check Laravel version
php artisan --version
```

## Version Compatibility

The Statamic MCP Server supports **dual version compatibility**:

| MCP Server Version | Statamic Version | PHP Version | Laravel Version |
|--------------------|------------------|-------------|-----------------|
| 1.4.0+             | 5.65+ or 6.0+    | 8.3+        | 11+ or 12+      |
| 1.3.x              | 5.0 - 5.64       | 8.1+        | 10+ or 11+      |

### Automatic Version Detection

The MCP server automatically detects your Statamic version and adapts its behavior:

```php
use Cboxdk\StatamicMcp\Support\StatamicVersion;

// The MCP server uses this internally to adapt behavior
StatamicVersion::isV6OrLater();    // Automatically adjusts tool behavior
StatamicVersion::supportsV6OptIns(); // Enables v6 features in v5.65+
```

## Upgrade Steps

### Step 1: Update to Statamic v5.65+ First

If you're on Statamic v5.0 - v5.64, upgrade to v5.65+ first to test v6 opt-in features:

```bash
# Update Statamic to latest v5.65+
composer require "statamic/cms:^5.65"

# Clear caches
php artisan cache:clear
php artisan statamic:stache:clear

# Run migrations if needed
php artisan migrate
```

### Step 2: Test V6 Opt-In Features (Optional)

Statamic v5.65+ includes v6 preparation features you can enable:

```php
// config/statamic/assets.php
return [
    // Enable v6 asset permissions model
    'v6_permissions' => env('STATAMIC_ASSETS_V6_PERMISSIONS', false),
];
```

Enable in your `.env`:

```env
STATAMIC_ASSETS_V6_PERMISSIONS=true
```

Test your application with this enabled before proceeding to v6.

### Step 3: Update MCP Server

Ensure you have the latest MCP server with v6 support:

```bash
# Update the MCP server
composer update cboxdk/statamic-mcp

# Verify the version supports v6
composer show cboxdk/statamic-mcp
# Should show version 1.4.0 or higher
```

### Step 4: Upgrade to Statamic V6

When Statamic v6 is officially released:

```bash
# Update to Statamic v6
composer require "statamic/cms:^6.0"

# Update dependencies
composer update

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan statamic:stache:clear
php artisan statamic:static:clear

# Run any migrations
php artisan migrate

# Rebuild asset metadata (if needed)
php artisan statamic:assets:generate-presets
```

### Step 5: Verify MCP Server Functionality

Test that all MCP tools still work:

```bash
# Test the MCP server
php artisan mcp:serve statamic

# In your AI assistant, test a few tools:
"Use statamic.system info to show me my Statamic version"
"List all my blueprints using statamic.blueprints list"
"Show me my collections with statamic.structures list collections"
```

## Testing Your Upgrade

### Automated Testing

Run the MCP server's test suite to verify compatibility:

```bash
cd vendor/cboxdk/statamic-mcp

# Run all tests
./vendor/bin/pest

# Run specific tool tests
./vendor/bin/pest tests/Feature/Routers/
```

### Manual Testing Checklist

Test these critical MCP operations:

- [ ] **System Info**: `statamic.system info` returns correct v6 version
- [ ] **Blueprint Operations**: List, get, and validate blueprints
- [ ] **Content Operations**: Create, update, and delete entries
- [ ] **Asset Operations**: Upload and manage assets (test both permission models)
- [ ] **Global Operations**: Update global values across sites
- [ ] **Cache Operations**: Clear and warm caches
- [ ] **Template Analysis**: Validate Antlers and Blade templates

### Performance Testing

```bash
# Test template performance analysis
"Use statamic.development templates to analyze my home page template"

# Test cache operations
"Use statamic.system cache to clear all caches"

# Verify asset operations
"Use statamic.assets to list all asset containers"
```

## V6 Features

### Enhanced Asset Permissions

Statamic v6 introduces a new asset permission model. The MCP server automatically detects and supports both models:

**V5 Model** (traditional folder permissions):
```php
// Automatically used when StatamicVersion::hasV6AssetPermissions() returns false
```

**V6 Model** (new permission system):
```php
// Automatically used when StatamicVersion::hasV6AssetPermissions() returns true
```

### Version-Aware Tool Responses

All MCP tools now include version information in their responses:

```json
{
  "success": true,
  "data": {...},
  "meta": {
    "statamic_version": "6.0.0",
    "is_v6": "true",
    "v6_asset_permissions": "enabled"
  }
}
```

### Backward Compatibility

The MCP server maintains **100% backward compatibility**:

- All tool names remain the same
- All tool schemas remain unchanged
- All tool behaviors remain consistent
- Version detection happens automatically

## Troubleshooting

### Common Issues

#### MCP Server Not Detecting V6

**Symptom**: Tools report v5 version when v6 is installed

**Solution**:
```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan statamic:stache:clear

# Restart your AI assistant's MCP connection
```

#### Asset Operations Failing

**Symptom**: Asset uploads or management fail after v6 upgrade

**Solution**:
```bash
# Regenerate asset metadata
php artisan statamic:assets:generate-presets

# Check asset container permissions
php artisan statamic:assets:meta
```

#### Template Validation Errors

**Symptom**: Template tools report errors on previously valid templates

**Solution**:
```bash
# Update template cache
php artisan view:clear

# Revalidate templates
"Use statamic.development antlers-validate to check my templates"
```

### PHP Version Issues

If you see errors about minimum PHP version:

```bash
# Check your PHP version
php -v

# If < 8.3, upgrade PHP first:
# On macOS with Homebrew:
brew install php@8.3

# On Ubuntu:
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.3
```

### Dependency Conflicts

```bash
# If you encounter dependency conflicts:
composer why-not statamic/cms 6.0

# Update conflicting packages:
composer update --with-dependencies

# Or use specific constraints:
composer require "statamic/cms:^6.0" --update-with-dependencies
```

## Rollback Plan

If you need to rollback to Statamic v5:

### Step 1: Restore from Backup

```bash
# Restore your content files
tar -xzf content-backup-YYYYMMDD.tar.gz

# Restore your config
tar -xzf config-backup-YYYYMMDD.tar.gz

# Restore database
php artisan statamic:import
```

### Step 2: Downgrade Statamic

```bash
# Downgrade to Statamic v5.65
composer require "statamic/cms:^5.65"

# Clear caches
php artisan cache:clear
php artisan statamic:stache:clear
```

### Step 3: Verify MCP Server

```bash
# Test MCP server functionality
php artisan mcp:serve statamic

# Verify version detection
"Use statamic.system info to show me my Statamic version"
```

## Support & Resources

### Official Documentation

- **Statamic v6 Upgrade Guide**: https://statamic.dev/upgrade-guide
- **Statamic v6 Changelog**: https://statamic.dev/releases
- **MCP Server GitHub**: https://github.com/cboxdk/statamic-mcp

### Getting Help

**Issues with MCP Server**:
- GitHub Issues: https://github.com/cboxdk/statamic-mcp/issues
- Include version information: `php artisan statamic:version`, `composer show cboxdk/statamic-mcp`

**Issues with Statamic v6**:
- Statamic Discord: https://statamic.com/discord
- Statamic Support: https://statamic.com/support

### Version Information Commands

```bash
# Full system version report
php artisan statamic:version

# MCP server version
composer show cboxdk/statamic-mcp

# Laravel version
php artisan --version

# PHP version
php -v
```

## Summary

✅ **The Statamic MCP Server provides seamless v5.65+ → v6 migration**:

- **Automatic version detection** - No code changes needed
- **Dual version support** - Works with both v5.65+ and v6.0+
- **Zero breaking changes** - All tools work identically
- **Asset compatibility** - Handles both permission models
- **Comprehensive testing** - CI/CD validates both versions

**Questions?** Open an issue at https://github.com/cboxdk/statamic-mcp/issues
