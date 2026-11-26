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
- **Lifetime-bounded** — the default cap is 365 days; permanent tokens require a null `security.max_token_lifetime_days`

Expired tokens are rejected automatically. Revoked tokens are deleted from the database.

## Migrating password-based clients

Basic Auth is no longer accepted. Create a scoped Bearer token or reconnect through OAuth. Remove `web.basic_auth_enabled` and `STATAMIC_MCP_BASIC_AUTH_ENABLED` from your configuration.

## OAuth 2.1 (Browser-Based Clients)

For MCP clients that support the OAuth 2.1 specification, the addon provides a full authorization server with PKCE (S256).

### Authorization Code Flow with PKCE

1. **Dynamic Client Registration**: The client registers via `POST /mcp/oauth/register` (RFC 7591), providing a `client_name` and `redirect_uris`. The server returns a public `client_id`; these clients use PKCE, not a client secret. CIMD clients can instead use their HTTPS metadata URL as the client ID.
2. **Authorization Request**: The client redirects to `GET /{cp}/mcp/oauth/authorize` with `code_challenge` (S256), requested scopes and `resource` from protected-resource metadata.
3. **Consent Screen**: The user sees the consent screen in the Statamic CP and approves or denies the request. Only the requested scopes the user can exercise are shown; a request for `*` from a user who is not a super admin is narrowed to the scopes they can hold. A client asking for nothing the user can hold is sent back with `invalid_scope`. See [`security.scopes_follow_permissions`](../configuration/reference.md#scopes_follow_permissions).
4. **Token Exchange**: The client exchanges the authorization code at `POST /mcp/oauth/token` with the `code_verifier` and the same `resource`.
5. **Refresh**: The client can refresh tokens via `POST /mcp/oauth/token` with `grant_type=refresh_token` and the same `resource`.

`resource` identifies this MCP endpoint, including its path and any non-default port.
It is bound to the authorization code, refresh grant and access token. Missing or
mismatched values return `invalid_target`; a token bound to another endpoint is
refused. Legacy OAuth grants without a bound audience require new consent. Personal
tokens do not use this OAuth audience binding. Authorization callbacks include `iss`,
which the client checks against discovery's `issuer`.

Registration accepts optional `application_type: "web"` or `"native"`. This is
client metadata, not an OIDC mode or an exemption from redirect and PKCE checks.

### Reviewing consent

Consent uses the native Statamic Control Panel with the site, signed-in account,
client and redirect destination shown before approval. Scope choices share the
token picker's permission-filtered labels, descriptions and groups. Admin and
delete scopes are not preselected, even when configured as defaults or requested
through `*`; integration scopes also require an explicit selection. Review all
selected scopes rather than assuming a full-access request selects everything.
An empty selection never grants all scopes. The displayed offer is bound to the
session, user, client and request and cannot be reused after submission.

Control Panel copy, including consent and scope descriptions, uses the addon's
English and Danish language files and the CP locale. Protocol tool names, scope
values and error codes do not change with the display language.

### Refresh rotation and storage

Refresh tokens rotate on every exchange. Their successors share the original grant expiry, so refresh does not keep a grant alive indefinitely. Reusing an already-consumed refresh token revokes the refresh and access tokens for that user/client. Changing an OAuth token's scopes or expiry also ends refresh access and requires new consent. Renaming a token without changing those values preserves refresh access.

Issuance, scope changes and revocation share an atomic cache lock. Configure `oauth.lock_store` with a lock-capable cache store shared by every application node (for example Redis or the database cache store). Do not use the `array` store outside tests. A single-server site can use the file cache store. The database OAuth driver alone does not make a node-local cache lock distributed.

All v4 OAuth drivers must implement `revokeGrantsForUser` through the `OAuthDriver` contract. Revocation includes refresh grants and pending authorization codes whose access tokens have expired or been pruned. Custom drivers must also accept the `resource` argument on both exchange methods, preserve `CapabilitySnapshot.resource` through rotation, and reject an audience mismatch before consuming the grant. Update custom drivers before upgrading; the incomplete legacy fallback has been removed.

### Redirect URI matching

The `redirect_uri` of an authorization request must match one the client registered, exactly. There is one exception, which RFC 8252 §7.3 requires for native apps: a plain-http loopback URI (`http://localhost` or `http://127.0.0.1`) matches on any port. A desktop client such as Claude Code registers `http://localhost/callback` and listens on whatever port the operating system gives it when sign-in starts.

Scheme, host, path and query must still be equal, `localhost` does not match `127.0.0.1`, and a URI with user info or a fragment never qualifies. The authorization code is bound to the exact URI requested, port included, and PKCE is required on every request, so a code delivered to another local port cannot be redeemed.

### Token Metadata Tracking

OAuth-created tokens store `oauth_client_id` and `oauth_client_name` for integration tracking. The CP dashboard shows an "OAuth" badge and hides the regenerate button for OAuth-created tokens.

### Token Revocation

Clients can revoke tokens via `POST /mcp/oauth/revoke` (RFC 7009), supporting both access and refresh token revocation.

### Discovery

OAuth metadata is available at:
- `/.well-known/oauth-authorization-server` — RFC 8414 metadata
- `/.well-known/oauth-protected-resource` — RFC 9728 protected resource metadata

The protected-resource document is also available at
`/.well-known/oauth-protected-resource/mcp/statamic` (substitute your configured MCP
path). This is the URL sent in `WWW-Authenticate`. It advertises minimal initial
read scopes; authorization-server metadata advertises all available scopes.

### Requesting additional access

An unauthenticated request returns HTTP 401 with a Bearer challenge containing
`resource_metadata` and initial or action-specific `scope`. A valid token missing
scopes returns HTTP 403 with `error="insufficient_scope"` and the complete required
scope set. For a batch sent through `execute_tools`, all required scopes are checked
before any member executes. Prompts and blueprint resources receive scope challenges
too. CORS exposes `WWW-Authenticate` to allowed browser clients.

The client asks the person to authorize those scopes, then retries after consent.
A profile, resource-policy or Statamic permission refusal still needs the site's
operator; more token scopes do not override it. Challenges never read protected
records to infer their state.

### Configuration

OAuth is enabled by default. See the [Configuration Reference](../configuration/reference.md) for all OAuth settings including TTLs, default scopes, and max clients.

## Authentication Flow

1. Request arrives at the web endpoint
2. `HandleMcpCors` rejects untrusted Origin headers against `app.url` and `allowed_origins`, then handles CORS preflight and response headers
3. `EnsureSecureTransport` rejects plain HTTP in production (if `require_https` enabled)
4. `AuthenticateForMcp` middleware extracts the `Authorization` header
5. If `Bearer` prefix: validate token hash, expiry, active user and OAuth audience, then load scopes
6. Otherwise return `401 Authentication required`; Basic Auth is not accepted
7. Rate limiter applies per-token or per-IP throttling
8. `RequireMcpPermission` checks the complete request's scopes before dispatch
9. The tool or resource checks profile, resource policy, Statamic permissions and any approval requirement

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

The same list feeds laravel/mcp's Origin check on this endpoint, which refuses a request carrying an `Origin` header unless it is localhost, `APP_URL`, in `mcp.allowed_origins` or in this list. Requests without an `Origin` header (desktop and server-side clients) are not affected.

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

Failed authentication is limited separately: 5 failed attempts a minute per credential and 30 per address answer 429. A valid token is never held back by these limits, so one misconfigured client behind a shared address cannot lock out the others.

### Force Web Mode

The following flag forces HTTP-style security checks, including the HTTP profile, in console-based HTTP runtimes. It does not provide a token-login flow for stdio:

```env
STATAMIC_MCP_FORCE_WEB_MODE=true
```

### Maximum Token Lifetime

Set the maximum lifetime (in days) for tokens:

```env
STATAMIC_MCP_MAX_TOKEN_LIFETIME=365
```

This is the upper bound — an omitted or later expiry is capped. Leaving the
environment variable unset keeps the 365-day default. Set
`security.max_token_lifetime_days` to null (or `STATAMIC_MCP_MAX_TOKEN_LIFETIME=null`)
only when permanent tokens should be available. Clearing an expiry while a cap
is configured is refused; OAuth-token expiry cannot be edited through the CP.
