<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;
use Cboxdk\StatamicMcp\Actions\Events\ExecutionCompleted;
use Cboxdk\StatamicMcp\Actions\LifecycleObservers;
use Cboxdk\StatamicMcp\Actions\ObserverRegistry;
use Cboxdk\StatamicMcp\Testing\FakeLifecycleObserver;
use Illuminate\Container\Container;
use Psr\Log\NullLogger;

it('isolates observer construction dispatch and logging failures', function () {
    $container = new Container;
    $registry = new ObserverRegistry;
    $registry->register(BrokenObserverConstructor::class);
    $registry->register(BrokenObserverDispatch::class);
    $registry->register(FakeLifecycleObserver::class);
    $registry->freeze();
    $fake = new FakeLifecycleObserver;
    $container->instance(FakeLifecycleObserver::class, $fake);
    $logger = new class extends NullLogger
    {
        public function warning(string|Stringable $message, array $context = []): void
        {
            throw new RuntimeException('Logging failed with sensitive details');
        }
    };
    $observers = LifecycleObservers::fromRegistry($registry, $container, $logger);
    $event = new ExecutionCompleted('event', 'operation', 'fixture/addon/read', 1, 'http', 'completed', 'none', 1.0, gmdate('c'), executionId: 'execution');
    $observers->dispatch($event);
    expect($fake->events)->toBe([$event]);
    expect($fake->named('execution.completed'))->toBe([$event]);
    expect($event->metadata())->not->toHaveKeys(['arguments', 'result', 'exception', 'credential']);
});

it('freezes observer registration without sharing it between applications', function () {
    $first = new ObserverRegistry;
    $second = new ObserverRegistry;
    $first->register(FakeLifecycleObserver::class);
    $first->register(FakeLifecycleObserver::class);
    $first->freeze();
    $second->freeze();
    expect($first->classes())->toBe([FakeLifecycleObserver::class]);
    expect($second->classes())->toBe([]);
    expect(fn () => $first->register(FakeLifecycleObserver::class))->toThrow(LogicException::class);
});

final class BrokenObserverConstructor implements LifecycleObserver
{
    public function __construct()
    {
        throw new RuntimeException('Sensitive exporter configuration');
    }

    public function observe(LifecycleEvent $event): void {}
}

final class BrokenObserverDispatch implements LifecycleObserver
{
    public function observe(LifecycleEvent $event): void
    {
        throw new RuntimeException('Sensitive exporter response');
    }
}
