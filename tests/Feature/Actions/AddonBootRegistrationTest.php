<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Actions;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\ObserverRegistry;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Testing\FakeLifecycleObserver;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\ServiceProvider;
use Statamic\Statamic;

class AddonBootRegistrationTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), DeferredRegistrationProvider::class];
    }

    public function test_statamic_deferred_addon_boot_can_register_before_registries_freeze(): void
    {
        $registry = app(CapabilityRegistry::class);
        $action = $registry->get('fixture/deferred/read');
        $this->assertTrue($registry->forScopes(['deferred:read'])->permits($action));
        $this->assertContains(FakeLifecycleObserver::class, app(ObserverRegistry::class)->classes());
        $this->expectException(\LogicException::class);
        app(ScopeRegistry::class)->register('fixture/deferred', 'deferred:late', 'Too late');
    }

    public function test_observers_cannot_register_after_statamic_has_finished_booting(): void
    {
        $this->expectException(\LogicException::class);
        app(ObserverRegistry::class)->register(FakeLifecycleObserver::class);
    }
}

class DeferredRegistrationProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The same deferred phase used by AddonServiceProvider::bootAddon.
        Statamic::booted(function (): void {
            $this->app->make(ScopeRegistry::class)->register('fixture/deferred', 'deferred:read', 'Read fixture');
            $this->app->make(CapabilityRegistry::class)->register(new ActionDefinition(
                'fixture/deferred', 'read', 1, 'Read fixture', ActionEffect::Read, ['deferred:read'],
                ['type' => 'object'], ['type' => 'object'], DeferredAction::class, DeferredAction::class,
            ));
            $this->app->make(ObserverRegistry::class)->register(FakeLifecycleObserver::class);
        });
    }
}

class DeferredAction implements ActionHandler, ActionPolicy
{
    public function execute(array $input, ExecutionContext $context): array
    {
        return [];
    }

    public function authorize(array $input, ExecutionContext $context): void {}

    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return $result;
    }
}
