<?php

namespace Cboxdk\StatamicMcp\Tests\Tools;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;

class GlobalToolsIntegrationTest extends TestCase
{
    public function test_content_router_handles_global_operations(): void
    {
        $router = new ContentRouter;

        // Test that ContentRouter handles globals
        $this->assertEquals('statamic-content', $router->name());
        $this->assertStringContainsString('content', $router->description());
    }

    public function test_content_router_supports_global_actions(): void
    {
        $router = new ContentRouter;

        // Test list globals operation
        $response = $router->execute([
            'action' => 'list',
            'type' => 'global',
        ]);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        // Global operations are now implemented, so should be true
        $this->assertTrue($response['success']);
    }

    public function test_global_operations_follow_content_router_pattern(): void
    {
        $router = new ContentRouter;

        // Test that ContentRouter validates global operations properly
        $response = $router->execute([
            'action' => 'list',
            'type' => 'global',
        ]);

        // Global operations are now implemented and should work
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('globals', $response['data']);
    }

    public function test_content_router_provides_global_metadata(): void
    {
        $router = new ContentRouter;

        $response = $router->execute([
            'action' => 'list',
            'type' => 'global',
        ]);

        // Check metadata structure (even on error responses)
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayHasKey('tool', $response['meta']);
        $this->assertEquals('statamic-content', $response['meta']['tool']);
    }

    public function test_global_operations_require_valid_type(): void
    {
        $router = new ContentRouter;

        // Test invalid type parameter
        $response = $router->execute([
            'action' => 'list',
            'type' => 'invalid_type',
        ]);

        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('errors', $response);
    }
}
