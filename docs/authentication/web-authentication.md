---
title: "Web Authentication"
description: "Bearer token and OAuth methods for securing the web MCP endpoint"
weight: 2
---

# Web Authentication

The web MCP endpoint accepts scoped Bearer tokens, including tokens issued through OAuth. Basic Auth was removed in v4; legacy configuration cannot re-enable it.

## Bearer Token (Recommended)

```
Authorization: Bearer <your-mcp-token>
```

Tokens are created in the CP at **Tools > MCP > Tokens**. Each token is:

- **SHA-256 hashed** at rest — the plaintext is shown once at creation
- **Scoped** — carries specific permissions (see [Token Scopes](token-scopes.md))
- **Tied to a user** — never does more than that user's Statamic permissions allow. Entry writes are attributed to the user in revisions and `updated_by`, as a Control Panel save would be, and the audit log records whose token was used
- **Optionally expiring** — set an expiry date or leave as permanent

Expired tokens are rejected automatically. Revoked tokens are deleted from the database.

## Migrating password-based clients

Basic Auth is no longer accepted. Create a scoped Bearer token or reconnect through OAuth. Remove `web.basic_auth_enabled` and `STATAMIC_MCP_BASIC_AUTH_ENABLED` from your configuration.

## OAuth 2.1 (Browser-Based Clients)

For MCP clients that support the OAuth 2.1 specification, the addon provides a full authorization server with PKCE (S256).

### Authorization Code Flow with PKCE

1. **Dynamic Client Registration**: The client registers via `POST /mcp/oauth/register` (RFC 7591), providing a `client_name` and `redirect_uris`. The server returns a public `client_id`; these clients use PKCE, not a client secret. CIMD clients can instead use their HTTPS metadata URL as the client ID.
2. **Authorization Request**: The client redirects to `GET /{cp}/mcp/oauth/authorize` with `code_challenge` (S256) and requested scopes.
3. **Consent Screen**: The user sees the consent screen in the Statamic CP and approves or denies the request. Only the requested scopes the user can exercise are shown; a request for `*` from a user who is not a super admin is narrowed to the scopes they can hold. A client asking for nothing the user can hold is sent back with `invalid_scope`. See [`security.scopes_follow_permissions`](../configuration/reference.md#scopes_follow_permissions).
4. **Token Exchange**: The client exchanges the authorization code at `POST /mcp/oauth/token` with the `code_verifier`.
5. **Refresh**: The client can refresh tokens via `POST /mcp/oauth/token` with `grant_type=refresh_token`.

Refresh tokens rotate on every exchange. Their successors share the original grant expiry, so refresh does not keep a grant alive indefinitely. Reusing an already-consumed refresh token revokes the refresh and access tokens for that user/client. Changing an OAuth token's scopes or expiry also ends refresh access and requires new consent. Renaming a token without changing those values preserves refresh access.

Issuance, scope changes and revocation share an atomic cache lock. Configure `oauth.lock_store` with a lock-capable cache store shared by every application node (for example Redis or the database cache store). Do not use the `array` store outside tests. A single-server site can use the file cache store. The database OAuth driver alone does not make a node-local cache lock distributed.

All v4 OAuth drivers must implement `revokeGrantsForUser` through the `OAuthDriver` contract. Revocation includes refresh grants and pending authorization codes whose access tokens have expired or been pruned. Update custom drivers before upgrading; the incomplete legacy fallback has been removed.

### Token Metadata Tracking

OAuth-created tokens store `oauth_client_id` and `oauth_client_name` for integration tracking. The CP dashboard shows an "OAuth" badge and hides the regenerate button for OAuth-created tokens.

### Token Revocation

Clients can revoke tokens via `POST /mcp/oauth/revoke` (RFC 7009), supporting both access and refresh token revocation.

### Discovery

OAuth metadata is available at:
- `/.well-known/oauth-authorization-server` — RFC 8414 metadata
- `/.well-known/oauth-protected-resource` — RFC 9728 protected resource metadata

### Configuration

OAuth is enabled by default. See the [Configuration Reference](../configuration/reference.md) for all OAuth settings including TTLs, default scopes, and max clients.

## Authentication Flow

1. Request arrives at the web endpoint
2. `HandleMcpCors` rejects untrusted Origin headers against `app.url` and `allowed_origins`, then handles CORS preflight and response headers
3. `EnsureSecureTransport` rejects plain HTTP in production (if `require_https` enabled)
4. `AuthenticateForMcp` middleware extracts the `Authorization` header
5. If `Bearer` prefix: validate token hash, check expiry, load scopes
6. If `Basic` prefix and explicitly enabled: authenticate against Statamic users, verify CP access and refuse enabled or required 2FA
7. If neither: return `401 Authentication required`
8. Rate limiter applies per-token or per-IP throttling
9. `RequireMcpPermission` middleware checks token scopes against the requested tool

## Security Best Practices

### HTTPS Enforcement

The web endpoint rejects plain HTTP requests by default in production and staging environments. This is controlled by the `require_https` config:

```env
STATAMIC_MCP_WEB_REQUIRE_HTTPS=true   # default — enforces HTTPS
# Configure trusted proxies when TLS terminates upstream; keep HTTPS enforcement enabled.
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

The endpoint applies rate limiting by default (60 requests per minute). Configure in `.env`:

```env
STATAMIC_MCP_RATE_LIMIT_MAX=120
```

### Force Web Mode

By default, CLI access bypasses authentication. To require tokens even in CLI context:

```env
STATAMIC_MCP_FORCE_WEB_MODE=true
```

### Maximum Token Lifetime

Set the maximum lifetime (in days) for tokens:

```env
STATAMIC_MCP_MAX_TOKEN_LIFETIME=365
```

This is the upper bound — tokens cannot be created with an expiry beyond this limit. Leave unset or set to `null` for no maximum.
