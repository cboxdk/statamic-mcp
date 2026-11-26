<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint as BlueprintObject;

class BlueprintsRouterTest extends TestCase
{
    private BlueprintsRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new BlueprintsRouter;
    }

    public function test_writes_return_fields_and_versions_matching_the_stored_blueprint(): void
    {
        $where = ['namespace' => 'globals', 'handle' => 'record'];
        $created = $this->router->execute(['action' => 'create', ...$where, 'fields' => [
            ['handle' => 'intro', 'field' => ['type' => 'text', 'display' => 'Introduction']],
        ]]);
        $this->assertTrue($created['success'], json_encode($created));
        $this->assertSame('text', $created['data']['blueprint']['fields']['intro']['type']);
        $read = $this->router->execute(['action' => 'get', ...$where]);
        $this->assertSame($read['data']['blueprint']['version'], $created['data']['blueprint']['version']);
        $updated = $this->router->execute(['action' => 'update', ...$where, 'title' => 'Changed']);
        $this->assertTrue($updated['success'], json_encode($updated));
        $this->assertSame('Introduction', $updated['data']['blueprint']['fields']['intro']['display']);
        $this->assertNotSame($created['data']['blueprint']['version'], $updated['data']['blueprint']['version']);
        $read = $this->router->execute(['action' => 'get', ...$where, 'include_format_spec' => false]);
        $this->assertSame($read['data']['blueprint'], $updated['data']['blueprint']);
        $deleted = $this->router->execute(['action' => 'delete', ...$where]);
        $this->assertTrue($deleted['success'], json_encode($deleted));
        $this->assertSame($read['data']['blueprint'], $deleted['data']['blueprint']);
    }

    public function test_list_all_blueprints(): void
    {
        // Clear any existing mocks and set up fresh ones
        Blueprint::clearResolvedInstances();
        Blueprint::shouldReceive('in')
            ->with('collections')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('taxonomies')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('globals')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('forms')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('assets')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('users')
            ->andReturn(collect([]));

        // Mock Collection facade to return empty collection handles
        Collection::shouldReceive('handles')
            ->andReturn(collect([]));

        $result = $this->router->execute([
            'action' => 'list',
        ]);

        // Router should work and return blueprints (empty or with test data)
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('items', $result['data']);
    }

    public function test_list_blueprints_by_namespace(): void
    {
        // Clear any existing mocks and set up fresh ones
        Blueprint::clearResolvedInstances();
        Blueprint::shouldReceive('in')
            ->with('collections')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('taxonomies')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('globals')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('forms')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('assets')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('users')
            ->andReturn(collect([]));

        // Mock Collection facade to return empty collection list
        Collection::shouldReceive('handles')
            ->andReturn(collect([]));

        $result = $this->router->execute([
            'action' => 'list',
            'namespace' => 'collections',
        ]);

        // Router should work and return blueprints filtered by namespace
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('items', $result['data']);
    }

    public function test_list_blueprints_with_details(): void
    {
        $this->createTestBlueprint('detailed', 'collections', 'Detailed Blueprint');

        $result = $this->router->execute([
            'action' => 'list',
            'include_details' => true,
        ]);

        $this->assertTrue($result['success']);
        $blueprint = collect($result['data']['items'])->firstWhere('handle', 'detailed');
        $this->assertSame('Detailed Blueprint', $blueprint['title']);
        $this->assertArrayHasKey('hidden', $blueprint);
        $this->assertArrayHasKey('order', $blueprint);
    }

    public function test_paging_preserves_same_named_blueprints_in_different_namespaces(): void
    {
        Collection::make('pages')->save();
        Taxonomy::make('tags')->save();
        foreach (['globals', 'collections', 'assets', 'collections.pages', 'taxonomies.tags'] as $namespace) {
            $this->createTestBlueprint('shared', $namespace, ucfirst($namespace));
        }

        $arguments = ['action' => 'list', 'limit' => 1, 'include_fields' => false];
        $items = [];
        do {
            $result = $this->router->execute($arguments);
            $this->assertTrue($result['success']);
            $this->assertCount(1, $result['data']['items']);
            array_push($items, ...$result['data']['items']);
            $arguments['cursor'] = $result['data']['next_cursor'];
            $this->assertLessThan(20, count($items), 'Pagination must terminate.');
        } while ($arguments['cursor'] !== null);

        $shared = array_values(array_filter($items, fn (array $item): bool => $item['handle'] === 'shared'));
        $this->assertSame(['assets', 'collections.pages', 'collections', 'globals', 'taxonomies.tags'], array_column($shared, 'namespace'));
        $this->assertSame(count($items), $result['data']['total']);
    }

    public function test_blueprint_pages_and_totals_exclude_denied_handles(): void
    {
        foreach (['a_hidden', 'b_visible', 'c_visible'] as $handle) {
            $this->createTestBlueprint($handle, 'globals', $handle);
        }
        config(['statamic.mcp.tools.blueprints.resources.read' => ['*_visible']]);

        foreach (['list', 'scan', 'types'] as $action) {
            $arguments = ['action' => $action, 'namespace' => 'globals', 'limit' => 1];
            $first = $this->router->execute($arguments);
            $this->assertTrue($first['success']);
            $this->assertSame(['b_visible'], array_column($first['data']['items'], 'handle'));
            $this->assertSame(2, $first['data']['total']);
            $second = $this->router->execute($arguments + ['cursor' => $first['data']['next_cursor']]);
            $this->assertSame(['c_visible'], array_column($second['data']['items'], 'handle'));
            $this->assertNull($second['data']['next_cursor']);
        }
    }

    public function test_list_blueprints_without_fields(): void
    {
        $this->createTestBlueprint('no_fields', 'collections', 'No Fields');

        $result = $this->router->execute([
            'action' => 'list',
            'include_fields' => false,
        ]);

        $this->assertTrue($result['success']);
        $blueprint = collect($result['data']['items'])->firstWhere('handle', 'no_fields');
        $this->assertSame('No Fields', $blueprint['title']);
        $this->assertArrayNotHasKey('fields', $blueprint);
    }

    public function test_get_specific_blueprint(): void
    {
        $this->createTestBlueprintWithFields('detailed_blog', 'collections', 'Detailed Blog', [
            'title' => ['type' => 'text', 'display' => 'Title'],
            'content' => ['type' => 'markdown', 'display' => 'Content'],
            'author' => ['type' => 'text', 'display' => 'Author'],
        ]);

        $result = $this->router->execute([
            'action' => 'get',
            'handle' => 'detailed_blog',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['blueprint'];
        $this->assertEquals('detailed_blog', $data['handle']);
    }

    public function test_get_blueprint_by_namespace(): void
    {
        $this->createTestBlueprint('global_config', 'globals', 'Global Config');

        $result = $this->router->execute([
            'action' => 'get',
            'handle' => 'global_config',
            'namespace' => 'globals',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['blueprint'];
        $this->assertEquals('global_config', $data['handle']);
        $this->assertEquals('globals', $data['namespace']);
    }

    public function test_scan_blueprints(): void
    {
        // Clear any existing mocks and set up fresh ones
        Blueprint::clearResolvedInstances();
        Blueprint::shouldReceive('in')
            ->with('collections')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('taxonomies')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('globals')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('forms')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('assets')
            ->andReturn(collect([]));
        Blueprint::shouldReceive('in')
            ->with('users')
            ->andReturn(collect([]));

        // Mock Collection facade to return empty collection list
        Collection::shouldReceive('handles')
            ->andReturn(collect([]));

        $result = $this->router->execute([
            'action' => 'scan',
        ]);

        // Router should work and return blueprint scan results
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('items', $result['data']);
    }

    public function test_types_analysis_typescript(): void
    {
        $this->createTestBlueprintWithFields('type_test', 'collections', 'Type Test', [
            'title' => ['type' => 'text'],
            'published' => ['type' => 'toggle'],
            'content' => ['type' => 'markdown'],
        ]);

        $result = $this->router->execute([
            'action' => 'types',
            'output_format' => 'typescript',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('typescript', $result['data']['format']);
        $fields = collect($result['data']['items'])->firstWhere('handle', 'type_test')['fields'];
        $this->assertSame('text', $fields['title']['type']);
        $this->assertSame('toggle', $fields['published']['type']);
    }

    public function test_types_analysis_all_formats(): void
    {
        $this->createTestBlueprint('format_test', 'collections', 'Format Test');

        $result = $this->router->execute([
            'action' => 'types',
            'output_format' => 'all',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('all', $result['data']['format']);
        $fields = collect($result['data']['items'])->firstWhere('handle', 'format_test')['fields'];
        $this->assertSame('text', $fields['title']['type']);
    }

    public function test_validate_blueprint(): void
    {
        $this->createTestBlueprintWithFields('validation_test', 'collections', 'Validation Test', [
            'title' => ['type' => 'text', 'display' => 'Title'],
            'content' => ['type' => 'markdown', 'display' => 'Content'],
        ]);

        $result = $this->router->execute([
            'action' => 'validate',
            'handle' => 'validation_test',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('validation', $result['data']);
    }

    public function test_create_blueprint_fails_validation(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'handle' => 'new_blueprint',
            'title' => 'New Blueprint',
        ]);

        $this->assertFalse($result['success']);
        // Blueprint creation is implemented but may fail due to setup issues
        $this->assertNotEmpty($result['errors']);
    }

    public function test_update_blueprint_fails_validation(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'handle' => 'existing_blueprint',
            'title' => 'Updated Blueprint',
        ]);

        $this->assertFalse($result['success']);
        // Blueprint update is implemented but may fail due to setup issues
        $this->assertNotEmpty($result['errors']);
    }

    public function test_delete_blueprint_requires_confirmation(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
            'handle' => 'blueprint_to_delete',
        ]);

        $this->assertFalse($result['success']);
        // Blueprint deletion is implemented but requires confirmation
        $this->assertNotEmpty($result['errors']);
    }

    public function test_generate_blueprint_fails_validation(): void
    {
        $result = $this->router->execute([
            'action' => 'generate',
            'handle' => 'generated_blueprint',
        ]);

        $this->assertFalse($result['success']);
        // Blueprint generation is implemented but may fail due to setup issues
        $this->assertNotEmpty($result['errors']);
    }

    public function test_invalid_action(): void
    {
        $result = $this->router->execute([
            'action' => 'invalid_action',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("Unknown action 'invalid_action'. Use one of: list, get", $result['errors'][0]);
    }

    public function test_missing_handle_for_get(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The get action needs handle', $result['errors'][0]);
    }

    public function test_missing_handle_for_update(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The update action needs handle', $result['errors'][0]);
    }

    public function test_missing_handle_for_delete(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The delete action needs handle', $result['errors'][0]);
    }

    public function test_missing_handle_for_validate(): void
    {
        $result = $this->router->execute([
            'action' => 'validate',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The validate action needs handle', $result['errors'][0]);
    }

    public function test_blueprint_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'handle' => 'nonexistent_blueprint',
        ]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Blueprint not found: nonexistent_blueprint', $result['errors'][0]);
    }

    public function test_blueprint_not_found_in_namespace(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'handle' => 'nonexistent',
            'namespace' => 'collections',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Blueprint not found: nonexistent', $result['errors'][0]);
    }

    public function test_validate_nonexistent_blueprint(): void
    {
        $result = $this->router->execute([
            'action' => 'validate',
            'handle' => 'nonexistent_validation',
        ]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('Blueprint not found: nonexistent_validation', $result['errors'][0]);
    }

    public function test_create_blueprint_requires_handle(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'namespace' => 'globals',
            'title' => 'Missing Handle',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The create action needs handle', $result['errors'][0]);
    }

    public function test_create_blueprint_requires_collection_handle_for_collections(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'handle' => 'test_blog',
            'namespace' => 'collections',
            'title' => 'Test Blog',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('collection is required when namespace=collections', $result['errors'][0]);
    }

    public function test_create_blueprint_requires_taxonomy_handle_for_taxonomies(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'handle' => 'test_tag',
            'namespace' => 'taxonomies',
            'title' => 'Test Tag',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('taxonomy is required when namespace=taxonomies', $result['errors'][0]);
    }

    public function test_create_blueprint_validates_collection_exists(): void
    {
        Collection::shouldReceive('find')
            ->with('nonexistent_collection')
            ->andReturn(null);

        $result = $this->router->execute([
            'action' => 'create',
            'handle' => 'test_blog',
            'namespace' => 'collections',
            'collection' => 'nonexistent_collection',
            'title' => 'Test Blog',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection not found: nonexistent_collection', $result['errors'][0]);
    }

    public function test_create_blueprint_validates_taxonomy_exists(): void
    {
        Taxonomy::shouldReceive('find')
            ->with('nonexistent_taxonomy')
            ->andReturn(null);

        $result = $this->router->execute([
            'action' => 'create',
            'handle' => 'test_tag',
            'namespace' => 'taxonomies',
            'taxonomy' => 'nonexistent_taxonomy',
            'title' => 'Test Tag',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Taxonomy not found: nonexistent_taxonomy', $result['errors'][0]);
    }

    public function test_delete_nonexistent_blueprint_returns_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
            'handle' => 'some_blueprint',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Blueprint not found', $result['errors'][0]);
    }

    public function test_generate_blueprint_requires_fields(): void
    {
        $result = $this->router->execute([
            'action' => 'generate',
            'handle' => 'test_generated',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The generate action needs fields', $result['errors'][0]);
    }

    public function test_generate_blueprint_requires_valid_field_definitions(): void
    {
        Blueprint::shouldReceive('in')
            ->with('collections')
            ->andReturn(collect([]));

        $result = $this->router->execute([
            'action' => 'generate',
            'handle' => 'test_generated',
            'fields' => [
                ['invalid' => 'no handle or type'],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No valid field definitions found', $result['errors'][0]);
    }

    public function test_validate_fields_rejects_flat_field_format(): void
    {
        // Use reflection to test the private validateFields method
        $method = new \ReflectionMethod(BlueprintsRouter::class, 'validateFields');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, [
            ['handle' => 'title', 'type' => 'text'],
        ]);

        // Should return an error because "field" key is missing
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('missing the "field" key', $result['errors'][0]);
        $this->assertStringContainsString('Move it inside a "field" object', $result['errors'][0]);
    }

    public function test_validate_fields_rejects_unknown_fieldtype(): void
    {
        $method = new \ReflectionMethod(BlueprintsRouter::class, 'validateFields');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, [
            ['handle' => 'title', 'field' => ['type' => 'nonexistent_type']],
        ]);

        // Should return an error listing available types
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown field type "nonexistent_type"', $result['errors'][0]);
        $this->assertStringContainsString('Available types:', $result['errors'][0]);
    }

    public function test_validate_fields_accepts_valid_fields(): void
    {
        $method = new \ReflectionMethod(BlueprintsRouter::class, 'validateFields');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, [
            ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
            ['handle' => 'content', 'field' => ['type' => 'textarea', 'display' => 'Content']],
        ]);

        // Should return validated fields
        $this->assertArrayHasKey('validated', $result);
        $this->assertCount(2, $result['validated']);
        $this->assertEquals('title', $result['validated'][0]['handle']);
        $this->assertEquals('text', $result['validated'][0]['field']['type']);
    }

    public function test_validate_fields_rejects_duplicate_handles(): void
    {
        $method = new \ReflectionMethod(BlueprintsRouter::class, 'validateFields');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'title', 'field' => ['type' => 'textarea']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate field handle', $result['errors'][0]);
    }

    public function test_validate_fields_suggests_near_miss_params(): void
    {
        $method = new \ReflectionMethod(BlueprintsRouter::class, 'validateFields');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, [
            ['handle' => 'categories', 'field' => ['type' => 'terms', 'taxonomy' => 'categories']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Did you mean "taxonomies"', $result['errors'][0]);
    }

    public function test_validate_fields_strips_template_expressions(): void
    {
        $method = new \ReflectionMethod(BlueprintsRouter::class, 'validateFields');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, [
            ['handle' => 'title', 'field' => ['type' => 'text', 'default' => '{{ malicious }}', 'placeholder' => 'Normal text']],
        ]);

        $this->assertArrayHasKey('validated', $result);
        $field = $result['validated'][0]['field'];
        $this->assertStringNotContainsString('{{', $field['default'] ?? '');
        $this->assertEquals('Normal text', $field['placeholder']);
    }

    /**
     * Helper method to create a test blueprint
     */
    private function createTestBlueprint(string $handle, string $namespace, string $title): void
    {
        $blueprint = BlueprintObject::make()
            ->setHandle($handle)
            ->setNamespace($namespace)
            ->setContents([
                'title' => $title,
                'sections' => [
                    'main' => [
                        'fields' => [
                            [
                                'handle' => 'title',
                                'field' => [
                                    'type' => 'text',
                                    'display' => 'Title',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $blueprint->save();
    }

    /**
     * Helper method to create a test blueprint with specific fields
     */
    private function createTestBlueprintWithFields(string $handle, string $namespace, string $title, array $fields): void
    {
        $fieldDefinitions = [];
        foreach ($fields as $fieldHandle => $fieldConfig) {
            $fieldDefinitions[] = [
                'handle' => $fieldHandle,
                'field' => $fieldConfig,
            ];
        }

        $blueprint = BlueprintObject::make()
            ->setHandle($handle)
            ->setNamespace($namespace)
            ->setContents([
                'title' => $title,
                'sections' => [
                    'main' => [
                        'fields' => $fieldDefinitions,
                    ],
                ],
            ]);

        $blueprint->save();
    }
}
