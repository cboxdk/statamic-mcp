---
title: "Token Scopes"
description: "The granular permission scopes that control what AI assistants can access through MCP tokens"
weight: 1
---

# Token Scopes

Every API token carries a set of scopes that control which tools and actions the token can access. Scopes use a `domain:permission` format.

## Available Scopes

| Scope | Description |
|-------|-------------|
| `*` | Full access: expanded to an explicit list when the token is issued (see below) |
| `blueprints:read` | Read blueprints, fieldsets and installed fieldtype schemas |
| `blueprints:admin` | Create, update, delete blueprints and fieldsets (v3: `blueprints:write`) |
| `entries:read` | Read entries across collections |
| `entries:write` | Create and update entries (as drafts), edit published content |
| `entries:publish` | Publish, unpublish, publish a working copy; create/localize with `data.published: true`; update with any `data.published` flag, including false. Needs `entries:write` too |
| `entries:delete` | Delete entries. Needs `entries:write` too |
| `terms:read` | Read taxonomy terms |
| `terms:write` | Create and update terms |
| `terms:delete` | Delete terms. Needs `terms:write` too |
| `globals:read` | Read global sets and their values |
| `globals:write` | Update localized global values; set definitions use `structures:admin` |
| `structures:read` | Read collections, taxonomies, navigations, sites |
| `structures:admin` | Create, update, delete structural configuration (v3: `structures:write`) |
| `assets:read` | Read asset containers and files |
| `assets:write` | Upload, move, copy, edit assets |
| `assets:delete` | Delete assets and containers. Needs `assets:write` too |
| `users:read` | Read user data, roles, groups |
| `users:admin` | Create, update, delete users and role assignments (v3: `users:write`) |
| `system:read` | Read system info, health status, cache state, config |
| `system:admin` | Clear caches, manage system operations (v3: `system:write`) |
| `forms:read` | Read Forms 2 definitions, not submission values |
| `forms:write` | Change form definitions and connector configuration; also required for spam marking |
| `submissions:read` | Read, export and summarize permitted submissions; needed with `forms:write` for spam marking |
| `submissions:delete` | Delete submissions, with approval by default |
| `content-facade:read` | Read content audit and cross-reference data |

The legacy `content-facade:write` enum value still exists, but no built-in action
uses it. The facade has no write tool. Forms scopes are independent: see
[Forms](../tools/forms.md) for the exact action grants and native permissions.

### Retired scopes

These still load on old tokens but grant nothing, and nobody is offered them.
`php artisan mcp:migrate-v4` previews their removal or revocation; `--apply`
carries it out. It never adds replacement scopes.

| Scope | Why |
|-------|-----|
| `content:read`, `content:write` | No tool ever asked for them, so they granted nothing in v3 either. Use the `entries:*` and `terms:*` scopes. Dropped from every token; an OAuth refresh drops them too. |
| `structures:write`, `blueprints:write`, `users:write`, `system:write` | Replaced by the `:admin` scope of the same domain. The token, or the client, has to be approved again. |

## Common Combinations

### Read-Only Exploration

These scopes permit reads, not writes. Profiles, native permissions and resource
policies still restrict which data can be inspected:

```
blueprints:read, entries:read, terms:read,
globals:read, structures:read, assets:read, system:read
```

### Content Editing

For content management workflows — create and edit entries, terms, and globals:

```
entries:read, entries:write, terms:read, terms:write,
globals:read, globals:write
```

### Publishing

Content editing plus publishing and deleting:

```
entries:read, entries:write, entries:publish, entries:delete,
terms:read, terms:write, terms:delete, globals:read, globals:write
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
  answer a missing-scope refusal. An explicit `data.published: true` is refused,
  not silently changed to a draft. Send `data.published: false` to create a draft.
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

This token-free behavior applies to built-in tools. Integration tools also require
`STATAMIC_MCP_STDIO_TOKEN` with current capability consent and run as that grant's
Statamic user; see [extending tools](../extending/tools.md).

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
| `statamic-forms` | `forms` and, for submission actions, `submissions` |

These are router domains. Clients call their `-read` and `-write`/`-admin` facets,
not the unsuffixed names; see [tool discovery](../tools/overview.md#finding-the-tools).

## Managing Tokens

Tokens are created and revoked in the Statamic Control Panel at **Tools > MCP > Tokens**.

### Token Properties

- **Name** — Human-readable label (e.g. "Claude Desktop - Production")
- **Scopes** — One or more scopes from the table above, limited to those the token's user can exercise
- **Expiry** — Bounded by `security.max_token_lifetime_days` (365 days by default); permanent tokens are available only when that cap is null
- **User** — Each token belongs to a Statamic user

### Token Storage

Tokens are stored as SHA-256 hashes (in YAML files by default, or in the `mcp_tokens` database table when using the database storage driver). The plaintext token is shown once at creation and cannot be retrieved later. If lost, revoke the old token and create a new one.

File tokens coalesce `last_used_at` updates to at most once a minute; the displayed
last-used time is not an exact request timestamp. Audit records provide individual
call history when logging is enabled. Invalid credentials do not repeatedly parse
every unchanged token file, and an index lookup still verifies the stored token
hash. File mutations retain the store lock and atomic replacement behavior.
