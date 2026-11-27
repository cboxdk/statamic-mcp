---
title: "Installation"
description: "Install the Statamic MCP Server package and run the setup command"
weight: 1
---

# Installation

## Requirements

- **PHP** 8.3 or higher
- **Statamic CMS** ^6.44
- **Laravel** 12.0+
- **laravel/mcp** ^1.3 (installed automatically as a dependency)

## Install via Composer

```bash
composer require cboxdk/statamic-mcp
```

## Run the Install Command

The install command publishes configuration, saves an explicit stdio profile and
runs the required database migrations for configured database drivers:

```bash
php artisan mcp:statamic:install --profile=read_only
```

On an existing installation it preserves configuration unless `--force` is used,
offers token and client-permission migration, and finishes with `mcp:doctor`.
A failed doctor check makes the installer exit unsuccessfully even if files have
already been written; fix the reported setting and run doctor again.

If Laravel's configuration is cached, run `php artisan config:clear` before
installing. The installer refuses to change a profile behind a stale cache;
rebuild the cache with `php artisan config:cache` after doctor passes.

Client setup merges the Statamic server into project-local `.mcp.json` (Claude
Code) or `.cursor/mcp.json`, using an absolute artisan path and the selected
profile. Invalid JSON is left untouched. Cursor guidance is maintained in a
marked block without replacing unrelated rules. Cline setup is manual; the
installer does not write invented VS Code settings or register Claude twice.

The configuration is `config/statamic/mcp.php`; default file stores use
`storage/statamic-mcp/`. Dashboard assets are published by Statamic's addon asset
publishing, not by this command. If your deployment does not publish them:

```bash
php artisan vendor:publish --tag=statamic-mcp --force
```

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

Visit **Tools > MCP** for Connect, personal tokens and your own activity. The
separate MCP Admin page contains all tokens, the site-wide audit log and system
information according to your permissions. Approvals have their own page.

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

- Run `php artisan vendor:publish --tag=statamic-mcp --force`
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

An unset profile for a disabled transport is not a warning; an unsupported profile
is a failure. Local/testing loopback HTTP is allowed without production HTTPS and
debug-mode failures. Built-in local tools need no token; operator-profile
integrations need a configured token with current consent.

This is a read-only configuration check. It does not create test writes, acquire
locks, contact exporters, validate the supplied token or migrate grants. The JSON
report includes `checks` and informational `notes`. Verify ingress, shared storage,
file permissions, retention and recovery separately. A passing report does not
certify complete v4 migration or release readiness.
