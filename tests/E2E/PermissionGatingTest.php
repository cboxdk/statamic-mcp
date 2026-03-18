<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\E2E;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesAuthenticatedUser;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Statamic\Facades\Collection;

class PermissionGatingTest extends TestCase
{
    use CreatesAuthenticatedUser;
    use RefreshDatabase;

    public function test_regular_user_denied_admin_page(): void
    {
        $response = $this->actingAsUser()
            ->get(cp_route('statamic-mcp.admin'));

        // Statamic may return 403 or redirect unauthorized users
        $this->assertTrue(
            in_array($response->getStatusCode(), [302, 403], true),
            "Expected 302 or 403, got {$response->getStatusCode()}"
        );
    }

    public function test_disabled_tool_returns_permission_denied_in_web_context(): void
    {
        Config::set('statamic.mcp.tools.entries.enabled', false);
        Config::set('statamic.mcp.web.enabled', true);

        // Simulate web MCP context
        request()->headers->set('X-MCP-Remote', 'true');

        $testId = bin2hex(random_bytes(4));
        $handle = "test-{$testId}";

        Collection::make($handle)->title('Test')->save();

        $router = new EntriesRouter;
        $result = $router->execute([
            'action' => 'list',
            'collection' => $handle,
        ]);

        $this->assertFalse($result['success']);

        /** @var array<int, string> $errors */
        $errors = $result['errors'] ?? [];
        $errorString = $result['error'] ?? '';
        $errorMessage = $errors[0] ?? (is_string($errorString) ? $errorString : '');
        $this->assertStringContainsString('disabled', $errorMessage);
    }

    public function test_expired_token_is_rejected_by_token_service(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/tokens');

        $this->app->singleton(TokenStore::class, DatabaseTokenStore::class);

        // Create a token via the service
        /** @var TokenService $tokenService */
        $tokenService = app(TokenService::class);

        $result = $tokenService->createToken(
            'test-user-id',
            'Expired Token',
            [TokenScope::ContentRead],
            \Illuminate\Support\Carbon::now()->addMinute(),
        );

        $plainToken = $result['token'];
        $tokenId = $result['model']->id;

        // Force-expire the token by updating its expiry to the past
        /** @var TokenStore $store */
        $store = app(TokenStore::class);
        $store->update($tokenId, ['expiresAt' => Carbon::now()->subDay()]);

        // Validate the expired token — should return null
        $validated = $tokenService->validateToken($plainToken);

        $this->assertNull($validated, 'Expired token should be rejected by TokenService');
    }

    public function test_super_admin_can_access_dashboard_and_admin(): void
    {
        // Use a single admin user to avoid Statamic Pro multi-user restriction
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->get(cp_route('statamic-mcp.dashboard'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(cp_route('statamic-mcp.admin'))
            ->assertOk();
    }
}
