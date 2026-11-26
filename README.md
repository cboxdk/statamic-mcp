# Statamic MCP Server

A comprehensive MCP (Model Context Protocol) server for Statamic CMS v6 that provides AI assistants with structured access to Statamic's content management capabilities through a modern router-based architecture.

## Requirements

- PHP 8.3+
- Laravel 12+
- Statamic 6.44+
- Laravel MCP ^1.3

## Installation

```bash
# Install via Composer
composer require cboxdk/statamic-mcp

# Run the installation command
php artisan mcp:statamic:install --profile=read_only
```

### Recommended: Laravel Boost Integration

We recommend installing **Laravel Boost** alongside this addon for the best experience:

```bash
composer require laravel/boost --dev
```

**Laravel Boost** provides Laravel-specific tools (Eloquent, database, debugging), while **Statamic MCP Server** provides Statamic-specific tools (blueprints, collections, entries, assets). Together they give your AI assistant complete coverage.

## Web MCP Endpoint

The web MCP endpoint is **disabled by default**. Enable it explicitly and choose the path:

```env
STATAMIC_MCP_WEB_ENABLED=true
STATAMIC_MCP_HTTP_PROFILE=read_only
STATAMIC_MCP_WEB_PATH="/mcp/statamic"
```

Create a token in the CP dashboard (Tools > MCP > Tokens), then configure Cursor
with the following `.cursor/mcp.json`. Other clients use their own formats below.

```json
{
    "mcpServers": {
        "statamic": {
            "url": "https://your-site.test/mcp/statamic",
            "headers": {
                "Authorization": "Bearer <YOUR_TOKEN>"
            }
        }
    }
}
```

See [Getting Started](docs/quickstart.md) for detailed setup or [AI Client Setup](docs/getting-started/ai-clients.md) for client-specific instructions (Claude, Cursor, ChatGPT, Windsurf).

## Features

The MCP server organizes capabilities into domain routers. Clients see separate
`-read` and `-write`/`-admin` tools, with an `action` parameter on each.

### Blueprints and fieldsets — `statamic-blueprints-read` / `statamic-blueprints-admin`
Actions: `list`, `get`, `create`, `update`, `delete`, `scan`, `generate`, `types`, `validate`, `list_fieldsets`, `get_fieldset`, `create_fieldset`, `update_fieldset`, `delete_fieldset`, `fieldtypes`

Read and edit native definitions without flattening tabs, sections, imports or field references. Resolved fields report their source; fieldset updates and deletes support version checks. Inspect installed fieldtypes, generate types, or scope a blueprint `get` to one `field` dot path. See the [blueprint and fieldset reference](docs/tools/overview.md#statamic-blueprints).

### Entry Management — `statamic-entries-read` / `statamic-entries-write`
Actions: `list`, `get`, `create`, `update`, `localize`, `delete`, `publish`, `unpublish`, `list_revisions`, `get_revision`, `restore_revision`, `publish_working_copy`

Entry operations with filtering, safe sorting, cursor pagination, revisions and localization. `get` accepts an id, slug or URI within a collection and site; localized reads distinguish local overrides from inherited values. `update` can merge replicator items by id instead of replacing the whole array.

### Term Management — `statamic-terms-read` / `statamic-terms-write`
Taxonomy term operations with slug conflict prevention, dependency validation, and relationship mapping.

### Global Management — `statamic-globals-read` / `statamic-globals-write`
Read and update localized global values with version checks and field filtering. Manage set definitions through structures.

### Structure Management — `statamic-structures-read` / `statamic-structures-admin`
Collection, taxonomy, navigation and global-set configuration; sites are read-only.

### Asset Management — `statamic-assets-read` / `statamic-assets-write`
Asset container and file operations: upload (from a URL, base64 or a local file), move, copy and delete, with metadata management.

### User Management — `statamic-users-read` / `statamic-users-admin`
User CRUD, role assignment, group management with RBAC support.

### Forms — `statamic-forms-read` / `statamic-forms-write`
Forms 2 definitions and site-bound submissions, with separate submission scopes and write-only connector configuration. See [Forms](docs/tools/forms.md).

### System Management — `statamic-system-read` / `statamic-system-admin`
System info, health checks, cache management (clear/warm), and configuration access.

### Content Workflow Facade — `statamic-content-facade-read`
Read-only analysis: `content_audit`, paginated `content_validate`, and `cross_reference`. Cross-reference refuses more than 5,000 readable entries and terms rather than reporting incomplete orphan results.

### Finding the tools

By default, the catalog lists the tool facets allowed by the caller's scopes and
installation profile. `statamic-discover` finds authorized tools by intent or
returns a tool's input/output schemas. Set `STATAMIC_MCP_SEARCHABLE_CATALOG=true`
to opt into `search_tools` / `execute_tools` for less-used tools; this changes
discovery, not authorization.

Read-only [resources and completions](docs/tools/overview.md#resources) expose
blueprints, collections, sites, fieldtypes and record URIs. Following a resource
link from a write is a new authorized read, not an additional grant.

### Adding your own tools

Another package can serve a `BaseRouter` with its own scopes and consent through
the integration API. Register from its service provider:

```php
use Cboxdk\StatamicMcp\StatamicMcp;

public function boot(): void
{
    StatamicMcp::integration('vendor/notes')
        ->scope('notes:read', 'Read notes', ['view notes'])
        ->router(NotesRouter::class, version: 1);
}
```

The package must define its native permission, action contracts and exact-record
authorization. Integrations share the approval, idempotency and audit pipeline;
HTTP and stdio both require current capability consent. `registerTool()` and
`extra_tools` remain compatibility paths for existing scoped domains. An unscoped
custom domain is refused for every token, including legacy `*` tokens. See
[Extending: tools](docs/extending/tools.md) for the complete contract.

### Workflow prompts
`write_entry`, `translate_entry`, `edit_page_builder` and `model_content` prepare
authorized live context. They do not write or approve changes.

## Architecture

### Router-Based Design
- Domain routers with separate read and write/admin facets
- **Action-based routing**: Each router handles multiple related operations
- **Better AI performance**: Fewer tools to choose from, clearer purposes
- **Single file per domain**: Easy maintenance and testing

### Security
- Scoped API tokens with separate read, edit, publish, delete and administration grants
- OAuth 2.1 authorization server with PKCE and dynamic client registration
- Bearer tokens and OAuth
- Rate limiting per token
- Audit logging for all operations
- Path traversal protection
- PHPStan Level 9 strict typing

### CP Dashboard
The native Statamic CP pages under Tools > MCP provide connection snippets,
personal tokens and your own activity. The separate admin page exposes all tokens,
the site-wide audit log and system information according to the user's permissions.
Approvals have their own page. OAuth consent and token scope selection share
permission-filtered choices; administration and deletion need explicit selection
on OAuth consent. Interface copy is translated through Laravel language files.

## Configuration

```bash
php artisan vendor:publish --tag=statamic-mcp-config
```

Key settings in `config/statamic/mcp.php`:
- Web endpoint (enabled, path, HTTPS enforcement)
- Authentication (scoped tokens, token lifetime, audit logging)
- Security (force web mode, audit logging, response size limit, unknown-field rejection)
- Rate limiting (max attempts per minute)
- Per-domain tool enablement

## Development

```bash
# Run tests
./vendor/bin/pest
composer test

# Code formatting
./vendor/bin/pint
composer pint

# Static analysis (Level 9)
# The memory limit is required — the default crashes the parallel worker
./vendor/bin/phpstan analyse --memory-limit=2G
composer stan

# Full quality check
composer quality
```

### Quality Standards
- PHPStan Level 9 with zero errors, no baseline and no suppressions
- Laravel Pint formatting
- Strict types on all PHP files
- Comprehensive test suite

## Example Usage

```
"What version of Statamic is installed?"
"Show me all blueprints and generate TypeScript types"
"Create a new blog entry with title and content fields"
"List all global sets and their current values"
"Clear all caches and show me the status"
"Validate stored content against its blueprints"
```

## Contributing

1. Fork the repository
2. Install: `composer install`
3. Test: `./vendor/bin/pest`
4. Quality: `composer quality`
5. Submit pull request

## License

MIT License
