<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\E2E;

use Cboxdk\StatamicMcp\Tests\Concerns\CreatesAuthenticatedUser;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Inertia\Testing\AssertableInertia;

class AdminDashboardTest extends TestCase
{
    use CreatesAuthenticatedUser;

    public function test_super_admin_can_access_admin_dashboard(): void
    {
        $this->actingAsAdmin()
            ->get(cp_route('statamic-mcp.admin'))
            ->assertOk();
    }

    public function test_regular_user_is_denied_admin_dashboard(): void
    {
        $response = $this->actingAsUser()
            ->get(cp_route('statamic-mcp.admin'));

        // Statamic may return 403 or redirect unauthorized users
        $this->assertTrue(
            in_array($response->getStatusCode(), [302, 403], true),
            "Expected 302 or 403, got {$response->getStatusCode()}"
        );
    }

    public function test_unauthenticated_user_is_redirected_from_admin(): void
    {
        $this->get(cp_route('statamic-mcp.admin'))
            ->assertRedirect();
    }

    public function test_admin_dashboard_props_include_admin_data(): void
    {
        $response = $this->actingAsAdmin()
            ->get(cp_route('statamic-mcp.admin'))
            ->assertOk();

        // @phpstan-ignore-next-line (assertInertia is a TestResponse macro registered by Inertia)
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('allTokens')
            ->has('availableUsers')
            ->has('systemStats')
        );
    }
}
