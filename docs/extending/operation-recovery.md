---
title: "Operation recovery"
description: "Inspect and reconcile retained v4 action outcomes without repeating writes"
---

# Operation recovery

This page describes the v4 registered-action operation store. Legacy router calls
have not yet been migrated to this store. It does not add idempotency to those calls.

When a write loses its response, retry its original prepared handle. Do not prepare
a new operation to find out whether the previous write worked. A new handle is a
new operation and can repeat side effects. Replays still require current permissions.

## Inspect the retained state

Run this on the application host:

```sh
php artisan mcp:inspect-operation OPERATION_HANDLE
```

The JSON identifies the actor, grant, action definition, site, input digest and
retained state. It excludes credentials, raw input and result content. Keep the
reported `version` for reconciliation. An unknown or pruned handle is not evidence
that no write occurred.

- `succeeded`: the result was stored. Retry within the original retry window and
  current access restrictions to receive a filtered replay.
- `failed_no_effect`: the coordinator refused work before entering the handler.
- `executing`: the claim is still reserved. A worker may be running, or may have
  crashed before recording its outcome. Recovery does not automatically release it.
- `outcome_unknown`: the handler finished with an exception, or completion could not
  be acknowledged. Content, native listeners, cache work or external effects may
  already have happened. The handle cannot execute again.

The coordinator does not provide a transaction across Statamic files, SQL records,
queues and external services. Inspect each affected system before deciding what to
do next. A missing content file alone does not establish that no effects occurred.

## Record an investigated outcome

For an `outcome_unknown` operation, write a local UTF-8 evidence file describing the
checks and their result. Keep it within 12,000 bytes; avoid copying credentials or
full content into it. Outcomes are `no_effect`, `committed` and `partial`.

```sh
php artisan mcp:reconcile-operation OPERATION_HANDLE \
  --expected-version=4 \
  --reported-by=operator@example.test \
  --outcome=partial \
  --evidence-file=/secure/path/investigation.txt
```

This is a dry run. Add `--apply` after completing the investigation to persist the
report. The recorded operator identity is an administrator-supplied assertion,
not an independently verified human identity. The command is local administration;
there is no MCP tool that can invoke it.

Reconciliation atomically changes the inspected version to terminal `reconciled`.
It never invokes a handler, repairs content, retries a purge, reopens the handle or
creates a replacement operation. A concurrent or repeated reconciliation is refused.
`executing` records remain reserved: elapsed time alone cannot prove a worker stopped.

The report is encrypted with the application's encryption key and bound to the
operation, request binding and previous version. Durable audit admission is required
before the transition. If acknowledgement fails, inspect the same handle before
trying again. Inspection shows the reported outcome and an evidence digest without
displaying the evidence. The report is an operator's finding, not a computed proof
that all effects have committed or rolled back.

## Retention and storage

Reconciled records retain their capacity slot for at least 30 days after the report.
Then `mcp:prune-operations` may remove them. Retention is extended only for this
terminal report; it does not renew the execution or retry window. Copy investigation
evidence to the deployment's incident archive if it must be kept longer.

Executing and unresolved records are never pruned automatically. Do not remove them
to make room without investigating their effects. `mcp:repair-operation-capacity`
only recounts occupied slots; it does not resolve or resume operations.

The file backend is for a single node with the documented local locking and fsync
semantics. Multi-node operation requires shared SQL storage. SQL operation reads and
mutations refuse an existing Laravel or PDO transaction: a host rollback must not
undo an acknowledged claim. Durable SQL audit applies the same restriction. A
host that wraps requests in transactions must bind an independently committed
operation connection and a suitable durable audit driver; using a savepoint on the
host's existing connection does not provide that guarantee.

Keep the encryption key available for all retained records. Restoring an old store
backup can restore old claims and outcomes; investigate content and external effects
before accepting requests after a restore. A backup is not a safe rollback of CMS
writes or grants.
