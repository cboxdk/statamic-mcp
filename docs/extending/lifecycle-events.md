---
title: "Lifecycle events"
description: "Observe tool executions, approvals and grants without being able to change them"
weight: 30
---

# Lifecycle events

Every MCP call and every approval emits lifecycle events. A package can observe
them, to export metrics or traces, to feed its own audit, or to notify someone.
An observer can never veto, change or undo anything: events are dispatched after
the decision or the write is already durable.

## Events

| Event | When |
|---|---|
| `execution.started` | After authorization, and after any approval or idempotency claim, right before the handler runs |
| `execution.completed` | The handler finished and kept its contract. `effects` is `committed` when something was written, `none` for a read |
| `execution.failed` | The handler refused or threw. `effects` says whether anything landed: `none`, `committed` or `unknown` |
| `execution.denied` | Refused before the handler: token scope, Statamic permission or resource policy. No `started` precedes it |
| `execution.replayed` | A stored result was handed back (idempotency key or approval). Not a new write |
| `approval.requested` | A gated call is waiting for a person |
| `approval.approved` / `approval.rejected` | The person decided; `via` is `control_panel` or `authority` (e.g. Cbox ID). A rejection because the record changed has `reason: stale` |
| `approval.consumed` | The approved call ran; the approval cannot run again |
| `grant.issued`, `grant.revoked`, `grant.refresh_replay_detected` | Token and OAuth grant changes |

For one call, `operation_id` is the same on `started` and its terminal event. On
an approved call it is the approval id.

## What an event carries

`metadata()` returns a flat array: `schema_version`, `event_id`, `event`,
`operation_id`, `action_id` (such as `statamic-mcp/entries/update`), `transport`,
`outcome`, `effects`, `duration_ms` and `occurred_at`. Never the call's arguments,
its result, the record's content, exception messages, credentials or who the
person is. If you need the record, read it yourself.

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
- Delivery is at least once in spirit, not exactly once: deduplicate on
  `event_id` if you store events.
- Events are synchronous. Keep observers fast, or hand the work to a queue.
- The tool audit log (Tools → MCP → Activity) is separate and does not depend on
  observers.
