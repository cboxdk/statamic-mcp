# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.9.1] - 2026-09-04

### Fixed
- **Asset field values are round-trip safe** (#41) — `get` returns an assets field the way Statamic stores it, as a container-relative path (`icons/heart.svg`), but both the validation rules and the fieldtype pipeline expect the form the Control Panel submits, the asset ID (`assets::icons/heart.svg`). Sending a value straight back to `create`/`update` therefore failed file rules such as `mimes` — `MimesRule` does an `Asset::find()` on the value and a bare path finds nothing — and on rule-free fields it would have broken later in `Assets::process()`, which calls `Asset::findOrFail()`. Incoming asset paths are now resolved to canonical IDs before validation, in nested replicator, grid, group and bard set fields as well as top-level ones, and in the entry's stored data that an update merges in — so an unchanged asset field elsewhere in the blueprint no longer fails an update that never touched it. Values that resolve to no asset are left alone so validation still reports the real problem instead of silently dropping content
- **`content_validate` no longer flags every valid assets field** — The read-side sweep ran the blueprint's rules against stored values, which meant it reported up to three errors for a perfectly valid single-file assets field: "must be a file of type: svg" (the file rules resolve the value with `Asset::find()`, which a bare path misses), plus "must be an array" and "must not have more than 1 items" from the fieldtype's own rules, which expect a list. Asset references are now bridged to canonical IDs for the rule pass, through nested replicator, bard, grid and group fields as well as top-level ones. The structural pass still sees the stored values verbatim, so a `missing_asset` finding keeps quoting the reference as it appears on disk
- **`content_validate` resolves single-container asset fields** — An assets field that omits `container` where the site has exactly one was skipped by the missing-asset check; it now resolves the same way the fieldtype does

## [2.9.0] - 2026-08-27

### Added
- **`content_validate` action on `statamic-content-facade`** — Validates content that is *already stored* against its blueprints. Writes through this addon are validated on the way in; content that arrives another way (git merges, hand-edited YAML, blueprints changed after the content was written) was previously invisible. Each record gets two passes: the blueprint's own validation rules, evaluated the same way a Control Panel save evaluates them, plus structural checks the rule engine cannot express — replicator/bard blocks naming a set that no longer exists, sets and grid rows storing keys the blueprint dropped, `select`/`radio`/`button_group`/`checkboxes` values outside the declared options, assets fields pointing at missing files, and navigation items linking to deleted entries. All of these pass rule validation silently while breaking at render time. Supports `scope`, `collection`/`taxonomy` filters, `severity` filtering, offset/limit paging across the combined record stream, and a `max_findings` cap that keeps summary counts accurate when the list is truncated
- **`ValidatesContentRecords` concern** — The two-pass record validation above, extracted so other routers can reuse it. The rule pass is isolated per record: a malformed stored value that makes a fieldtype's rule builder throw is reported as a `rule_engine_error` warning rather than aborting the sweep, and the structural pass still runs to name the underlying shape problem
- **MCP resources for blueprints** — The server now exposes the `resources` capability, which it previously left unused. `statamic://blueprints` lists every readable blueprint with its URI; `statamic://blueprints/{namespace}/{handle}` returns one blueprint's fields, so a client can look up field structure to shape a write without spending a tool call. Because `RequireMcpPermission` defers scope checks to the primitive, resources run the same four gates a router read does — tool enablement, token scope (`blueprints:read`), resource policy, and Statamic permissions — via a new `AuthorizesResourceAccess` concern. Blueprints the resource policy hides are absent from the index, not merely refused on read
- **`#[Title]` on every tool** — `tools/list` has always carried a `title` field; without the attribute it fell back to `Str::headline(class_basename())`, so clients displayed "Entries Router". Tools now declare their own display titles

- **Typed validation model** — `Finding`, `RecordRef`, and the `Severity`/`FindingType`/`RecordType` enums replace the `array<string, mixed>` bags the validation sweep threaded through its call chain. Severity is derived from the finding type rather than passed alongside it, so a finding cannot be built with a severity that contradicts what it describes; findings become arrays only at the MCP response boundary
- **`Testing/InteractsWithMcp` and `Testing/FakeTransport`** — Shipped testing helpers for driving this addon's MCP server, dogfooded by the package's own suite. A `tests/Fixtures` composition site is included in the PHPStan paths so the traits are analysed where they are actually mixed in — which immediately caught a wrong `class-string` bound in the trait itself
- **Supply-chain gate** — `bin/check-licenses.php` fails the build on any non-permissive dependency (SPDX dual-licensing handled: a package passes if any arm is permissive), and `bin/generate-sbom.php` emits a deterministic CycloneDX 1.5 `sbom.json` with sorted components and a content-derived serial number, so it only changes when dependencies do. Wired into CI along with `composer audit --no-dev`, plus a new `composer qa` aggregate. CI validates the generated document rather than diffing it against the committed one: `composer.lock` is gitignored because this is a library, so a freshly resolved lock legitimately differs and a drift check would fail whenever any transitive dependency publishes. The license check found one real case: `statamic/cms` is proprietary, recorded as a justified exception because a Statamic addon cannot avoid depending on Statamic
- **`SECURITY.md`** — Private vulnerability reporting via GitHub, an explicit split between what this addon secures and what the operator does, and a limitations section stating plainly that the audit log is append-only by convention with no hash chain (neither tamper-proof nor tamper-evident), that confirmation tokens are replayable within their window, and that `require_https` falls back to off when the published config predates the key

### Fixed
- **The MIT license text actually ships** — `composer.json` has always declared MIT, but the repository never contained a `LICENSE` file, so the grant existed only as metadata. The standard MIT text is now included
- **Entry updates no longer fail on blueprints with a required slug** (#39) — The #27 fix removed the slug from the validated payload entirely, so Statamic's default slug field (`validate: [required, UniqueEntryValue…]`) could never be satisfied: every update failed with "The Slug field is required", whether the caller omitted the slug or resent the current one. The entry's effective slug is now injected back into the validation payload, and both `FieldsValidator` invocations (including the `TypeError` fallback) resolve the `UniqueEntryValue({collection}, {id}, {site})` placeholders via `withReplacements()`, so the rule excludes the entry being updated — the false positive #27 was about — while a slug owned by another entry is still rejected
- **`date` no longer has to be resent on every update of a dated collection** — Like the slug, the date is an entry property absent from the merged data payload, so a blueprint with a required date field failed any update that did not repeat a date the caller never meant to change. The entry's current date now satisfies the rule when the payload omits it
- **Explicit slug on create actually works** — `createEntry()` read `$arguments['slug']`, but the tool schema never declared the parameter, so no client could send it and the slug was always derived from the title. The schema now declares `slug`, and create also accepts it as `data.slug` — the shape update uses — storing it as an entry property in both cases, never as a data key
- **PHPStan level 9 is no longer partly disabled** — The config carried blanket `ignoreErrors` patterns (`#Method .* should return .* but returns mixed#`, `#Cannot call method .* on .*\|null#`, `#Parameter .* expects .*, mixed given#`, and four more) that suppressed whole error classes across `src/`, so "level 9 clean" meant considerably less than it sounded. Removing them surfaced 54 real errors — almost all method calls on `Blueprint|null`, `Entry|null`, or `GlobalSet|null` after a lookup, because `requireResource()` returned an error array without narrowing the variable. Every site now checks for null explicitly (identical messages, identical behaviour, and the analyser can see it), the untyped Statamic/Eloquent return values are narrowed rather than cast, and the four `@phpstan-ignore` annotations on `abort()` calls are gone. `requireResource()` itself is removed, having no callers left
- **CI verifies formatting instead of rewriting it** — The Tests workflow ran Pint in fix mode, committed the result, and pushed it back to the branch; it now runs `pint --test` and fails on violations, matching what the release workflow already does
- **CI exercises both Laravel majors** — `composer.json` claims Laravel 12 and 13 via `orchestra/testbench: ^10.0 || ^11.0`, but the matrix only varied PHP, so every job resolved testbench 11 and the Laravel 12 claim was never verified. The matrix now spans both. The suite passes on Laravel 12
- **Package classes are no longer `final`** — Nine classes were sealed, blocking consumers from extending or decorating what the package ships
- **Failed tool calls set the MCP `isError` flag** — Every response was returned via `Response::structured()`, which never marks an error, so a failed call arrived at the client indistinguishable from a successful one; only a model parsing the JSON body would notice `"success": false`. Failures are now assembled from `Response::error()` plus the same structured content, so both the protocol flag and the full envelope (including confirmation tokens) survive
- **Plaintext credentials no longer reach stack traces** — `TokenService::validateToken()`/`findByPlainText()`, `ConfirmationTokenManager`'s token methods, `AuthenticateForMcp::authenticateWithCredentials()`, and every `ClientConfigGenerator` method took secrets as plain parameters. In the stdio server, `setupErrorHandling()` writes `getTraceAsString()` to stderr, so a throw anywhere in those call chains logged the bearer token verbatim. All are now marked `#[\SensitiveParameter]`, matching the hardening laravel/mcp applied upstream in v0.9.0
- **Server version no longer hardcoded** — `StatamicMcpServer::$version` was the literal `'2.8.0'` and would have drifted at the next release. It is read from Composer's installed-package metadata, falling back to `0.0.0` only when that is unavailable
- **Release workflow no longer hangs** — The release job ran `pest --parallel`, which is not parallel-safe: `Statamic\Testing\AddonTestCase` points every Stache store, and `PreventsSavingStacheItemsToDisk`'s `dev-null` directory, at one shared `tests/__fixtures__` path, so ParaTest workers deleted each other's fixtures. This produced ~34 spurious failures or, when workers collided on the file-store `flock()` calls, a hang that burned the 6h job timeout (v2.6.1 and v2.8.0 both died this way, and both releases had to be published by hand). The release job now runs the same single-process `pest` that gates pull requests, and every job has an explicit `timeout-minutes` so a hang fails in minutes instead of hours
- **Release notes are no longer empty** — The changelog extraction matched `[v2.8.0]` against headings written as `[2.8.0]`, so it never selected anything. The tag's `v` prefix is now stripped, with a fallback message if the section is missing
- **Release workflow verifies formatting instead of rewriting it** — The `Fix code formatting` step ran Pint in fix mode and discarded the result; it now runs `pint --test` and fails on violations

### Changed
- **Docs follow the standard topic layout** — `introduction.md` became `index.md`, `quickstart.md` moved to the docs root, and a generated `requirements.md` states only what the resolver enforces. `docs/superpowers/` and its subfolders gained the `_index.md` landings and frontmatter they were missing, which had been downgrading the docs site's grading from `complete` to `partial`. All relative links repaired and verified
- **Pest constraint widened to `^4.1 || ^5.0`** — Pest 5 is stable; the package was a major behind
- **Tests now exercise the real MCP protocol** — Every test drove tools through `execute()` directly, so JSON-RPC argument delivery, response serialization, the `isError` flag, and outputSchema conformance had no coverage at all; `StatamicMcpServerTest` even read protected properties by reflection. A new `McpProtocolSurfaceTest` drives tools through `StatamicMcpServer::tool()`. This required registering `Laravel\Mcp\Server\McpServiceProvider` in `TestCase::getPackageProviders()` — Testbench does not run package auto-discovery, so without it `Laravel\Mcp\Request` never receives arguments and protocol-level tests pass while asserting nothing
- **`composer stan` passes `--memory-limit=2G`** — PHPStan crashed its parallel worker at PHP's default 128M; 1G proved marginal once `tests/Fixtures` joined the analysis paths
- **Removed the `composer test:parallel` script** — It could not work for the reason above; use `composer test`

## [2.8.0] - 2026-07-29

### Changed
- **laravel/mcp ^0.9 support** — Widens the dependency constraint to `^0.6 || ^0.7 || ^0.8 || ^0.9`. The 0.9 breaking changes are client-side only (the `Laravel\Mcp\Client\Contracts\Transport` contract gained `setProtocolVersion()`, and the MCP *client* now only negotiates `2025-11-25`/`2025-06-18`); this addon ships a server and does not implement either, so no code changes were needed. Server-side gains come for free: stricter JSON-RPC `params`/`arguments` validation (`-32602` on non-object payloads), a `CursorPaginator` fix for negative cursor offsets, and hardened OAuth dynamic client registration

## [2.7.0] - 2026-07-05

### Changed
- **laravel/mcp ^0.8 support** — Widens the dependency constraint to `^0.6 || ^0.7 || ^0.8`, allowing the latest laravel/mcp release (v0.8.x) with MCP client support, MCP UI Apps, `ResourceLink` content type, and OAuth improvements. All patterns used by this addon are unchanged across 0.6–0.8

### Fixed
- **Blueprint `types` action null handles** — Blueprints without a handle are now skipped during type analysis instead of triggering a type error
- **Output buffer cleanup type safety** — Shutdown output-buffer sweep in the MCP server no longer relies on an impossible `false` comparison flagged by stricter dependency types

## [2.6.1] - 2026-06-30

### Fixed
- **Confirmation token retry loop** — Router schemas now expose `confirmation_token` as an optional top-level argument, giving MCP clients a valid schema slot for the token returned by confirmation-required responses (#34)
- **Confirmation payload drift on retry** — Confirmation tokens now preserve the originally confirmed arguments, tolerate associative key reordering, and restore the confirmed payload before executing the gated action while still rejecting changed payloads and reordered lists (#34)

### Tests
- Added regression coverage for schema exposure, two-step confirmation retries, nested associative argument reordering, and confirmed payload restoration

## [2.6.0] - 2026-06-02

### Fixed
- **Eloquent user ID compatibility** — Normalizes Statamic user IDs before MCP token ownership checks so sites using the Eloquent users driver no longer hit strict type errors in the MCP dashboard or token flows (#31)
- **OAuth authorization user IDs** — Applies the same user ID normalization when creating OAuth authorization codes for Eloquent-backed users
- **Fresh install dependency compatibility** — Allows `laravel/mcp` `^0.7` alongside `^0.6` and updates installation docs/generated guidance to match (#31)

### Tests
- Added regression coverage for integer Eloquent user IDs, string user IDs, and missing current users in MCP dashboard user resolution

## [2.5.0] - 2026-05-06

### Added
- **Revision-aware entry workflows** — When a collection has revisions enabled, the MCP server now respects Statamic's editorial workflow instead of bypassing it with direct saves. Updates to published entries create a working copy (published content unchanged), creates use `store()` for draft + initial revision, and publish/unpublish delegate to Statamic's built-in revision-aware methods (#30)
- **New entry actions**: `list_revisions`, `get_revision`, `restore_revision`, `publish_working_copy` — full revision lifecycle management via the `statamic-entries` router
- **`version` parameter on entry get** — Retrieve `published`, `working_copy`, or `latest` version of an entry
- **`HandlesRevisions` trait** — Encapsulates revision-aware save, list, get, and restore operations matching the Statamic CP's exact editorial workflow
- **Confirmation gate defaults for revision actions** — `restore_revision` and `publish_working_copy` now require confirmation in production by default
- **31 new tests** covering the full revision lifecycle (create → working copy → list revisions → restore → publish)

### Fixed
- **Multi-site response in `publishWorkingCopyAction`** — Re-fetches entry with site context to return correct localized data
- **Stale revision metadata after restore** — `revision_status` and `restored_as_working_copy` now reflect actual state after `restoreRevisionAction`
- **`normalizeTableCell` unbounded recursion** — Table cell normalization now unwraps one level only, preventing stack overflow on deeply nested structures
- **`filterOutputFields` denied field stripping** — Denied fields are now correctly stripped from list responses and nested entry data, not just top-level get responses
- **`generateBlueprint` field array shape** — Blueprint generation now produces the correct indexed handle/field format
- **`revision_message` type safety** — `publishWorkingCopyAction` validates that revision message is a string before passing to Statamic
- **PHPStan L8 compliance** — Resolved mixed offset access in `filterOutputFields` recursive field filtering

## [2.4.0] - 2026-05-05

### Added
- **Per-field wire-format spec** — `BlueprintsRouter::get` now emits a `_format_spec` per field describing the exact wire format (shape, allowed node types, set handles, canonical examples). Covers bard (inline + full), replicator, grid, group, markdown, scalar, select/checkbox, relationship, asset, table, and date fields. Controllable via `include_format_spec` (default `true`) and `max_format_depth` (default 2, max 5) parameters (#29)
- **`FieldFormatException`** — new exception class for malformed bard/replicator/grid/table input, with precise field-path error messages that survive production sanitization
- **Client-safe exception allow-list** — `FieldFormatException`, `ValidationException`, `FieldtypeNotFoundException`, and `BlueprintNotFoundException` messages now reach the client in production instead of being replaced with a generic placeholder
- **Configurable confirmation actions** — new `confirmation.actions` config block allows per-domain control over which actions require confirmation tokens. Domains not listed fall back to `default`. `*` gates every action; `[]` disables the gate. Shipped defaults preserve existing behaviour (#26)
- **`ConfirmationActionGate`** — new helper that resolves `(domain, action)` → gated? from config, replacing the previous hardcoded logic in `RequiresConfirmation`

### Fixed
- **Entry slug self-collision on update** — `updateEntry()` no longer fails with "slug already taken" when updating an entry without changing its slug. The fix passes the current entry ID as exclusion to `UniqueEntryValue`, matching the pattern used in `createEntry()` (#28)
- **Table cell normalization** — `SanitizesFieldData` now correctly normalizes `{value: …}` objects in table cells to plain strings, preventing `[object Object]` rendering in the CP
- **Playwright login throttling in CI** — Browser tests now use a shared `globalSetup` + `storageState` pattern (single login per run) instead of per-test login, preventing Statamic's login throttle from failing the last test

## [2.2.4] - 2026-04-14

### Fixed
- **Critical:** Update action on entries, terms, and globals no longer crashes with `Cannot access offset of type string on string` when blueprints include third-party fieldtypes (e.g., SEO Pro) — validation now falls back to incoming-only fields when a `TypeError` occurs in the full-merge validation pipeline
- **OAuth CIMD discovery:** `cimd_enabled` config checks now use `(bool)` cast with `true` default — strict `=== true` comparison against env strings silently disabled CIMD, and published configs missing the key (due to `mergeConfigFrom` shallow merge) returned `null`
- **OAuth path-suffixed discovery:** Added `/.well-known/oauth-authorization-server/{path}` and `/.well-known/oauth-protected-resource/{path}` routes per RFC 8414 §3.1 — MCP clients discovering metadata for a server at e.g. `/mcp/statamic` use path insertion and previously got 403

### Added
- **OAuth 2.1 CIMD support:** Client ID Metadata Document (CIMD) resolution for OAuth authorization — MCP clients can now present verified application identity (name, logo, policy URLs) on the consent screen
- CIMD resolver with SSRF protection, JSON-LD validation, and configurable caching
- Consent screen shows verified client metadata when available (name, logo, redirect URIs)
- `/.well-known/oauth-authorization-server` now advertises `client_id_metadata_document_supported`
- 10 new update validation tests covering deep nested replicator/bard/grid/group blueprints, round-trip create→update, and simulated crashing third-party fieldtypes
- 24 discovery endpoint tests covering CIMD config edge cases, path-suffixed discovery, CP route changes, revocation endpoint, and full client discovery flow simulation (protected-resource → path-suffixed AS metadata → CIMD check)
- Comprehensive CIMD test suite (unit, feature, E2E)

## [2.1.0] - 2026-04-13

### Fixed
- **Critical:** Entry updates with `terms` field type no longer crash with "Cannot access offset of type string on string" (ENG-697)
- **Critical:** Data saved via MCP now matches CP format — all routers (entries, terms, globals) call `$fields->process()->values()` after validation, running fieldtype transformations (Terms prefix stripping, Bard node normalization, Relationship wrapping)
- **Critical:** OAuth auth code and refresh token double-spend prevented — added `fflush()` before lock release in `BuiltInOAuthDriver`
- **Security:** OAuth `client_name` is now sanitized with `strip_tags()` on registration to prevent stored XSS from unauthenticated clients
- **Security:** OAuth mutation endpoints (`/mcp/oauth/token`, `/register`, `/revoke`) now enforce HTTPS in production via `EnsureSecureTransport` middleware
- **Security:** Removed inconsistent hardcoded scope fallbacks in `AuthorizeController` — config file is now the single source of truth
- Collection `taxonomies` field now persists on create and update (previously silently ignored)
- Taxonomy `preview_targets` and `default_status` now persist on create and update
- Navigation `collections` now persists on create and update
- Removed broken `$taxonomy->collections()` setter call from `configureTaxonomy()` — the association is stored on the collection side
- File handle leak in `BlueprintsRouter` when `flock()` fails after `fopen()` succeeds
- Missing `fflush()` before lock release in `FileTokenStore::updateIndex()` and `removeFromIndex()`
- Relationship fields (`terms`, `entries`, `users`, `assets`) and `checkboxes` now normalize bare strings to arrays before validation

### Changed
- **OAuth default scopes** changed from `*` (all permissions) to read-only: `content:read`, `blueprints:read`, `structures:read`, `entries:read`, `terms:read`, `globals:read`, `assets:read`, `system:read`, `content-facade:read`. Override via `STATAMIC_MCP_OAUTH_DEFAULT_SCOPES` env var.
- `sanitizeStoredFieldDataForValidation()` marked as `@deprecated` — exists only for backward compatibility with content saved by MCP prior to v2.1 without the `process()` step. Safe to remove once all MCP-created content has been re-saved.

### Added
- `SanitizesFieldData` trait for pre-validation input normalization across all content routers
- 8 new integration tests for terms field updates (ENG-697 reproduction)
- 6 new structure router tests for taxonomy and navigation field persistence

## [2.0.4] - 2026-04-10

### Fixed
- **Critical:** Entry creation no longer crashes with "Cannot access offset of type string on string" when data contains complex nested fields (Bard, Replicator)
- Date fields now accept any common format (Y-m-d, Y-m-d H:i, ISO 8601, `{date, time}` objects) — values are normalized to the Zulu format Statamic expects before validation
- `date` and `published` in entry data are now correctly extracted as first-class entry properties instead of failing blueprint validation on dated collections

### Added
- `NormalizesDateFields` trait for consistent date handling across all routers (Entries, Terms, Globals)
- 13 new integration tests covering date normalization, published extraction, and error handling

## [2.0.3] - 2026-04-09

### Fixed
- **Critical:** Blueprint update action no longer destroys existing fields — fields are now merged by default instead of replaced
- Blueprint update preserves tab and section organization in multi-tab blueprints

### Added
- `replace_fields` parameter on blueprint update for explicit full-replacement when needed

## [2.0.2] - 2026-03-19

### Fixed
- Install command no longer crashes on sites without a database — migrations are now skipped automatically when file-based storage drivers are configured (the default)
- Config publish prompt: confirming "Overwrite? yes" now actually overwrites the file (previously `--force` stayed false, so `vendor:publish` silently skipped it)
- Migration failures are caught with actionable guidance instead of crashing the installer
- Completion message now reflects what actually happened during install

### Added
- `--skip-migrations` flag on `mcp:statamic:install` as an explicit escape hatch

## [2.0.1] - 2026-03-18

### Fixed
- Token expiry date validation no longer blocks submission — `max_token_lifetime_days` is now a default suggestion, not a hard server-side rejection
- Token form error feedback uses Statamic toast notifications and native `ui-error-message` components with red border highlighting

### Added
- Scope presets (Read Only, Content Editor, Full Access) in token create/edit form, matching documented common combinations
- Preset-aware badge display in admin token table — shows preset name instead of listing individual scopes
- Admin token form now uses Statamic-style grouped permission cards with per-group "Check All"

### Removed
- Internal development plans and specs (`docs/superpowers/`) accidentally included in v2.0.0

## [2.0.0] - 2026-03-18

### Breaking Changes

#### Statamic v5 Support Removed
- **Statamic v6.6+ only** — minimum requirement is now `statamic/cms:^6.6`
- **Laravel 12+ only** — supports `laravel/framework:^12.0` and `^13.0` (via Statamic v6)
- Removed `StatamicVersion` dual-version detection helper
- Removed v5 compatibility shims and feature flags

#### Laravel MCP Upgraded to v0.6
- **laravel/mcp** upgraded from `^0.2` to `^0.6`
- Tools now use `#[Name]` and `#[Description]` attributes instead of methods
- `BaseStatamicTool` now wraps `handle()` — tools implement `executeInternal()`
- Response format changed to `Response::structured()` / `Response::error()`
- `ToolResult` and `ToolInputSchema` classes removed

#### Architecture: Single-Purpose Tools → Router Pattern
- **11 MCP tools** replace 140+ individual tools
- Each router handles multiple actions via `action` parameter
- Tool names changed from dot notation (`statamic.blueprints.list`) to hyphenated routers (`statamic-blueprints` with `action: list`)
- `type` parameter renamed to `resource_type` (avoids JSON Schema keyword collision)

#### Config Restructured
- Per-tool config (`tools.statamic.content.web_enabled`) replaced with simple toggles (`tools.entries.enabled`)
- Per-tool rate limiting removed — single global `rate_limit.max_attempts`
- New sections: `stores`, `storage`, `oauth`, `security`, `dashboard`
- Re-publish required: `php artisan vendor:publish --tag=statamic-mcp-config --force`

### Added

#### Storage Driver Abstraction
- `TokenStore` and `AuditStore` contracts with config-based class binding
- File drivers (default): YAML tokens with hash index, JSONL audit with SplFileObject
- Database drivers: Eloquent with atomic operations
- `mcp:migrate-store` command for file↔database migration
- `mcp:prune-audit` and `mcp:prune-tokens` commands

#### OAuth 2.1 Authorization Server
- Discovery endpoints (`.well-known/oauth-protected-resource`, `.well-known/oauth-authorization-server`)
- Dynamic Client Registration (RFC 7591) with per-IP quotas
- Authorization Code + PKCE S256 with Blade consent screen
- Refresh token rotation (30-day TTL)
- Token revocation endpoint (RFC 7009)
- `OAuthDriver` interface with built-in file driver and database driver
- Live-tested with ChatGPT and Claude Desktop

#### Scoped API Token System
- 21 granular permission scopes via `TokenScope` backed string enum
- SHA-256 hashed token storage
- Custom `McpTokenGuard` registered as `mcp` auth guard
- `AuthenticateForMcp` middleware with Bearer + Basic Auth fallback
- `RequireMcpPermission` middleware for scope validation

#### CP Dashboard (Vue 3 + KITT UI)
- User page (`/cp/mcp`): Connect + My Tokens
- Admin page (`/cp/mcp/admin`): All Tokens, Activity log, System info
- Stateful tab URLs (`?tab=activity`)
- Connect panel with client config snippets for Claude/Cursor/ChatGPT/Windsurf

#### Domain Routers
- `statamic-blueprints` — list, get, create, update, delete, scan, generate, types, validate
- `statamic-entries` — list, get, create, update, delete, publish, unpublish
- `statamic-terms` — list, get, create, update, delete
- `statamic-globals` — list, get, update
- `statamic-structures` — list, get, create, update, delete, configure (collections, taxonomies, navigations, sites, global sets)
- `statamic-assets` — list, get, create, update, delete, move, copy, upload (containers + assets)
- `statamic-users` — list, get, search, create, update, delete, activate, deactivate, assign_role, remove_role
- `statamic-system` — info, health, cache_status, cache_clear, cache_warm, config_get, config_set
- `statamic-content-facade` — content_audit, cross_reference

#### Agent Education Tools
- `statamic-system-discover` — intent-based tool and action discovery
- `statamic-system-schema` — tool schema inspection

#### Security Hardening
- Centralized web context security guard in `BaseRouter`
- Path traversal protection on all file-based stores
- Recursive null-byte validation on tool arguments
- Atomic rate limiting per-IP and per-token
- OAuth scope injection prevention
- PKCE S256 strict enforcement with timing-safe comparison
- Constant-time Basic Auth to prevent user enumeration
- PII redaction in audit logs
- `config_set` restricted to CLI-only
- CORS wildcard rejected in production
- Directory permissions 0700 for token/OAuth storage
- Correlation ID validation (alphanumeric, max 128 chars)
- HTTPS error messages don't leak env variable names
- Per-IP OAuth client registration quota (default 5)

#### Audit Logging
- Single entry per tool call with user/token/IP context
- Mutation tracking (resource type, id, changed fields)
- Pluggable storage backends (file JSONL or database)
- Admin dashboard with filters and detail panel

#### Events
- `McpTokenSaved` and `McpTokenDeleted` for Statamic Git automation

#### Documentation
- Complete docs site: introduction, getting-started, authentication, configuration, tools
- UPGRADE.md migration guide from v1.x

### Changed
- `web.enabled` now defaults to `true` (was `false`)
- `symfony/yaml` constraint widened to `^7.0 || ^8.0`
- All error responses use standardized envelope format
- `parseBytes()` replaced with PHP 8.3 `ini_parse_quantity()`
- `DatabaseTokenStore` uses `fill()` instead of `forceFill()`

### Removed
- Statamic v5 compatibility layer and `StatamicVersion` helper
- 140+ individual single-purpose tool classes (replaced by 11 routers)
- Dot-notation tool names
- `getToolName()` / `getToolDescription()` method pattern
- `ToolResult` and `ToolInputSchema` imports
- `AuditService` (consolidated into `ToolLogger`)
- `McpRateLimiter` (dead code)
- `statamic-content` router (split into entries, terms, globals)
- `HandlesContainers` trait (dead code — shadowed by AssetsRouter)
- `decay_minutes` config key (was unused)
- `web.middleware` config key (never existed)
- Deprecated `ToolLogger` no-op methods
- Custom OAuth login page

---

## [1.4.0] - 2025-01-19

### Added

#### Statamic v6 Dual Version Support
- Full compatibility with both Statamic v5.65+ and v6.0+
- Automatic version detection — no code changes needed when upgrading
- Zero breaking changes — all tools work identically across versions
- Asset permission compatibility for both v5 and v6 models

#### Version Detection System
- `StatamicVersion` helper class for runtime version detection
- Methods: `isV6OrLater()`, `supportsV6OptIns()`, `hasV6AssetPermissions()`

#### Testing Infrastructure
- GitHub Actions test matrix for PHP 8.3 × Statamic 5.65/6.0
- Automated dual-version validation in CI/CD

### Changed
- **PHP**: Minimum version raised to `^8.3`
- **Statamic CMS**: Updated to `^5.65|^6.0`
- **Laravel**: Support for `^11.0|^12.0`
- **Pest**: Updated to `^4.1`
- PHPStan Level 8 compliance across all files
- Composer minimum-stability changed from `dev` to `stable`

---

## [1.3.0] - 2025-01-15

### Added
- Blueprint type analysis and generation tools
- Comprehensive global management (sets and values)
- Advanced template performance analysis
- Navigation structure management
- Enhanced cache management with selective clearing

---

## [1.2.0] - 2025-01-10

### Added
- Entry management tools (CRUD operations)
- Term management tools (taxonomy terms)
- Template validation and linting
- Development workflow tools

---

## [1.1.0] - 2025-01-05

### Added
- Collection and taxonomy management
- Blueprint scanning and validation
- System information and cache tools

---

## [1.0.0] - 2025-01-01

### Added
- Initial release
- Core MCP server functionality
- Basic blueprint and content management
- Laravel MCP v0.2.0 integration
- Comprehensive test suite

[2.9.1]: https://github.com/cboxdk/statamic-mcp/compare/v2.9.0...v2.9.1
[2.9.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.8.0...v2.9.0
[2.8.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.7.0...v2.8.0
[2.7.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.6.1...v2.7.0
[2.6.1]: https://github.com/cboxdk/statamic-mcp/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.5.0...v2.6.0
[2.5.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/cboxdk/statamic-mcp/compare/v2.3.0...v2.4.0
[2.0.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.4.0...v2.0.0
[1.4.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/cboxdk/statamic-mcp/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/cboxdk/statamic-mcp/releases/tag/v1.0.0
