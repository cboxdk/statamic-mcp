---
title: "Installation"
description: "Install the Statamic MCP Server package and run the setup command"
weight: 1
---

# Installation

## Requirements

- **PHP** 8.3 or higher
- **Statamic CMS** v6.35.1+
- **Laravel** 12.0+
- **laravel/mcp** ^1.1.2 (installed automatically as a dependency)

## Install via Composer

```bash
composer require cboxdk/statamic-mcp
```

## Run the Install Command

The install command publishes configuration and (when using the database storage driver) creates the necessary database tables, and publishes the dashboard assets:

```bash
php artisan mcp:statamic:install --profile=read_only
```

This creates:
- `config/statamic/mcp.php` — Package configuration
- `storage/statamic-mcp/` — File-based token and audit storage (default driver)
- `public/vendor/statamic-mcp/` — Dashboard assets (Vue 3 build)

By default, tokens are stored as YAML flat files. To use database storage instead, change the `stores.tokens` driver to `DatabaseTokenStore` in `config/statamic/mcp.php` and run `php artisan migrate`.

Native SQL security stores require committed connections outside host transactions.
This includes database tokens, OAuth, operation records and durable audit. They
refuse both Laravel transactions and raw PDO transactions; the caller retains
ownership and must close its own transaction. Run MCP authentication and execution
outside transaction-wrapping middleware, or bind a custom driver using a dedicated
committed connection. A savepoint is not an independent commit. Token and OAuth
security reads use the primary database so replica lag cannot delay revocation or
refresh replay detection. Native Eloquent actor/role authorization also uses primary
reads and refuses existing transaction snapshots.


## Verify Installation

Check that the package is registered:

```bash
composer show cboxdk/statamic-mcp
```

Visit **Tools > MCP** in the Statamic Control Panel. You should see the dashboard with Connect, Tokens, Activity, and Settings tabs.

## Web Endpoint

The web MCP endpoint is **disabled by default**. Enable it explicitly with `STATAMIC_MCP_WEB_ENABLED=true` and select `STATAMIC_MCP_HTTP_PROFILE=read_only`, `editor` or `operator`. The endpoint then uses the path configured in `STATAMIC_MCP_WEB_PATH` (default: `/mcp/statamic`).

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

- **[Quick Start](../quickstart.md)** — Create a token and connect your first AI assistant
- **[AI Client Setup](ai-clients.md)** — Copy-paste config for your specific client

## Configuration preflight

Run `php artisan mcp:doctor` before exposing the endpoint. Use `--json` for a
machine-readable report; exit code 1 indicates a failed configuration check.
The command checks transport profiles, HTTPS/Origin settings, private-network fetch
protection, input budgets, driver contracts and local stores/locks in multi-node mode.
It prints no token values or raw configuration/exception messages.

This is a read-only configuration check. It does not create test writes, acquire
locks, contact exporters, validate the supplied token or migrate grants. Warnings
identify checks that still require deployment verification, including trusted proxies,
shared storage, file permissions, retention and recovery. A passing report does not
certify complete v4 migration or release readiness.
