<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

final class LifecycleObservers
{
    /** @param list<LifecycleObserver> $observers */
    public function __construct(private readonly LoggerInterface $logger, private readonly array $observers = []) {}

    public static function fromRegistry(ObserverRegistry $registry, Container $container, LoggerInterface $logger): self
    {
        $observers = [];
        foreach ($registry->classes() as $class) {
            try {
                $observer = $container->make($class);
                if (! $observer instanceof LifecycleObserver) {
                    throw new \LogicException('Invalid lifecycle observer binding.');
                }
                $observers[] = $observer;
            } catch (\Throwable) {
                self::report($logger, null);
            }
        }

        return new self($logger, $observers);
    }

    public function dispatch(LifecycleEvent $event): void
    {
        foreach ($this->observers as $observer) {
            try {
                $observer->observe($event);
            } catch (\Throwable) {
                self::report($this->logger, $event->eventId());
            }
        }
    }

    private static function report(LoggerInterface $logger, ?string $eventId): void
    {
        try {
            $logger->warning('MCP lifecycle observer failed.', ['event_id' => $eventId]);
        } catch (\Throwable) {
            // Logging is also an observer, never a veto after persistence.
        }
    }
}
