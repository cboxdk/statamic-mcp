# Upgrading from v3.x to v4.0

v4 never widens what an existing token or OAuth grant may do. A credential
whose meaning changed is narrowed or must be approved again. Content is not
touched by any step below.

## Steps

1. Back up `storage/statamic-mcp/` (or the MCP tables) and your config.
2. `composer require cboxdk/statamic-mcp:^4.0`
3. `php artisan mcp:statamic:install --upgrade` keeps your config, asks for the
   stdio profile (`read_only`, `editor` or `operator`), runs migrations and shows
   the token plan below.
4. `php artisan mcp:doctor` checks HTTPS, origins, debug mode, trusted proxies,
   profiles, storage contracts and how many tokens v4 affects. It changes nothing.
5. `php artisan mcp:migrate-v4` shows the token plan as a dry run. Run it again
   with `--apply` to carry it out. `--json` prints the plan for scripts.

## What the token plan does

| v3 token | v4 |
|---|---|
| `*` (full access) | Refused by v4. Revoked; the owner creates a new token or authorizes the client again |
| `structures:write`, `blueprints:write`, `users:write`, `system:write` | Replaced by `{domain}:admin` and grant nothing. A personal token keeps its other scopes and loses these; an OAuth grant, or a token with nothing else, is revoked |
| `entries:write`, `terms:write`, `assets:write` | Still edit. Publishing and deleting need `entries:publish`, `entries:delete`, `terms:delete`, `assets:delete`. Kept as is; add the scopes in Tools → MCP where they belong |
| `content:read`, `content:write` | Never granted anything and are retired. A personal token loses them (revoked if nothing else is left); an OAuth grant drops them at its next refresh |
| Read scopes | Unchanged |

No scope is ever added. The plan acts only on a token that still looks the way
it did when planned, and a second run finds only what is left. Before changing
anything it writes a journal (mode `0600`) to `storage/statamic-mcp/migrations/`
(`storage.migrations_path`) with each token's scopes before and after. The
journal never holds secrets or hashes. If it cannot be written, nothing changes.

## Clients

- Basic Auth is gone. Use a Bearer token or OAuth.
- The HTTP endpoint must be enabled explicitly (`STATAMIC_MCP_WEB_ENABLED=true`).
- `confirmation_token` is ignored. A gated action answers `APPROVAL_REQUIRED`
  with an `approval_id`; the person approves it in Tools → MCP → Approvals and
  the client repeats the call with that id.
- Users `create`, `update`, `activate`, `assign_role` and `delete` need an
  approval over HTTP by default (`confirmation.actions.users`). Changing your
  own email or password over HTTP also needs `data.current_password`.
- List `filters` accept only blueprint field handles and the named properties
  (entries: `id`, `slug`, `title`, `published`, `status`, `date`, `uri`,
  `parent`, `origin`; terms: `id`, `slug`, `title`, `uri`), with scalar values.
  Other keys are refused with the valid ones.
- Relationship fields must name records that exist: term slugs, entry ids, user
  ids. Unknown terms are no longer created.
- OAuth authorize errors before consent show an error page instead of
  redirecting to the client.
- Every tool is split in two. Rename the tools in your client's permission rules
  and saved prompts:

  | v3 tool | v4 read tool | v4 tool that changes the site |
  |---|---|---|
  | `statamic-entries` | `statamic-entries-read` | `statamic-entries-write` |
  | `statamic-terms` | `statamic-terms-read` | `statamic-terms-write` |
  | `statamic-globals` | `statamic-globals-read` | `statamic-globals-write` |
  | `statamic-assets` | `statamic-assets-read` | `statamic-assets-write` |
  | `statamic-blueprints` | `statamic-blueprints-read` | `statamic-blueprints-admin` |
  | `statamic-structures` | `statamic-structures-read` | `statamic-structures-admin` |
  | `statamic-users` | `statamic-users-read` | `statamic-users-admin` |
  | `statamic-system` | `statamic-system-read` | `statamic-system-admin` |
  | `statamic-content-facade` | `statamic-content-facade-read` | – |

  To let a client read without asking: `mcp__statamic__*-read` in Claude Code's
  `permissions.allow`, `statamic:*-read` in Cursor's MCP allowlist. claude.ai and
  ChatGPT do it from the tools' read-only hints.
- `tools/list` shows only what the token can use, and `search_tools`/`execute_tools`
  are gone unless `catalog.searchable` is turned on.
- Stdio needs an explicit profile, and registered actions over stdio need a
  configured v4 token (`statamic.mcp.stdio.token`).

## Custom drivers and tools

- Token stores must keep the capability snapshot fields; OAuth drivers must
  implement user-wide grant revocation; audit drivers must implement
  `DurableAuditStore`. `mcp:doctor` reports the last two.
- `AuditStore::query()` gained an `?string $actor` filter (`user:{id}` or
  `cli`); a custom audit store must accept and apply it.
- A tool from another package in a domain with no scope of its own is refused
  for every token. Make it a registered action with its own scope.

## Rolling back to v3

A rollback is a procedure, not only `composer require` of an older version.

1. While v4 is still installed: `php artisan mcp:migrate-v4 --rollback`, then
   again with `--apply`. It revokes every token that v3 would let publish or
   delete although v4 did not (an `{entries,terms,assets}:write` token without
   the matching publish/delete scopes), OAuth grants included.
2. `composer require cboxdk/statamic-mcp:^3.6`
3. Do not restore tokens from the backup. A token revoked during the upgrade or
   the rollback stays revoked; its owner creates a new one. Pending approvals
   are not carried back, and content changed under v4 stays changed.

# Upgrading from v1.x to v2.0

## Requirements Changed

| | v1.x | v2.0 |
|---|------|------|
| PHP | ^8.3 | ^8.3 |
| Statamic | ^5.65 \| ^6.0 | ^6.6 |
| Laravel | ^11.0 \| ^12.0 | ^12.0 \| ^13.0 (via Statamic v6) |
| Laravel MCP | ^0.4.1 \| ^0.5 | ^0.6 \|\| ^0.7 \|\| ^0.8 \|\| ^0.9 |
| Symfony YAML | ^7.3 | ^7.0 \| ^8.0 |

**Statamic v5 support has been removed.** If you are on Statamic v5, stay on v1.x.

## Breaking Changes

### Config file restructured

The config file (`config/statamic/mcp.php`) has been completely restructured. **Re-publish it:**

```bash
php artisan vendor:publish --tag=statamic-mcp-config --force
```

Key changes:
- Per-tool config (`tools.statamic.content.web_enabled`) replaced with simple toggles (`tools.entries.enabled`)
- Per-tool rate limiting removed — now a single global `rate_limit.max_attempts`
- `decay_minutes` config removed (was unused)
- New sections: `stores`, `storage`, `oauth`, `security`, `dashboard`

### Tool names changed

All tool names now use the `statamic-` prefix with hyphens:

| v1.x | v2.0 |
|------|------|
| `statamic.content` | `statamic-entries`, `statamic-terms`, `statamic-globals` |
| `statamic.structures` | `statamic-structures` |
| `statamic.assets` | `statamic-assets` |
| `statamic.users` | `statamic-users` |
| `statamic.system` | `statamic-system` |
| `statamic.blueprints` | `statamic-blueprints` |
| *(new)* | `statamic-content-facade` |
| *(new)* | `statamic-system-discover` |
| *(new)* | `statamic-system-schema` |

### Tool parameter changes

- `type` parameter renamed to `resource_type` (avoids JSON Schema keyword collision)
- All tools now use `action` parameter for operation selection

### Response format changed

All tools now return a standardized envelope:

```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "statamic_version": "6.6.0",
    "laravel_version": "12.0",
    "tool": "statamic-entries",
    "timestamp": "2026-03-18T12:00:00Z"
  }
}
```

### MCP client configuration

Update your `.mcp.json` or client config to use the new tool names and the web endpoint:

```json
{
  "mcpServers": {
    "statamic": {
      "url": "https://your-site.test/mcp/statamic",
      "headers": {
        "Authorization": "Bearer <your-mcp-token>"
      }
    }
  }
}
```

## New Features

### Storage drivers

Tokens and audit logs now support pluggable storage backends:

- **File (default):** YAML tokens, JSONL audit — no database required
- **Database:** Eloquent-backed — configure in `config/statamic/mcp.php`:

```php
'stores' => [
    'tokens' => \Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore::class,
    'audit' => \Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore::class,
],
```

Migrate existing tokens between drivers:

```bash
php artisan mcp:migrate-store tokens --from=file --to=database
```

### OAuth 2.1

Browser-based MCP clients (ChatGPT, Claude) can now authenticate via OAuth:

- Dynamic Client Registration (`POST /mcp/oauth/register`)
- Authorization Code + PKCE S256
- Refresh token rotation
- Token revocation

Enable in config (enabled by default):

```env
STATAMIC_MCP_OAUTH_ENABLED=true
```

### Scoped API tokens

21 granular scopes for fine-grained access control:

```
content:read, content:write, entries:read, entries:write,
terms:read, terms:write, globals:read, globals:write,
blueprints:read, blueprints:write, structures:read, structures:write,
assets:read, assets:write, users:read, users:write,
system:read, system:write, content-facade:read, content-facade:write, *
```

Manage tokens via the CP dashboard: **Tools > MCP > Tokens**.

### CP Dashboard

Split into two views:
- **User page** (`/cp/mcp`): Connect tab + My Tokens
- **Admin page** (`/cp/mcp/admin`): All Tokens, Activity log, System info

### Audit logging

All MCP tool calls are logged with user, token, IP, duration, and mutation tracking. View in the admin dashboard Activity tab, or query via the audit store API.

## Migration Steps

1. **Update composer.json:**
   ```bash
   composer require cboxdk/statamic-mcp:^2.0
   ```

2. **Re-publish config:**
   ```bash
   php artisan vendor:publish --tag=statamic-mcp-config --force
   ```

3. **Review your config** — transfer any custom settings to the new structure

4. **Run install command** (updates AI client configs):
   ```bash
   php artisan mcp:statamic:install
   ```

5. **Create API tokens** in the CP dashboard (Tools > MCP > Tokens)

6. **Update MCP client configs** to use new tool names and web endpoint URL

7. **(Optional) Run database migrations** if using database storage:
   ```bash
   php artisan migrate
   ```
