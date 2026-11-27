---
title: "V4 implementation status"
description: "Verified work and remaining release gates"
---

# V4 implementation status

Branch: `sd/release/v4`. Worktree: `security-review-hardening/statamic-mcp`.
Status: implementation in progress, not release-ready. No public release or tag.
Draft PR: https://github.com/cboxdk/statamic-mcp/pull/88.

## Phase 3.4–3.6 — SEO Pro, search and cache

SEO Pro uses the integration API for native defaults, redirects, errors, reports,
sitemap and field data. Redirect creation also checks delete authority because
the native save removes matching errors. Site-wide reports and sitemap require a
super user, dedicated grant and unrestricted content/SEO policies. Report reads
never regenerate; generation uses native chunks and retention.

Search checks each candidate's scope, resource policy, native record permission
and site before paging. Domains with denied fields are omitted to prevent a hit
from leaking protected values. Reads support the native local Comb index only;
hosted/custom drivers are refused instead of returning an incomplete first page.
Reindex uses the configured driver and requires approval over HTTP.

Static cache modes are `full`, native-only `statamic`, and clear-then-queue
`refresh`. Warming preserves worker connection/queue through pagination and never
follows redirects. URL batches are validated before invalidation against configured
site origins, not the request Host. Glide is included in explicit cache clearing.
Avoiding a duplicate `rules: all` flush checks actual files as well as metadata.

Independent reviews found redirect site binding, hit metadata loss, swallowed
native persistence vetoes, inherited-default copying, malformed opening hours,
report generation edge cases, asset search permissions, incomplete hosted search,
lost warmer queue configuration, Host poisoning and stale file-cache detection.
These are fixed and regression-covered. Follow-up review found the configured
redirect response code, encoded pagination names and application-subdirectory URL
joining; those final corrections passed local regressions, not another review.

Final optional suite: **2246 passed, 6 database-dependent skips, 12627 assertions**
(539.25 seconds). Combined focused gate: **104 passed, 1802 assertions** (54.36
seconds). With Forms Pro and SEO Pro uninstalled, the integration/protocol/forms/
search/cache gate passed **104 tests, 1757 assertions**, with 17 optional-addon
tests skipped. PHPStan 9, Pint, Composer audit, all 184 core-lock dependency
licenses and CycloneDX 1.5 generation passed. The committed SBOM remains the
release snapshot, as required by the library CI policy.

The full run exposed shared operation storage and SEO Pro's process-global 404
callback leaking between test applications; the base fixture now isolates both.
Native inherited-default validation still prints an upstream Antlers
`str_replace(null)` deprecation; it is not suppressed. Remote CI is pending.

The first remote SEO job exposed a test-only migration path under Testbench's
application `vendor`, masked locally by a symlink. The fixture now locates the
installed package through its provider class, like the Eloquent fixture. With
the symlink temporarily absent, the exact CI command went from **2 failed / 29
passed** to **31 passed, 350 assertions**. Production code is unchanged by this
follow-up; Code Quality, Forms Pro, Eloquent, Cbox channels and browser checks
passed on the initial phase checkpoint.

On the disposable 1,512-page site, seven warm updates measured a median **47.65 ms
before / 44.52 ms after** the cache changes. This is no observed regression, not a
statistically established speedup; the complete release budgets remain phase 5.

## Phase 3.3 — Forms Pro

Optional package-owned read/write tools cover Automagic settings and native page
logic. Address fields use the integration format/sanitizer API. Connector settings
stay inaccessible; rule conditions on protected fields cannot be read or probed.
Native permissions apply to the token's actor, including stdio. A dedicated CI job
installs Forms Pro 1.0.1; it is not a runtime dependency or bundled commercial code.

Independent review found that an unanswered form could not be evaluated. The new
regression failed before the fix; omitted or empty data now uses native empty-input
evaluation. The focused Forms Pro/core Forms run passed **21 tests, 163 assertions**.
The later combined gate above includes this correction. Phase 3 is prepared as a
checkpoint on `sd/release/v4`; remote CI remains pending.

## Phase 3.2 — Forms 2

Native form definitions and site-bound submissions now have separate scopes.
Definitions support fields, fieldsets, connector configuration, honeypot and storage;
submission actions support paging, filters, export, summary, spam and deletion.
Connector configuration stays write-only, including previews, errors and audit.
Statamic 6.44 and Laravel MCP 1.3 are the new minimums; CI pins the new minimum too.

Independent review caught create permissions, replay redaction, incomplete submission
versions, random legacy connector ids and nested protected fields. Follow-up review
caught native nested handles and flattened imports. Regression coverage now includes
each; the final review found no remaining blockers. The Eloquent test reads actual
stored form fields and settings, and verifies submission reads and deletion.

Local gate: **2197 passed, 24 environment-dependent skips, 12079 assertions**
(547.05 seconds). PHPStan 9, Pint, Composer audit and 184 dependency licenses passed.
This completes local 3.2 verification, not the whole phase or its remote CI gate.

## Phase 3.1 — integration foundation

`StatamicMcp::integration()` registers package-owned router actions, scopes,
approval defaults, fieldtypes and observers. Router actions share the existing
approval, idempotency and lifecycle pipeline. Consent binds the action contract;
HTTP and stdio run as the current grant's actor and recheck authorization at
persistence. Deferred effects cover static URLs, search indexes, Stache and Glide.

Independent review found a reserved-domain bypass, cross-container fieldtype
callbacks, replay output-schema drift and inconsistent operation/event capability
identities. Regression controls failed without each fix; follow-up review passed.
Final local gate: **2161 passed, 33 environment-dependent skips, 11653 assertions**
(467.87 seconds), PHPStan 9 and Pint passed. Composer audit had no advisories and
all 183 locked dependency licenses passed. Real cache/search/filesystem assertions
cover deferred effects after both a successful write and a throw-after-save.

The later phase-3 sections above supersede this foundation-only checkpoint.
No phase-3 merge, tag or release.

## Polish plan checkpoint — 2026-11-26

The approved five-phase polish plan supersedes the historical V00–V10 progress
notes below. Phase 1 and 2.1–2.4 are pushed; phase 2.4 is `52f338d`, with all
19 CI checks green. Phase 2.4 removes `configure` and `config_set`, moves collection
tree edits to `move`, and preserves configuration updates (including enabling and
disabling collection ordering). Navigation/global-set updates use the router's
Statamic permissions without invented inner permission checks.

Phase 2.4 verification: full suite **2063 passed, 33 skipped, 10494 assertions**;
the subsequent ordering regression failed before the fix, then the focused tree
and write-contract suite passed **34 tests, 134 assertions**. Pint and PHPStan 9
passed. The skips require optional integrations or database environments.

Phase 2.5 implements the closed error-code enum and one MCP error object, with
permission-filtered near-miss handles and production-safe exceptions. Independent
review found legacy replay diagnostics, parent-policy misclassification, missing
localization misclassification and lost reconciliation instructions; all four are
fixed. Full suite: **2085 passed, 33 skipped, 10741 assertions**. Pint and PHPStan 9
passed. The local, untracked lockfile had seven Symfony advisories in four packages;
updating those packages and their http-foundation dependency to 8.1.12 cleared the
audit. The full suite passed again with the same counts on those patched versions,
including the optional Cbox clients. No published dependency constraint changed.

Phase 2.5 is pushed as `b804f0e`, with all 19 CI checks green.

Phase 2.6 implements cursor pages, saved-record snapshots and versions, framework
versions only in `server/discover`, and per-tool result schemas. Independent review
found missing denied-field filtering on asset copies and collection write snapshots;
both were reproduced by failing tests and fixed. Follow-up review found keyed role
lists, fully filtered empty records, and inconsistent tree versions under field
policies; protocol and tree regression tests now cover these. The full-record mock
failure in `McpParentRevisionTest` was removed with that redundant private-method
test: `CollectionTreeRegressionTest` already checks both behaviors against real
files through `execute()`, preserving the published file, draft and tree.

Full phase 2.6 regression: **2112 passed, 33 skipped, 11160 assertions** (378.60 s).
PHPStan 9, Pint, Composer audit and the license check passed. Pushed as `94c2d40`;
all 19 remote CI checks passed.

Phase 2.7 implements explicit path/value projections on get, size-bounded list
pages with continuation cursors, and the blueprint read tool's result-size hint.
A 1,001-record pagination probe and a 150 KB page-builder read passed. Saved writes
and their replays remain successful when their receipts need shortening. Review
found cursor bindings diverging after denied filters were removed, and late timing
metadata overflowing a full page; both were reproduced and fixed.
Final regression: **2119 passed, 33 skipped, 11274 assertions** (412.47 s).
PHPStan 9 and Pint passed. Phase 2.7 is pushed as `148a404`; all 19 CI checks passed.

Phase 2.8–2.9 implements four task prompts with authorized context and
paginated completions, and the combined `statamic-discover` with scope/profile-filtered
actions and schemas. Review found translation publication, source/destination version
confusion, blueprint mismatch, unset/inherited page builders, combined response size,
first-page-only completions and blueprint default filtering. Targeted regressions
cover the fixes: **132 passed, 908 assertions**.

Phase 2.10 binds the OAuth resource audience through authorization, code exchange,
refresh and access tokens, includes the issuer in callbacks, and challenges with
the full required scope set before executing a call or an `execute_tools` batch.
Challenges cover prompts and blueprint resources too; raw JSON parsing matches the
MCP transport, including a non-JSON Content-Type. DCR accepts web/native metadata
without weakening redirect validation. Consent preserves the resource in its form
and server-side binding. Personal tokens are unaffected; audience-less OAuth grants
must authorize again. Real-client step-up remains a phase 5 gate.

Phase 2.11 migrates exact project-local Claude Code and Cursor CLI rules, dry-run
by default. Old allows become read-only; ask/deny rules cover both facets. Merged
discovery receives no automatic allow. Changes get exclusive private backups and
atomic replacement; global settings, wildcards and renamed servers remain manual.
The installer asks separately before changing permissions. Review's partial-failure
reporting bug was reproduced and fixed: a token-journal failure reports client files
already migrated instead of saying nothing changed. Tests cover all three files,
accept/decline, malformed JSON, symlinks, backup refusal, repeat runs and rollback.

The combined run exposed the scope picker's missing entry grants for collection
tree callers. The picker now offers those grants through the structures path even
when only the entries tool is disabled, while retaining both resource-policy gates.
This affects what can be requested, not per-call authorization. Both reported cases
failed before their fixes; scope-picker and CP tests pass **32 tests, 196 assertions**.
The action/permission mirror now checks collection get with and without include_tree
and collection move with its actual arguments.

Final 2.8–2.11 local regression: **2144 passed, 33 skipped, 11521 assertions**
(447.94 s). PHPStan 9, Pint, Composer audit, all 183 dependency licenses and
CycloneDX 1.5 generation passed. As required by the library CI policy, the committed
SBOM remains the release snapshot; the untracked lockfile is not published.
The 33 local skips need optional integrations or database environments; the draft
PR records the remote matrix results. Real-client compatibility, PostgreSQL,
Forms Pro and release performance budgets remain phase 5 gates.
No merge, release or deployment.

## Native UI-kit checkpoint — 2026-11-26

Item 4.9 was brought forward after phase 2. Token tables/forms, client setup,
admin activity/system panels and approvals use Statamic's native components.
Admin reuses TokenList; deletion and regeneration use confirmation stacks.
Loading, empty, failed and uncertain-outcome states are explicit. Late client
configuration responses cannot replace the newly selected client, and audit
filter changes cancel the previous request. Untrusted error text is interpolated;
Field validation errors are escaped because the native ErrorMessage renders HTML.

Forms Pro and core Forms 2.0 were inspected for UI patterns only: native panels,
copyable fields, numbered setup instructions and the official Vite/Tailwind build.
No commercial source was copied and no Forms Pro dependency was added. The
stylesheet imports Statamic's addon Tailwind entry and scans this addon's Vue files;
relying only on the host CSS silently omitted utilities used only by the addon.

Verification: **2144 PHP tests passed, 33 optional/environment skips, 11521
assertions** (470.07 s), PHPStan 9 and Pint passed. **16 Playwright tests passed**
on the disposable Statamic site, including real token create/edit/regenerate/delete,
old-secret rejection, literal validation errors, owner/OAuth action restrictions,
configuration races, audit retry/filtering, cancelled elevation, transport failures
and stale approvals. Repeated local runs hit DCR's registration rate and per-IP
client limits. Resetting the disposable site's cache and removing only its
Playwright client fixtures restored the run; neither limit was weakened.
Composer audit and 183 dependency licenses
passed. Vite emits 42.34 KB JavaScript and 5.30 KB CSS (13.08/1.63 KB gzip).

Desktop and 390px dark layouts were rendered and inspected, including pending,
empty, loading and error states. The form's expiration field and footer remain
reachable by scrolling. Chromium narrow layout is not a real-device test.
A real-pointer recording covers row menus, cancelled deletion and preset selection.
The independent review and its follow-up reported no concrete regressions.

This completes the native-component migration, not all phase 4 UI work: scope
levels (4.6), OAuth consent design (4.7), personal activity (4.8), translations
(4.10) and the expanded client guides (4.5) remain separate plan items. Phase 3
is next. No merge, tag or release is part of this checkpoint.

## V00 compatibility inventory

| Change | Compatibility | Target |
|---|---|---|
| Origin validation, HTTPS fallback, bounded downloads | Compatible security correction | v4; eligible for reviewed v3 backport |
| Entry/site/author publication checks, revision restore policy | Compatible correction; unauthorized callers lose access | v4; eligible for reviewed v3 backport |
| User serialization allowlist and actual roles/groups permissions | Security correction; undocumented secret output removed | v4; eligible for reviewed v3 backport |
| Refresh replay, fixed grant expiry, mutation lock, orphan revocation | Compatible for shipped drivers | v4; custom-driver capability required in v4 |
| Globals object/site policy; save/delete veto; cache failures | Compatible correctness/security fixes | v4; eligible for reviewed v3 backport |
| Basic Auth removal | Breaking; no legacy enable flag | v4 only |
| HTTP default disabled | Breaking installation default; published explicit enable remains explicit | v4 only |
| Mandatory OAuth user-grant revocation method | Breaking custom driver contract | v4 only |
| Action/scope registry, execution and durable operations | Registered reads exposed; writes remain internal | v4 only |
| Explicit HTTP/stdio profiles | Breaking; missing profiles disable execution, installer requires selection | v4 only |
| Forced collection/taxonomy cascades | Breaking; content must be deleted explicitly before the parent | v4 only |
| Immutable addon consent in CP/OAuth and storage | Implemented foundation; built-in granular scopes and approvals remain | v4 only |

Backports are candidates, not completed branches. Do not cherry-pick the combined
v4 tree into v3: Basic Auth removal and the mandatory driver method require separation.

## Verification recorded so far

- Statamic write contracts, cache behavior, revisions and globals: 55 tests, 157 assertions.
- Full regression: **1604 passed, 18 skipped, 7289 assertions** (688.71 seconds). The skipped SEO Pro cases require the optional package. This run precedes the final SQL migration/CAS and user-write veto follow-ups.
- Follow-up cache and operation migration: 14 tests passed, 57 assertions.
- SQL migration, collation and cross-process claim/crash checks: 8 tests passed, 42 assertions.
- Final user/write/cache contracts: 27 tests passed, 64 assertions; user privilege boundaries: 5 tests passed, 12 assertions.
- PHPStan level 9 and Pint passed; no new suppressions or baseline entries.
- Follow-up contracts/auth/operations/revisions: 80 tests passed, 210 assertions.
- File operations tested with two real subprocesses; one claim wins and a crashed claim remains reserved. SQL compare-and-set tested against SQLite, including two independent processes synchronized before claiming and a crash after the winning claim. PostgreSQL/MySQL concurrency remains unverified.
- Opis JSON Schema 2.6.1 installed; Composer reported no dependency advisories.

These records do not certify V02–V10. The main plan remains the release checklist.

## Remaining implementation (not release gates alone)

- V02: addon scopes and immutable snapshots now reach CP/OAuth issuance, refresh and
  both token stores. Built-in granular scopes are in (decision mem_WfwnG95o9xs0Kx0RBHEHix,
  option a): `{entries,terms,assets}:write` keep edit; publish and delete need
  `entries:publish`, `entries:delete`, `terms:delete`, `assets:delete`. Admin domains
  use `{domain}:admin`; their v3 `:write` scopes grant nothing. `mcp:statamic:install`
  guides the upgrade (profile, migrations, token review). `*` is expanded to an explicit list at issuance
  and a stored v3 `*` (token or refresh grant) is refused until approved again;
  `mcp:doctor` counts the affected tokens. Automated migration (V09) remains.
  Legacy core-only tokens do not authorize registered actions.
- V03: profiles now restrict the existing tools/resources/prompts and installer.
  The registered-action authorizer now reloads credentials, scopes, consent and site
  access. Built-in tools and resources now share one decision (`McpAuthorizer`:
  token scope, Statamic permission, resource policy) with a positive/negative matrix
  over HTTP/stdio, token, actor and policy (`McpAuthorizerMatrixTest`). Registered
  actions keep `GrantAuthorizer`. Site/locale and field policies stay in the handlers
  (where the object exists) and have their own matrix (`SiteAndFieldPolicyMatrixTest`:
  entries, terms, globals × site × HTTP/stdio, denied fields on output, input and
  list filters). It found terms crossing sites over HTTP; fixed.
  Editor currently handles unpublished entries only; live working-copy editing is refused.
- V04 (done): entries have resource versions: `version` on every entry response and
  `expected_version` on single-entry writes, checked under a per-entry lock
  (`EntryExpectedVersionTest`). Terms and globals have the same through
  `ChecksResourceVersions` (`TermAndGlobalExpectedVersionTest`). Built-in writes
  take an optional `idempotency_key` on the OperationStore (`IdempotentWrite`):
  replay, spent, reused and an honest `OUTCOME_UNKNOWN`, with process race and
  crash tests for the file and database stores (`IdempotentWriteConcurrencyTest`).
  Unknown outcomes are reconciled with the existing recovery command. Prepare-only handles, admission limits, pruning,
  capacity repair and driver bindings are implemented. Process races on admission,
  claim and idempotent writes run against the file store, SQLite and MySQL; the
  MySQL cases run in the MySQL CI job (`mcpMysqlOperationTables()` in
  `tests/Pest.php`) and skip elsewhere.
- V05 (done): approvals are operations in the OperationStore (`Approvals`):
  owner-bound, one exact call, expiring, decided by POST with CSRF in the CP
  (Tools → MCP → Approvals); approve needs Statamic's elevated session, so a
  browser agent driving the CP tab cannot approve on the session alone. The
  retried call is authorized again in full; a changed record makes the approval
  stale. Claim is atomic with the write (process races and crash on file,
  SQLite, MySQL). Stateless confirmation tokens are removed.
- V06 (done): built-in tools emit `execution.*` events through the same observers
  as registered actions (`BaseRouter::observedAction()`: started only after
  authorization and claims, denied without started, replayed for stored results),
  and approvals emit `approval.requested/approved/rejected/consumed`. Metadata only;
  observer failure after a write leaves the result intact (`BuiltInLifecycleEventsTest`).
  Registered writes keep their mandatory durable audit (`ExecutionAudit`); built-in
  writes keep the tool audit log, which is secondary and never turns a committed
  write into an error.
- V07: the optional
  telemetry adapter is implemented in statamic-telemetry PR #5 against published
  SDK 3.9.3; execution coverage still depends on the public router migration.
- V08 (done): coverage inventory done (`ActionCoverageInventoryTest`): all
  9 routers, every action × resource type, mode, scopes and Statamic permission;
  only `assets list container` and `structures list collection` carry none and
  filter per item. No action writes on a `:read` scope. The other tools
  (discover, schema) are read-only and both resources run the tool gates. Write
  classification has one source (`isWriteAction()`). Blueprint writes ignored
  Statamic's veto and tree moves reported no `write_effects`; both now go through
  `persistStatamic()` (`StatamicWriteContractTest`). The V00 list (globals site,
  save/delete false, partial update + move, cache exit code) is closed.
  Cache matrix done on rendered pages (`RenderedCacheMatrixTest`): entry title,
  slug, unpublish, publish, delete, collection route, term (dependent page),
  global and navigation (every page), plus the REST API, each warm and cold.
  Without MCP's clear only the written entry's own URL refreshes, so the full
  static clear stays. Eloquent driver 5.14 (`EloquentDriverTest`, own CI job):
  entries, versions, tree moves and concurrent tree change, terms, globals,
  navigation, collection route and blueprints, read back from the tables. Found
  and fixed: collection `route` never applied, nav trees stored empty on Eloquent,
  tree version blind on Eloquent, entry version from the Blink copy, Blink reset
  missing the named stores. Revisions (`WorkingCopyStateTest`): live entry, slug
  and working copy are right after an edit, a refused working copy, a refused
  publish, a publish that throws after saving (reported `unknown`, live changed)
  and a restore; read again as the next call would. GraphQL (entry, unpublish,
  term) and mount (mount change, renamed mount page) are in the matrix; GraphQL's
  response cache is invalidated by Statamic itself, mount needs MCP's static
  clear. On Eloquent, a mount change and a renamed mount page move the stored
  URIs (Statamic does it). Eloquent now covers every repository MCP touches:
  asset containers and assets (create, update, move, copy, delete read back from
  `asset_containers`/`assets_meta`), revisions (working copy, publish, restore in
  `revisions`) and forms (MCP only reads them: form blueprints and `system info`).
  Only sites, tokens and addon settings stay on files; MCP does not use them.
- V09 (done): `mcp:migrate-v4` (`Auth\V4TokenMigration`) plans the token changes
  as a dry run and applies them with `--apply`: v3 `*` revoked; a replaced admin
  `:write` dropped from a personal token with other live scopes, otherwise
  revoked (OAuth grants with their refresh tokens). It never adds a scope
  (asserted per token), acts only on a token unchanged since planning, journals
  scopes before/after (`0600`, no secrets) before the first change and stops with
  nothing changed when the journal cannot be written; a second run finds only
  what is left. `--rollback` revokes before a downgrade every token v3 would let
  publish or delete. Fixture: token files in v3.6.2's exact format migrate in
  place, index included. The installer shows the same plan (it used to call a
  token with a replaced scope dead although its other scopes still worked).
  `mcp:doctor` now fails debug mode on an enabled HTTP endpoint and warns when
  every proxy is trusted, and points at `mcp:migrate-v4`. UPGRADE.md has the
  v3 → v4 guide and the rollback procedure. Not covered: approvals and
  operations are not migrated (v3 has none; the v3 `confirmation_token` is
  ignored). `mcp:doctor` also fails (warns without a stdio profile) when a log
  channel the server uses (default and deprecations, stacks expanded) writes to
  stdout; the stdio server already sends PHP errors to stderr itself.
- V10 (done as a review; see "V10 release candidate review" below). The
  minimum stays Statamic 6.35.1: the lowest dependencies pass.

## Final polish, phase 1 — correctness and security (2026-11-26)

Plan: the "v4 plan for den sidste polering" artifact, items 1.1–1.11, all done.

- 1.1 Relationship fields: per-type format specs; unknown terms refused (never created); entry and user ids checked.
- 1.2 A landed write over `max_response_size` answers success with slim data and `response_truncated`.
- 1.3 Config numbers read through `Support\McpConfig` (string env values no longer 500 the admin page).
- 1.4 List filters validated (`RouterHelpers::validateListFilters()`); the Stache index leak is gone.
- 1.5 `content_validate` with no allowed taxonomy/collection validates nothing instead of failing.
- 1.6 `content:*` retired (`TokenScope::isRetired()`), dropped by the migration and on refresh; doctor counts them.
- 1.7 Token lifetime cap enforced on edit; OAuth token expiry immutable; doctor counts tokens over the cap.
- 1.8 Users writes gated by a floor; `current_password` for own email/password; secrets hidden in previews.
- 1.9 OAuth: unused clients expire, error page before consent, logo only same-host https, per-credential auth throttle.
- 1.10 Guard refuses inactive users and `*`; refresh drops lost scopes; generic CIMD errors; doctor warns without elevated sessions.
- 1.11 CP: server-side audit actor filter, Regenerate on own tokens only, capped expiry UI, error toasts.

Verified: full suite 2048 passed, 33 skipped, 10132 assertions (256 s); PHPStan level 9 and Pint clean.

## V10 release candidate review (2026-11-24)

Reviewed at the commit that adds this section. This review records results; it is
not a release decision, and nothing is tagged.

**Dependency matrix.** CI: PHP 8.3/8.4/8.5 × Laravel 12/13 on SQLite (Statamic
^6.35.1, plus exactly 6.35.1 on 8.3/L12) and on MySQL 8.0; SEO Pro 7.16.0;
Eloquent driver 5.14.0; Cbox Relay/ID clients. New job `lowest`:
`--prefer-lowest` resolves Statamic 6.35.1, laravel/mcp 1.1.2, Laravel 12.71,
Symfony 7.4, opis/json-schema 2.6.1, Pest 4.3; locally **1962 passed, 39 skipped**.
It found one defect, in the tests: Symfony Process 7.4's `waitUntil()` reads once
before handing output to its callback, so a worker already printing `ready` hung
the five multi-process race tests until their 20 s timeout. They now poll the
cumulative output (`waitForWorkerReady()` in `tests/Pest.php`).

**Client matrix.** A fresh `statamic/statamic` site (Statamic 6.44.0, Laravel
13.44, laravel/mcp 1.3.0) with the branch installed by path, driven by the
official TypeScript SDK 1.32.2 (protocol 2025-11-25) and 1.17.5 (2025-06-18),
over Streamable HTTP and stdio: connect, `tools/list`, discover, entries
list/create, delete (HTTP: `APPROVAL_REQUIRED`; stdio is not gated), `search_tools`,
`execute_tools`, resources list/read, prompts list, and a token holding only
`entries:read` refused a create (`Token missing required scope: entries:write`).
All green for both versions on both transports. **It found a defect the suite
missed:** the advertised output schema typed `data` as an object, but a failure
sends `data: null` (and list results send an array). The SDK validates failures
too and threw away every error response with `-32602 Structured content does not
match the tool's output schema`. `data` is now `object|array|null`;
`McpProtocolSurfaceTest` validates a success and a failure of every tool against
its advertised schema with opis (9 of 10 failed before the fix). 

**Claude Code 2.1.332** (2026-11-25), against the same site: bearer over HTTP and
stdio connect (`claude mcp list`). OAuth through `claude mcp login`: Claude Code
uses CIMD (`client_id` is `https://claude.ai/oauth/claude-code-client-metadata`),
PKCE and a localhost callback. **It failed first**: the authorize endpoint built the
expected `resource` without the port, so on any non-default port (`artisan serve`,
Docker on 8080) it refused the resource the metadata itself advertised with
`Invalid resource parameter`. Fixed (`getSchemeAndHttpHost()`, regression test in
`OAuthFlowTest`). Then: the consent page names the client, spells out `*` and hides
the replaced v3 scopes; granting only `entries:read`, `entries:write` and
`blueprints:read` issued exactly those. With the token Claude Code stored: entries
list, draft create and blueprints list allowed; `data.published: true`
(`Token missing required scope: entries:publish`) and users refused. Refresh keeps
the three scopes; replaying the used refresh token answers `invalid_grant ...
grant revoked`, and Claude Code then reports "Needs authentication". Not run: a
chat session (no model account in the orb), Claude Desktop and Cursor.

Seen while testing, not changed: `scopes_supported` advertises `*` and the replaced
v3 `:write` scopes, and Claude Code asks for all of them (consent hides them, so
nothing is granted); a top-level `published` on entry create is not in the schema
and is ignored, so the call succeeds as a draft.

**Performance baseline** (same site, HTTP, median of 5, `php artisan serve`, one
worker; a baseline to compare against, not a budget):

| Call | Response bytes | Median ms |
|---|---|---|
| `tools/list` | 14,751 | 28 |
| entries list (pages) | 3,552 | 39 |
| blueprints list | 786 | 28 |
| `search_tools` | 5,955 | 27 |
| `resources/read statamic://blueprints` | 233 | 25 |

**Queries and memory per call** (2026-11-25, same site with 1,500 pages in a
structured collection, PHP 8.4 with opcache under `php artisan serve`; measured by
a request middleware and a lifecycle observer in the site, warm medians):

| Call | SQL, file stores | SQL, database stores | Stache queries | Peak memory | ms |
|---|---|---|---|---|---|
| entries list | 0 | 3 | 1 | 4.4 MB | 87 |
| entries get | 0 | 3 | 3 | 5.2 MB | 55 |
| entries create | 0 | 3 | 7 | 26 MB | 1,380 |
| entries update | 0 | 3 | 7 | 17 MB | 1,500 |
| content_audit | 0 | 3 | 2 | 12.7 MB | 212 |
| blueprints/structures list, discover, `tools/list` | 0 | 2–3 | 0 | 2.6–2.8 MB | 4–20 |

With database stores every call costs 2 token queries (lookup, last used) and 1
audit insert, whatever the content size; a delete that waits for approval costs 8.
No N+1. The "Stache queries" are not SQL: Statamic 6.44 emits a `QueryExecuted` on
an in-memory `fake` connection for every Stache query, for debug bars.

Writes used to be slow because of the full Stache clear after every write: the next
read rebuilt every store from disk, about 1 ms per entry on the site. Statamic alone,
find + save on a warm Stache, takes 15 ms at 300 pages and 34 ms at 1,500; after
`statamic:stache:clear`, 364 ms and 1,539 ms.

Since then a write repairs only the indexes it leaves stale (`StacheRepair`, see
CLAUDE.md, "The Stache is repaired after a write, late"). `StacheConsistencyTest`
compares every index of every store after 33 kinds of write with a rebuild from disk;
13 of them fail without the repair. On the 1,500-page site, in-process through the
router (median of 5): update 34 ms, create 48 ms, delete 37 ms, against 1,575, 1,621 and
937 ms with `stache_after_write = clear`.

Stdio (one process, 130 mixed calls): reads are flat over 150 calls. With the full
clear, writes grew about 18 kB each. That is Statamic: `stache:clear` followed by find
+ save grows 18 kB per round without MCP, find + save without the clear grows nothing, and
neither container instances, event listeners nor static properties grow. HTTP is
unaffected (a process per request).

No exporter I/O exists in this package; telemetry lives in statamic-telemetry.

**Release gates (plan section Verifikation).**

| # | Gate | Result |
|---|---|---|
| 1 | Extension contracts, synthetic addon | Covered (ExtraTools, registered actions) |
| 2 | Tools/registry/resources/prompts share one matrix | Covered (`McpAuthorizer`, coverage inventory) |
| 3 | Grants before/after package, action, role, revoke, site | Covered; V09 migration never widens |
| 4 | Revisions on/off, working copy | Covered (`WorkingCopyStateTest`, Eloquent) |
| 5 | Real concurrent processes | Covered on file, SQLite, MySQL; PostgreSQL not run |
| 6 | Crash before/after write | Covered (`IdempotentWriteConcurrencyTest`) |
| 7 | Observer throws after write | Covered (`BuiltInLifecycleEventsTest`) |
| 8 | Private cache, revoke | Covered (cache hints `private`) |
| 9 | Wire tests and real clients | Wire tests, SDK clients and Claude Code (bearer, stdio, OAuth with CIMD, refresh, replay) yes; Claude Desktop and Cursor no |
| 10 | PHP/Laravel/Statamic matrix, file/database, SEO Pro | Covered, lowest included |
| 11 | Response size, queries, memory | Measured: constant SQL per call, peak 2.6–26 MB; writes repair the Stache instead of clearing it (34 ms against 1,575 ms at 1,500 pages) |
| 12 | PHPStan 9, Pint, audit, migration fixtures, manual security review | Automated parts pass; v3.6.2 token fixture; the manual review of final writes and grants is for a person |

Open before a release: the manual security review (12), Claude Desktop and
Cursor (9), PostgreSQL concurrency (5), and the release procedure itself.

Registered read actions now have public HTTP/stdio adapters and participate in
tool search. Registered writes remain unexposed until resource versioning and
approvals are wired. High-impact prototype actions are refused. WebMCP remains deferred as
agreed; no browser tool interface is published.

## Follow-up implementation — 2026-11-07

- Missing/unknown transport profiles fail closed. Stdio and HTTP profiles are
  independent; HTTP does not inherit the local operator profile.
- Installer requires profile selection and no longer sets `APP_ENV=local` in client
  configurations. Prompts no longer advise switching to CLI to bypass permissions.
- Runtime extra-tool registration is container-owned. A two-container test verifies
  that registrations do not cross application instances.
- Asset and structure writes register cache cleanup before native events and check
  explicit false returns. Asset updates clone candidates before changing metadata.
- Nonempty collection/taxonomy deletion is refused even with force. Parent vetoes
  preserve blueprints; successful parent deletion precedes blueprint cleanup.
- CI workflows include the release branch, and SQLite has an additional job pinned
  to the declared minimum Statamic version. These remote jobs have not been run here.
- Focused surface tests: 46 passed / 166 assertions. Asset contracts and permissions:
  61 passed / 168 assertions. Structures, trees and extra tools: 117 passed / 490
  assertions, before the final stricter cascade rejection and draft-profile adjustment.
- Final profile, asset veto and cascade contracts: 20 passed / 72 assertions.
  PHPStan level 9, Pint, Composer validation/audit and dependency licenses passed.
- The follow-up full-suite process ended without a final summary during the session
  interruption. It is not counted as a completed verification run.

The remaining V02–V10 work above remains required. These fixes do not convert the
internal action/operation prototype into the final public v4 execution pipeline.

### Completed follow-up verification

The replacement full run completed: **1621 passed, 18 skipped, 7361 assertions**,
1292.26 seconds on local PHP 8.5.7. This precedes the final asset-rename postcondition
and relocation of the resource-dependent editor guard; those receive targeted checks.
The full Pint check passed, as did PHPStan level 9 and the dependency gates.

On commit `ec39f7e`, GitHub CI passed Code Quality (including SBOM/audit), browser
E2E, SEO Pro integration, and PHP 8.3/Laravel 12/MySQL. Other matrix jobs were still
running when recorded. A new head requires its own remote checks; these results do
not certify future commits or the unimplemented V02–V10 functionality.

The late asset guard verifies file location after Statamic's native move, because
that method ignores false returns from filesystem rename. The editor's live-entry
restriction now runs after the concrete entry/site/author authorization, rather than
performing an entry lookup before access checks. The final focused run passed **22
tests / 84 assertions**, including these cases. Installer recovery commands carry
the selected profile, and CI deduplicates push/PR runs on the same branch.

### Grant and consent implementation — 2026-11-07

- Application-owned scope/capability registries freeze after provider boot. Addon
  scopes require registered native Statamic permissions (or super users when none
  are declared); reserved core scope names cannot be captured by another package.
- Immutable snapshots contain action fingerprints, scope-definition fingerprints
  and a stable grant ID. OAuth access-token rotation changes the credential ID,
  not the grant ID used for deduplication. Generic scope edits discard consent;
  rename, reordered scopes, secret rotation and storage import preserve it.
- CP and OAuth capture the displayed definitions before submission. User/session
  bindings prevent cross-user reuse. OAuth also binds client/redirect/state/PKCE,
  expires after ten minutes and atomically records nonce consumption in the cache.
  That cache must be shared across HTTP workers; this is not the durable write
  ApprovalStore. CP offers expire after an hour and allow separate explicit token
  creations with separate grant IDs. A page reload renews the displayed offer.
- The production registered-action authorizer reloads the current credential and
  checks grant ownership, expiry, exact capabilities/scopes, profile, actor status
  and native site access. Addon policies can narrow its decision. The legacy MCP
  surfaces are not yet migrated; no new production write path is exposed here.
- Verification before the final scope-definition binding: 184 OAuth/contract tests
  (713 assertions), 87 CP/token tests (369 assertions), 118 stable-grant tests
  (450 assertions), and 20 CP/consent tests (91 assertions). These overlap and are
  not a new complete-suite total. PHPStan 9, Pint and the frontend build passed;
  npm reported no vulnerabilities in the installed development dependencies.

V03–V10 remain required. This checkpoint is not a release certification.

### Complete grant-checkpoint verification

The unchanged grant/consent checkpoint passed the complete local suite on PHP
8.5.7: **1640 passed, 18 skipped, 7477 assertions**, 1560.25 seconds. Full Pint,
PHPStan level 9, the frontend build, Composer audit and the 180-package license
check passed. Regenerating the SBOM produced no diff. The 18 optional integration
skips remain visible; this is not certification of unimplemented V03–V10 work.

CI at preceding commit `ecd1f09` passed browser E2E, SEO Pro, code quality,
PHP 8.3/8.4/8.5 SQLite jobs and several MySQL jobs. The remaining matrix jobs
were still running at this checkpoint; new changes require their own CI run.

### Bounded transport input

HTTP now guards the raw stream before Laravel input transformations and SDK header
parsing. Stdio uses a package-specific registrar adapter with bounded chunks,
complete-line framing and safe handling of EOF and oversized frames. Other local
MCP handles remain registered. Configured decoded-input limits are now applied,
rather than merely documented. Tests cover CORS, fragmented frames, malformed JSON,
limits and the existing HTTP protocol flow. A standalone million-value JSON probe
was rejected in 20.4 ms with 6 MiB peak allocation; the regression fixture runs
under a 32 MiB memory limit. This is one local measurement, not a general latency SLA.

The final input-focused run passed **19 tests / 35 assertions**. The existing
17 HTTP protocol tests also passed during the initial combined run. Two assertions
in the new test harness were corrected (Inertia may precede the guard, while
JSON transformers must follow it; an unrelated test route was caught by Statamic
routing). PHPStan level 9 and Pint passed.

### Typed lifecycle observations

The internal action executor now emits typed start/completed/denied/failed/replayed
events. A fresh execution ID correlates phases of one attempt; an operation ID
may span attempts and is not a span key. Replay reports no new effects. An
application-owned registry freezes observer classes at boot and isolates both
construction and invocation failures, including failure of the fallback logger.
`FakeLifecycleObserver` supports integration contract tests.

Token issuance/revocation and both OAuth refresh-replay paths emit metadata-only
grant events. Access-token rotation preserves the grant and does not emit a new
issuance or revocation. Legacy Statamic Git notifications remain available, but
exceptions after persistence no longer turn successful token changes into errors.
Expired used refresh tokens cannot revoke a newer grant in either backend.

Verification: **124 tests / 368 assertions** across actions, observers, token service
and both OAuth driver contracts; PHPStan level 9 and Pint passed. Execution events
still describe the internal executor: migrating legacy public routers and adding
approval events, durable audit and the optional telemetry adapter remain required.

The local MCP registration now runs independently of the HTTP route cache.
OAuth default scopes include registered integration scopes. The new grant-default
case passed with the seven existing grant integration tests; the final transport
run passed 9 tests / 25 assertions, and PHPStan/Pint passed.

### Prepared handles, admission and retention

Writes in the internal action executor now require a separate authorized prepare
step. It issues a random opaque handle bound to actor, stable grant, full action
definition, transport/profile, site and canonical input. Execution never creates
a missing record. A lost/expired/pruned handle therefore cannot recreate a write.
Fresh preparation is safe before execution; a lost execution response must be
retried with its original handle, not a new prepare request.

File and SQL stores limit admission to 256 retained records by default, with a
3,000,000-byte maximum serialized record. The file backend uses one permanent
lock and fixed temporary paths, avoiding per-lookup lock artifacts or unbounded
crash candidates. It fsyncs files and directory changes. File capacity is reserved
before persistence, so interrupted writes can overcount but cannot oversubscribe.
Database admission uses an atomic counter reservation in the insert transaction.

Expired records can be pruned, but executing/unknown outcomes remain reserved.
Capacity repair recounts occupied slots without changing operation outcomes and
keeps orphan recovery candidates. The commands are `mcp:prune-operations` and
`mcp:repair-operation-capacity`. Multi-node configuration rejects the file backend.
Provider bindings and conditional SQL migration loading are now present.

Verification before the final orphan-initialization refactor: **35 tests / 281
assertions**, including real simultaneous process admission/claims on local POSIX
files and SQLite, crash retention, no re-execution after pruning, and CLI wiring.
PHPStan level 9 and Pint passed. Public router migration, resource versions, durable
approvals and full outcome reconciliation are still required.

### Complete prepared-operation checkpoint verification

Commit `8abcef1` passed the full local suite on PHP 8.5.7: **1673 passed,
18 skipped, 7712 assertions**, 2086.34 seconds. The optional SEO skips remain
visible. This run precedes the subsequent native actor-context and addon boot
registration corrections; those need their own verification.

### Native execution context and addon registration

The action executor now uses a private non-session guard and restores the prior
Auth default, user resolver and request resolver in `finally`. Native Statamic
events observe the execution actor. Nested calls, anonymous callers, failures and
new scoped worker lifecycles are covered, without login/logout events or changes
to CP session guards. Missing/inactive actors cannot inherit an ambient super user.

Registries freeze after Statamic's deferred addon boot callbacks, rather than
before `bootAddon`. This fixes a real integration ordering failure found by the
telemetry contract test. Registration after that phase still fails closed.

Input schema validation now respects configured transport input budgets, including
10 MiB base64 asset uploads and nesting beyond the prototype's hidden depth 32.
The separate result budget stays at 1 MiB. Object serializers are refused before
output validation can execute them.

Focused verification: **22 action/grant/observer tests / 104 assertions**, then
**12 boundary tests / 44 assertions**, with overlap. PHPStan 9 and Pint passed.
The full 1673-test run above predates this follow-up.

### Optional telemetry reference integration

[statamic-telemetry PR #5](https://github.com/cboxdk/statamic-telemetry/pull/5)
implements opt-in execution/grant observations using published laravel-telemetry
3.9.3. HTTP spans keep their parent, stdio traces are isolated, metadata and metric
labels are projected explicitly, and native content events remain separately
configured. Real exporter/redactor failures do not change content-write/replay
outcomes. It does not expose telemetry read tools or approve operations.

Its full suite passed **188 tests / 898 assertions**. Physically removing MCP
and rerunning passed **180 tests / 859 assertions**, with the 8 MCP tests skipped.
Project PHPStan, adapter level 9, Pint, audit, licenses and deterministic SBOM
passed. Remote matrix checks and the complete public MCP migration remain pending;
neither package has been tagged or released.


### Public registered reads and consistent replay encoding

Registered reads now use the shared executor through HTTP, stdio and ToolSearch.
Catalog visibility checks all configured sites; an actor permitted only on a
non-default site is not hidden. Invocation still checks the requested site, current
credential, immutable grant and native permissions. Wire arguments cannot select an
actor/grant. Stdio requires `STATAMIC_MCP_STDIO_TOKEN` with explicit capability consent.
Builtin router migration and all registered write adapters remain outstanding.

The real protocol tests passed **27 tests / 218 assertions** including the existing
HTTP/server tests; the final registered-adapter cases passed **6 / 49**. Result storage
now uses the same nesting depth and unescaped Unicode encoding as schema validation,
so a valid deep or Unicode-heavy result is not rejected after its write has landed.
The combined action/operation suite passed **57 tests / 395 assertions**. PHPStan
level 9 passed. These are focused checks, not a replacement full-suite result.


### File-token mutation race

File-token reads and complete mutations now share one permanent store lock.
Previously, a last-used update could read the record before deletion and write it
back after revocation. The lock covers the existence check, read, modification,
replacement and index update. Token replacement uses a private temporary file and
atomic rename; there is no unlocked-write fallback. Failed unlink operations are
reported rather than acknowledged as successful revocation. All workers must run
the new code; mixed old/new workers do not share this locking protocol.

Separate-process tests cover usage, rename and delete contention; an open reader
also proves that replacement preserves a complete old snapshot. Token contracts,
file-store stress and dataset scaling passed **50 tests / 811 assertions**. The
counting filesystem test wrapper now forwards actual locks and chmod. PHPStan 9
passed. This is single-node POSIX behavior, not a distributed filesystem guarantee.


### Durable recovery identity

Prepared operations now include encrypted, bounded metadata identifying actor,
stable grant, exact action/version/fingerprint, effect, site, transport/profile,
preparation time and a digest of canonical input. Neither the credential nor raw
input is retained. A 10 MiB upload therefore does not enlarge the recovery record.
The ciphertext is immutable through store transitions and bound to the operation
ID and request binding; copying it between handles is rejected. Missing/damaged
metadata fails before claim or handler invocation. Legacy prototype handles without
this metadata cannot be executed by this version and require outcome investigation.

This identifies a retained unknown outcome; it is not yet a resource-versioned
preview, approval record, complete durable audit or an outcome-reconciliation UI.
The operation/retention/contract run passed **36 tests / 301 assertions**, including
both backends, encryption failure before reservation, corrupted/missing intent,
cross-handle ciphertext reuse and retention after a throwing handler. PHPStan 9 and
Pint passed. The initial test run exposed two test-harness mistakes (arrow-function
value capture and Pest's exception-class assertion); both were corrected before
the successful run.


The local administrator command `mcp:inspect-operation HANDLE` returns retained
state and recovery identity as JSON without raw input/results or credential IDs.
It never changes state, retries a handler or treats a missing record as proof of no
effect. The intent/inspection suite passed **10 tests / 73 assertions**. This does
not yet provide manual outcome resolution or substitute for durable audit.


### Durable audit admission and bounded history reads

Registered writes now require `DurableAuditStore::writeDurable()` before invoking
the handler. This requirement does not follow telemetry sampling or the legacy
`audit_logging` flag. Admission runs after the operation claim, while the coordinator
still knows no handler effect has begun. Failure, revocation or expiry during this
phase leaves a terminal `failed_no_effect` operation. Read actions do not require
write admission. Unsupported custom audit drivers fail closed for registered writes.

File audit append, rotation, queries and purge use one permanent process lock.
Entries and directory changes are fsynced, file permissions are private, and purge
checks write/flush/rename results. An interrupted JSON tail is preserved and separated
from the next complete entry. Pagination performs two streaming passes and retains
only the requested page; 12,000 approximately 2 KiB records are queried in a separate
32 MiB PHP process. Queries retain the existing active-file/50 MiB window semantics.
The file backend remains single-node; old and new workers must not run concurrently.

SQL durable admission rejects a caller transaction rather than promising persistence
that could later roll back; use a dedicated durable driver when the host wraps work
in a transaction. Native model vetoes are reported as failures. The operation record
is authoritative for outcome: secondary terminal-audit failure cannot undo a successful
write or reopen its handle. Known committed effects stay explicit when final output
filtering fails, and replay still applies current field visibility. Legacy tool
outcome logging is isolated and adds `meta.audit_status=unavailable` on failure;
this does not yet migrate legacy tools to mandatory pre-write admission.

Verification: action/operation/audit contracts **86 / 541** before the final admission
cases; focused durable admission, expiry, SQL veto, storage and stress **40 / 183**;
final output-filter and legacy outcome tests **9 / 51**. These runs overlap.
Native SQL admission is also tested outside transactions and against a listener
that opens an uncommitted transaction. PHPStan level 9 and Pint passed without
suppressions. A complete regression run for this head remains
required. Resource-versioned approvals and builtin migration are still outstanding.

### Complete durable-audit checkpoint and isolated permission fixtures

Unchanged commit `3e007b7` passed the full local suite: **1720 passed, 18 optional
SEO skips, 7987 assertions**, 2753.16 seconds. Remote Laravel 12 SQLite jobs passed
on PHP 8.3/8.4/8.5 and the pinned Statamic 6.35.1 job passed. MySQL exposed one
fixture error: migrations ran after RefreshDatabase started a transaction, so DDL
removed a nested savepoint. Audit/token migrations now use Testbench's pre-transaction
hook. The new focused MySQL 8.0.46 run passed **48 tests / 281 assertions**.

The local test resource file had retained 1409 roles from previous runs. Native
Stache role-index construction dominated the sampled CPU time. Roles and groups
now have private per-application files, cleaned after each test. An uninstrumented
A/B run of the same four actor tests (33 assertions) fell from **13.17 seconds to
0.21 seconds**. No production repository or vendor code was modified. The old vendor
fixture was preserved; new tests no longer use it. This is test isolation and a
local test-time measurement, not a production latency claim.

### Terminal reconciliation and transaction durability

`mcp:reconcile-operation` records an investigated `outcome_unknown` as terminal
`reconciled`, using the inspected version and a bounded encrypted report. It is
read-only unless `--apply` is supplied. Reported outcomes are no-effect, committed
or partial; they are administrator assertions, not automated proof. Neither the
command nor the trusted recovery service invokes a handler or reopens a handle.
Executing records remain reserved. Evidence is bound to the operation and version;
inspection prints its digest rather than its text. Reports retain their capacity
slot for at least 30 days, then ordinary pruning can remove them.

SQL operations now require a Laravel PDO connection outside both Laravel-tracked
and raw PDO transactions, checked before and after access. This prevents host
rollback or query-listener transactions from undoing an acknowledged claim. Durable
SQL audit also rejects raw PDO transactions. Unsupported transaction wrappers must
use a separate committed connection/driver rather than savepoints.

The focused actions/storage run passed **162 tests / 975 assertions** on SQLite,
including the new recovery and expiry cases. The separate MySQL run above covers
transaction refusal, recovery, audit and token contracts. PHPStan level 9 passed.
The full checkpoint precedes these changes; a new full run remains required.

### Token expiry integrity

File-token hydration refuses missing, malformed and non-absolute expiry metadata
instead of silently converting it into unlimited lifetime. Explicit null still
means deliberately non-expiring access; absolute offsets and fractional seconds
remain supported. A token is expired at the exact expiry instant. Corrupt reads do
not rewrite or repair the source credential. These checks do not complete granular
builtin consent, resource versioning or approval migration.

### Credential rotation and authoritative storage reads

Commit `23af034` passed the complete local suite: **1751 passed, 18 optional SEO
skips, 8149 assertions**, 187.91 seconds. This precedes the credential fixes below.

Authenticated execution contexts now retain a non-secret credential revision.
Regenerating a token invalidates already authenticated contexts, including a change
during durable admission. A fresh credential with the same approved grant can
still replay a completed operation. Revisions are not included in persisted intent
or lifecycle events. Native SQL token and operation reads explicitly use the primary
connection, preventing stale replicas from reviving revoked credentials or hiding
claimed operations; ordinary application read routing is unchanged.

Both credential-rotation cases and both independent primary/replica cases failed
before their fixes. The actions suite now passes **96 tests / 659 assertions**.
PHPStan level 9 and Pint pass. This does not yet cover authoritative native SQL
actor/role reads or refreshing object-specific permissions after audit admission.

### Fresh actor and object permissions at admission

A regression reproduced target access being revoked during durable admission while
the actor retained the grant's broader permission. The executor now refreshes its
private actor guard and permission caches, then repeats the object policy after
admission, before the handler. A refusal records `failed_no_effect`. Refresh requires
the same active context and cannot replace an outer or nested actor.

Native Eloquent user, role and pivot reads use their primary connections during
authorization/execution. Nested scopes restore the original PDO or lazy resolver
even after exceptions. Existing SQL transactions are refused because a transaction
snapshot cannot establish current committed authorization. Custom user repositories
remain responsible for authoritative reads; this does not silently replace them.
Ordinary host reads outside execution retain their routing. These guarantees concern
registered actions; legacy public router migration remains outstanding.

Verification: OAuth/token/storage/protocol **579 tests / 1622 assertions**; final
actions **103 tests / 690 assertions**, including separate user/role connections,
stale replicas, raw/Laravel transaction ownership and nested guard restoration.
PHPStan level 9 and Pint passed. Full regression for this combined change follows.

### Complete authentication checkpoint and OAuth primary reads

Unchanged `ccbb48e` passed the complete local suite: **1762 passed, 18 optional SEO
skips, 8202 assertions**, 195.33 seconds. This precedes the following SQL-store changes.

A stale refresh-token replica could hide the consumed flag when detecting replay,
leaving the replacement family active. Native OAuth reads now use the primary.
Native SQL token and OAuth operations also refuse ambient Laravel/raw PDO transactions
before access and before acknowledging success. Otherwise a host rollback could
undo token revocation or one-time authorization-code consumption after acknowledgement.
The drivers do not roll back transactions they do not own. Installation guidance
now documents this breaking transaction boundary. The SQL contract fixtures use
Testbench migration cleanup without wrapping the behavior under test in a transaction.

Five regression cases failed before the fixes: hidden refresh replay, Laravel/raw
PDO token reads, and Laravel/raw PDO authorization-code consumption. The first
focused replacement run passed **58 tests / 223 assertions**; broader and MySQL
verification follow. Two additional listener tests cover a transaction opened during
a token read. These fixes do not implement the remaining V02–V10 work.

Final focused SQL/command checks: **15 tests / 55 assertions**; SQL service,
pruning, CP token lifecycle, permission gating and migration roundtrip checks:
**48 tests / 1416 assertions**. Native **MySQL 8.0.46 passed 87 tests / 1578
assertions**, including OAuth, token storage/service and file/database roundtrips
(287.26 seconds). These runs overlap. PHPStan level 9 and Pint passed.

### Read-only installation preflight

`mcp:doctor [--json]` checks explicit profiles, boolean HTTP enablement, HTTPS,
Origin configuration, private-network fetch protection, input budgets, durable
audit/OAuth driver contracts and local security stores/locks in multi-node mode.
It does not print credentials or raw configuration/exception values, migrate
grants, acquire test locks or perform test writes. Missing stdio credentials and
deployment-only checks are reported separately. Ingress/proxy trust, actual store
sharing/reachability, filesystem permissions, retention and recovery remain explicit
verification tasks; a passing preflight is not a release or migration certificate.
The command is partial V09 delivery, not the migration journal/rollback implementation.
