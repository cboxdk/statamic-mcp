<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Actions;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\ActionExecutor;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\Contracts\ActorScope;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\ExecutionAudit;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\LifecycleObservers;
use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Cboxdk\StatamicMcp\Actions\SchemaValidator;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Actions\Transport\ContextFactory;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Authorization\GrantAuthorizer;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Contracts\DurableAuditStore;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Testing\FakeLifecycleObserver;
use Cboxdk\StatamicMcp\Tests\Concerns\InteractsWithOAuthConsent;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Psr\Log\NullLogger;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class GrantIntegrationTest extends TestCase
{
    use InteractsWithOAuthConsent;

    private function definition(string $name = 'read', int $version = 1): ActionDefinition
    {
        return new ActionDefinition('fixture/grants', $name, $version, 'Read integration status.', ActionEffect::Read,
            ['fixture:status'], ['type' => 'object', 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false],
            GrantFixtureHandler::class, GrantFixturePolicy::class, ['read_only', 'operator']);
    }

    /** Simulate the registry of an application deployment, including its boot freeze. */
    private function deploy(bool $addAction = false): CapabilityRegistry
    {
        $scopes = new ScopeRegistry;
        $scopes->register('fixture/grants', 'fixture:status', 'Read integration status', ['view mcp dashboard']);
        $registry = new CapabilityRegistry($scopes);
        $registry->register($this->definition());
        if ($addAction) {
            $registry->register($this->definition('new_action'));
        }
        $registry->freeze();
        $this->app->instance(ScopeRegistry::class, $scopes);
        $this->app->instance(CapabilityRegistry::class, $registry);

        return $registry;
    }

    public function test_oauth_binds_addon_consent_to_the_displayed_capabilities_through_refresh(): void
    {
        config(['statamic.editions.pro' => true]);
        $this->deploy();
        Role::make()->handle('integration-reader')->permissions(['access cp', 'view mcp dashboard'])->save();
        $actor = User::make()->email('integration-reader@example.test')->assignRole('integration-reader');
        $actor->save();
        $this->assertFalse($actor->isSuper());
        $this->actingAs($actor, 'web');
        $client = app(OAuthDriver::class)->registerClient('Addon client', ['https://example.test/callback']);
        $verifier = str_repeat('v', 43);
        $url = '/cp/mcp/oauth/authorize';
        $form = $this->withConsentOffer($url, [
            'client_id' => $client->clientId, 'redirect_uri' => 'https://example.test/callback',
            'state' => 'integration', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'scope' => 'fixture:status', 'scopes' => ['fixture:status'], 'decision' => 'approve',
        ]);

        // A package update between displaying and submitting consent cannot expand it.
        $this->deploy(true);
        $approval = $this->post($url, $form)->assertRedirectContains('code=');
        parse_str((string) parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $exchange = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $query['code'], 'client_id' => $client->clientId,
            'redirect_uri' => 'https://example.test/callback', 'code_verifier' => $verifier,
        ])->assertOk();
        $token = app(TokenService::class)->validateToken($exchange->json('access_token'));
        $this->assertSame(['fixture:status'], $token->scopes);
        $this->assertSame(['fixture/grants/read' => $this->definition()->fingerprint()], $token->capabilities->actions);
        $context = new ExecutionContext($actor->id(), $token->capabilities->grantId, 'default', 'operator', 'http', 'read', hrtime(true) + 10_000_000_000, $token->id, $token->credentialRevision());
        $authorizer = app(GrantAuthorizer::class);
        $authorizer->authorize($this->definition(), $context);
        foreach ([$this->definition('new_action'), $this->definition('read', 2)] as $unapproved) {
            try {
                $authorizer->authorize($unapproved, $context);
                $this->fail('Undisplayed or changed capabilities must not be granted.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $refresh = $this->post('/mcp/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $exchange->json('refresh_token'), 'client_id' => $client->clientId,
        ])->assertOk();
        $renewed = app(TokenService::class)->validateToken($refresh->json('access_token'));
        $this->assertSame($token->capabilities->toStored(), $renewed->capabilities->toStored());
        $this->assertNotSame($token->id, $renewed->id);
        $context = new ExecutionContext($actor->id(), $renewed->capabilities->grantId, 'default', 'operator', 'http', 'read', hrtime(true) + 10_000_000_000, $renewed->id, $renewed->credentialRevision());
        $actor->removeRole('integration-reader')->save();
        app(PermissionCache::class)->clear();
        $this->expectException(AuthorizationException::class);
        $authorizer->authorize($this->definition(), $context);
    }

    public function test_operation_replay_survives_credential_rotation_but_not_revocation(): void
    {
        $this->deploy();
        $actor = User::make()->email('rotation@example.test')->makeSuper();
        $actor->save();
        $action = new ActionDefinition('fixture/grants', 'edit', 1, 'Edit once.', ActionEffect::Edit,
            ['fixture:status'], ['type' => 'object', 'additionalProperties' => false],
            $this->definition()->outputSchema, GrantFixtureHandler::class, GrantFixturePolicy::class);
        $registry = new CapabilityRegistry(app(ScopeRegistry::class));
        $registry->register($action);
        $registry->freeze();
        $snapshot = $registry->forScopes(['fixture:status']);
        $service = app(TokenService::class);
        $first = $service->createToken($actor->id(), 'First credential', ['fixture:status'], capabilities: $snapshot)['model'];
        $context = new ExecutionContext($actor->id(), $snapshot->grantId, 'default', 'operator', 'http', 'same-write', hrtime(true) + 10_000_000_000, $first->id, $first->credentialRevision());
        $directory = storage_path('framework/testing/grant-rotation-' . bin2hex(random_bytes(8)));
        $events = new FakeLifecycleObserver;
        $executor = new ActionExecutor($registry, app(GrantAuthorizer::class),
            new SchemaValidator,
            new LifecycleObservers(new NullLogger, [$events]), $this->app,
            new OperationCoordinator(
                new FileOperationStore($directory),
                app(Encrypter::class)), app(ActorScope::class), app(ExecutionAudit::class));
        GrantFixtureHandler::$calls = 0;
        $operation = $executor->prepare($action->id(), [], $context);
        $this->assertSame(0, GrantFixtureHandler::$calls);
        $context = $context->withOperationId($operation->id);
        try {
            $this->assertSame(['ok' => true], $executor->execute($action->id(), [], $context));
            $service->revokeToken($first->id);
            $second = $service->createToken($actor->id(), 'Rotated credential', ['fixture:status'], capabilities: $snapshot)['model'];
            $renewed = new ExecutionContext($actor->id(), $snapshot->grantId, 'default', 'operator', 'http', $operation->id, hrtime(true) + 10_000_000_000, $second->id, $second->credentialRevision());
            $this->assertSame(['ok' => true], $executor->execute($action->id(), [], $renewed));
            $this->assertSame(1, GrantFixtureHandler::$calls);
            $this->assertSame(['execution.started', 'execution.completed', 'execution.replayed'],
                array_map(fn ($event) => $event->eventName(), $events->events));
            $this->assertSame($events->events[0]->executionId, $events->events[1]->executionId);
            $this->assertNotSame($events->events[1]->executionId, $events->events[2]->executionId);
            $this->assertSame('none', $events->events[2]->effects);
            $service->revokeToken($second->id);
            $this->expectException(AuthorizationException::class);
            $executor->execute($action->id(), [], $renewed);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_secret_rotation_invalidates_authenticated_snapshots_but_preserves_current_grant_replay(): void
    {
        [$action, $token, $context, $store, $directory] = $this->secretRotationFixture();
        try {
            $executor = app(ActionExecutor::class);
            $operation = $executor->prepare($action->id(), [], $context);
            $context = $context->withOperationId($operation->id);
            $this->assertSame(['ok' => true], $executor->execute($action->id(), [], $context));
            $rotated = app(TokenService::class)->regenerateToken($token->id)['model'];
            $this->assertSame($token->id, $rotated->id);
            $this->assertSame($token->capabilities->grantId, $rotated->capabilities->grantId);
            try {
                $executor->execute($action->id(), [], $context);
                $this->fail('An old authenticated credential must not survive regeneration.');
            } catch (AuthorizationException) {
                $this->assertSame(1, GrantFixtureHandler::$calls);
            }
            request()->attributes->set('mcp_token', $rotated);
            $fresh = app(ContextFactory::class)->create('default', $operation->id);
            $this->assertSame(['ok' => true], $executor->execute($action->id(), [], $fresh));
            $this->assertSame(1, GrantFixtureHandler::$calls);
            $this->assertStringNotContainsString($fresh->credentialRevision, app(OperationCoordinator::class)->intent($store->find($operation->id))->toJson());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_secret_rotation_during_durable_admission_refuses_the_handler(): void
    {
        [$action, $token, $context, $store, $directory] = $this->secretRotationFixture();
        $audit = \Mockery::mock(DurableAuditStore::class);
        $admissions = 0;
        $audit->shouldReceive('writeDurable')->andReturnUsing(function (array $entry) use ($token, &$admissions): void {
            if ($entry['status'] === 'admitted') {
                $admissions++;
                app(TokenService::class)->regenerateToken($token->id);
            }
        });
        $this->app->instance(AuditStore::class, $audit);
        try {
            $executor = app(ActionExecutor::class);
            $operation = $executor->prepare($action->id(), [], $context);
            try {
                $executor->execute($action->id(), [], $context->withOperationId($operation->id));
                $this->fail('Regeneration must stop the handler before effects.');
            } catch (AuthorizationException) {
                $this->assertSame(1, $admissions);
                $this->assertSame(0, GrantFixtureHandler::$calls);
                $this->assertSame(OperationState::FailedNoEffect, $store->find($operation->id)->state);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_object_permission_revocation_during_admission_refuses_the_handler(): void
    {
        [$action, $token, $context, $store, $directory] = $this->secretRotationFixture();
        config(['statamic.editions.pro' => true]);
        Role::make()->handle('keep-grant')->permissions(['view mcp dashboard'])->save();
        Role::make()->handle('target-editor')->permissions(['edit target entries'])->save();
        $actor = User::find($context->actorId);
        $actor->makeSuper(false)->assignRole('keep-grant')->assignRole('target-editor')->save();
        $policy = \Mockery::mock(ActionPolicy::class);
        $policy->shouldReceive('authorize')->andReturnUsing(function (): void {
            if (! User::current()->hasPermission('edit target entries')) {
                throw new AuthorizationException('Target permission was revoked.');
            }
        });
        $policy->shouldReceive('filterResult')->andReturnUsing(fn ($input, $result) => $result);
        $this->app->instance(GrantFixturePolicy::class, $policy);
        $audit = \Mockery::mock(DurableAuditStore::class);
        $audit->shouldReceive('writeDurable')->andReturnUsing(function (array $entry) use ($context): void {
            if ($entry['status'] === 'admitted') {
                $changed = clone User::find($context->actorId);
                $changed->removeRole('target-editor')->save();
            }
        });
        $this->app->instance(AuditStore::class, $audit);
        try {
            $executor = app(ActionExecutor::class);
            $operation = $executor->prepare($action->id(), [], $context);
            try {
                $executor->execute($action->id(), [], $context->withOperationId($operation->id));
                $this->fail('Revoked target access must prevent the write.');
            } catch (AuthorizationException) {
                $this->assertSame(0, GrantFixtureHandler::$calls);
                $this->assertSame(OperationState::FailedNoEffect, $store->find($operation->id)->state);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function secretRotationFixture(): array
    {
        $this->deploy();
        config(['statamic.mcp.security.force_web_mode' => true]);
        request()->headers->set('X-MCP-Remote', 'true');
        $actor = User::make()->email('secret-rotation@example.test')->makeSuper();
        $actor->save();
        $action = new ActionDefinition('fixture/grants', 'edit', 1, 'Edit once.', ActionEffect::Edit,
            ['fixture:status'], ['type' => 'object', 'additionalProperties' => false],
            $this->definition()->outputSchema, GrantFixtureHandler::class, GrantFixturePolicy::class);
        $registry = new CapabilityRegistry(app(ScopeRegistry::class));
        $registry->register($action);
        $registry->freeze();
        $this->app->instance(CapabilityRegistry::class, $registry);
        $token = app(TokenService::class)->createToken($actor->id(), 'Rotate secret', ['fixture:status'],
            capabilities: $registry->forScopes(['fixture:status']))['model'];
        request()->attributes->set('mcp_token', $token);
        $context = app(ContextFactory::class)->create('default');
        $directory = storage_path('framework/testing/secret-rotation-' . bin2hex(random_bytes(8)));
        $store = new FileOperationStore($directory);
        $this->app->instance(OperationStore::class, $store);
        GrantFixtureHandler::$calls = 0;

        return [$action, $token, $context, $store, $directory];
    }

    public function test_registered_scopes_can_be_configured_as_oauth_defaults(): void
    {
        $this->deploy();
        $actor = User::make()->email('default-scope@example.test')->makeSuper();
        $actor->save();
        $this->actingAs($actor, 'web');
        config()->set('statamic.mcp.oauth.default_scopes', ['fixture:status']);
        $client = app(OAuthDriver::class)->registerClient('Defaults', ['https://example.com/callback']);
        $response = $this->get(cp_route('statamic-mcp.oauth.authorize') . '?' . http_build_query([
            'client_id' => $client->clientId, 'redirect_uri' => 'https://example.com/callback',
            'response_type' => 'code', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        ]));
        $response->assertOk()->assertSee('fixture:status');
    }

    public function test_control_panel_tokens_capture_only_displayed_integration_actions(): void
    {
        $this->deploy();
        $actor = User::make()->email('cp-consent@example.test')->makeSuper();
        $actor->save();
        $this->actingAs($actor, 'web');
        $page = $this->get(cp_route('statamic-mcp.dashboard'), ['X-Inertia' => 'true'])->assertOk();
        $offer = $page->json('props.capabilityOffer');
        $this->assertIsString($offer);
        $this->withCookie(config('session.cookie'), app('session')->getId())->withCredentials();
        $this->deploy(true);
        $body = ['name' => 'Personal addon', 'scopes' => ['fixture:status'], 'capability_offer' => $offer];
        $created = $this->postJson(cp_route('statamic-mcp.tokens.store'), $body)->assertCreated();
        $token = app(TokenService::class)->validateToken($created->json('token'));
        $this->assertSame(['fixture/grants/read' => $this->definition()->fingerprint()], $token->capabilities->actions);
        $second = $this->postJson(cp_route('statamic-mcp.tokens.store'), $body)->assertCreated();
        $other = app(TokenService::class)->validateToken($second->json('token'));
        $this->assertNotSame($token->capabilities->grantId, $other->capabilities->grantId);
        // Renaming from a form that resends addon scopes must not strip them or consent.
        $this->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $token->id]), [
            'name' => 'Renamed', 'scopes' => ['fixture:status'],
        ])->assertOk();
        $this->assertSame($token->capabilities->toStored(), app(TokenService::class)->validateToken($created->json('token'))->capabilities->toStored());
        $this->postJson(cp_route('statamic-mcp.tokens.store'), [
            'name' => 'No consent', 'scopes' => ['fixture:status'],
        ])->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->postJson(cp_route('statamic-mcp.tokens.store'), $body)->assertUnprocessable();
    }

    public function test_changing_a_registered_scope_requires_new_consent_even_for_super_users(): void
    {
        $registry = $this->deploy();
        $actor = User::make()->email('changed-scope@example.test')->makeSuper();
        $actor->save();
        $snapshot = $registry->forScopes(['fixture:status']);
        $token = app(TokenService::class)->createToken($actor->id(), 'Scope binding', ['fixture:status'], capabilities: $snapshot)['model'];
        $context = new ExecutionContext($actor->id(), $snapshot->grantId, 'default', 'operator', 'http', 'read', hrtime(true) + 10_000_000_000, $token->id, $token->credentialRevision());
        app(GrantAuthorizer::class)->authorize($this->definition(), $context);
        $changedScopes = new ScopeRegistry;
        $changedScopes->register('fixture/grants', 'fixture:status', 'Read integration status', ['access cp']);
        $changedScopes->freeze();
        $this->app->instance(ScopeRegistry::class, $changedScopes);
        $this->expectException(AuthorizationException::class);
        app(GrantAuthorizer::class)->authorize($this->definition(), $context);
    }

    public function test_wildcards_never_capture_addon_actions(): void
    {
        $registry = $this->deploy();
        $this->assertSame([], $registry->forScopes(['*'])->actions);
    }

    public function test_core_scope_names_are_reserved(): void
    {
        $this->expectException(\LogicException::class);
        (new ScopeRegistry)->register('fixture/grants', 'entries:read', 'Impersonate core');
    }

    public function test_unknown_scopes_cannot_be_minted_programmatically(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(TokenService::class)->createToken('actor', 'Invalid', ['nonexistent:scope']);
    }
}

class GrantFixtureHandler implements ActionHandler
{
    public static int $calls = 0;

    public function execute(array $input, ExecutionContext $context): array
    {
        self::$calls++;

        return ['ok' => true];
    }
}

class GrantFixturePolicy implements ActionPolicy
{
    public function authorize(array $input, ExecutionContext $context): void {}

    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return $result;
    }
}
