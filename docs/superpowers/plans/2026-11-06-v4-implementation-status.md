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
| Action/scope registry, execution and durable operation prototype | Internal, not exposed to clients yet | v4 only |
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
- V04: integrate operation storage into all writes; wire the supplied SQL migration and add
  retention/quota/recovery and multi-node database concurrency coverage.
- V05: durable approvals, CP UI, revocation and atomic consumption. Existing
  stateless confirmations have not yet been replaced.
- V06/V07: complete public lifecycle events, durable audit and released-version
  telemetry adapter. Prototype observers are isolated but not a telemetry integration.
- V08: migrate built-ins, close the remaining Statamic policy/data/cache matrix,
  and test supported third-party fieldtypes and repositories.
- V09/V10: migration/doctor, rollback fixtures, dependency/client matrix, performance
  baseline and release candidate review. No support claim for Statamic 6.40 as a
  new minimum yet; the requirement remains 6.35.1 pending the matrix.

The prototype has no service-provider binding or public MCP registration. This is
intentional: it must not admit production writes before authorization and approvals
are wired. High-impact prototype actions are refused. WebMCP remains deferred as
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
