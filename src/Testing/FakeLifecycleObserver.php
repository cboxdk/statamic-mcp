<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Testing;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;

/** Bind this instance and register its class before boot to inspect actual pipeline events. */
class FakeLifecycleObserver implements LifecycleObserver
{
    /** @var list<LifecycleEvent> */
    public array $events = [];

    public function observe(LifecycleEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<LifecycleEvent> */
    public function named(string $name): array
    {
        return array_values(array_filter($this->events, fn (LifecycleEvent $event): bool => $event->eventName() === $name));
    }
}
