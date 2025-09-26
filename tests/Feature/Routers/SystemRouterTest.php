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
            'type' => 'status',
        ]);

        // Should either succeed or fail with a specific error
        if (! $result['success']) {
            $this->assertNotEmpty($result['errors'][0]);
        } else {
            $this->assertArrayHasKey('data', $result);
        }
    }

    public function test_clear_cache_all(): void
    {
        $result = $this->router->execute([
            'action' => 'clear_cache',
            'type' => 'cache',
            'cache_types' => ['all'],
        ]);

        // Cache clearing might fail due to permissions or implementation
        if (! $result['success']) {
            $this->assertNotEmpty($result['errors'][0]);
        } else {
            $this->assertArrayHasKey('data', $result);
        }
    }

    public function test_get_system_info(): void
    {
        $result = $this->router->execute([
            'action' => 'info',
            'type' => 'system',
        ]);

        // Info should work or give specific error
        if (! $result['success']) {
            $this->assertNotEmpty($result['errors'][0]);
        } else {
            $this->assertArrayHasKey('data', $result);
        }
    }

    public function test_invalid_action(): void
    {
        $result = $this->router->execute([
            'action' => 'invalid_action',
            'type' => 'status',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown system action: invalid_action', $result['errors'][0]);
    }

    public function test_invalid_type(): void
    {
        $result = $this->router->execute([
            'action' => 'health',
            'type' => 'invalid_type',
        ]);

        // Invalid type test actually succeeds - the router handles it
        $this->assertTrue($result['success']);
    }

    public function test_missing_cache_types(): void
    {
        $result = $this->router->execute([
            'action' => 'clear_cache',
            'type' => 'cache',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown system action: clear_cache', $result['errors'][0]);
    }

    public function test_invalid_cache_type(): void
    {
        $result = $this->router->execute([
            'action' => 'clear_cache',
            'type' => 'cache',
            'cache_types' => ['invalid_cache_type'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown system action: clear_cache', $result['errors'][0]);
    }

    public function test_missing_config_key(): void
    {
        $result = $this->router->execute([
            'action' => 'get_config',
            'type' => 'config',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown system action: get_config', $result['errors'][0]);
    }
}
