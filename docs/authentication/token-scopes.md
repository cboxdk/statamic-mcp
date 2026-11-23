---
title: "Token Scopes"
description: "All 29 granular permission scopes for controlling what AI assistants can access via MCP tokens"
weight: 1
---

# Token Scopes

Every API token carries a set of scopes that control which tools and actions the token can access. Scopes use a `domain:permission` format.

## Available Scopes

| Scope | Description |
|-------|-------------|
| `*` | Full access: expanded to an explicit list when the token is issued (see below) |
| `content:read` | Read entries, terms, and globals across all content routers |
| `content:write` | Create, update, delete content across all content routers |
| `blueprints:read` | Read blueprint definitions and field schemas |
| `blueprints:admin` | Create, update, delete blueprints (v3: `blueprints:write`) |
| `entries:read` | Read entries across collections |
| `entries:write` | Create and update entries (as drafts), edit published content |
| `entries:publish` | Publish, unpublish, publish a working copy, create or update with `published: true`. Needs `entries:write` too |
| `entries:delete` | Delete entries. Needs `entries:write` too |
| `terms:read` | Read taxonomy terms |
| `terms:write` | Create and update terms |
| `terms:delete` | Delete terms. Needs `terms:write` too |
| `globals:read` | Read global sets and their values |
| `globals:write` | Update global values, manage global sets |
| `structures:read` | Read collections, taxonomies, navigations, sites |
| `structures:admin` | Create, update, delete structural configuration (v3: `structures:write`) |
| `assets:read` | Read asset containers and files |
| `assets:write` | Upload, move, copy, edit assets |
| `assets:delete` | Delete assets and containers. Needs `assets:write` too |
| `users:read` | Read user data, roles, groups |
| `users:admin` | Create, update, delete users and role assignments (v3: `users:write`) |
| `system:read` | Read system info, health status, cache state, config |
| `system:admin` | Clear caches, manage system operations (v3: `system:write`) |
| `content-facade:read` | Read content audit and cross-reference data |
| `content-facade:write` | Write content facade operations |

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

### Publishing

Content editing plus publishing and deleting:

```
content:read, content:write, entries:read, entries:write,
entries:publish, entries:delete, terms:read, terms:write,
terms:delete, globals:read, globals:write
```

### Full Access

Only a super admin can request it. `*` is never stored: when the token is issued
it is replaced by every scope the user holds at that moment, so the token does not
grow when a later release adds a scope.

```
*
```

## How Scopes Work

### Scope Checking

When a tool is called via the web endpoint, the `RequireMcpPermission` middleware checks whether the token has a scope that covers the requested domain and action.

- A `:read` scope covers read operations (list, get)
- A `:write` scope covers create and update. In the entries, terms and assets
  domains, publishing and deleting need their own scope on top of it
  (`entries:publish`, `entries:delete`, `terms:delete`, `assets:delete`).
- The admin domains (structures, blueprints, users, system) use `:admin` for every
  change, delete included.
- Read scopes do **not** grant write access
- `*` is not a wildcard. A token still carrying it from v3 is refused, see below.

### Upgrading from v3

- **`*` tokens stop working.** A v3 token with `*` would have covered every scope
  added since, which nobody approved. Create a new token, or let the client
  authorize again; the new grant lists its scopes explicitly. An OAuth refresh
  token for a `*` grant is refused with `invalid_grant`, which makes the client
  ask for consent again.
- **`entries:write`, `terms:write` and `assets:write` tokens keep editing** but can
  no longer publish or delete. Add `entries:publish`, `entries:delete`,
  `terms:delete` or `assets:delete` where a token should. Until then such calls
  answer `Token missing required scope: …`, and a create that would have been
  published is saved as a draft.
- **`structures:write`, `blueprints:write`, `users:write` and `system:write` stop
  working.** v4 replaced them with `:admin` scopes. A token holding one is refused
  with a message naming its replacement; approve the token again.
- Run `php artisan mcp:statamic:install`: on an existing installation it keeps your
  config, sets the stdio profile, runs migrations, lists every affected token and
  offers to revoke the ones that no longer work. `php artisan mcp:doctor` counts
  them.
- Tools other packages register in a domain with no scope of its own can no
  longer be reached by any token. In v3 a `*` token reached them.

### Scopes Follow Permissions

A scope never grants more than the token's user may do: every call also checks the
user's Statamic permissions. So the dashboard, token creation and the OAuth consent
screen offer only the scopes the user can actually exercise — an editor who may edit
blog entries is offered `entries:read` and `entries:write` (and `entries:publish` or
`entries:delete` if they may publish or delete), not `users:admin` or `*`.
Only super admins can hold `*`, `system:admin` and the content-facade scopes.

See [`security.scopes_follow_permissions`](../configuration/reference.md#scopes_follow_permissions)
for the details and the switch to turn it off.

### Stdio profiles

Local access (`php artisan mcp:start statamic`) requires an explicit
`STATAMIC_MCP_STDIO_PROFILE=read_only`, `editor` or `operator`. It runs with local
process authority, without an HTTP token. Profiles and site-wide resource policies
still restrict execution. Only `operator` enables administration. Missing or unknown
profiles reject calls. Never switch transports to evade an HTTP permission denial.

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
| `statamic-content-facade` | `content-facade` |

## Managing Tokens

Tokens are created and revoked in the Statamic Control Panel at **Tools > MCP > Tokens**.

### Token Properties

- **Name** — Human-readable label (e.g. "Claude Desktop - Production")
- **Scopes** — One or more scopes from the table above, limited to those the token's user can exercise
- **Expiry** — Optional expiration date (null = never expires)
- **User** — Each token belongs to a Statamic user

### Token Storage

Tokens are stored as SHA-256 hashes (in YAML files by default, or in the `mcp_tokens` database table when using the database storage driver). The plaintext token is shown once at creation and cannot be retrieved later. If lost, revoke the old token and create a new one.

