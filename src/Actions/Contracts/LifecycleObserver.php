<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

interface LifecycleObserver
{
    /** Delivery can repeat. Deduplicate durable consumers on eventId(). */
    public function observe(LifecycleEvent $event): void;
}
