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
| `web.enabled` | `STATAMIC_MCP_WEB_ENABLED` | `false` | Enable the web MCP endpoint |
| `web.path` | `STATAMIC_MCP_WEB_PATH` | `/mcp/statamic` | URL path for the endpoint |
| `web.require_https` | `STATAMIC_MCP_WEB_REQUIRE_HTTPS` | `true` | Reject plain HTTP requests (skipped in local/testing) |
| `web.allowed_origins` | — | `[]` | CORS allowed origins for browser-based clients. Empty = no CORS headers |
| `web.middleware` | — | `['throttle:60,1']` | Additional middleware applied to the endpoint |

```php
'web' => [
    'enabled' => env('STATAMIC_MCP_WEB_ENABLED', false),
    'path' => env('STATAMIC_MCP_WEB_PATH', '/mcp/statamic'),
    'require_https' => env('STATAMIC_MCP_WEB_REQUIRE_HTTPS', true),
    'allowed_origins' => [], // e.g. ['https://your-app.com'] or ['*']
    'middleware' => [
        'throttle:60,1',
    ],
],
```

## Authentication

Controls token authentication behavior.

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `auth.guard` | — | `mcp` | Laravel auth guard name |
| `auth.tokens.expiry_days` | `STATAMIC_MCP_TOKEN_EXPIRY` | `null` | Default token expiry in days (null = never) |
| `auth.tokens.max_per_user` | `STATAMIC_MCP_MAX_TOKENS` | `10` | Maximum tokens per user |

```php
'auth' => [
    'guard' => 'mcp',
    'tokens' => [
        'expiry_days' => env('STATAMIC_MCP_TOKEN_EXPIRY', null),
        'max_per_user' => env('STATAMIC_MCP_MAX_TOKENS', 10),
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

Controls authentication enforcement and audit logging.

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `security.force_web_mode` | `STATAMIC_MCP_FORCE_WEB_MODE` | `false` | Require token auth even in CLI context |
| `security.audit_logging` | `STATAMIC_MCP_AUDIT_LOGGING` | `true` | Log all MCP tool calls |
| `security.expose_versions` | `STATAMIC_MCP_EXPOSE_VERSIONS` | `false` | Include Statamic/Laravel versions in responses |
| `security.max_upload_size` | `STATAMIC_MCP_MAX_UPLOAD_SIZE` | `10485760` | Max upload size in bytes (10MB) |
| `security.max_token_lifetime_days` | `STATAMIC_MCP_MAX_TOKEN_LIFETIME` | `365` | Maximum token lifetime in days |

```php
'security' => [
    'force_web_mode' => env('STATAMIC_MCP_FORCE_WEB_MODE', false),
    'audit_logging' => env('STATAMIC_MCP_AUDIT_LOGGING', true),
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

```php
'tools' => [
    'content' => ['enabled' => true],
    'structures' => ['enabled' => true],
    'assets' => ['enabled' => true],
    'users' => ['enabled' => true],
    'system' => ['enabled' => true],
    'blueprints' => ['enabled' => true],
    'entries' => ['enabled' => true],
    'terms' => ['enabled' => true],
    'globals' => ['enabled' => true],
],
```

To disable a domain, set `enabled` to `false`:

```php
'tools' => [
    'users' => ['enabled' => false],   // Disable user tools
    'system' => ['enabled' => false],  // Disable system tools
],
```

## Environment Variables Summary

Quick reference for all `.env` variables:

```env
# Web endpoint
STATAMIC_MCP_WEB_ENABLED=true
STATAMIC_MCP_WEB_PATH="/mcp/statamic"
STATAMIC_MCP_WEB_REQUIRE_HTTPS=true

# Authentication
STATAMIC_MCP_TOKEN_EXPIRY=30
STATAMIC_MCP_MAX_TOKENS=10

# Dashboard
STATAMIC_MCP_DASHBOARD_ENABLED=true

# Security
STATAMIC_MCP_FORCE_WEB_MODE=false
STATAMIC_MCP_AUDIT_LOGGING=true
STATAMIC_MCP_EXPOSE_VERSIONS=false
STATAMIC_MCP_MAX_UPLOAD_SIZE=10485760

# Rate limiting
STATAMIC_MCP_RATE_LIMIT_MAX=60
STATAMIC_MCP_RATE_LIMIT_DECAY=1
```
