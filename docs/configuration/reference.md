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

```php
'web' => [
    'enabled' => env('STATAMIC_MCP_WEB_ENABLED', true),
    'path' => env('STATAMIC_MCP_WEB_PATH', '/mcp/statamic'),
    'require_https' => env('STATAMIC_MCP_WEB_REQUIRE_HTTPS', true),
    'allowed_origins' => [], // e.g. ['https://your-app.com'] or ['*']
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
| `security.max_response_size` | `STATAMIC_MCP_MAX_RESPONSE_SIZE` | `100000` | Largest tool response in bytes before it is refused; `0` disables the guard |
| `security.reject_unknown_fields` | `STATAMIC_MCP_REJECT_UNKNOWN_FIELDS` | `true` | Refuse writes carrying keys that are not field handles inside a set, grid row or group |

```php
'security' => [
    'force_web_mode' => env('STATAMIC_MCP_FORCE_WEB_MODE', false),
    'audit_logging' => env('STATAMIC_MCP_AUDIT_LOGGING', true),
    'max_upload_size' => env('STATAMIC_MCP_MAX_UPLOAD_SIZE', 10 * 1024 * 1024),
    'expose_versions' => env('STATAMIC_MCP_EXPOSE_VERSIONS', false),
    'max_token_lifetime_days' => env('STATAMIC_MCP_MAX_TOKEN_LIFETIME', 365),
    'tool_timeout_seconds' => env('STATAMIC_MCP_TOOL_TIMEOUT', 30),
    'max_response_size' => (int) env('STATAMIC_MCP_MAX_RESPONSE_SIZE', 100000),
    'reject_unknown_fields' => env('STATAMIC_MCP_REJECT_UNKNOWN_FIELDS', true),
],
```

### `max_response_size`

The limit exists to protect the client's context window, not the server: the
response has already been built by the time it is measured. The right ceiling
therefore depends on the client, which is why it is configurable. Setting it to
`0` disables the guard entirely — reasonable for a client with a large context,
but a tool call can then return an arbitrarily large payload.

### `reject_unknown_fields`

Inside a replicator set, grid row, bard set or group, a key that is not a field
handle is not an error to Statamic. `Replicator::processRow()` and
`Grid::processRow()` merge the raw row back over the processed one, so the key
is written to the content file as inert data that no template reads — and the
write reports success. For a client that cannot read the blueprint from the
repository, that is undiagnosable, so this refuses the write instead and names
the valid handles at that level.

The check does not apply at the top level of a record. An entry legitimately
carries keys that are not blueprint fields — `template` and `layout` are read
back by `Entry::template()` and `Entry::layout()`, `parent` backs structures —
and no allowlist can enumerate what every addon adds.

## Rate Limiting

Controls request throttling for the web endpoint. Skipped in CLI context.

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `rate_limit.max_attempts` | `STATAMIC_MCP_RATE_LIMIT_MAX` | `60` | Max requests per minute |

```php
'rate_limit' => [
    'max_attempts' => env('STATAMIC_MCP_RATE_LIMIT_MAX', 60),
],
```

## Cache

Whether a write clears Statamic's caches — and it does, because Statamic does not rebuild
the indexes that *depend* on a write. Change a field's `max_items` and its index keeps the
old shape until a query throws on it; change a collection's mount and every entry 404s;
remove a taxonomy and `whereTaxonomy()` goes on returning entries.

**What changed in 3.1.0 is *when*.** The clear used to run through Artisan in the middle
of the request, resetting the in-memory stores while the call was still using them. On a
live multisite the tree repository returned nothing, Statamic padded the empty tree with
every entry at root, nested URLs flattened and a random entry became the homepage. It now
runs once the tool call is finished — the response is built, nothing further reads
Statamic, and the next call starts fresh. Same coverage, without the mechanism that did
the damage.

```php
'cache' => [
    'clear_stache_after_write' => env('STATAMIC_MCP_CLEAR_STACHE_AFTER_WRITE', true),
    'clear_static_after_write' => env('STATAMIC_MCP_CLEAR_STATIC_AFTER_WRITE', true),
],
```

Turn either off if you would rather trade index freshness for speed on a large site and
rely on your own invalidation rules. The `statamic-system` tool's `cache_clear` action is
unaffected — that clear runs immediately, because it was asked for.

## Resources

The read-only surface: `statamic://blueprints` and friends.

These have their own switch because they used to share the tools' one. A site that keeps
its content model in Git turns the blueprints *tool* off precisely because it can create
and delete blueprints — and that also removed the only read-only way for an agent to
learn a blueprint's fields, while the server's own instructions tell it to read the
blueprint before every write.

```php
'resources' => [
    'enabled' => env('STATAMIC_MCP_RESOURCES_ENABLED', true),
    'require_statamic_permission' => env('STATAMIC_MCP_RESOURCES_REQUIRE_PERMISSION', true),
],
```

`require_statamic_permission` keeps the Statamic permission check (`configure fields`,
`configure collections` or `configure taxonomies`) on top of the token scope and the
resource-policy allowlist. Set it to `false` if your editors hold none of those and you
would rather let the token scope you minted decide who may read schema.

## Tool Catalog

Controls how the tool list is presented to a client when it connects.

By default the catalog is **partial**. Only the tools a session usually opens with are
listed — `statamic-entries`, `statamic-blueprints` and `statamic-system-discover`. The
rest are reached through `search_tools` and `execute_tools`.

The reason is token cost: the full catalog is roughly 31 KB of JSON schema that every
client loads on every connection, before it has asked anything. Withholding the less-used
tools cuts that to about 12.6 KB — a ~60% saving in the client's context window.

Nothing is taken away. The hidden tools are fully available and fully gated — token
scopes, resource policy, Statamic permissions and the confirmation gate all apply
exactly as they do on a direct call. Only their schemas wait until a client asks.

```php
'catalog' => [
    'searchable' => env('STATAMIC_MCP_SEARCHABLE_CATALOG', true),
],
```

Set it to `false` if your MCP client handles `search_tools` poorly and you would rather
it saw every tool listed directly:

```env
STATAMIC_MCP_SEARCHABLE_CATALOG=false
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
| `oauth.max_clients` | `STATAMIC_MCP_OAUTH_MAX_CLIENTS` | `50` | Maximum number of registered OAuth clients |
| `oauth.max_clients_per_ip` | `STATAMIC_MCP_OAUTH_MAX_CLIENTS_PER_IP` | `5` | Maximum client registrations per IP address |

```php
'oauth' => [
    'enabled' => env('STATAMIC_MCP_OAUTH_ENABLED', true),
    'driver' => env('STATAMIC_MCP_OAUTH_DRIVER', BuiltInOAuthDriver::class),
    'code_ttl' => (int) env('STATAMIC_MCP_OAUTH_CODE_TTL', 600),
    'client_ttl' => (int) env('STATAMIC_MCP_OAUTH_CLIENT_TTL', 2592000),
    'token_ttl' => (int) env('STATAMIC_MCP_OAUTH_TOKEN_TTL', 604800),
    'refresh_token_ttl' => (int) env('STATAMIC_MCP_OAUTH_REFRESH_TOKEN_TTL', 2592000),
    'default_scopes' => array_filter(explode(',', env('STATAMIC_MCP_OAUTH_DEFAULT_SCOPES', '*'))),
    'max_clients' => (int) env('STATAMIC_MCP_OAUTH_MAX_CLIENTS', 50),
    'max_clients_per_ip' => (int) env('STATAMIC_MCP_OAUTH_MAX_CLIENTS_PER_IP', 5),
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
STATAMIC_MCP_MAX_RESPONSE_SIZE=100000
STATAMIC_MCP_REJECT_UNKNOWN_FIELDS=true

# Rate limiting
STATAMIC_MCP_RATE_LIMIT_MAX=60

# Tool catalog
STATAMIC_MCP_SEARCHABLE_CATALOG=true

# Cache
STATAMIC_MCP_CLEAR_STACHE_AFTER_WRITE=false
STATAMIC_MCP_CLEAR_STATIC_AFTER_WRITE=true

# Resources
STATAMIC_MCP_RESOURCES_ENABLED=true
STATAMIC_MCP_RESOURCES_REQUIRE_PERMISSION=true

# OAuth 2.1
STATAMIC_MCP_OAUTH_ENABLED=true
STATAMIC_MCP_OAUTH_DRIVER=BuiltInOAuthDriver
STATAMIC_MCP_OAUTH_CODE_TTL=600
STATAMIC_MCP_OAUTH_CLIENT_TTL=2592000
STATAMIC_MCP_OAUTH_TOKEN_TTL=604800
STATAMIC_MCP_OAUTH_REFRESH_TOKEN_TTL=2592000
STATAMIC_MCP_OAUTH_DEFAULT_SCOPES=*
STATAMIC_MCP_OAUTH_MAX_CLIENTS=50
STATAMIC_MCP_OAUTH_MAX_CLIENTS_PER_IP=5

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
