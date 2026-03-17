---
title: "Configuration Reference"
description: "Complete reference for all config options, environment variables, and defaults"
weight: 1
---

# Configuration Reference

All configuration lives in `config/statamic/mcp.php`. Most settings can be controlled via environment variables.

## Web Endpoint

Controls the HTTP-accessible MCP endpoint.

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `web.enabled` | `STATAMIC_MCP_WEB_ENABLED` | `true` | Enable the web MCP endpoint |
| `web.path` | `STATAMIC_MCP_WEB_PATH` | `/mcp/statamic` | URL path for the endpoint |
| `web.require_https` | `STATAMIC_MCP_WEB_REQUIRE_HTTPS` | `true` | Reject plain HTTP requests (skipped in local/testing) |
| `web.allowed_origins` | — | `[]` | CORS allowed origins for browser-based clients. Empty = no CORS headers |
| `web.middleware` | — | `['throttle:60,1']` | Additional middleware applied to the endpoint |

```php
'web' => [
    'enabled' => env('STATAMIC_MCP_WEB_ENABLED', true),
    'path' => env('STATAMIC_MCP_WEB_PATH', '/mcp/statamic'),
    'require_https' => env('STATAMIC_MCP_WEB_REQUIRE_HTTPS', true),
    'allowed_origins' => [], // e.g. ['https://your-app.com'] or ['*']
    'middleware' => [
        'throttle:60,1',
    ],
],
```

## Dashboard

Controls the CP dashboard at Tools > MCP.

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `dashboard.enabled` | `STATAMIC_MCP_DASHBOARD_ENABLED` | `true` | Show the MCP dashboard in the CP |

```php
'dashboard' => [
    'enabled' => env('STATAMIC_MCP_DASHBOARD_ENABLED', true),
],
```

## Security

Controls authentication enforcement, audit logging, and system hardening.

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `security.force_web_mode` | `STATAMIC_MCP_FORCE_WEB_MODE` | `false` | Require token auth even in CLI context |
| `security.audit_logging` | `STATAMIC_MCP_AUDIT_LOGGING` | `true` | Log all MCP tool calls |
| `security.max_upload_size` | `STATAMIC_MCP_MAX_UPLOAD_SIZE` | `10485760` | Max upload size in bytes (10MB) |
| `security.expose_versions` | `STATAMIC_MCP_EXPOSE_VERSIONS` | `false` | Include Statamic/Laravel versions in responses |
| `security.max_token_lifetime_days` | `STATAMIC_MCP_MAX_TOKEN_LIFETIME` | `365` | Maximum token lifetime in days |
| `security.tool_timeout_seconds` | `STATAMIC_MCP_TOOL_TIMEOUT` | `30` | Maximum execution time per tool call |

```php
'security' => [
    'force_web_mode' => env('STATAMIC_MCP_FORCE_WEB_MODE', false),
    'audit_logging' => env('STATAMIC_MCP_AUDIT_LOGGING', true),
    'max_upload_size' => env('STATAMIC_MCP_MAX_UPLOAD_SIZE', 10 * 1024 * 1024),
    'expose_versions' => env('STATAMIC_MCP_EXPOSE_VERSIONS', false),
    'max_token_lifetime_days' => env('STATAMIC_MCP_MAX_TOKEN_LIFETIME', 365),
    'tool_timeout_seconds' => env('STATAMIC_MCP_TOOL_TIMEOUT', 30),
],
```

## Rate Limiting

Controls request throttling for the web endpoint. Skipped in CLI context.

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `rate_limit.max_attempts` | `STATAMIC_MCP_RATE_LIMIT_MAX` | `60` | Max requests per window |
| `rate_limit.decay_minutes` | `STATAMIC_MCP_RATE_LIMIT_DECAY` | `1` | Window duration in minutes |

```php
'rate_limit' => [
    'max_attempts' => env('STATAMIC_MCP_RATE_LIMIT_MAX', 60),
    'decay_minutes' => env('STATAMIC_MCP_RATE_LIMIT_DECAY', 1),
],
```

## Tool Domains

Enable or disable individual tool domains. When a domain is disabled, its tools are not registered and calls return an error.

Each domain can be toggled via `STATAMIC_MCP_TOOL_{NAME}_ENABLED` environment variables.

```php
'tools' => [
    'blueprints' => ['enabled' => env('STATAMIC_MCP_TOOL_BLUEPRINTS_ENABLED', true)],
    'entries' => ['enabled' => env('STATAMIC_MCP_TOOL_ENTRIES_ENABLED', true)],
    'terms' => ['enabled' => env('STATAMIC_MCP_TOOL_TERMS_ENABLED', true)],
    'globals' => ['enabled' => env('STATAMIC_MCP_TOOL_GLOBALS_ENABLED', true)],
    'structures' => ['enabled' => env('STATAMIC_MCP_TOOL_STRUCTURES_ENABLED', true)],
    'assets' => ['enabled' => env('STATAMIC_MCP_TOOL_ASSETS_ENABLED', true)],
    'users' => ['enabled' => env('STATAMIC_MCP_TOOL_USERS_ENABLED', true)],
    'system' => ['enabled' => env('STATAMIC_MCP_TOOL_SYSTEM_ENABLED', true)],
    'content-facade' => ['enabled' => env('STATAMIC_MCP_TOOL_CONTENT_FACADE_ENABLED', true)],
],
```

To disable a domain, set its env var to `false`:

```env
STATAMIC_MCP_TOOL_USERS_ENABLED=false
STATAMIC_MCP_TOOL_SYSTEM_ENABLED=false
```

## OAuth

Configure the OAuth 2.1 authorization server for browser-based MCP client registration and token exchange using PKCE (RFC 7636).

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `oauth.enabled` | `STATAMIC_MCP_OAUTH_ENABLED` | `true` | Enable the OAuth 2.1 authorization server |
| `oauth.driver` | `STATAMIC_MCP_OAUTH_DRIVER` | `BuiltInOAuthDriver::class` | OAuth driver implementation |
| `oauth.code_ttl` | `STATAMIC_MCP_OAUTH_CODE_TTL` | `600` | Authorization code TTL in seconds (10 min) |
| `oauth.client_ttl` | `STATAMIC_MCP_OAUTH_CLIENT_TTL` | `2592000` | Client registration TTL in seconds (30 days) |
| `oauth.token_ttl` | `STATAMIC_MCP_OAUTH_TOKEN_TTL` | `604800` | Access token TTL in seconds (7 days) |
| `oauth.refresh_token_ttl` | `STATAMIC_MCP_OAUTH_REFRESH_TOKEN_TTL` | `2592000` | Refresh token TTL in seconds (30 days) |
| `oauth.default_scopes` | `STATAMIC_MCP_OAUTH_DEFAULT_SCOPES` | `*` | Comma-separated default scopes for OAuth tokens |
| `oauth.max_clients` | `STATAMIC_MCP_OAUTH_MAX_CLIENTS` | `1000` | Maximum number of registered OAuth clients |

```php
'oauth' => [
    'enabled' => env('STATAMIC_MCP_OAUTH_ENABLED', true),
    'driver' => env('STATAMIC_MCP_OAUTH_DRIVER', BuiltInOAuthDriver::class),
    'code_ttl' => (int) env('STATAMIC_MCP_OAUTH_CODE_TTL', 600),
    'client_ttl' => (int) env('STATAMIC_MCP_OAUTH_CLIENT_TTL', 2592000),
    'token_ttl' => (int) env('STATAMIC_MCP_OAUTH_TOKEN_TTL', 604800),
    'refresh_token_ttl' => (int) env('STATAMIC_MCP_OAUTH_REFRESH_TOKEN_TTL', 2592000),
    'default_scopes' => array_filter(explode(',', env('STATAMIC_MCP_OAUTH_DEFAULT_SCOPES', '*'))),
    'max_clients' => (int) env('STATAMIC_MCP_OAUTH_MAX_CLIENTS', 1000),
],
```

## Storage Drivers

Configure which storage backends to use for tokens and audit logs. Swap to database drivers for multi-server or high-availability deployments.

| Key | Default | Description |
|-----|---------|-------------|
| `stores.tokens` | `FileTokenStore::class` | Token storage driver (`FileTokenStore` or `DatabaseTokenStore`) |
| `stores.audit` | `FileAuditStore::class` | Audit log storage driver (`FileAuditStore` or `DatabaseAuditStore`) |

```php
'stores' => [
    'tokens' => FileTokenStore::class,
    'audit' => FileAuditStore::class,
],
```

## Storage Paths

File paths used by the file-based storage drivers.

| Key | Default | Description |
|-----|---------|-------------|
| `storage.tokens_path` | `storage_path('statamic-mcp/tokens')` | Token storage directory |
| `storage.audit_path` | `storage_path('statamic-mcp/audit.log')` | Audit log file path |
| `storage.oauth_clients_path` | `storage_path('statamic-mcp/oauth/clients')` | OAuth client registrations |
| `storage.oauth_codes_path` | `storage_path('statamic-mcp/oauth/codes')` | OAuth authorization codes |
| `storage.oauth_refresh_path` | `storage_path('statamic-mcp/oauth/refresh')` | OAuth refresh tokens |

```php
'storage' => [
    'tokens_path' => storage_path('statamic-mcp/tokens'),
    'audit_path' => storage_path('statamic-mcp/audit.log'),
    'oauth_clients_path' => storage_path('statamic-mcp/oauth/clients'),
    'oauth_codes_path' => storage_path('statamic-mcp/oauth/codes'),
    'oauth_refresh_path' => storage_path('statamic-mcp/oauth/refresh'),
],
```

## Environment Variables Summary

Quick reference for all `.env` variables:

```env
# Web endpoint
STATAMIC_MCP_WEB_ENABLED=true
STATAMIC_MCP_WEB_PATH="/mcp/statamic"
STATAMIC_MCP_WEB_REQUIRE_HTTPS=true

# Dashboard
STATAMIC_MCP_DASHBOARD_ENABLED=true

# Security
STATAMIC_MCP_FORCE_WEB_MODE=false
STATAMIC_MCP_AUDIT_LOGGING=true
STATAMIC_MCP_EXPOSE_VERSIONS=false
STATAMIC_MCP_MAX_UPLOAD_SIZE=10485760
STATAMIC_MCP_MAX_TOKEN_LIFETIME=365
STATAMIC_MCP_TOOL_TIMEOUT=30

# Rate limiting
STATAMIC_MCP_RATE_LIMIT_MAX=60
STATAMIC_MCP_RATE_LIMIT_DECAY=1

# OAuth 2.1
STATAMIC_MCP_OAUTH_ENABLED=true
STATAMIC_MCP_OAUTH_DRIVER=BuiltInOAuthDriver
STATAMIC_MCP_OAUTH_CODE_TTL=600
STATAMIC_MCP_OAUTH_CLIENT_TTL=2592000
STATAMIC_MCP_OAUTH_TOKEN_TTL=604800
STATAMIC_MCP_OAUTH_REFRESH_TOKEN_TTL=2592000
STATAMIC_MCP_OAUTH_DEFAULT_SCOPES=*
STATAMIC_MCP_OAUTH_MAX_CLIENTS=1000

# Tool toggles (set to false to disable)
STATAMIC_MCP_TOOL_BLUEPRINTS_ENABLED=true
STATAMIC_MCP_TOOL_ENTRIES_ENABLED=true
STATAMIC_MCP_TOOL_TERMS_ENABLED=true
STATAMIC_MCP_TOOL_GLOBALS_ENABLED=true
STATAMIC_MCP_TOOL_STRUCTURES_ENABLED=true
STATAMIC_MCP_TOOL_ASSETS_ENABLED=true
STATAMIC_MCP_TOOL_USERS_ENABLED=true
STATAMIC_MCP_TOOL_SYSTEM_ENABLED=true
STATAMIC_MCP_TOOL_CONTENT_FACADE_ENABLED=true
```
