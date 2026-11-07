<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Actions;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\ActionExecutor;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\LifecycleObservers;
use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\SchemaValidator;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Authorization\GrantAuthorizer;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
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
        $context = new ExecutionContext($actor->id(), $token->capabilities->grantId, 'default', 'operator', 'http', 'read', hrtime(true) + 10_000_000_000, $token->id);
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
        $context = new ExecutionContext($actor->id(), $renewed->capabilities->grantId, 'default', 'operator', 'http', 'read', hrtime(true) + 10_000_000_000, $renewed->id);
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
        $context = new ExecutionContext($actor->id(), $snapshot->grantId, 'default', 'operator', 'http', 'same-write', hrtime(true) + 10_000_000_000, $first->id);
        $directory = storage_path('framework/testing/grant-rotation-' . bin2hex(random_bytes(8)));
        $executor = new ActionExecutor($registry, app(GrantAuthorizer::class),
            new SchemaValidator,
            new LifecycleObservers(new NullLogger), $this->app,
            new OperationCoordinator(
                new FileOperationStore($directory),
                app(Encrypter::class)));
        GrantFixtureHandler::$calls = 0;
        try {
            $this->assertSame(['ok' => true], $executor->execute($action->id(), [], $context));
            $service->revokeToken($first->id);
            $second = $service->createToken($actor->id(), 'Rotated credential', ['fixture:status'], capabilities: $snapshot)['model'];
            $renewed = new ExecutionContext($actor->id(), $snapshot->grantId, 'default', 'operator', 'http', 'same-write', hrtime(true) + 10_000_000_000, $second->id);
            $this->assertSame(['ok' => true], $executor->execute($action->id(), [], $renewed));
            $this->assertSame(1, GrantFixtureHandler::$calls);
            $service->revokeToken($second->id);
            $this->expectException(AuthorizationException::class);
            $executor->execute($action->id(), [], $renewed);
        } finally {
            File::deleteDirectory($directory);
        }
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
        $context = new ExecutionContext($actor->id(), $snapshot->grantId, 'default', 'operator', 'http', 'read', hrtime(true) + 10_000_000_000, $token->id);
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
