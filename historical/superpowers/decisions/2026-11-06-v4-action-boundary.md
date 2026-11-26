---
title: "V4 action boundary prototype"
description: "Internal prototype decisions and integration constraints"
---

# V4 action boundary prototype

Status: internal prototype on `sd/release/v4`, not a released integration API.

An action has a package-owned id, version, explicit scopes, effect, input and output
JSON schemas, handler and mandatory resource policy. `CapabilityRegistry` and
`ScopeRegistry` belong to one application instance and freeze before execution.
Duplicate ids, scope ownership conflicts and registration after freeze fail.
A grant snapshot identifies the exact actions and definition fingerprints approved;
adding another action to the same scope does not expand that snapshot. A package
must increment its action version when it changes semantics without changing the
schema. Installed PHP packages remain trusted code, not sandboxed tenants.

`ActionExecutor` delegates current actor/grant/profile authorization to the mandatory
`Authorizer`, validates input, invokes the resource policy, rechecks authorization
and the deadline, then calls the handler once, filters fields under the current policy and validates output. Replayed results are filtered and validated again; a changed field policy cannot reveal previously cached fields. The transport
constructs `ExecutionContext`; clients cannot supply an actor or grant by setting
tool arguments. Registered read actions are exposed through HTTP, stdio and tool
search. Their catalog visibility and invocation require an explicit immutable grant.
Stdio reads require `STATAMIC_MCP_STDIO_TOKEN`; an ambient CLI identity is not a grant.
Registered writes remain unexposed pending resource versions and approvals. The
internal executor uses the operation coordinator for edit actions; publish/delete/admin
remain refused. Built-in routers have not yet migrated to this boundary.

JSON Schema validation uses Opis 2.6.1+, with no network/file resolvers registered.
Schemas are bounded, JSON-only object schemas; external references and executable
extensions are rejected. Input follows the configured transport budgets (16 MiB
by default); output is bounded to 1 MiB, 20,000 values and depth 256. Result storage
uses the same JSON encoding and nesting budget as validation.
The transport must additionally enforce budgets before parsing input; post-allocation
validation alone does not bound worker memory. Empty nested PHP arrays have JSON
array semantics: adapters must preserve object/list distinctions when decoding.

The synthetic addon in `ActionContractTest` provides a read and a draft-only title
write using real Statamic users and policies. It proves no superuser or wildcard is
needed and that a revoked role is checked again. It is not a general content handler
and does not replace production Statamic blueprint processing or cache handling.

Lifecycle observers receive metadata without arguments/results/exceptions. Observer
and logger failures are isolated. An exception after a write begins has unknown effects;
output-schema validation failure cannot assert rollback. These events are at-least-once
integration observations, not durable audit. Typed grant events are emitted by issuance, revocation and refresh-replay paths.
Approval events will be added with the approval implementation.

Implemented foundations include immutable grant consent, the production authorizer,
profiles, native actor isolation, bounded operation stores and the optional telemetry
adapter. Remaining before the API can be frozen: built-in action migration, resource
versions, durable approvals/audit, complete public lifecycle coverage and the final
compatibility matrix. WebMCP remains a later opt-in CP adapter.

The operation prototype uses immutable bindings and compare-and-set state changes.
The file implementation holds a process lock across file fsync and atomic rename;
it is single-node and does not claim power-loss durability across every filesystem.
The SQL implementation uses a monotonically increasing version in a conditional update, not a lease. It compares record bytes before that update so case-insensitive database collations cannot weaken the comparison. The migration is conditionally loaded when the SQL backend is configured. A crashed executing
record never automatically returns to ready. Results are encrypted through Laravel's
Encrypter and are replayed only after current authorization and policy checks.
Admission is bounded, with pruning and capacity-repair commands. Executing and
unknown operations are retained for investigation. Resource versions and complete
outcome reconciliation remain required; the synthetic addon does not establish
those release guarantees.

Native Eloquent authorization reads are pinned to primary user and role connections
within the execution scope, restoring previous read routing on exit. Host transaction
snapshots are refused. Custom repositories must supply current authoritative reads.
`ActorScope::refresh` refreshes only its active context; the executor uses it before
pre-execution policy checks and again after durable admission. Policy implementations
must make fresh resource decisions on every invocation rather than retaining an
authorization result from preparation. Credential revision binding invalidates an
already authenticated context when its token secret changes; stable grant identity
still permits replay with a newly authenticated replacement credential.
