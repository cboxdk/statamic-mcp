---
title: "Introduction"
description: "MCP server for Statamic CMS v6 that gives AI assistants structured access to content, blueprints, assets, users, and system operations"
weight: 1
---

# Introduction

**Statamic MCP Server** is a Laravel package that exposes your Statamic CMS v6 site to AI assistants via the [Model Context Protocol](https://modelcontextprotocol.io). It gives tools like Claude, Cursor, ChatGPT, and Windsurf structured, authenticated access to your content, blueprints, collections, assets, and more.

## Overview

The server registers domain routers over local stdio (`php artisan mcp:start statamic`)
and an opt-in HTTP endpoint. Both require an explicit installation profile. HTTP
uses scoped Bearer tokens, including OAuth grants. This example is for Cursor's
`.cursor/mcp.json`; see [client setup](getting-started/ai-clients.md) for other formats.

```json
{
    "mcpServers": {
        "statamic": {
            "url": "https://your-site.test/mcp/statamic",
            "headers": {
                "Authorization": "Bearer <your-token>"
            }
        }
    }
}
```

## Key Features

### Router-Based Architecture
- Separate read and write/admin tools for each domain
- Each router handles multiple actions via an `action` parameter
- Fewer tools for the LLM to reason about, clearer purpose per tool
- Single file per domain for easy maintenance

### Scoped API Tokens
- Scopes in `domain:permission` format, plus `*` for full access (expanded to an explicit list when issued)
- SHA-256 hashed storage, configurable expiry, max tokens per user
- Bearer tokens and OAuth
- Created and managed in the Statamic Control Panel

### CP Dashboard
- **Tools > MCP** — Connection snippets, personal tokens and your own activity
- **MCP Admin** — All tokens, site-wide activity and system information, permission-gated
- **Approvals** — Review one exact requested operation; approval requires elevated authentication
- Native consent and shared scope selection with English and Danish interface translations

### Security
- Rate limiting per token (configurable)
- Audit logging for all operations
- Per-domain tool enablement
- Path traversal protection
- PHPStan Level 9 strict typing, with no baseline and no suppressions

## Available Tools

| Read tool | Write tool | Purpose |
|---|---|---|
| `statamic-blueprints-read` | `statamic-blueprints-admin` | Blueprints, reusable fieldsets, native layout/imports, field provenance and type generation |
| `statamic-entries-read` | `statamic-entries-write` | Entries by id/slug/URI, safe sorting, localizations and revisions |
| `statamic-terms-read` | `statamic-terms-write` | Taxonomy terms |
| `statamic-globals-read` | `statamic-globals-write` | Localized global values |
| `statamic-structures-read` | `statamic-structures-admin` | Collections, taxonomies, navigation and global-set definitions; read-only sites |
| `statamic-assets-read` | `statamic-assets-write` | Asset containers and files |
| `statamic-forms-read` | `statamic-forms-write` | Forms 2 definitions and separately scoped submissions |
| `statamic-users-read` | `statamic-users-admin` | Users, roles and groups |
| `statamic-system-read` | `statamic-system-admin` | System info, search and cache operations |
| `statamic-content-facade-read` | — | Content audit, paginated validation and bounded cross-reference |

`statamic-discover` maps an intent to authorized actions or returns a tool's live
schemas. The default catalog lists permitted facets directly; searchable tools
are an opt-in configuration. Read-only [resources and completions](tools/overview.md#resources)
provide content-model catalogs and record links under the same access boundaries.

## Requirements

- **PHP** 8.3+
- **Statamic** ^6.44
- **Laravel** 12.0+
- **laravel/mcp** ^1.3

## Quick Links

- [Installation](getting-started/installation.md) — Install and configure the package
- [Quick Start](quickstart.md) — Connect your first AI assistant in 2 minutes
- [Token Scopes](authentication/token-scopes.md) — Available scopes and common combinations
- [AI Client Setup](getting-started/ai-clients.md) — Config for Claude, Cursor, ChatGPT, Windsurf
- [Configuration Reference](configuration/reference.md) — All config options and env variables
- [Tool Reference](tools/overview.md) — Detailed documentation for each tool
- [Extending Fieldtypes](extending/fieldtypes.md) — Teach the server about a fieldtype an addon provides
