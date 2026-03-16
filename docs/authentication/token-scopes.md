---
title: "Token Scopes"
description: "All 17 granular permission scopes for controlling what AI assistants can access via MCP tokens"
weight: 1
---

# Token Scopes

Every API token carries a set of scopes that control which tools and actions the token can access. Scopes use a `domain:permission` format.

## Available Scopes

| Scope | Description |
|-------|-------------|
| `*` | Full access to all tools and actions |
| `content:read` | Read entries, terms, and globals across all content routers |
| `content:write` | Create, update, delete content across all content routers |
| `blueprints:read` | Read blueprint definitions and field schemas |
| `blueprints:write` | Create, update, delete blueprints |
| `entries:read` | Read entries across collections |
| `entries:write` | Create, update, publish, unpublish, delete entries |
| `terms:read` | Read taxonomy terms |
| `terms:write` | Create, update, delete terms |
| `globals:read` | Read global sets and their values |
| `globals:write` | Update global values, manage global sets |
| `structures:read` | Read collections, taxonomies, navigations, sites |
| `structures:write` | Create, update, delete structural configuration |
| `assets:read` | Read asset containers and files |
| `assets:write` | Upload, move, copy, delete assets |
| `users:read` | Read user data, roles, groups |
| `users:write` | Create, update, delete users and role assignments |
| `system:read` | Read system info, health status, cache state, config |
| `system:write` | Clear caches, manage system operations |

## Common Combinations

### Read-Only Exploration

Safe for any environment. The AI assistant can inspect everything but change nothing:

```
content:read, blueprints:read, entries:read, terms:read,
globals:read, structures:read, assets:read, system:read
```

### Content Editing

For content management workflows — create and edit entries, terms, and globals:

```
content:read, content:write, entries:read, entries:write,
terms:read, terms:write, globals:read, globals:write
```

### Full Development

For local development only — unrestricted access:

```
*
```

## How Scopes Work

### Scope Checking

When a tool is called via the web endpoint, the `RequireMcpPermission` middleware checks whether the token has a scope that covers the requested domain and action.

- The `*` scope grants access to everything
- A `:read` scope covers read operations (list, get)
- A `:write` scope covers write operations (create, update, delete)
- Read scopes do **not** grant write access

### CLI Bypass

CLI access (`php artisan mcp:serve`) bypasses all scope checks. No token is needed. To force token authentication even in CLI mode, set:

```env
STATAMIC_MCP_FORCE_WEB_MODE=true
```

### Domain Mapping

Each tool maps to a scope domain:

| Tool | Required Scope Domain |
|------|----------------------|
| `statamic-blueprints` | `blueprints` |
| `statamic-entries` | `entries` |
| `statamic-terms` | `terms` |
| `statamic-globals` | `globals` |
| `statamic-structures` | `structures` |
| `statamic-assets` | `assets` |
| `statamic-users` | `users` |
| `statamic-system` | `system` |

## Managing Tokens

Tokens are created and revoked in the Statamic Control Panel at **Tools > MCP > Tokens**.

### Token Properties

- **Name** — Human-readable label (e.g. "Claude Desktop - Production")
- **Scopes** — One or more scopes from the table above
- **Expiry** — Optional expiration date (null = never expires)
- **User** — Each token belongs to a Statamic user

### Token Storage

Tokens are stored as SHA-256 hashes in the `mcp_tokens` table. The plaintext token is shown once at creation and cannot be retrieved later. If lost, revoke the old token and create a new one.

### Token Limits

By default, each user can create up to 10 tokens. Configure with:

```env
STATAMIC_MCP_MAX_TOKENS=10
```
