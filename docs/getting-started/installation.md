---
title: "Installation"
description: "Install the Statamic MCP Server package and run the setup command"
weight: 1
---

# Installation

## Requirements

- **PHP** 8.3 or higher
- **Statamic CMS** v6.6+
- **Laravel** 12.0+
- **laravel/mcp** ^0.6 (installed automatically as a dependency)

## Install via Composer

```bash
composer require cboxdk/statamic-mcp
```

## Run the Install Command

The install command publishes configuration and (when using the database storage driver) creates the necessary database tables, and publishes the dashboard assets:

```bash
php artisan mcp:statamic:install
```

This creates:
- `config/statamic/mcp.php` — Package configuration
- `storage/statamic-mcp/` — File-based token and audit storage (default driver)
- `public/vendor/statamic-mcp/` — Dashboard assets (Vue 3 build)

By default, tokens are stored as YAML flat files. To use database storage instead, change the `stores.tokens` driver to `DatabaseTokenStore` in `config/statamic/mcp.php` and run `php artisan migrate`.

## Verify Installation

Check that the package is registered:

```bash
composer show cboxdk/statamic-mcp
```

Visit **Tools > MCP** in the Statamic Control Panel. You should see the dashboard with Connect, Tokens, Activity, and Settings tabs.

## Web Endpoint

The web MCP endpoint is **enabled by default**. After installation, the endpoint is available at the path configured in `STATAMIC_MCP_WEB_PATH` (default: `/mcp/statamic`).

To disable it, set in your `.env`:

```env
STATAMIC_MCP_WEB_ENABLED=false
```

## Publish Configuration (Optional)

If you didn't run the install command, you can publish the config manually:

```bash
php artisan vendor:publish --tag=statamic-mcp-config
```

This creates `config/statamic/mcp.php` where you can customize middleware, rate limits, authentication, and per-domain tool settings.

See [Configuration Reference](../configuration/reference.md) for all available options.

## Recommended: Laravel Boost

For the best development experience, install **Laravel Boost** alongside this package:

```bash
composer require laravel/boost --dev
```

**Laravel Boost** provides Laravel-specific tools (Eloquent, database, debugging), while **Statamic MCP Server** provides Statamic-specific tools (blueprints, collections, entries, assets). Together they give your AI assistant complete coverage.

## Troubleshooting

### Dashboard Not Appearing

- Clear the view cache: `php artisan view:clear`
- Ensure the package is registered: check `composer show cboxdk/statamic-mcp`
- Verify the service provider is loaded: check `bootstrap/providers.php` or `config/app.php`

### Migration Errors

- Run `php artisan migrate` if the token table doesn't exist
- Check your database connection is configured correctly

### Assets Not Loading

- Run `php artisan vendor:publish --tag=statamic-mcp-assets --force`
- Clear browser cache

## Next Steps

- **[Quick Start](quickstart.md)** — Create a token and connect your first AI assistant
- **[AI Client Setup](ai-clients.md)** — Copy-paste config for your specific client
