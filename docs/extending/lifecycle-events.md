---
title: "Lifecycle events"
description: "Observe tool executions, approvals and grants without being able to change them"
weight: 30
---

# Lifecycle events

This describes the **unreleased v4 contract**.

Tool calls, approval transitions, grant changes and HTTP rejections expose
metadata-only lifecycle events. A package can observe them for metrics, traces,
its own audit or notifications. An observer cannot veto, change or undo a call.
Approval and grant events follow their durable state changes; execution starts
before the handler and finishes after the response has been finalized.

## Events

| Event | When |
|---|---|
| `execution.started` | After authorization, and after any approval or idempotency claim, right before the handler runs |
| `execution.completed` | The finalized envelope is successful, after response sizing and deferred cache work. `effects` is `committed` when something was written, `none` for a read |
| `execution.failed` | The final result failed, including handler or response-size failures. `effects` says whether anything landed: `none`, `committed` or `unknown` |
| `execution.denied` | Refused before the handler: token scope, Statamic permission or resource policy. No `started` precedes it |
| `execution.replayed` | A stored result was handed back (idempotency key or approval). Not a new write |
| `approval.requested` | A gated call is waiting for a person |
| `approval.approved` / `approval.rejected` | The person decided; `via` is `control_panel` or `authority` (e.g. Cbox ID). A rejection because the record changed has `reason: stale` |
| `approval.consumed` | The approved call ran; the approval cannot run again |
| `approval.expired` | An expired approval is retried; emitted once for that expiry, not on every subsequent retry or by a timer |
| `request.rejected` | The MCP input middleware observes an HTTP 4xx rejection, including failures before tool dispatch |
| `grant.issued`, `grant.revoked`, `grant.refresh_replay_detected` | Token and OAuth grant changes |

For one call, `operation_id` is the same on `started` and its terminal event. On
an approved call it is the approval id. For a successful approved execution,
`approval.consumed` precedes `execution.completed`. Observers must not assume
completion precedes approval consumption.

A bounded successful-write receipt remains `execution.completed`, not a failure
because the original payload was too large. Cache work is measured before the
terminal event; a cache failure does not undo a committed write. Replays do not
emit another `execution.started` / `execution.completed` pair.

`request.rejected` describes transport rejection, not a handler execution. It
does not claim to observe failures rejected by a reverse proxy or web server
before reaching the middleware, or application errors returned with HTTP 200.

## What an event carries

`metadata()` returns a flat array whose fields depend on the event. Execution
events include `schema_version`, `event_id`, `event`, `operation_id`,
`execution_id`, `action_id` (such as `statamic-mcp/entries/update`),
`action_version`, `transport`, `outcome`, `effects`, `duration_ms` and `occurred_at`.

Final execution metadata also exposes:

- `reason` and `error_code`: machine-readable failure classification when present,
  not an exception message.
- `correlation_id`: the diagnostic identifier when available.
- `response_bytes`: bytes of the encoded response **envelope only**, not the MCP
  JSON-RPC wrapper, duplicated text/structured content, SSE framing or HTTP bytes.
- `cache_ms`: time spent on deferred cache work, not the whole execution duration.
- `traceparent`: a validated W3C version-00 trace context when supplied. Other
  versions, malformed values and zero trace/span identifiers are not forwarded.

Fields unavailable at a particular event can be null. HTTP rejection events
include `http_status`, correlation and trace context, but have no tool execution
or operation identity. Never the call's arguments, its result, record content,
exception messages, credentials or actor identity. Read records separately under
the appropriate authorization if an integration needs their content.

## Registering an observer

```php
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;

final class MetricsObserver implements LifecycleObserver
{
    public function observe(LifecycleEvent $event): void
    {
        $data = $event->metadata();
        // count $data['event'], $data['action_id'], $data['outcome'] ...
    }
}
```

Register it while Statamic boots, from a service provider:

```php
use Cboxdk\StatamicMcp\Actions\ObserverRegistry;
use Statamic\Statamic;

public function boot(): void
{
    Statamic::booted(fn () => $this->app->make(ObserverRegistry::class)->register(MetricsObserver::class));
}
```

The registry is frozen once Statamic has booted; registering later throws.

## Delivery

- An observer that throws is logged and skipped. The call, its result and the
  other observers are not affected.
- Dispatch is best effort, not a durable delivery queue. A crash or failing
  observer can lose an observation; deduplicate on `event_id` in downstream
  storage, but do not infer an at-least-once delivery guarantee.
- Events are synchronous. Keep observers fast, or hand the work to a queue.
- The tool audit log (Tools → MCP → Activity) is separate and does not depend on
  observers.
