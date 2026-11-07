<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;

/** Register observer classes during provider boot; no global or request-specific state. */
final class ObserverRegistry
{
    /** @var array<class-string<LifecycleObserver>, true> */
    private array $observers = [];

    private bool $frozen = false;

    /** @param class-string<LifecycleObserver> $observer */
    public function register(string $observer): void
    {
        if ($this->frozen || ! is_subclass_of($observer, LifecycleObserver::class)) {
            throw new \LogicException('Invalid or late lifecycle observer registration.');
        }
        if (! isset($this->observers[$observer]) && count($this->observers) >= 64) {
            throw new \LogicException('Lifecycle observer registry exceeds its budget.');
        }
        $this->observers[$observer] = true;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /** @return list<class-string<LifecycleObserver>> */
    public function classes(): array
    {
        if (! $this->frozen) {
            throw new \LogicException('Freeze observers before dispatching events.');
        }

        return array_keys($this->observers);
    }
}
