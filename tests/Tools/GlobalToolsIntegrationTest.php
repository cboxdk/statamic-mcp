<?php

namespace Cboxdk\StatamicMcp\Tests\Tools;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;

class GlobalToolsIntegrationTest extends TestCase
{
    public function test_globals_router_handles_global_operations(): void
    {
        $router = new GlobalsRouter;

        // Test that GlobalsRouter handles globals
        $this->assertEquals('statamic-globals', $router->name());
        $this->assertStringContainsString('global', $router->description());
    }

    public function test_globals_router_supports_global_actions(): void
    {
        $router = new GlobalsRouter;

        // Test list globals operation
        $response = $router->execute([
            'action' => 'list',
        ]);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        // Global operations are now implemented, so should be true
        $this->assertTrue($response['success']);
    }

    public function test_global_operations_follow_globals_router_pattern(): void
    {
        $router = new GlobalsRouter;

        // Test that GlobalsRouter handles global operations properly
        $response = $router->execute([
            'action' => 'list',
        ]);

        // Global operations are now implemented and should work
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('globals', $response['data']);
    }

    public function test_globals_router_provides_global_metadata(): void
    {
        $router = new GlobalsRouter;

        $response = $router->execute([
            'action' => 'list',
        ]);

        // Check metadata structure (even on error responses)
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayHasKey('tool', $response['meta']);
        $this->assertEquals('statamic-globals', $response['meta']['tool']);
    }

    public function test_global_operations_require_valid_action(): void
    {
        $router = new GlobalsRouter;

        // Test invalid action parameter
        $response = $router->execute([
            'action' => 'invalid_action',
        ]);

        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('errors', $response);
    }
}
