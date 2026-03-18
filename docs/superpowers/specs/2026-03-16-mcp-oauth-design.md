# MCP OAuth 2.1 — Design Spec

## Problem

ChatGPT and future MCP clients require OAuth 2.1 with PKCE for authentication. The addon currently only supports Bearer tokens and Basic Auth. The ChatGPT setup guide tells users OAuth is required but the addon doesn't provide it.

## Solution

Implement a minimal, self-contained OAuth 2.1 Authorization Code + PKCE flow directly in the addon. No external dependencies (Passport, Auth0). The OAuth flow ends by issuing a standard `McpToken` via the existing `TokenService` — from that point, everything is identical to manual Bearer token auth.

A driver interface allows swapping the built-in implementation for Passport or other providers later.

## OAuth Flow

```
ChatGPT                          MCP Server                        Statamic CP
   |                                |                                   |
   |-- GET /mcp/statamic ---------> |                                   |
   |<-- 401 + WWW-Authenticate -----|                                   |
   |                                |                                   |
   |-- GET /.well-known/            |                                   |
   |   oauth-protected-resource --> |                                   |
   |<-- {authorization_servers} ----|                                   |
   |                                |                                   |
   |-- GET /.well-known/            |                                   |
   |   oauth-authorization-server ->|                                   |
   |<-- {endpoints, PKCE, scopes} --|                                   |
   |                                |                                   |
   |-- POST /mcp/oauth/register --> |                                   |
   |<-- {client_id} ----------------|                                   |
   |                                |                                   |
   |-- Redirect user to ----------> |-- /mcp/oauth/authorize ---------->|
   |                                |   (PKCE challenge, scopes)        |
   |                                |                                   |
   |                                |   User logs in, sees consent ---->|
   |                                |   User approves scopes            |
   |                                |                                   |
   |<-- Redirect with code ---------|<-- Redirect + auth code ----------|
   |                                |                                   |
   |-- POST /mcp/oauth/token -----> |                                   |
   |   (code + PKCE verifier)       |                                   |
   |<-- {access_token} -------------|  (creates McpToken via            |
   |                                |   TokenService)                   |
   |                                |                                   |
   |-- GET /mcp/statamic ---------> |  (normal Bearer auth)             |
   |   Authorization: Bearer xxx    |                                   |
```

The key insight: OAuth is only the **handshake**. Once the token is issued, it's a regular `McpToken` with scopes, expiry, and audit logging — identical to manually created tokens.

## Endpoints

All OAuth endpoints are public (not behind CP auth). The authorize endpoint requires Statamic CP login.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/.well-known/oauth-protected-resource` | GET | Discovery — tells client where the OAuth server is |
| `/.well-known/oauth-authorization-server` | GET | Metadata — endpoints, scopes, PKCE support |
| `/mcp/oauth/register` | POST | Dynamic Client Registration (RFC 7591) |
| `/mcp/oauth/authorize` | GET | Shows consent screen in CP (requires Statamic login) |
| `/mcp/oauth/authorize` | POST | User approves/denies — redirects with code or error |
| `/mcp/oauth/token` | POST | Exchanges auth code + PKCE verifier for McpToken |

### Discovery Responses

**`/.well-known/oauth-protected-resource`**:
```json
{
  "resource": "https://your-site.dk/mcp/statamic",
  "authorization_servers": ["https://your-site.dk"],
  "scopes_supported": ["*", "content:read", "content:write", ...],
  "bearer_methods_supported": ["header"]
}
```

**`/.well-known/oauth-authorization-server`**:
```json
{
  "issuer": "https://your-site.dk",
  "authorization_endpoint": "https://your-site.dk/mcp/oauth/authorize",
  "token_endpoint": "https://your-site.dk/mcp/oauth/token",
  "registration_endpoint": "https://your-site.dk/mcp/oauth/register",
  "scopes_supported": ["*", "content:read", "content:write", ...],
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code"],
  "code_challenge_methods_supported": ["S256"],
  "token_endpoint_auth_methods_supported": ["none"]
}
```

### Dynamic Client Registration

**Request**: `POST /mcp/oauth/register`
```json
{
  "client_name": "ChatGPT",
  "redirect_uris": ["https://chatgpt.com/connector/oauth/callback123"]
}
```

**Response**: `201 Created`
```json
{
  "client_id": "mcp_abc123def456",
  "client_name": "ChatGPT",
  "redirect_uris": ["https://chatgpt.com/connector/oauth/callback123"],
  "client_id_issued_at": 1710590400
}
```

Clients are ephemeral. ChatGPT creates new ones frequently. Pruned after configurable TTL (default 30 days).

### Authorization Endpoint

**Request**: `GET /mcp/oauth/authorize`
```
?response_type=code
&client_id=mcp_abc123def456
&redirect_uri=https://chatgpt.com/connector/oauth/callback123
&scope=content:read content:write
&state=xyz
&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
&code_challenge_method=S256
&resource=https://your-site.dk/mcp/statamic
```

If user is not logged into Statamic CP, they are redirected to CP login first, then back to the consent screen.

**Consent screen** shows:
- Client name (from registration)
- Requested scopes with human-readable labels
- Approve / Deny buttons

**On Approve**: `POST /mcp/oauth/authorize` creates an auth code and redirects:
```
https://chatgpt.com/connector/oauth/callback123?code=AUTH_CODE&state=xyz
```

**On Deny**: Redirects with error:
```
https://chatgpt.com/connector/oauth/callback123?error=access_denied&state=xyz
```

### Token Endpoint

**Request**: `POST /mcp/oauth/token`
```
grant_type=authorization_code
&code=AUTH_CODE
&redirect_uri=https://chatgpt.com/connector/oauth/callback123
&client_id=mcp_abc123def456
&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
```

**Validation**:
1. Code exists and hasn't been used
2. Code hasn't expired (10 min TTL)
3. `client_id` matches code's client
4. `redirect_uri` matches code's redirect URI
5. PKCE: `BASE64URL(SHA256(code_verifier)) === code_challenge` stored with the code
6. Mark code as used (single-use enforcement)

**On success**: The controller calls `OAuthDriver::exchangeCode()` which validates the code and returns `OAuthAuthCode` (user_id, scopes). The controller then calls `TokenService::createToken()` with the approved scopes and configured token TTL. Returns:
```json
{
  "access_token": "mcp_plain_text_token_here",
  "token_type": "Bearer",
  "expires_in": 86400,
  "scope": "content:read content:write"
}
```

The `scope` reflects what the user actually approved (may differ from what was requested). `expires_in` is the token TTL from `oauth.token_ttl` config.

**On failure**: Returns standard OAuth error response (RFC 6749 Section 5.2):
```json
{
  "error": "invalid_grant",
  "error_description": "Authorization code has expired"
}
```

Standard error codes: `invalid_grant` (expired/used code), `invalid_client` (unknown client_id), `invalid_request` (PKCE failure, redirect_uri mismatch), `unsupported_grant_type`.

The `access_token` is a plain-text MCP token. From this point, the client uses standard Bearer auth.

## Storage

### OAuth Clients (from Dynamic Client Registration)

Stored using the same driver pattern as `TokenStore` (file or database, selected via config):

| Field | Type | Purpose |
|-------|------|---------|
| client_id | string | Random identifier (prefixed `mcp_`) |
| client_name | string | Human-readable name |
| redirect_uris | array | Allowed callback URLs |
| created_at | Carbon | For TTL-based pruning |

**File driver**: YAML files in `storage/statamic-mcp/oauth/clients/{client_id}.yaml`
**Database driver**: `mcp_oauth_clients` table

### Auth Codes (short-lived, single-use)

| Field | Type | Purpose |
|-------|------|---------|
| code | string | Random, stored hashed (SHA-256) |
| client_id | string | Must match on exchange |
| user_id | string | Statamic user who approved |
| scopes | array | Approved scopes |
| code_challenge | string | PKCE S256 challenge |
| code_challenge_method | string | Always `S256` |
| redirect_uri | string | Must match on exchange |
| expires_at | Carbon | 10 minutes from creation |
| used | bool | Single-use enforcement |

**File driver**: JSON files in `storage/statamic-mcp/oauth/codes/{hash}.json`
**Database driver**: `mcp_oauth_codes` table

Auth codes are automatically pruned (expired + used codes deleted by a scheduled prune).

## OAuthDriver Interface

The driver handles OAuth-specific concerns (clients, codes, PKCE). Token creation stays in the controller via `TokenService` — keeping responsibilities separated.

```php
namespace Cboxdk\StatamicMcp\OAuth\Contracts;

use Cboxdk\StatamicMcp\OAuth\OAuthAuthCode;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;

interface OAuthDriver
{
    /** Store a dynamically registered client. Validates redirect_uris (must be https, no fragments). */
    public function registerClient(string $clientName, array $redirectUris): OAuthClient;

    /** Validate a client_id exists and return its data. */
    public function findClient(string $clientId): ?OAuthClient;

    /** Create an authorization code after user consent. Returns plain code (stored hashed). */
    public function createAuthCode(
        string $clientId,
        string $userId,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $redirectUri,
    ): string;

    /**
     * Validate and consume an auth code. Returns the validated code data if valid.
     * The controller uses the returned data to create an McpToken via TokenService.
     *
     * MUST be atomic — concurrent calls with the same code MUST NOT both succeed.
     * File driver: use flock() or atomic rename. Database driver: UPDATE WHERE used = false.
     *
     * @throws OAuthException with specific error code on failure:
     *   - 'invalid_grant' — code expired, already used, or not found
     *   - 'invalid_client' — client_id mismatch
     *   - 'invalid_request' — redirect_uri mismatch or PKCE verification failed
     */
    public function exchangeCode(
        string $code,
        string $codeVerifier,
        string $clientId,
        string $redirectUri,
    ): OAuthAuthCode;

    /** Prune expired clients and used/expired codes. Returns count pruned. */
    public function prune(): int;
}
```

### Exceptions

```php
namespace Cboxdk\StatamicMcp\OAuth\Exceptions;

class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,  // OAuth error code: invalid_grant, invalid_client, etc.
        string $description,
        int $httpStatus = 400,
    ) {
        parent::__construct($description, $httpStatus);
    }
}
```

### DTOs

```php
class OAuthClient
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        /** @var array<int, string> */
        public readonly array $redirectUris,
        public readonly Carbon $createdAt,
    ) {}
}

class OAuthAuthCode
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $userId,
        /** @var array<int, string> */
        public readonly array $scopes,
        public readonly string $redirectUri,
    ) {}
}
```

## Consent Screen (Vue)

A new Statamic CP page at `/cp/mcp/oauth/authorize`. Uses Statamic's standard CP pattern: a Blade view that loads a Vue component, registered through `Statamic::pushCpRoutes()`.

Shows:
- Client name and icon
- List of requested scopes with human-readable labels and checkboxes
- Default: all scopes checked (configurable via `oauth.default_scopes`)
- User can uncheck scopes they don't want to grant
- "Approve" button — POST to `/mcp/oauth/authorize`
- "Deny" button — redirects with `error=access_denied`

The consent screen needs a separate CP route (not under the MCP tab structure) because it's accessed during the OAuth redirect flow, not normal CP navigation.

## Config

```php
'oauth' => [
    'enabled' => env('STATAMIC_MCP_OAUTH_ENABLED', true),
    'driver' => env('STATAMIC_MCP_OAUTH_DRIVER', \Cboxdk\StatamicMcp\OAuth\Drivers\BuiltInOAuthDriver::class),
    'code_ttl' => (int) env('STATAMIC_MCP_OAUTH_CODE_TTL', 600),           // seconds
    'client_ttl' => (int) env('STATAMIC_MCP_OAUTH_CLIENT_TTL', 2592000),   // seconds (30 days)
    'token_ttl' => (int) env('STATAMIC_MCP_OAUTH_TOKEN_TTL', 86400),       // seconds (24 hours)
    'default_scopes' => array_filter(explode(',', env('STATAMIC_MCP_OAUTH_DEFAULT_SCOPES', '*'))),
    'max_clients' => (int) env('STATAMIC_MCP_OAUTH_MAX_CLIENTS', 1000),
],
```

Notes:
- `default_scopes`: comma-separated string from env, parsed to array. Default `'*'` → `['*']` (full access)
- `token_ttl`: OAuth-issued tokens expire after 24h by default (shorter than manually created tokens)
- `max_clients`: cap to prevent registration endpoint abuse

## Changes to Existing Code

### AuthenticateForMcp middleware

No changes needed. OAuth tokens are regular `McpToken` Bearer tokens — the existing middleware validates them identically.

### 401 Response

Update the 401 response in `AuthenticateForMcp` to include a `resource_metadata` URL in the `WWW-Authenticate` header when OAuth is enabled:

```
WWW-Authenticate: Bearer resource_metadata="https://your-site.dk/.well-known/oauth-protected-resource"
```

This tells MCP clients where to start the OAuth discovery flow.

### ServiceProvider

- Register OAuth routes when `oauth.enabled` is true
- Bind `OAuthDriver` contract from config class
- Register `.well-known` routes at the root level (not under `/mcp` prefix)

### Token naming

Tokens created via OAuth are named `"{client_name} (OAuth)"` so they're identifiable in the admin dashboard.

## File Structure

```
src/
  OAuth/
    Contracts/
      OAuthDriver.php           # Interface
    Drivers/
      BuiltInOAuthDriver.php    # Self-contained implementation
    OAuthClient.php             # DTO
    OAuthAuthCode.php           # DTO
  Http/
    Controllers/
      OAuth/
        DiscoveryController.php     # .well-known endpoints
        RegistrationController.php  # POST /mcp/oauth/register
        AuthorizeController.php     # GET+POST /mcp/oauth/authorize
        TokenController.php         # POST /mcp/oauth/token (rename: OAuthTokenController to avoid collision)
resources/
  js/
    pages/
      OAuthConsent.vue          # Consent screen

database/
  migrations/
    oauth/
      create_mcp_oauth_clients_table.php
      create_mcp_oauth_codes_table.php
```

## Security

- **PKCE S256 required** — reject `plain` method. If `code_challenge_method` is missing or not `S256`, return `invalid_request`
- **Single-use codes** — atomically mark as used on exchange. File driver: `flock()` or atomic rename. Database driver: `UPDATE WHERE used = false` with affected rows check. Concurrent requests MUST NOT both succeed
- **Code TTL** — 10 minutes (configurable), reject expired codes
- **Redirect URI validation** — exact match between registration, authorize, and token exchange. Registration validates: must be `https://` (except `http://localhost` for dev), no fragments, absolute URIs
- **Client validation** — verify client_id exists before showing consent screen
- **Client cap** — `max_clients` config prevents registration endpoint resource exhaustion (default 1000)
- **Registration rate limiting** — `/mcp/oauth/register` rate-limited (10 per minute per IP)
- **Scope validation** — authorize endpoint validates requested scopes against `TokenScope::cases()`, rejects unknown scopes with `invalid_scope`
- **State passthrough** — `state` parameter is forwarded in the redirect URI unchanged. Server does not validate or store it (client-managed CSRF)
- **Resource parameter** — `resource` parameter from RFC 8707 is validated against the configured MCP endpoint URL. Mismatch returns `invalid_request`
- **CSRF** — consent form POST protected by Statamic's CSRF middleware
- **HTTPS** — enforced via existing `EnsureSecureTransport` middleware in production
- **Rate limiting** — token endpoint rate-limited to prevent brute force
- **Token revocation** — OAuth-issued tokens are regular `McpToken`s, revocable via the CP dashboard. No separate revocation endpoint needed (deliberate — RFC 7009 is optional)

## Pruning

Add `mcp:prune-oauth` command (or extend existing `mcp:prune-tokens` to include OAuth cleanup):
- Prune expired auth codes (code_ttl exceeded)
- Prune used auth codes (already exchanged)
- Prune expired clients (client_ttl exceeded)

Recommend running via Laravel scheduler alongside existing prune commands.

## Testing

- Discovery endpoints return correct metadata
- Dynamic client registration creates and returns client
- Authorization endpoint redirects to CP login if not authenticated
- Consent screen shows correct scopes
- Approve creates auth code and redirects correctly
- Deny redirects with error
- Token exchange validates PKCE, creates McpToken
- Expired codes are rejected
- Used codes are rejected
- Invalid PKCE verifier is rejected
- Mismatched redirect_uri is rejected
- Invalid client_id is rejected
- Pruning removes expired clients and codes
