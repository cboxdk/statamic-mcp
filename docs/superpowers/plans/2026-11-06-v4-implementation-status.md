---
title: "V4 implementation status"
description: "Verified work and remaining release gates"
---

# V4 implementation status

Branch: `sd/release/v4`. Worktree: `security-review-hardening/statamic-mcp`.
Status: implementation in progress, not release-ready. No public release or tag.
Draft PR: https://github.com/cboxdk/statamic-mcp/pull/88.

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
  both token stores. Built-in granular edit/publish/delete/admin scopes and migration
  remain. Legacy core-only tokens do not authorize registered actions.
- V03: profiles now restrict the existing tools/resources/prompts and installer.
  The registered-action authorizer now reloads credentials, scopes, consent and site
  access. Wiring it across all public MCP surfaces is still missing.
  Editor currently handles unpublished entries only; live working-copy editing is refused.
- V04: integrate operations into all writes; add resource-version bindings and
  complete outcome reconciliation. Prepare-only handles, admission limits, pruning,
  capacity repair, driver bindings and SQLite process concurrency are implemented.
  Shared MySQL admission still needs CI coverage.
- V05: durable approvals, CP UI, revocation and atomic consumption. Existing
  stateless confirmations have not yet been replaced.
- V06/V07: complete public lifecycle events and durable audit. The optional
  telemetry adapter is implemented in statamic-telemetry PR #5 against published
  SDK 3.9.3; execution coverage still depends on the public router migration.
- V08: migrate built-ins, close the remaining Statamic policy/data/cache matrix,
  and test supported third-party fieldtypes and repositories.
- V09/V10: migration/doctor, rollback fixtures, dependency/client matrix, performance
  baseline and release candidate review. No support claim for Statamic 6.40 as a
  new minimum yet; the requirement remains 6.35.1 pending the matrix.

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
