<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;

class SystemRouterTest extends TestCase
{
    private SystemRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new SystemRouter;
    }

    public function test_get_health_status(): void
    {
        $result = $this->router->execute([
            'action' => 'health',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('health', $result['data']);

        $health = $result['data']['health'];
        $this->assertArrayHasKey('overall_status', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertArrayHasKey('checks', $health);
        $this->assertContains($health['overall_status'], ['healthy', 'degraded', 'unhealthy']);
    }

    public function test_clear_cache_all(): void
    {
        $result = $this->router->execute([
            'action' => 'cache_clear',
            'cache_type' => 'all',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('cache_cleared', $result['data']);
    }

    public function test_get_system_info_without_details(): void
    {
        $result = $this->router->execute([
            'action' => 'info',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('system_info', $result['data']);

        $info = $result['data']['system_info'];
        $this->assertArrayHasKey('environment', $info);

        // include_details defaults to false, so counts should not be present
        $this->assertArrayNotHasKey('collections_count', $info);
    }

    public function test_get_system_info_with_details(): void
    {
        $result = $this->router->execute([
            'action' => 'info',
            'include_details' => true,
        ]);

        $this->assertTrue($result['success']);
        $info = $result['data']['system_info'];

        // With include_details, counts should be present
        $this->assertArrayHasKey('collections_count', $info);
        $this->assertArrayHasKey('taxonomies_count', $info);
        $this->assertArrayHasKey('users_count', $info);
        $this->assertArrayHasKey('sites', $info);
        $this->assertIsArray($info['sites']);
    }

    public function test_invalid_action(): void
    {
        $result = $this->router->execute([
            'action' => 'invalid_action',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("Unknown action 'invalid_action'. Use one of: info, health", $result['errors'][0]);
    }

    public function test_an_argument_the_action_does_not_take_is_refused(): void
    {
        // System has no resource types. v3 ignored one; v4 says so, because a
        // read that drops an argument answers a question nobody asked.
        $result = $this->router->execute([
            'action' => 'health',
            'resource_type' => 'invalid_type',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not take resource_type', $result['errors'][0]);
    }

    public function test_cache_clear_without_type_defaults_to_all(): void
    {
        $result = $this->router->execute([
            'action' => 'cache_clear',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('cache_cleared', $result['data']);
    }

    public function test_cache_clear_specific_type(): void
    {
        $result = $this->router->execute([
            'action' => 'cache_clear',
            'cache_type' => 'views',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('cache_cleared', $result['data']);
    }

    public function test_missing_config_key(): void
    {
        $result = $this->router->execute([
            'action' => 'config_get',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The config_get action needs config_key', $result['errors'][0]);
    }

    public function test_config_get_allowed_key(): void
    {
        $result = $this->router->execute([
            'action' => 'config_get',
            'config_key' => 'app.name',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('config', $result['data']);
        $this->assertEquals('app.name', $result['data']['config']['key']);
    }

    public function test_config_get_restricted_key(): void
    {
        $result = $this->router->execute([
            'action' => 'config_get',
            'config_key' => 'database.connections',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('restricted', $result['errors'][0]);
    }

    public function test_config_get_allowed_cp_route_key(): void
    {
        $result = $this->router->execute([
            'action' => 'config_get',
            'config_key' => 'statamic.cp.route',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('statamic.cp.route', $result['data']['config']['key']);
    }

    public function test_config_get_rejects_broad_statamic(): void
    {
        // Broad statamic namespace keys should be rejected
        $result = $this->router->execute([
            'action' => 'config_get',
            'config_key' => 'statamic.sites',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('restricted', $result['errors'][0]);
    }

    public function test_config_get_rejects_app_url(): void
    {
        // app.url can reveal internal infrastructure details
        $result = $this->router->execute([
            'action' => 'config_get',
            'config_key' => 'app.url',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('restricted', $result['errors'][0]);
    }

    public function test_config_get_rejects_app_env(): void
    {
        $result = $this->router->execute([
            'action' => 'config_get',
            'config_key' => 'app.env',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('restricted', $result['errors'][0]);
    }

    public function test_cache_status(): void
    {
        $result = $this->router->execute([
            'action' => 'cache_status',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('cache_status', $result['data']);
    }

    public function test_cache_warm(): void
    {
        $result = $this->router->execute([
            'action' => 'cache_warm',
        ]);

        // Cache warming may fail in test environment (no stache:warm command)
        if ($result['success']) {
            $this->assertArrayHasKey('data', $result);
            $this->assertArrayHasKey('cache_warmed', $result['data']);
        } else {
            $this->assertNotEmpty($result['errors'][0]);
        }
    }

    public function test_config_get_rejects_broad_cp_namespace(): void
    {
        // The broad 'statamic.cp' key should no longer be allowed
        // Only specific sub-keys like 'statamic.cp.route' are permitted
        $result = $this->router->execute([
            'action' => 'config_get',
            'config_key' => 'statamic.cp',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('restricted', $result['errors'][0]);
    }

    public function test_config_get_restricts_broad_mcp_key(): void
    {
        // The broad 'statamic.mcp' key should no longer be allowed
        // Only specific sub-keys like 'statamic.mcp.web.enabled' are permitted
        $result = $this->router->execute([
            'action' => 'config_get',
            'config_key' => 'statamic.mcp',
        ]);

        $this->assertFalse($result['success']);
    }

    public function test_system_info_performance_metrics(): void
    {
        // Define LARAVEL_START if not already defined (test environment)
        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        $result = $this->router->execute([
            'action' => 'info',
            'include_details' => false,
            'include_performance' => true,
        ]);

        $this->assertTrue($result['success']);
        $info = $result['data']['system_info'];
        $this->assertArrayHasKey('memory_usage', $info);
        $this->assertArrayHasKey('current', $info['memory_usage']);
        $this->assertArrayHasKey('peak', $info['memory_usage']);
        $this->assertArrayHasKey('execution_time', $info);
    }
}
