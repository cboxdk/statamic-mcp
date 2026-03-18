# Statamic MCP Server

A comprehensive MCP (Model Context Protocol) server for Statamic CMS v6 that provides AI assistants with structured access to Statamic's content management capabilities through a modern router-based architecture.

## Requirements

- PHP 8.3+
- Laravel 12+
- Statamic 6.6+
- Laravel MCP ^0.6

## Installation

```bash
# Install via Composer
composer require cboxdk/statamic-mcp

# Run the installation command
php artisan mcp:statamic:install
```

### Recommended: Laravel Boost Integration

We recommend installing **Laravel Boost** alongside this addon for the best experience:

```bash
composer require laravel/boost --dev
```

**Laravel Boost** provides Laravel-specific tools (Eloquent, database, debugging), while **Statamic MCP Server** provides Statamic-specific tools (blueprints, collections, entries, assets). Together they give your AI assistant complete coverage.

## Web MCP Endpoint

The web MCP endpoint is **enabled by default** after installation. To customize the path:

```env
STATAMIC_MCP_WEB_PATH="/mcp/statamic"
```

Create a token in the CP dashboard (Tools > MCP > Tokens), then configure your AI client:

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

See [Getting Started](docs/getting-started/quickstart.md) for detailed setup or [AI Client Setup](docs/getting-started/ai-clients.md) for client-specific instructions (Claude, Cursor, ChatGPT, Windsurf).

## Features

The MCP server organizes Statamic's capabilities into domain routers with action-based routing:

### Blueprint Management — `statamic-blueprints`
Actions: `list`, `get`, `create`, `update`, `delete`, `scan`, `generate`, `types`, `validate`

List, inspect, create, and modify blueprints. Generate TypeScript/PHP types from field definitions. Validate blueprints for conflicts and structural integrity.

### Entry Management — `statamic-entries`
Dedicated entry operations with filtering, search, pagination, status filtering, merge strategies, and bulk operations.

### Term Management — `statamic-terms`
Taxonomy term operations with slug conflict prevention, dependency validation, and relationship mapping.

### Global Management — `statamic-globals`
Global set structure and values management with multi-site support, change tracking, and field-level filtering.

### Structure Management — `statamic-structures`
Collection, taxonomy, navigation, and site configuration management.

### Asset Management — `statamic-assets`
Asset container and file operations: upload, move, copy, rename, delete with metadata management.

### User Management — `statamic-users`
User CRUD, role assignment, group management with RBAC support.

### System Management — `statamic-system`
System info, health checks, cache management (clear/warm), and configuration access.

### Content Workflow Facade — `statamic-content-facade`
High-level workflow operations: `content_audit` and `cross_reference`.

### Agent Education Tools
- `statamic-system-discover` — Intent-based tool discovery
- `statamic-system-schema` — Tool schema inspection

## Architecture

### Router-Based Design
- **11 domain routers** instead of 140+ individual tools
- **Action-based routing**: Each router handles multiple related operations
- **Better AI performance**: Fewer tools to choose from, clearer purposes
- **Single file per domain**: Easy maintenance and testing

### Security
- Scoped API tokens with 21 granular permissions
- OAuth 2.1 authorization server with PKCE and dynamic client registration
- Bearer token + Basic Auth authentication
- Rate limiting per token
- Audit logging for all operations
- Path traversal protection
- PHPStan Level 8 strict typing

### CP Dashboard
Vue 3 dashboard in the Statamic CP (Tools > MCP) with:
- **Connect** — Endpoint URL, client config snippets for Claude/Cursor/ChatGPT/Windsurf
- **Tokens** — Create, list, and revoke API tokens with scope selection
- **Activity** — Audit log of MCP tool calls
- **Settings** — System stats, endpoint status, rate limiting

## Configuration

```bash
php artisan vendor:publish --tag=statamic-mcp-config
```

Key settings in `config/statamic/mcp.php`:
- Web endpoint (enabled, path, HTTPS enforcement)
- Authentication (scoped tokens, token lifetime, audit logging)
- Security (force web mode, audit logging)
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

# Static analysis (Level 8)
./vendor/bin/phpstan analyse
composer stan

# Full quality check
composer quality
```

### Quality Standards
- PHPStan Level 8 with zero errors
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
"Analyze this Antlers template for performance issues"
```

## Contributing

1. Fork the repository
2. Install: `composer install`
3. Test: `./vendor/bin/pest`
4. Quality: `composer quality`
5. Submit pull request

## License

MIT License
