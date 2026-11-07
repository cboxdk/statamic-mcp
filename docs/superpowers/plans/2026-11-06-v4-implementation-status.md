---
title: "V4 implementation status"
description: "Verified work and remaining release gates"
---

# V4 implementation status

Branch: `sd/release/v4`. Worktree: `security-review-hardening/statamic-mcp`.
Status: implementation in progress, not release-ready. No public release or tag.

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
| Granular scopes, immutable grants, approvals | Not implemented yet | v4 only |

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

- V02: connect dynamic scopes and immutable capability snapshots to token issuance,
  OAuth consent, refresh and both token stores. The current registry is internal.
- V03: profiles now restrict the existing tools/resources/prompts and installer.
  A production authorizer with immutable grants across all surfaces is still missing.
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
