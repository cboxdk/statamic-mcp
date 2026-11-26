<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\E2E;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesAuthenticatedUser;
use Cboxdk\StatamicMcp\Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use CreatesAuthenticatedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/tokens');

        $this->app->singleton(TokenStore::class, DatabaseTokenStore::class);
    }

    public function test_create_token_returns_token_data(): void
    {
        $this->actingAsAdmin()
            ->postJson(cp_route('statamic-mcp.tokens.store'), [
                'name' => 'Test Token',
                'scopes' => ['entries:read'],
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'token',
                'model' => ['id', 'name', 'scopes', 'created_at'],
                'message',
            ]);
    }

    public function test_update_token_succeeds(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $createResponse = $this->postJson(cp_route('statamic-mcp.tokens.store'), [
            'name' => 'Original Name',
            'scopes' => ['entries:read'],
        ]);

        $tokenId = $createResponse->json('model.id');

        $this->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $tokenId]), [
            'name' => 'Updated Name',
        ])
            ->assertOk()
            ->assertJsonPath('model.name', 'Updated Name');
    }

    public function test_regenerate_token_returns_new_token(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $createResponse = $this->postJson(cp_route('statamic-mcp.tokens.store'), [
            'name' => 'Regenerate Me',
            'scopes' => ['entries:read'],
        ]);

        $tokenId = $createResponse->json('model.id');
        $originalToken = $createResponse->json('token');

        $regenerateResponse = $this->postJson(cp_route('statamic-mcp.tokens.regenerate', ['token' => $tokenId]))
            ->assertOk()
            ->assertJsonStructure(['token', 'model', 'message']);

        $this->assertNotEquals($originalToken, $regenerateResponse->json('token'));
    }

    public function test_delete_token_removes_it(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $createResponse = $this->postJson(cp_route('statamic-mcp.tokens.store'), [
            'name' => 'Delete Me',
            'scopes' => ['entries:read'],
        ]);

        $tokenId = $createResponse->json('model.id');

        $this->deleteJson(cp_route('statamic-mcp.tokens.destroy', ['token' => $tokenId]))
            ->assertOk()
            ->assertJsonPath('message', 'Token revoked successfully.');
    }

    public function test_editing_a_token_keeps_it_within_the_lifetime_cap(): void
    {
        config(['statamic.mcp.security.max_token_lifetime_days' => 30]);
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);
        $tokenId = $this->postJson(cp_route('statamic-mcp.tokens.store'), ['name' => 'Capped', 'scopes' => ['entries:read']])->json('model.id');

        // Clearing the expiry would make the token immortal under a cap.
        $this->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $tokenId]), ['clear_expiry' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');

        // A date past the cap is brought back to it, as at creation.
        $expires = $this->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $tokenId]), ['expires_at' => now()->addYears(5)->toIso8601String()])
            ->assertOk()
            ->json('model.expires_at');
        $this->assertTrue(Carbon::parse($expires)->lessThanOrEqualTo(now()->addDays(30)->addMinute()), (string) $expires);

        // Renaming a token is unaffected.
        $this->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $tokenId]), ['name' => 'Renamed'])->assertOk();
    }

    public function test_without_a_cap_a_token_may_be_made_permanent(): void
    {
        config(['statamic.mcp.security.max_token_lifetime_days' => null]);
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);
        $tokenId = $this->postJson(cp_route('statamic-mcp.tokens.store'), ['name' => 'Open', 'scopes' => ['entries:read'], 'expires_at' => now()->addDay()->toIso8601String()])->json('model.id');

        $this->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $tokenId]), ['clear_expiry' => true])
            ->assertOk()
            ->assertJsonPath('model.expires_at', null);
    }

    public function test_an_oauth_token_keeps_its_own_expiry(): void
    {
        config(['statamic.mcp.security.max_token_lifetime_days' => null]);
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);
        $token = app(TokenService::class)->createToken((string) $admin->id(), 'Client (OAuth)', ['entries:read'], now()->addDay(), 'client-1', 'Client')['model'];

        foreach ([['clear_expiry' => true], ['expires_at' => now()->addMonth()->toIso8601String()]] as $change) {
            $this->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $token->id]), $change)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('expires_at');
        }
        $this->assertTrue(app(TokenStore::class)->find($token->id)?->expiresAt?->equalTo($token->expiresAt));
    }

    public function test_create_token_without_name_fails_validation(): void
    {
        $this->actingAsAdmin()
            ->postJson(cp_route('statamic-mcp.tokens.store'), [
                'scopes' => ['entries:read'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
}
