# Upgrading from v1.x to v2.0

## Requirements Changed

| | v1.x | v2.0 |
|---|------|------|
| PHP | ^8.3 | ^8.3 |
| Statamic | ^5.65 \| ^6.0 | ^6.6 |
| Laravel | ^11.0 \| ^12.0 | ^12.0 \| ^13.0 (via Statamic v6) |
| Laravel MCP | ^0.4.1 \| ^0.5 | ^0.6 |
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
