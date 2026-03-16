---
title: "Web Authentication"
description: "Bearer token and Basic Auth methods for securing the web MCP endpoint"
weight: 2
---

# Web Authentication

The web MCP endpoint supports two authentication methods, tried in order.

## Bearer Token (Recommended)

```
Authorization: Bearer <your-mcp-token>
```

Tokens are created in the CP at **Tools > MCP > Tokens**. Each token is:

- **SHA-256 hashed** at rest — the plaintext is shown once at creation
- **Scoped** — carries specific permissions (see [Token Scopes](token-scopes.md))
- **Tied to a user** — audit logs show which user's token was used
- **Optionally expiring** — set an expiry date or leave as permanent

Expired tokens are rejected automatically. Revoked tokens are deleted from the database.

## Basic Auth (Fallback)

```
Authorization: Basic <base64(email:password)>
```

Authenticates against Statamic's user system. The user must have the `access cp` permission. Basic Auth users get no scope restrictions — they can access all tools. Use Bearer tokens for production.

## Authentication Flow

1. Request arrives at the web endpoint
2. `HandleMcpCors` handles CORS preflight and headers (if `allowed_origins` configured)
3. `EnsureSecureTransport` rejects plain HTTP in production (if `require_https` enabled)
4. `AuthenticateForMcp` middleware extracts the `Authorization` header
5. If `Bearer` prefix: validate token hash, check expiry, load scopes
6. If `Basic` prefix: authenticate against Statamic users, verify CP access
7. If neither: return `401 Authentication required`
8. Rate limiter applies per-token or per-IP throttling
9. `RequireMcpPermission` middleware checks token scopes against the requested tool

## Security Best Practices

### HTTPS Enforcement

The web endpoint rejects plain HTTP requests by default in production and staging environments. This is controlled by the `require_https` config:

```env
STATAMIC_MCP_WEB_REQUIRE_HTTPS=true   # default — enforces HTTPS
STATAMIC_MCP_WEB_REQUIRE_HTTPS=false  # disable if behind a TLS-terminating proxy that strips the header
```

Local and testing environments always allow HTTP regardless of this setting.

### CORS (Browser-Based Clients)

Desktop MCP clients (Claude Desktop, Cursor, etc.) don't need CORS. If you're building a browser-based client, configure allowed origins:

```php
// config/statamic/mcp.php
'web' => [
    'allowed_origins' => ['https://your-app.com'],
    // or ['*'] to allow all origins (not recommended for production)
],
```

When `allowed_origins` is empty (the default), no CORS headers are sent.

### Production Checklist

- **HTTPS is enforced by default** — the middleware rejects HTTP in production
- Create **dedicated tokens** with minimal scopes per client
- Set **token expiry** for non-permanent integrations
- Enable **audit logging** (`STATAMIC_MCP_AUDIT_LOGGING=true`, on by default)
- **Disable unused tool domains** in `config/statamic/mcp.php`
- **Rotate tokens** periodically
- Review the **Activity tab** in the CP dashboard for unexpected usage

### Rate Limiting

The endpoint applies `throttle:60,1` middleware by default (60 requests per minute). Configure in `.env`:

```env
STATAMIC_MCP_RATE_LIMIT_MAX=120
STATAMIC_MCP_RATE_LIMIT_DECAY=1
```

Or override the middleware stack in `config/statamic/mcp.php`:

```php
'web' => [
    'middleware' => [
        'throttle:120,1',
    ],
],
```

### Force Web Mode

By default, CLI access bypasses authentication. To require tokens even in CLI context:

```env
STATAMIC_MCP_FORCE_WEB_MODE=true
```

### Token Expiry Default

Set a default expiry (in days) for new tokens:

```env
STATAMIC_MCP_TOKEN_EXPIRY=30
```

Leave unset or set to `null` for tokens that never expire.
