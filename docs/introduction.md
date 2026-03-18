---
title: "Introduction"
description: "MCP server for Statamic CMS v6 that gives AI assistants structured access to content, blueprints, assets, users, and system operations"
weight: 1
---

# Introduction

**Statamic MCP Server** is a Laravel package that exposes your Statamic CMS v6 site to AI assistants via the [Model Context Protocol](https://modelcontextprotocol.io). It gives tools like Claude, Cursor, ChatGPT, and Windsurf structured, authenticated access to your content, blueprints, collections, assets, and more.

## Overview

The server registers a set of domain routers — each handling a specific Statamic concern — and makes them available over both CLI (`php artisan mcp:serve`) and web (HTTP endpoint) transports. All web access is gated by scoped API tokens with 21 granular permissions.

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
- **11 MCP tools** instead of 140+ individual tools
- Each router handles multiple actions via an `action` parameter
- Fewer tools for the LLM to reason about, clearer purpose per tool
- Single file per domain for easy maintenance

### Scoped API Tokens
- 21 granular scopes in `domain:permission` format (plus `*` for full access)
- SHA-256 hashed storage, configurable expiry, max tokens per user
- Bearer token authentication with Basic Auth fallback
- Created and managed in the Statamic Control Panel

### CP Dashboard
- Unified Vue 3 dashboard at **Tools > MCP** with four tabs:
  - **Connect** — Endpoint URL and copy-paste config for Claude, Cursor, ChatGPT, Windsurf
  - **Tokens** — Create, list, and revoke API tokens with scope selection
  - **Activity** — Audit log of all MCP tool calls
  - **Settings** — System stats, endpoint status, rate limiting

### Security
- Rate limiting per token (configurable)
- Audit logging for all operations
- Per-domain tool enablement
- Path traversal protection
- PHPStan Level 8 strict typing

## Available Tools

| Tool | Actions | Description |
|------|---------|-------------|
| `statamic-blueprints` | list, get, create, update, delete, scan, generate, types, validate | Blueprint management and type generation |
| `statamic-entries` | list, get, create, update, delete, publish, unpublish | Entry operations with filtering and search |
| `statamic-terms` | list, get, create, update, delete | Taxonomy term operations with slug conflict prevention |
| `statamic-globals` | list, get, update | Global set structure and values with multi-site support |
| `statamic-structures` | list, get, create, update, delete, configure | Collections, taxonomies, navigations, sites |
| `statamic-assets` | list, get, create, update, delete, upload, move, copy | Asset container and file operations |
| `statamic-users` | list, get, create, update, delete, assign-role | User CRUD with role and group management |
| `statamic-system` | info, health, cache_status, cache_clear, cache_warm, config_get, config_set | System info, health checks, cache management |
| `statamic-content-facade` | content_audit, cross_reference | High-level workflow orchestration |
| `statamic-system-discover` | — | Intent-based tool discovery for AI agents |
| `statamic-system-schema` | — | Tool schema inspection |

## Requirements

- **PHP** 8.3+
- **Statamic** v6.6+
- **Laravel** 12.0+
- **laravel/mcp** ^0.6

## Quick Links

- [Installation](getting-started/installation.md) — Install and configure the package
- [Quick Start](getting-started/quickstart.md) — Connect your first AI assistant in 2 minutes
- [Token Scopes](authentication/token-scopes.md) — All 21 scopes explained
- [AI Client Setup](getting-started/ai-clients.md) — Config for Claude, Cursor, ChatGPT, Windsurf
- [Configuration Reference](configuration/reference.md) — All config options and env variables
- [Tool Reference](tools/overview.md) — Detailed documentation for each tool
