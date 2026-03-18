<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\E2E;

use Cboxdk\StatamicMcp\Tests\Concerns\CreatesAuthenticatedUser;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Inertia\Testing\AssertableInertia;

class UserDashboardTest extends TestCase
{
    use CreatesAuthenticatedUser;

    public function test_authenticated_admin_can_access_user_dashboard(): void
    {
        $this->actingAsAdmin()
            ->get(cp_route('statamic-mcp.dashboard'))
            ->assertOk();
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get(cp_route('statamic-mcp.dashboard'))
            ->assertRedirect();
    }

    public function test_dashboard_props_include_tokens_scopes_and_clients(): void
    {
        $response = $this->actingAsAdmin()
            ->get(cp_route('statamic-mcp.dashboard'))
            ->assertOk();

        // @phpstan-ignore-next-line (assertInertia is a TestResponse macro registered by Inertia)
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tokens')
            ->has('availableScopes')
            ->has('clients')
        );
    }

    public function test_dashboard_props_do_not_include_admin_only_data(): void
    {
        $response = $this->actingAsAdmin()
            ->get(cp_route('statamic-mcp.dashboard'))
            ->assertOk();

        // @phpstan-ignore-next-line (assertInertia is a TestResponse macro registered by Inertia)
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('allTokens')
            ->missing('systemStats')
        );
    }
}
