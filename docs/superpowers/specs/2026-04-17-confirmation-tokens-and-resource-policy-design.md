# Confirmation Tokens & Granular Resource Authorization

**Date:** 2026-04-17
**Status:** Approved
**Inspired by:** [statamic-ai-gateway](https://github.com/Michael-Stokoe/statamic-ai-gateway)

## Problem

The addon exposes powerful write and delete operations via MCP tools. Autonomous AI agents can invoke these without human oversight. Two gaps exist:

1. **No confirmation step for destructive operations.** An agent can delete entries, blueprints, or collections in a single call. Only `BlueprintsRouter::delete` has a rudimentary `confirm: true` parameter — no cryptographic binding, no TTL, no proof the agent saw the preview.

2. **No resource-level access control.** Token scopes operate at the domain level (`entries:write` grants write access to *all* collections). There is no way to restrict a token to specific collections, taxonomies, containers, or sites. There is no way to hide sensitive fields from MCP responses.

## Solution

Two new services with clear boundaries, integrated into the existing router flow via traits.

---

## Feature 1: Stateless Confirmation Tokens

### ConfirmationTokenManager

**Location:** `src/Auth/ConfirmationTokenManager.php`

Stateless HMAC-SHA256 token manager. No database, no cache, no session state.

### Token Structure

```
payload  = tool_name | canonical_json(arguments_without_token) | unix_timestamp
signature = hmac_sha256(payload, APP_KEY)
token    = base64url(timestamp . "." . signature)
```

The token is cryptographically bound to the exact tool + arguments combination. Changing any argument invalidates the token. The timestamp ensures expiry without server-side state.

### Token Lifecycle

```
Agent                           MCP Server
  |                                |
  |-- delete entry "about-us" --->|
  |                                |-- requiresConfirmation? yes
  |                                |-- generate HMAC token
  |<-- 200 {                      |
  |     requires_confirmation: true,
  |     confirmation_token: "MTcx...",
  |     description: "Delete entry 'about-us' from collection 'pages'",
  |     expires_in: 300
  |   }
  |                                |
  |-- delete entry "about-us"  -->|
  |   + confirmation_token        |
  |                                |-- validate HMAC + TTL
  |                                |-- execute deletion
  |<-- 200 { success: true }      |
```

### Operations Requiring Confirmation

| Router | Actions | Rationale |
|--------|---------|-----------|
| All routers | `delete` | Permanent data loss |
| BlueprintsRouter | `create`, `update`, `delete` | Blueprint changes cascade to all content using that blueprint |

### Configuration

```php
// config/statamic/mcp.php
'confirmation' => [
    'enabled' => env('STATAMIC_MCP_CONFIRMATION_ENABLED', null), // null = auto-detect
    'ttl' => env('STATAMIC_MCP_CONFIRMATION_TTL', 300),

    // Per-domain gate list. Domains not listed fall back to 'default'.
    // '*' gates every action; [] disables the gate for that domain.
    'actions' => [
        'default'    => ['delete'],
        'blueprints' => ['create', 'update', 'delete'],
        // e.g. 'entries' => ['create', 'update', 'delete', 'publish', 'unpublish'],
    ],
],
```

**Environment-aware default:** When `enabled` is `null` (the default), confirmation is automatically enabled in `production` and disabled in `local`, `development`, `testing`, and `staging`. This matches the existing pattern in `EnsureSecureTransport` middleware which skips HTTPS enforcement in non-production environments. Explicitly setting `true` or `false` overrides the auto-detection.

**Configurable action list:** `confirmation.actions` maps domain handle → gated actions. Resolved by `ConfirmationActionGate::gates($domain, $action)` inside the `RequiresConfirmation` trait. Shipped defaults reproduce the original behaviour (delete everywhere + blueprint writes), so existing consumers see no change. Operators can widen the gate per domain (e.g. require confirmation on `entries.update`) without forking the package.

### Public API

```php
class ConfirmationTokenManager
{
    public function generate(string $tool, array $arguments): string;
    public function validate(string $token, string $tool, array $arguments): bool;
}
```

**Arguments canonicalization:** The `confirmation_token` key is stripped from arguments before hashing, so validation uses the same canonical form as generation.

### CLI Behavior

Confirmation is bypassed in CLI context (consistent with existing scope check bypass). CLI tools run locally with full trust.

### Confirmation Response Contract

When confirmation is required and no valid token is provided:

```json
{
    "success": false,
    "requires_confirmation": true,
    "confirmation_token": "MTcxMjM0NTY3OC5hYmNkZWYxMjM0...",
    "description": "Delete entry 'about-us' from collection 'pages'. This action cannot be undone.",
    "expires_in": 300
}
```

### Integration: RequiresConfirmation Trait

**Location:** `src/Mcp/Tools/Concerns/RequiresConfirmation.php`

```php
trait RequiresConfirmation
{
    protected function requiresConfirmation(string $action): bool;
    protected function handleConfirmation(string $action, array $arguments): ?array;
    protected function buildConfirmationDescription(string $action, array $arguments): string;
}
```

Usage in routers: call `handleConfirmation()` early in the action method. If it returns non-null, return that response (it's the confirmation request). If null, the token was valid — proceed with execution.

### Replacing Existing confirm Parameter

The existing `confirm: true` parameter in `BlueprintsRouter::deleteBlueprint` is replaced by the HMAC token system. The `confirm` schema field is removed.

---

## Feature 2: Granular Resource-Level Authorization

### ResourcePolicy Service

**Location:** `src/Auth/ResourcePolicy.php`

Evaluates whether a specific operation on a specific resource is permitted, based on site-wide configuration. This is an admin-controlled policy, not per-token.

### Configuration

Extends the existing `tools` section in `config/statamic/mcp.php`:

```php
'tools' => [
    'entries' => [
        'enabled' => env('STATAMIC_MCP_TOOL_ENTRIES_ENABLED', true),
        'resources' => [
            'read' => ['*'],
            'write' => ['*'],
        ],
        'denied_fields' => [],
    ],
    'terms' => [
        'enabled' => env('STATAMIC_MCP_TOOL_TERMS_ENABLED', true),
        'resources' => [
            'read' => ['*'],
            'write' => ['*'],
        ],
        'denied_fields' => [],
    ],
    'assets' => [
        'enabled' => env('STATAMIC_MCP_TOOL_ASSETS_ENABLED', true),
        'resources' => [
            'read' => ['*'],
            'write' => ['*'],
        ],
        'denied_fields' => [],
    ],
    'blueprints' => [
        'enabled' => env('STATAMIC_MCP_TOOL_BLUEPRINTS_ENABLED', true),
        'resources' => [
            'read' => ['*'],
            'write' => ['*'],
        ],
        'denied_fields' => [],
    ],
    'globals' => [
        'enabled' => env('STATAMIC_MCP_TOOL_GLOBALS_ENABLED', true),
        'resources' => [
            'read' => ['*'],
            'write' => ['*'],
        ],
        'denied_fields' => [],
    ],
    'structures' => [
        'enabled' => env('STATAMIC_MCP_TOOL_STRUCTURES_ENABLED', true),
        'resources' => [
            'read' => ['*'],
            'write' => ['*'],
        ],
        'denied_fields' => [],
    ],
    'users' => [
        'enabled' => env('STATAMIC_MCP_TOOL_USERS_ENABLED', true),
        'resources' => [
            'read' => ['*'],
            'write' => ['*'],
        ],
        'denied_fields' => [],
    ],
    'system' => [
        'enabled' => env('STATAMIC_MCP_TOOL_SYSTEM_ENABLED', true),
    ],
    'content-facade' => [
        'enabled' => env('STATAMIC_MCP_TOOL_CONTENT_FACADE_ENABLED', true),
    ],
],
```

**Defaults:** `read: ['*']`, `write: ['*']`, `denied_fields: []` — no restrictions out of the box. Fully backwards compatible.

### Example: Restricted Configuration

```php
'entries' => [
    'enabled' => true,
    'resources' => [
        'read' => ['*'],                    // read all collections
        'write' => ['blog*', 'pages'],      // write only blog* and pages
    ],
    'denied_fields' => ['internal_notes', 'api_key'],
],
'assets' => [
    'enabled' => true,
    'resources' => [
        'read' => ['*'],
        'write' => ['images', 'documents'], // no writes to 'private' container
    ],
    'denied_fields' => [],
],
```

### Glob Pattern Matching

Uses PHP's `fnmatch()` for pattern evaluation:

| Pattern | Matches | Does not match |
|---------|---------|----------------|
| `*` | Everything | — |
| `blog*` | `blog`, `blog-archive`, `blog-drafts` | `my-blog` |
| `product_*` | `product_shoes`, `product_hats` | `products` |
| `pages` | `pages` | `pages-archive` |

### Public API

```php
class ResourcePolicy
{
    public function canAccess(string $domain, string $resource, string $mode): bool;
    public function filterFields(string $domain, array $data): array;
    public function getDeniedFields(string $domain): array;
}
```

- `canAccess()` — checks if resource handle matches any glob pattern in the read/write list for the domain
- `filterFields()` — recursively strips denied fields from data arrays (handles nested structures like Bard, Replicator, Grid)
- `getDeniedFields()` — returns the deny list for a domain

### Scope of Application

ResourcePolicy applies in **all contexts** (CLI and web). It is a site-wide admin policy, not an authentication gate. This differs from scope checks which are web-only.

### Field Filtering Behavior

- **Input filtering:** Denied fields are stripped from `data`/`fields` arguments before tool execution. This prevents agents from writing to restricted fields.
- **Output filtering:** Denied fields are stripped from response data before returning. This prevents agents from reading restricted field values.
- **Recursive:** Handles nested field structures (Bard sets, Replicator sets, Grid rows) by walking the data tree.
- **Silent:** Stripped fields produce no error — they are simply absent from the data. This matches the ai-gateway approach (defense in depth, not error on encounter).

### Integration: EnforcesResourcePolicy Trait

**Location:** `src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php`

```php
trait EnforcesResourcePolicy
{
    protected function checkResourceAccess(string $action, array $arguments): ?array;
    protected function filterInputFields(string $domain, array $arguments): array;
    protected function filterOutputFields(string $domain, array $data): array;
    protected function resolveResourceHandle(array $arguments): ?string;
}
```

`resolveResourceHandle()` extracts the relevant resource handle from arguments — this varies by domain (e.g., `collection` for entries, `taxonomy` for terms, `container` for assets). Returns `null` for actions that don't target a specific resource (e.g., `list` without a collection filter). When null, the resource-level check is skipped (access is allowed at the resource level — domain scope and tool enablement still apply).

---

## Authorization Evaluation Order

The full authorization chain for a web MCP request:

```
1. Tool enabled?          → config: tools.{domain}.enabled
2. Token scope?           → TokenScope: {domain}:{read|write}
3. Resource allowed?      → ResourcePolicy::canAccess(domain, handle, mode)
4. Statamic permissions?  → User::hasPermission('{action} {resource}')
5. Confirmation required? → ConfirmationTokenManager (deletes + blueprint writes)
6. Field filtering        → ResourcePolicy::filterFields() on input + output
```

Steps 1-4 are blocking (return error if denied). Step 5 returns a confirmation request. Step 6 silently modifies data.

For CLI context: steps 2, 4, and 5 are bypassed (existing behavior for 2/4; confirmation is unnecessary for local CLI). Steps 1, 3, and 6 still apply — resource policy and field filtering are site-wide admin policies regardless of context.

---

## New Files

| File | Type | Purpose |
|------|------|---------|
| `src/Auth/ConfirmationTokenManager.php` | Service | HMAC token generation and validation |
| `src/Auth/ResourcePolicy.php` | Service | Resource access evaluation and field filtering |
| `src/Mcp/Tools/Concerns/RequiresConfirmation.php` | Trait | Confirmation flow integration for routers |
| `src/Mcp/Tools/Concerns/EnforcesResourcePolicy.php` | Trait | Resource policy enforcement for routers |
| `tests/ConfirmationTokenManagerTest.php` | Test | Token generation, validation, expiry, tampering |
| `tests/ResourcePolicyTest.php` | Test | Glob matching, field filtering, defaults |
| `tests/RequiresConfirmationIntegrationTest.php` | Test | End-to-end confirmation flow through routers |
| `tests/ResourcePolicyIntegrationTest.php` | Test | End-to-end resource policy through routers |

## Modified Files

| File | Change |
|------|--------|
| `config/statamic/mcp.php` | Add `confirmation` section, add `resources` and `denied_fields` to each tool domain |
| `src/ServiceProvider.php` | Register `ConfirmationTokenManager` and `ResourcePolicy` as singletons |
| `src/Mcp/Tools/BaseRouter.php` | Use `RequiresConfirmation` and `EnforcesResourcePolicy` traits |
| `src/Mcp/Tools/Concerns/RouterHelpers.php` | Add resource policy check in `checkWebPermissions()` |
| `src/Mcp/Tools/Routers/BlueprintsRouter.php` | Remove old `confirm` parameter, use trait |
| All other routers | Inherit confirmation + resource policy via BaseRouter traits |

## Backwards Compatibility

- Default config has no restrictions (`read: ['*']`, `write: ['*']`, `denied_fields: []`)
- Confirmation auto-detects environment (enabled in production, disabled in local/dev/testing/staging). Override with `STATAMIC_MCP_CONFIRMATION_ENABLED=true|false`
- Existing tokens continue to work without changes
- No database migrations required
- CLI behavior unchanged (confirmation bypassed, resource policy still applies)

## Testing Strategy

- **Unit tests:** ConfirmationTokenManager (generation, validation, expiry, argument tampering, canonical ordering), ResourcePolicy (glob matching, field filtering, recursive nested data, defaults)
- **Integration tests:** Full router flow with confirmation tokens, resource policy enforcement across all routers, field filtering on real Statamic data structures
- **Edge cases:** Expired tokens, tampered tokens, empty resource lists, nested Bard/Replicator field filtering, glob patterns with special characters
