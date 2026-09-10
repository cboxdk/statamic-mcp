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
| `get` | Get a specific blueprint | `handle`, `namespace`, `field`, `include_config`, `include_format_spec`, `max_format_depth` |
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
| `list` | List with filtering | `collection`, `filters`, `include_unpublished`, `limit`, `offset` |
| `get` | Get entry | `collection`, `id`, `version` |
| `create` | Create entry | `collection`, `slug`, `data` |
| `update` | Update entry | `collection`, `id`, `data`, `merge_sets`, `revision_message` |
| `localize` | Create the entry's localization in another site | `collection`, `id`, `site`, `data` |
| `delete` | Delete entry | `collection`, `id` |
| `publish` | Publish entry | `collection`, `id`, `revision_message` |
| `unpublish` | Unpublish entry | `collection`, `id`, `revision_message` |
| `list_revisions` | List an entry's revisions | `collection`, `id` |
| `get_revision` | Read one revision | `collection`, `id`, `revision_id` |
| `restore_revision` | Restore a revision | `collection`, `id`, `revision_id` |
| `publish_working_copy` | Publish the working copy | `collection`, `id`, `revision_message` |

#### Narrowing a blueprint response

A page builder's format spec is proportional to every set it can hold, so the
full response for a large blueprint can exceed the response size limit at any
useful depth. Pass `field` to `statamic-blueprints get` with a dot path to scope
the response to one field or set:

```json
{ "action": "get", "namespace": "collections", "collection_handle": "pages",
  "handle": "pages", "field": "page_builder.ContentSection.media" }
```

Segments are the handles the spec already reports — `allowed_set_types` for a
set, `group_fields` for a group. An unresolvable path lists the valid segments
at the level it failed, so a client can walk down without guessing.

#### Setting a template or layout per entry

`template` and `layout` are entry data that Statamic reads back itself
(`Entry::template()` falls back to `$this->get('template')`), but neither has to
be a blueprint field. Send either in `data` on `create`, `update` or `localize`
and it is stored:

```json
{ "action": "update", "collection": "pages", "id": "home",
  "data": { "template": "pages/landing" } }
```

Send `null` to clear it and fall back to the collection's template. A non-string
value is refused. `parent` is not writable this way — an entry's parent comes
from the structure tree, not its data.

#### Updating one section of a page builder

By default a replicator field in `data` replaces the stored array outright, so
changing one section means resending every other. With `merge_sets: true` the
incoming items are merged into the stored array by their `id`: an id that
already exists is replaced in place, a new one is appended, and stored items
you did not send are left untouched.

Every item sent must carry an `id`. Removing or reordering items still requires
sending the full array with `merge_sets` off, where the intent is unambiguous.
Applies to top-level replicator fields only — not bard, whose nodes are not all
addressable by id.

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
| `content_audit` | Report content volume and coverage gaps across collections, taxonomies, and globals | `filters` |
| `content_validate` | Validate stored content against its blueprints and report schema drift | `scope`, `collection`, `taxonomy`, `severity`, `limit`, `offset`, `max_findings` |
| `cross_reference` | Analyze relationships and dependencies between content types | `filters` |

Schema accepts `action` (required, enum: `content_audit`, `content_validate`, `cross_reference`) and the parameters listed above.

#### `content_validate`

Writes made through this addon are validated on the way in. `content_validate` is
the read-side sweep for everything else — git merges, hand-edited YAML, and
blueprints changed after the content was written.

Each record gets two passes: the blueprint's own validation rules, evaluated the
same way a Control Panel save evaluates them, plus structural checks the rule
engine cannot express.

| Finding type | Severity | What it means |
|--------------|----------|---------------|
| `rule_violation` | error | A blueprint validation rule fails against the stored value |
| `unknown_set_type` | error | A replicator/bard block names a set that is no longer defined; it is dropped at render |
| `unknown_field` | warning | A set or grid row stores a key that is no longer a field in its blueprint |
| `invalid_option` | error | A `select`/`radio`/`button_group`/`checkboxes` value is outside the declared options |
| `missing_asset` | error | An assets field references a file that no longer exists in its container |
| `dangling_reference` | error | A navigation item links to an entry that was deleted |
| `rule_engine_error` | warning | A stored value made a fieldtype's rule builder throw; the structural pass usually names the cause |

Parameters:

- `scope` — `all` (default), `entries`, `terms`, `globals`, or `navigations`
- `collection` / `taxonomy` — restrict the entry or term sweep to one handle
- `severity` — return only `error` or only `warning` findings
- `limit` / `offset` — records scanned per call (default 100, max 500). Records are
  walked in a fixed order (entries → terms → globals → navigations) and the offset
  addresses that combined stream, so paging a large site means repeating the call
  with a rising offset until `pagination.has_more` is false.
- `max_findings` — cap on findings returned per call (default 200, max 2000).
  `summary.findings` stays accurate when the list is truncated.

Non-blueprint keys at the top level of an entry (`template`, `layout`, `parent`, …)
are legitimate and never reported; the `unknown_field` check applies only inside
sets and grid rows.

### `statamic-system-discover`

Intent-based tool discovery. Describe what you want to do and the tool suggests which MCP tool and action to use.

### `statamic-system-schema`

Inspect the full JSON schema of any registered tool. Useful for AI agents to understand available parameters.

## Resources

Alongside tools, the server exposes MCP *resources* — read-only views a client can
browse without spending a tool call.

| URI | Contents |
|-----|----------|
| `statamic://blueprints` | Every readable blueprint, with its namespace, handle, title, and URI |
| `statamic://blueprints/{namespace}/{handle}` | One blueprint's fields, including type, display, and validation rules |

Read `statamic://blueprints` first to discover the URIs, then read the one you need.
Namespaces include the per-collection and per-taxonomy forms Statamic uses for entry
and term blueprints, so a URI often looks like
`statamic://blueprints/collections.pages/article`.

Resources enforce the same authorization as the equivalent tool call: the domain
must be enabled, the token must carry `blueprints:read`, the resource policy must
allow the handle, and the underlying Statamic user must have permission. A blueprint
the resource policy hides does not appear in the index at all.

## Tool Annotations

Tools declare behavior annotations:

- **`#[IsReadOnly]`** — Tool only reads data and has no side effects
- **`#[IsIdempotent]`** — Tool can be called multiple times safely with the same result

Tools also declare a **`#[Title]`** — the human-readable name a client shows in its
UI, distinct from the protocol name (`statamic-entries` → "Statamic Entries").
