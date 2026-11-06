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
tool arguments. The prototype is deliberately not registered as a public MCP tool:
production admission still requires grants, approvals and built-in adapter wiring. The internal executor now uses the operation coordinator for edit actions; publish/delete/admin remain refused.

JSON Schema validation uses Opis 2.6.1+, with no network/file resolvers registered.
Schemas are bounded, JSON-only object schemas; external references and executable
extensions are rejected. Payloads are bounded to 1 MiB and depth 32 in this prototype.
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
integration observations, not durable audit. Approval and grant events will be added
with their owning implementations rather than emitted from unused scaffolding.

Remaining before the API can be frozen: persistent grant snapshots and dynamic scope
consent; production authorizer and explicit profiles; OperationStore and ApprovalStore;
first built-in action migration; request-state isolation; transport tests; adapter and
telemetry version contracts. WebMCP remains a later opt-in CP adapter.

The operation prototype uses immutable bindings and compare-and-set state changes.
The file implementation holds a process lock across file fsync and atomic rename;
it is single-node and does not claim power-loss durability across every filesystem.
The SQL implementation uses a monotonically increasing version in a conditional update, not a lease. It compares record bytes before that update so case-insensitive database collations cannot weaken the comparison. A standalone migration is provided but is not yet loaded by production wiring. A crashed executing
record never automatically returns to ready. Results are encrypted through Laravel's
Encrypter and are replayed only after current authorization and policy checks.
Production wiring still needs bounded retention/quota, migration registration and recovery
operations; the synthetic addon does not establish those release guarantees.
