<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\E2E;

use Cboxdk\StatamicMcp\Auth\TokenService;
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

    public function test_numbers_from_a_published_config_may_be_strings(): void
    {
        // A config published before v4 read these straight from env(), which
        // yields strings; the admin page answered 500 on the rate limit.
        config([
            'statamic.mcp.rate_limit.max_attempts' => '90',
            'statamic.mcp.security.max_token_lifetime_days' => '30',
            'statamic.mcp.security.tool_timeout_seconds' => '20',
        ]);

        $this->actingAsAdmin()
            ->get(cp_route('statamic-mcp.admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('systemStats.rate_limit_max', 90));

        $this->get(cp_route('statamic-mcp.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('maxTokenLifetimeDays', 30));
    }

    public function test_only_the_owner_is_offered_regenerate(): void
    {
        $this->actingAsAdmin();
        app(TokenService::class)->createToken((string) auth()->id(), 'Mine', ['entries:read']);
        app(TokenService::class)->createToken('someone-else', 'Theirs', ['entries:read']);

        $this->get(cp_route('statamic-mcp.admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('allTokens', fn ($tokens) => collect($tokens)->pluck('is_own', 'name')->sortKeys()->all() === ['Mine' => true, 'Theirs' => false]));
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
