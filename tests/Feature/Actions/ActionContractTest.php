<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Actions;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\ActionExecutor;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\Contracts\Authorizer;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\LifecycleObservers;
use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\SchemaValidator;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Psr\Log\NullLogger;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class ActionContractTest extends TestCase
{
    private ?string $operationDirectory = null;

    protected function tearDown(): void
    {
        if ($this->operationDirectory !== null) {
            File::deleteDirectory($this->operationDirectory);
        }
        parent::tearDown();
    }

    private function definition(string $name = 'read', ActionEffect $effect = ActionEffect::Read): ActionDefinition
    {
        return new ActionDefinition(
            'fixture/addon', $name, 1, 'Read a permitted draft.', $effect, ['fixture:read'],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'maxLength' => 100]], 'required' => ['id'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title'], 'additionalProperties' => false],
            DraftReadHandler::class, DraftPolicy::class,
        );
    }

    public function test_external_references_are_rejected_before_execution(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SchemaValidator::checkSchema(['type' => 'object', 'properties' => ['data' => ['$ref' => 'http://127.0.0.1/private']]]);
    }

    public function test_schema_rejects_wrong_types_and_unknown_fields(): void
    {
        $validator = new SchemaValidator;
        $validator->validate(['id' => 'draft'], $this->definition()->inputSchema);
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate(['id' => 42, 'secret' => 'not echoed'], $this->definition()->inputSchema);
    }

    public function test_duplicate_scope_cannot_change_ownership(): void
    {
        $scopes = new ScopeRegistry;
        $scopes->register('fixture/addon', 'fixture:read', 'Read');
        $this->expectException(\LogicException::class);
        $scopes->register('other/addon', 'fixture:read', 'Overwrite');
    }

    public function test_unregistered_scope_cannot_be_executed(): void
    {
        $registry = new CapabilityRegistry(new ScopeRegistry);
        $this->expectException(\LogicException::class);
        $registry->register($this->definition());
    }

    public function test_freeze_and_snapshot_prevent_silent_expansion(): void
    {
        $scopes = new ScopeRegistry;
        $scopes->register('fixture/addon', 'fixture:read', 'Read');
        $registry = new CapabilityRegistry($scopes);
        $registry->register($this->definition());
        $registry->register($this->definition('new_action'));
        $registry->freeze();
        $snapshot = $registry->snapshot(['fixture/addon/read']);
        $this->assertArrayNotHasKey('fixture/addon/new_action', $snapshot);
        $this->assertSame($this->definition()->fingerprint(), $snapshot['fixture/addon/read']);
        $this->expectException(\LogicException::class);
        $registry->register($this->definition('late'));
    }

    public function test_real_statamic_policy_and_failing_observer_share_the_pipeline(): void
    {
        config(['statamic.editions.pro' => true]);
        $handle = 'action-' . bin2hex(random_bytes(4));
        Collection::make($handle)->save();
        $entry = Entry::make()->collection($handle)->slug('draft')->published(false)->data(['title' => 'Before']);
        $entry->save();
        Role::make()->handle($handle)->permissions(["view {$handle} entries", "edit {$handle} entries"])->save();
        $user = User::make()->email($handle . '@example.test')->assignRole($handle);
        $user->save();
        $context = new ExecutionContext($user->id(), 'fixture-grant', 'default', 'editor', 'http', 'fixture-operation', hrtime(true) + 10_000_000_000);
        $scopes = new ScopeRegistry;
        $scopes->register('fixture/addon', 'fixture:read', 'Read');
        $scopes->register('fixture/addon', 'fixture:edit', 'Edit');
        $registry = new CapabilityRegistry($scopes);
        $registry->register($this->definition());
        $registry->register(new ActionDefinition(
            'fixture/addon', 'edit', 1, 'Edit the draft title.', ActionEffect::Edit, ['fixture:edit'],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string', 'maxLength' => 100]], 'required' => ['id', 'title'], 'additionalProperties' => false],
            $this->definition()->outputSchema, DraftWriteHandler::class, DraftPolicy::class,
        ));
        $registry->freeze();
        $authorizer = new class($registry->snapshot(['fixture/addon/read', 'fixture/addon/edit'])) implements Authorizer
        {
            /** @param array<string, string> $snapshot */
            public function __construct(private array $snapshot) {}

            public function authorize(ActionDefinition $action, ExecutionContext $context): void
            {
                if (($this->snapshot[$action->id()] ?? null) !== $action->fingerprint()) {
                    throw new AuthorizationException;
                }
            }
        };
        $brokenObserver = new class implements LifecycleObserver
        {
            public function observe(LifecycleEvent $event): void
            {
                throw new \RuntimeException('Synthetic exporter failure');
            }
        };
        $this->operationDirectory = storage_path('framework/testing/actions-' . $handle);
        $executor = new ActionExecutor($registry, $authorizer, new SchemaValidator, new LifecycleObservers(new NullLogger, [$brokenObserver]), $this->app,
            new OperationCoordinator(
                new FileOperationStore($this->operationDirectory),
                $this->app->make(Encrypter::class),
            ));
        $this->assertSame(['title' => 'Before'], $executor->execute('fixture/addon/read', ['id' => $entry->id()], $context));
        $writes = 0;
        Event::listen(EntrySaved::class, function () use (&$writes): void {
            $writes++;
        });
        $prepared = $executor->prepare('fixture/addon/edit', ['id' => $entry->id(), 'title' => 'After'], $context);
        $context = $context->withOperationId($prepared->id);
        $this->assertSame(0, $writes);
        $this->assertSame(['title' => 'After'], $executor->execute('fixture/addon/edit', ['id' => $entry->id(), 'title' => 'After'], $context));
        $this->assertSame(['title' => 'After'], $executor->execute('fixture/addon/edit', ['id' => $entry->id(), 'title' => 'After'], $context));
        $this->assertSame(1, $writes, 'An idempotent replay must not invoke the handler again.');
        $this->assertSame('After', Entry::find($entry->id())->get('title'));
        $this->assertFalse(Entry::find($entry->id())->published());
        config(['statamic.mcp.tools.entries.denied_fields' => ['title']]);
        try {
            $executor->execute('fixture/addon/edit', ['id' => $entry->id(), 'title' => 'After'], $context);
            $this->fail('Replay must respect newly restricted output fields.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringNotContainsString('After', $error->getMessage());
            $this->assertSame(1, $writes);
        }
        config(['statamic.mcp.tools.entries.denied_fields' => []]);
        $user->removeRole($handle)->save();
        $this->expectException(AuthorizationException::class);
        $executor->execute('fixture/addon/read', ['id' => $entry->id()], $context);
    }
}

class DraftPolicy implements ActionPolicy
{
    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return app(ResourcePolicy::class)->filterFields('entries', $result);
    }

    public function authorize(array $input, ExecutionContext $context): void
    {
        $entry = Entry::find($input['id']);
        $user = User::find($context->actorId);
        if (! $user || ! $entry || $entry->site()->handle() !== $context->site || $entry->published()
            || ! Gate::forUser($user)->allows(array_key_exists('title', $input) ? 'edit' : 'view', $entry)) {
            throw new AuthorizationException;
        }
    }
}

class DraftReadHandler implements ActionHandler
{
    public function execute(array $input, ExecutionContext $context): array
    {
        return ['title' => Entry::find($input['id'])->get('title')];
    }
}

class DraftWriteHandler implements ActionHandler
{
    public function execute(array $input, ExecutionContext $context): array
    {
        $entry = clone Entry::find($input['id']);
        if ($entry->set('title', $input['title'])->save() === false) {
            throw new \RuntimeException('Write refused.');
        }

        return ['title' => $entry->get('title')];
    }
}
