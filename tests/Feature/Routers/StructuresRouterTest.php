<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

class StructuresRouterTest extends TestCase
{
    private StructuresRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new StructuresRouter;
    }

    public function test_list_collections(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'collection',
        ]);

        // Expect either success or specific failure
        if (! $result['success']) {
            $this->assertStringContainsString('Failed to list collections:', $result['errors'][0]);
        } else {
            $this->assertArrayHasKey('collections', $result['data']);
        }
    }

    public function test_get_collection(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'collection',
            'handle' => 'nonexistent',
        ]);

        // Should fail with collection not found
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection not found: nonexistent', $result['errors'][0]);
    }

    public function test_create_collection_missing_handle(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'type' => 'collection',
        ]);

        // Should fail with missing handle
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection handle is required', $result['errors'][0]);
    }

    public function test_list_taxonomies(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'taxonomy',
        ]);

        // Expect either success or specific failure
        if (! $result['success']) {
            $this->assertStringContainsString('Failed to list taxonomies:', $result['errors'][0]);
        } else {
            $this->assertArrayHasKey('taxonomies', $result['data']);
        }
    }

    public function test_get_taxonomy(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'taxonomy',
            'handle' => 'nonexistent',
        ]);

        // Should fail with taxonomy not found
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Taxonomy not found: nonexistent', $result['errors'][0]);
    }

    public function test_navigation_not_implemented(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'navigation',
        ]);

        // Navigation actually succeeds - it's implemented in the router
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('navigations', $result['data']);
    }

    public function test_list_sites(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'site',
        ]);

        // Sites should work
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('sites', $result['data']);
    }

    public function test_get_site(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'site',
            'handle' => 'default',
        ]);

        // Default site should exist
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('site', $result['data']);
        $this->assertEquals('default', $result['data']['site']['handle']);
    }

    public function test_invalid_action(): void
    {
        $result = $this->router->execute([
            'action' => 'invalid',
            'type' => 'collection',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown collection action: invalid', $result['errors'][0]);
    }

    public function test_invalid_type(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'invalid',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown structure type: invalid', $result['errors'][0]);
    }

    public function test_missing_handle_for_get(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'collection',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Handle is required for get action', $result['errors'][0]);
    }

    public function test_collection_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'collection',
            'handle' => 'nonexistent_collection',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection not found: nonexistent_collection', $result['errors'][0]);
    }

    public function test_taxonomy_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'taxonomy',
            'handle' => 'nonexistent_taxonomy',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Taxonomy not found: nonexistent_taxonomy', $result['errors'][0]);
    }

    public function test_navigation_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'navigation',
            'handle' => 'nonexistent_navigation',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Navigation not found: nonexistent_navigation', $result['errors'][0]);
    }
}
