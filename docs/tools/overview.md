---
title: "Tool Overview"
description: "Complete reference for all 11 MCP tools, their actions, parameters, and response formats"
weight: 1
---

# Tool Overview

Every tool follows the same pattern: send an `action` parameter to specify what operation to perform, along with action-specific parameters. All tools return a standardized response.

## Response Format

```json
{
    "success": true,
    "data": { },
    "meta": {
        "tool": "statamic-blueprints",
        "timestamp": "2026-03-12T12:00:00Z",
        "statamic_version": "6.0.0",
        "laravel_version": "12.0.0"
    }
}
```

The `statamic_version` and `laravel_version` fields in `meta` are only included when `security.expose_versions` is enabled (defaults to `false`).

On error, the response includes an `error` key with a human-readable message and a `code` key with a machine-readable error code.

## Domain Routers

### `statamic-blueprints`

Manage blueprint definitions, field schemas, and type generation.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `list` | List blueprints | `namespace`, `include_details`, `include_fields` |
| `get` | Get a specific blueprint | `handle`, `namespace` |
| `create` | Create a blueprint | `handle`, `namespace`, `fields` |
| `update` | Update a blueprint | `handle`, `namespace`, `fields` |
| `delete` | Delete a blueprint | `handle`, `namespace`, `confirm` |
| `scan` | Scan all blueprints | `include_fields` |
| `generate` | Generate a blueprint | `handle`, `namespace`, `fields` |
| `types` | Generate TypeScript/PHP types | `handle`, `output_format` |
| `validate` | Validate a blueprint | `handle`, `namespace` |

### `statamic-entries`

Dedicated entry operations with advanced filtering, search, and pagination.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `list` | List with filtering | `collection`, `filter`, `search`, `status`, `page`, `per_page` |
| `get` | Get entry | `collection`, `id` |
| `create` | Create entry | `collection`, `slug`, `data` |
| `update` | Update entry | `collection`, `id`, `data`, `merge_strategy` |
| `delete` | Delete entry | `collection`, `id` |
| `publish` | Publish entry | `collection`, `id` |
| `unpublish` | Unpublish entry | `collection`, `id` |

### `statamic-terms`

Taxonomy term management with slug conflict prevention and dependency validation.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `list` | List terms | `taxonomy`, `search`, `page`, `per_page` |
| `get` | Get term | `taxonomy`, `slug` |
| `create` | Create term | `taxonomy`, `slug`, `data` |
| `update` | Update term | `taxonomy`, `slug`, `data` |
| `delete` | Delete term | `taxonomy`, `slug` |

### `statamic-globals`

Global set structure and values management with multi-site support.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `list` | List global sets | — |
| `get` | Get global set | `handle`, `site` |
| `update` | Update values | `handle`, `site`, `data`, `merge_strategy` |

### `statamic-structures`

Manage collections, taxonomies, navigations, and site configuration. Requires a `type` parameter (`collection`, `taxonomy`, `navigation`, `site`).

| Action | Type | Description | Key Parameters |
|--------|------|-------------|----------------|
| `list` | collection | List collections | — |
| `get` | collection | Get collection | `handle` |
| `create` | collection | Create collection | `handle`, `title`, `config` |
| `list` | taxonomy | List taxonomies | — |
| `get` | taxonomy | Get taxonomy | `handle` |
| `list` | navigation | List navigations | — |
| `list` | site | List sites | — |
| `get` | site | Get site | `handle` |

### `statamic-assets`

Asset container and file operations. Requires a `type` parameter (`container`, `asset`).

| Action | Type | Description | Key Parameters |
|--------|------|-------------|----------------|
| `list` | container | List containers | — |
| `get` | container | Get container | `handle` |
| `create` | container | Create container | `data` |
| `update` | container | Update container | `handle`, `data` |
| `delete` | container | Delete container | `handle` |
| `list` | asset | List assets | `container`, `folder` |
| `get` | asset | Get asset | `container`, `path` |
| `upload` | asset | Upload asset | `container`, `file_path`, `filename` |
| `move` | asset | Move asset | `container`, `path`, `destination` |
| `copy` | asset | Copy asset | `container`, `path`, `destination` |
| `delete` | asset | Delete asset | `container`, `path` |

### `statamic-users`

User CRUD with role and group management. Requires a `type` parameter (`user`, `role`, `group`).

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `list` | List users/roles/groups | `type` |
| `get` | Get by ID/handle | `type`, `id` or `handle` |
| `create` | Create user/role | `type`, `data` |
| `update` | Update user/role | `type`, `id`, `data` |
| `delete` | Delete user/role | `type`, `id` |
| `assign-role` | Assign role to user | `user_id`, `role` |

### `statamic-system`

System information, health checks, cache management, and configuration access.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `info` | Get system information | — |
| `health` | Health check status | — |
| `cache_status` | Check cache status and statistics | `include_details` |
| `cache_clear` | Clear system caches | `cache_type` (all, stache, static, views, app, config, route) |
| `cache_warm` | Warm system caches | `cache_type` |
| `config_get` | Read config value | `config_key` |
| `config_set` | Set config value | `config_key`, `config_value` |

### `statamic-content-facade`

High-level analysis workflows that orchestrate multiple router calls.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `content_audit` | Scan all content for issues, missing references, and orphaned content | `filters` |
| `cross_reference` | Analyze relationships and dependencies between content types | `filters` |

Schema accepts `action` (required, enum: `content_audit`, `cross_reference`) and optional `filters` (object).

### `statamic-system-discover`

Intent-based tool discovery. Describe what you want to do and the tool suggests which MCP tool and action to use.

### `statamic-system-schema`

Inspect the full JSON schema of any registered tool. Useful for AI agents to understand available parameters.

## Tool Annotations

Tools declare behavior annotations:

- **`#[IsReadOnly]`** — Tool only reads data and has no side effects
- **`#[IsIdempotent]`** — Tool can be called multiple times safely with the same result
