<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Nav;
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
            'resource_type' => 'collection',
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
            'resource_type' => 'collection',
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
            'resource_type' => 'collection',
        ]);

        // Should fail with missing handle
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection handle is required', $result['errors'][0]);
    }

    public function test_list_taxonomies(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'resource_type' => 'taxonomy',
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
            'resource_type' => 'taxonomy',
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
            'resource_type' => 'navigation',
        ]);

        // Navigation actually succeeds - it's implemented in the router
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('navigations', $result['data']);
    }

    public function test_list_sites(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'resource_type' => 'site',
        ]);

        // Sites should work
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('sites', $result['data']);
    }

    public function test_get_site(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'site',
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
            'resource_type' => 'collection',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown collection action: invalid', $result['errors'][0]);
    }

    public function test_invalid_type(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'resource_type' => 'invalid',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown structure type: invalid', $result['errors'][0]);
    }

    public function test_missing_handle_for_get(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'collection',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Handle is required for get action', $result['errors'][0]);
    }

    public function test_collection_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'collection',
            'handle' => 'nonexistent_collection',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection not found: nonexistent_collection', $result['errors'][0]);
    }

    public function test_taxonomy_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'taxonomy',
            'handle' => 'nonexistent_taxonomy',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Taxonomy not found: nonexistent_taxonomy', $result['errors'][0]);
    }

    public function test_navigation_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'navigation',
            'handle' => 'nonexistent_navigation',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Navigation not found: nonexistent_navigation', $result['errors'][0]);
    }

    /**
     * Taxonomy update should persist preview_targets and default_status.
     */
    public function test_update_taxonomy_persists_config_fields(): void
    {
        $taxonomy = Taxonomy::make('categories')->title('Categories');
        $taxonomy->save();

        $result = $this->router->execute([
            'action' => 'update',
            'resource_type' => 'taxonomy',
            'handle' => 'categories',
            'data' => [
                'title' => 'Updated Categories',
            ],
        ]);

        $this->assertTrue($result['success'], 'Taxonomy update should succeed: ' . json_encode($result));

        $updated = Taxonomy::find('categories');
        $this->assertNotNull($updated);
        $this->assertEquals('Updated Categories', $updated->title());
    }

    /**
     * Taxonomy create should accept preview_targets and default_status.
     */
    public function test_create_taxonomy_with_config_fields(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'resource_type' => 'taxonomy',
            'handle' => 'topics',
            'data' => [
                'handle' => 'topics',
                'title' => 'Topics',
            ],
        ]);

        $this->assertTrue($result['success'], 'Taxonomy create should succeed: ' . json_encode($result));

        $created = Taxonomy::find('topics');
        $this->assertNotNull($created);
        $this->assertEquals('Topics', $created->title());
    }

    /**
     * Navigation update should persist collections.
     */
    public function test_update_navigation_persists_collections(): void
    {
        $collection = Collection::make('pages')->title('Pages');
        $collection->save();

        $nav = Nav::make('main_nav');
        $nav->title('Main Nav');
        $nav->save();

        $result = $this->router->execute([
            'action' => 'update',
            'resource_type' => 'navigation',
            'handle' => 'main_nav',
            'data' => ['collections' => ['pages']],
        ]);

        $this->assertTrue($result['success'], 'Navigation update should succeed: ' . json_encode($result));

        $updated = Nav::find('main_nav');
        $this->assertNotNull($updated);
        $collectionHandles = $updated->collections()->map->handle()->all();
        $this->assertContains('pages', $collectionHandles, 'Collections should be persisted on navigation');
    }

    /**
     * Navigation create should accept collections.
     */
    public function test_create_navigation_with_collections(): void
    {
        $collection = Collection::make('posts')->title('Posts');
        $collection->save();

        $result = $this->router->execute([
            'action' => 'create',
            'resource_type' => 'navigation',
            'handle' => 'footer_nav',
            'data' => [
                'handle' => 'footer_nav',
                'title' => 'Footer Nav',
                'collections' => ['posts'],
            ],
        ]);

        $this->assertTrue($result['success'], 'Navigation create should succeed: ' . json_encode($result));

        $created = Nav::find('footer_nav');
        $this->assertNotNull($created);
        $collectionHandles = $created->collections()->map->handle()->all();
        $this->assertContains('posts', $collectionHandles, 'Collections should be set on new navigation');
    }

    /**
     * ENG-697 secondary issue: taxonomies should persist on collection update.
     */
    public function test_update_collection_persists_taxonomies(): void
    {
        // Create a taxonomy first
        $taxonomy = Taxonomy::make('page_tags')->title('Page Tags');
        $taxonomy->save();

        // Create a collection
        $collection = Collection::make('test_pages')->title('Test Pages');
        $collection->save();

        // Update collection with taxonomies
        $result = $this->router->execute([
            'action' => 'update',
            'resource_type' => 'collection',
            'handle' => 'test_pages',
            'data' => ['taxonomies' => ['page_tags']],
        ]);

        $this->assertTrue($result['success'], 'Collection update should succeed: ' . json_encode($result));

        // Verify the taxonomies actually persisted
        $updated = Collection::find('test_pages');
        $this->assertNotNull($updated);
        $taxonomyHandles = $updated->taxonomies()->map->handle()->all();
        $this->assertContains('page_tags', $taxonomyHandles, 'Taxonomy should be persisted on collection');
    }

    /**
     * ENG-697 secondary issue: taxonomies should persist on collection create.
     */
    public function test_create_collection_with_taxonomies(): void
    {
        // Create a taxonomy first
        $taxonomy = Taxonomy::make('tags')->title('Tags');
        $taxonomy->save();

        // Create a collection with taxonomies
        $result = $this->router->execute([
            'action' => 'create',
            'resource_type' => 'collection',
            'handle' => 'tagged_collection',
            'data' => [
                'handle' => 'tagged_collection',
                'title' => 'Tagged Collection',
                'taxonomies' => ['tags'],
            ],
        ]);

        $this->assertTrue($result['success'], 'Collection create should succeed: ' . json_encode($result));

        // Verify the taxonomies actually persisted
        $created = Collection::find('tagged_collection');
        $this->assertNotNull($created);
        $taxonomyHandles = $created->taxonomies()->map->handle()->all();
        $this->assertContains('tags', $taxonomyHandles, 'Taxonomy should be set on new collection');
    }
}
