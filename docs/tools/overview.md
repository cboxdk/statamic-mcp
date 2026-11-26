---
title: "Tool Overview"
description: "Reference for MCP domain tools, actions, parameters, and response formats"
weight: 1
---

# Tool Overview

Every tool follows the same pattern: send an `action` parameter to specify what operation to perform, along with action-specific parameters. All tools return a standardized response.

## Content workflow prompts

`prompts/list` exposes four read-only preparations, not additional tools:

| Prompt | Task-specific arguments |
|---|---|
| `write_entry` | Optional `id` for an update; omit it to prepare a draft |
| `translate_entry` | Required source `id` and `target_site` |
| `edit_page_builder` | Required `id` and top-level `field` |
| `model_content` | Optional `kind`: `blueprint` (default) or `fieldset` |

All take `collection`, an existing `blueprint` handle and `task`. Entry workflows also accept the source `site`. The prompt embeds current blueprint `_format_spec` data and, for an existing entry, its current content and version. Page-builder prompts read only the selected field. They enforce the same scopes, Statamic permissions, profile and denied-field rules as tool reads. If a selected record cannot be read, `prompts/get` returns a protocol error rather than an incomplete workflow.

`completion/complete` offers readable collection, blueprint (with `context.arguments.collection`) and site handles, and the two modeling kinds. Suggestions scan authorized catalog pages and return up to 100 matches; `hasMore` signals omitted candidates. A fieldset result is a proposed definition for review: fieldset CRUD is not yet exposed. Calling any prompt does not save, publish or approve anything.

Existing entries must match the supplied blueprint. An unset page-builder field is reported explicitly; an inherited field is read through the origin's authorization checks. The complete prompt is subject to `security.max_response_size`. Translation instructions create drafts with `data.published=false` and use the destination site's version for subsequent updates.

## Response Format

```json
{
    "success": true,
    "data": { },
    "meta": {
        "tool": "statamic-blueprints-read",
        "timestamp": "2026-11-26T12:00:00Z"
    }
}
```

Framework versions are not repeated in tool responses. When `security.expose_versions` is enabled (default `false`), `server/discover` reports Statamic, Laravel and PHP versions in `result._meta["statamic-mcp/versions"]`. Record `version` tokens are unrelated: they describe stored state for optimistic concurrency.

On failure, MCP sets `isError: true`. Both `structuredContent` and the JSON text content carry the same envelope:

```json
{
    "success": false,
    "error": {
        "code": "NOT_FOUND",
        "message": "Collection not found: postz",
        "field": "collection",
        "valid_values": ["posts"],
        "hint": "Did you mean posts?"
    },
    "data": null,
    "meta": {"tool": "statamic-entries-read", "timestamp": "2026-11-26T12:00:00Z"}
}
```

`code` and `message` are always present. `field`, `valid_values` and `hint` are optional. Handle suggestions contain at most three nearby resources the caller can read, ordered by edit distance, then alphabetically. A write-only token receives no suggestions. Entry ids, user ids and email addresses are not suggested. Error messages are for people; branch on the code, not on English wording.

### Error codes

| Code | Meaning and next action |
|---|---|
| `VALIDATION` | Correct the arguments or field values named in the error. |
| `NOT_FOUND` | The resource was not found; check its handle, id and namespace. |
| `FORBIDDEN` | A profile, resource policy or Statamic permission refuses this call. Ask the site's administrator. |
| `SCOPE_MISSING` | The token lacks a required scope. The owner must approve the additional access. |
| `APPROVAL_REQUIRED`, `APPROVAL_PENDING` | Wait for the owner to approve the exact call, then repeat it with `data.approval_id` as the `approval_id` argument. |
| `APPROVAL_REJECTED`, `APPROVAL_STALE`, `APPROVAL_MISMATCH`, `APPROVAL_EXPIRED`, `APPROVAL_SPENT` | This approval cannot authorize the call. Check the record and the owner's decision before requesting another approval. |
| `VERSION_CONFLICT` | The version-guarded step did not run. Read the current record and redo the change against its version. For a multi-step write, inspect `meta.write_effects`: an earlier step may have saved. |
| `OUTCOME_UNKNOWN` | A write may have landed. Read and reconcile the record; do not blindly retry with a new idempotency key. |
| `IDEMPOTENCY_KEY_REUSED` | The key was already used with different input. Reconcile that operation before starting another. |
| `IDEMPOTENCY_KEY_SPENT`, `IDEMPOTENCY_KEY_EXPIRED` | The key cannot execute again. Inspect the previous outcome before starting another operation. |
| `TOO_LARGE` | Narrow the read with pagination, filters or a blueprint field path. A completed write instead returns a reduced successful result. |
| `INTERNAL` | The server could not complete the call. Give `meta.correlation_id`, when present, to the operator. Inspect `meta.write_effects` before retrying a write. |

Approval ids, current versions and other recovery information stay in `data`. Unknown codes in legacy extension results map to `INTERNAL`; arbitrary strings never become new wire codes. Unexpected exceptions hide diagnostic details in production and carry `meta.correlation_id` for investigation in server logs.

### List pages

Lists return `data.items`, `data.next_cursor` and `data.total`. Send `next_cursor` as `cursor` on the next call, keeping the query arguments unchanged. Stop when it is `null`. You may change `limit` between pages. Invalid, modified or different-query cursors return `VALIDATION` rather than silently restarting.

If the page exceeds `security.max_response_size`, it ends at a whole-record boundary and sets `truncated: true`. Its cursor resumes at the first record not returned. A single oversized record returns `TOO_LARGE` with recovery guidance; it is never silently skipped.

Entries, terms, users and assets are ordered by id; configuration catalogs by handle; blueprints by namespace and handle; revisions newest first. Totals count only accessible records. Cursors are positions, not snapshots: concurrent writes can shift records between pages. Every call checks current permissions again.

### Reading part of a large record

Every domain's `get` accepts `fields`: 1–50 dot paths relative to the result's `data` object, including its record key. For example, `fields: ["entry.version", "entry.data.page_builder.0"]` reads the version and the first section without returning the whole page builder. Array indices are zero-based; wildcards are not supported. Missing or denied paths return `VALIDATION`, not a fabricated null.

The response is explicitly a projection, not a complete record:

```json
{"projection": true, "fields": [{"path": "entry.data.title", "value": "About us"}]}
```

This avoids turning a selected array item into a replacement array when writing it back. Authorization and denied-field filtering run before projection. If a selected field is still too large, select its children. Omit `fields` for the normal full record. Blueprint `field` still scopes a field's format spec by blueprint handles; `fields` selects paths in the resulting response. On blueprint writes, `fields` remains an array of field definitions.

The blueprint read tool advertises `anthropic/maxResultSizeChars`, capped at 500,000 characters, for clients that honor it. This is a client hint for the tool, not a way to bypass the server's byte limit.

### Saved records and output schemas

Record writes return the saved record, including its opaque `version`, under the same key as `get` (`entry`, `term`, `global`, etc.). Deletes return the final snapshot. An asset copy returns the new record under `copy`. A collection move returns its saved tree and only the configuration the caller may see; a reorder-only editor does not gain administrative settings. Denied-field policies apply to write responses too, and user credentials are never included.

Versions fingerprint record state; they are not framework versions. Entries, terms, globals and forms accept `expected_version` for optimistic concurrency, as do the optional Forms Pro settings and SEO Pro defaults/redirects. Check the action schema for supported arguments. Collection responses with `include_tree` also version the selected site's tree; those without it version the collection settings. User IDs are strings on both storage backends. System operations, such as cache clearing, do not have record versions.

Every built-in tool publishes an `outputSchema` for its envelope and result variants. Clients can validate `structuredContent` directly. Failed calls require `error`; successful calls carry the tool's records or list page. A completed write whose full response exceeds the configured limit remains successful with `response_truncated: true` and identifying metadata; do not repeat the write to retrieve its content.

## Finding the tools

By default, the catalog lists the read and write/admin tools the caller's scopes
and installation profile allow. Each domain retains one router implementation.
The read tools advertise only read actions; a write sent to a read tool is refused.

For clients without their own tool search, `STATAMIC_MCP_SEARCHABLE_CATALOG=true`
opts into the smaller catalog and exposes the other tools through `search_tools`
and `execute_tools`. This changes discovery, not token scopes, resource policy,
Statamic permissions or approvals. See [tool catalog configuration](../configuration/reference.md#tool-catalog).

`statamic-discover` maps an intent to authorized tools and actions, or accepts
`tool_name` to return a tool's live input/output schemas. With no arguments it
returns the available catalog. Discovery is not a grant of access to every record.

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
| `list` | List with filtering; `filters` takes field handles or `status` (published, scheduled, expired, draft) | `collection`, `filters`, `include_unpublished`, `limit`, `cursor` |
| `get` | Get entry | `collection`, `id`, `version` |
| `create` | Create entry | `collection`, `slug`, `data` |
| `update` | Update entry | `collection`, `id`, `data`, `merge_sets`, `revision_message`, `expected_version` |
| `localize` | Create the entry's localization in another site | `collection`, `id`, `site`, `data` |
| `delete` | Delete entry | `collection`, `id`, `expected_version` |
| `publish` | Publish entry | `collection`, `id`, `revision_message`, `expected_version` |
| `unpublish` | Unpublish entry | `collection`, `id`, `revision_message`, `expected_version` |
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
{ "action": "get", "namespace": "collections", "collection": "pages",
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

#### Not overwriting someone else's change

Every entry response carries a `version`. Pass it back as `expected_version` on
`update`, `delete`, `publish`, `unpublish`, `restore_revision` or
`publish_working_copy`, and the write only happens if the stored entry is still
that version. If anyone changed it meanwhile — another agent, an editor in the
Control Panel, an import — the call answers `VERSION_CONFLICT` with the
`current_version`, and nothing is saved. Read the entry again and redo the
change. Without `expected_version` the write is unconditional, as before.

#### Actions that need a person's approval

Over HTTP, gated actions (by default every `delete`, blueprint writes and entry
`restore_revision`/`publish_working_copy`; see `confirmation.actions`) do not run
on the first call. The answer is `APPROVAL_REQUIRED` with an `approval_id`, and
nothing is saved. The person the call acts as opens **Tools → MCP → Approvals**
in the Control Panel, checks the exact call and approves it, confirming it is
them (password, passkey or emailed code). Then repeat the same call with
`approval_id` added: it runs once. Other answers say why it did not run:
`APPROVAL_PENDING`, `APPROVAL_REJECTED`, `APPROVAL_STALE` (the record changed
since the request; ask again), `APPROVAL_MISMATCH` (a different call),
`APPROVAL_EXPIRED`. Approving grants nothing: the repeated call is checked
against the token's scopes and the person's permissions again. The local stdio
server is not gated.

The person is told by mail, and with Cbox Relay also by web push and webhooks;
with Cbox ID they can approve on their phone. See
[Approval channels](../extending/approval-channels.md).

#### Retrying a write safely

Every write action on every router takes an optional `idempotency_key`: a key
you choose (8–128 characters: letters, digits, `.`, `_`, `:`, `-`), new for each
change. Retry a call that timed out with the same key and the same arguments:

- If the first call finished, you get its result again with
  `idempotent_replay: true`, and nothing is written a second time.
- If the first call started and never reported (a crash, a killed worker), the
  retry answers `OUTCOME_UNKNOWN` with the record's `current_version` and does
  not write. Read the record to see whether the change landed.
- If the first call saved nothing, the key is spent (`IDEMPOTENCY_KEY_SPENT`);
  send the change again with a new key.
- The same key with different arguments is refused (`IDEMPOTENCY_KEY_REUSED`).

Keys are kept for `operations.retry_seconds` (one hour by default) in the
operation store. Multi-node sites must use `DatabaseOperationStore`, or two nodes
can each run the same key once.

### `statamic-terms`

Taxonomy term management with slug conflict prevention and dependency validation.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `list` | List terms | `taxonomy`, `search`, `page`, `per_page` |
| `get` | Get term | `taxonomy`, `slug` |
| `create` | Create term | `taxonomy`, `slug`, `data` |
| `update` | Update term | `taxonomy`, `slug`, `data`, `expected_version` |
| `delete` | Delete term | `taxonomy`, `slug`, `expected_version` |

Term responses carry a `version` too, and `update` and `delete` take
`expected_version` the same way as entries. A term's version covers every site,
because Statamic keeps all of a term's localizations in one file: a change to
the German localization makes the English version stale as well.

### `statamic-globals`

Global set structure and values management with multi-site support.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `list` | List global sets | — |
| `get` | Get global set | `handle`, `site` |
| `update` | Update values | `handle`, `site`, `data`, `merge_strategy`, `expected_version` |

`get` and `update` report the site's `version`, and `update` takes
`expected_version` the same way as entries. Each site's values are stored on
their own, so a version covers one site: a change in another site does not
conflict.

### `statamic-structures`

Manage collections, taxonomies, navigations, and site configuration. Requires a `resource_type` parameter (`collection`, `taxonomy`, `navigation`, `site`, `globalset`).

| Action | Type | Description | Key Parameters |
|--------|------|-------------|----------------|
| `list` | collection | List collections | — |
| `get` | collection | Get collection | `handle` |
| `create` | collection | Create collection | `handle`, `data` |
| `update` | collection | Change settings, including structure depth and ordering | `handle`, `data` |
| `move` | collection | Move an entry and its descendants | `handle`, `site`, `data.entry`, `data.parent` |
| `list` | taxonomy | List taxonomies | — |
| `get` | taxonomy | Get taxonomy | `handle` |
| `list` | navigation | List navigations | — |
| `get` | navigation | Get a navigation and its trees | `handle` |
| `create` | navigation | Create a navigation, optionally with a tree | `handle`, `data` |
| `update` | navigation | Change settings and replace trees | `handle`, `data` |
| `list` | site | List sites | — |
| `get` | site | Get site | `handle` |

#### Collection reads for editors

Collection `get` accepts either `configure collections` or `view {handle} entries`.
A token still needs `structures:read`, and the resource policy must allow the
requested collection. On multisite installations, an entry viewer must also have
access to at least one of the collection's sites, matching Statamic's collection
view policy. Editors can request that scope in the token picker and OAuth consent
flow.

An entry viewer receives `handle`, `title`, `blueprints`, `dated`, `orderable`,
`past_date_behavior`, `future_date_behavior` and `default_status`. This describes
how to create and schedule entries without exposing administrative configuration,
configured sites or an entry count across sites. Collection administrators, super
admins and local CLI callers retain the full response. Collection configuration
writes still require `configure collections` in web context.
Collection listing is filtered by each collection's view permission. Editors with
reorder rights can move branches; see [Collection trees](collection-trees.md).

#### Navigation trees

`get` returns a navigation's menus as `trees: [{site, items}]`. A write accepts that
same list back, so the usual edit is: read, change the items, send the list as
`data.trees`. A map of site to items, or a single `data.tree` with an optional
`data.site`, works too. A tree replaces the whole menu for its site.

Each item is `{id?, entry?, url?, title?, data?, children?}` and needs at least an
`entry`, a `url` or a `title`. Items without an `id` get one. The whole payload is
checked before anything is saved: unknown keys, entries that do not exist, duplicate
ids, unknown sites and items deeper than `max_depth` are refused with the path that
failed, e.g. `trees.default[1].children[0] is 3 levels deep`.

```json
{
  "action": "update",
  "resource_type": "navigation",
  "handle": "main",
  "data": {
    "tree": [
      {"id": "home", "entry": "91fc9bb6-..."},
      {"title": "Contact", "url": "/contact"}
    ]
  }
}
```

### `statamic-assets`

Asset container and file operations. Requires a `resource_type` parameter (`container`, `asset`).

| Action | Type | Description | Key Parameters |
|--------|------|-------------|----------------|
| `list` | container | List containers | — |
| `get` | container | Get container | `handle` |
| `create` | container | Create container | `data` |
| `update` | container | Update container | `handle`, `data` |
| `delete` | container | Delete container | `handle` |
| `list` | asset | List assets in one folder (the root by default); `recursive` adds its subfolders | `container`, `folder`, `recursive` |
| `get` | asset | Get asset | `container`, `path` |
| `upload` | asset | Upload a file | `container`, `folder`, one of `source_url` / `content`+`encoding`+`filename` / `file_path` |
| `move` | asset | Move asset | `container`, `path`, `destination` |
| `copy` | asset | Copy asset with its data | `container`, `path`, `destination` |
| `create` | asset | Create a file from inline content | `container`, `filename`, `content` |
| `update` | asset | Update asset data (alt, title, …) | `container`, `path`, `data` |
| `delete` | asset | Delete asset | `container`, `path` |

Each action asks for the Statamic permission the Control Panel would, on the container it
acts on: `view {container} assets` to list and get, `upload` to upload, create and copy,
`edit` to update, `move` to move and `delete` to delete. `configure asset containers` covers
all of them, and is what creating, updating and deleting a container requires. Listing
containers returns only the ones the caller can view and the resource policy allows.

`upload` takes the file one of three ways:

- **`source_url`** — the server downloads it. This is the way for a remote client such
  as Claude or ChatGPT to add an image without sending it as base64. Only public
  `http`/`https` hosts on default ports can be fetched, never the server's own network;
  see [`security.url_import`](../configuration/reference.md#url_import).
- **`content`** with `encoding: base64` and a `filename` — the bytes in the call itself.
  Fine for small files; a photo of a few megabytes is too much for most clients.
- **`file_path`** — a file under `storage/app`, for local CLI clients.

`folder` places the file, e.g. `uploads/cases`. Every upload goes through Statamic's
own allowed-extensions check, as a Control Panel upload does; a site widens it with
`statamic.assets.additional_uploadable_extensions`.

For `move` and `copy`, `destination` is the new path with the filename:
`archive/old-hero.jpg`. End it with `/` to keep the filename. The extension cannot
change. `copy` refuses to overwrite an existing file.

### `statamic-users`

User CRUD with role and group management. Requires a `resource_type` parameter (`user`, `role`, `group`).

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
| `cache_clear` | Clear system caches | `cache_type` (all, stache, static, glide, views, app, config, route) |
| `cache_warm` | Warm the Stache or queue static pages | `cache_type` (stache, static, all) |
| `invalidate_urls` | Invalidate a validated batch of site URLs | `urls` |
| `search` | Permission-filtered local-index search | `handle`, `query`, `site`, `limit`, `cursor` |
| `reindex` | Submit a native index rebuild; approval required by default | `handle`, `site` |
| `config_get` | Read config value | `config_key` |

See [Search and cache](search-and-cache.md) for driver limits, content authorization,
queue requirements and post-write cache modes. Optional [Forms Pro](../extending/forms-pro.md)
and [SEO Pro](../extending/seo-pro.md) tools have separately approved integration scopes.

### `statamic-content-facade`

High-level analysis workflows that orchestrate multiple router calls.

| Action | Description | Key Parameters |
|--------|-------------|----------------|
| `content_audit` | Report content volume and coverage gaps across collections, taxonomies, and globals | `filters` |
| `content_validate` | Validate stored content against its blueprints and report schema drift | `scope`, `collection`, `taxonomy`, `severity`, `limit`, `cursor`, `max_findings` |
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
- `limit` / `cursor` — records scanned per call (default 100, max 500). Records are
  walked in a fixed order (entries → terms → globals → navigations). Repeat the
  query with the returned `next_cursor` until it is `null`. The cursor and `total`
  address that combined record stream, not the findings; a page with no findings
  can still have a next page.
- `max_findings` — cap on findings returned per call (default 200, max 2000).
  `summary.findings` stays accurate when the list is truncated.

Non-blueprint keys at the top level of an entry (`template`, `layout`, `parent`, …)
are legitimate and never reported; the `unknown_field` check applies only inside
sets and grid rows.

### `statamic-discover`

Omit arguments for `data.discovery.available_tools`, supply `intent` for recommendations, or `tool_name` for one tool's input/output schemas and annotations. This replaces both `statamic-system-discover` and `statamic-system-schema`.

The catalog and schemas include only actions allowed by the token's scopes and installation profile. Each actual call still checks its concrete resource, site and Statamic permissions. A system inventory is included only with `system:read` (or locally) and each inventory section is read through its own domain router, with pagination and authorization intact. Extensions can declare discovery keywords; see [Extending: tools](../extending/tools.md#be-recommended-by-discovery).

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

### V4 structure deletion

Collections and taxonomies must be empty before they can be deleted. `force: true`
does not bypass this requirement. Delete content explicitly through the corresponding
entry or term actions, with their normal permission and resource checks. MCP does not
provide a transaction across these individual deletions. Blueprint cleanup occurs only
after the parent accepts deletion; an unknown write outcome requires reconciliation
before retrying.
