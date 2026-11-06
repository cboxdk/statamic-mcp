<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;
use Cboxdk\StatamicMcp\Actions\Events\ExecutionEvent;
use Psr\Log\LoggerInterface;

final class LifecycleObservers
{
    /** @param list<LifecycleObserver> $observers */
    public function __construct(private readonly LoggerInterface $logger, private readonly array $observers = []) {}

    public function dispatch(ExecutionEvent $event): void
    {
        foreach ($this->observers as $observer) {
            try {
                $observer->observe($event);
            } catch (\Throwable) {
                try {
                    $this->logger->warning('MCP lifecycle observer failed.', ['event_id' => $event->id]);
                } catch (\Throwable) {
                    // Logging is also an observer, never a veto after persistence.
                }
            }
        }
    }
}
