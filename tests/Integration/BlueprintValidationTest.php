<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;

class BlueprintValidationTest extends TestCase
{
    use CreatesTestContent;

    private BlueprintsRouter $router;

    private string $collectionHandle;

    private string $testId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new BlueprintsRouter;
        $this->testId = bin2hex(random_bytes(4));
        $this->collectionHandle = "bp_val_{$this->testId}";
        $this->createTestCollection($this->collectionHandle);
    }

    public function test_create_with_flat_fields_returns_error_with_correct_format(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'handle' => "flat_{$this->testId}",
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'type' => 'text'],
            ],
        ]);

        $this->assertFalse($result['success']);
        // Error should tell the user to use the "field" key
        $error = $result['errors'][0];
        $this->assertStringContainsString('field', $error);
        $this->assertStringContainsString('"type"', $error);
    }

    public function test_create_with_unknown_fieldtype_returns_available_types(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'handle' => "badtype_{$this->testId}",
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'body', 'field' => ['type' => 'richtext']],
            ],
        ]);

        $this->assertFalse($result['success']);
        $error = $result['errors'][0];
        $this->assertStringContainsString('Unknown field type', $error);
        $this->assertStringContainsString('richtext', $error);
        // Should list available types
        $this->assertStringContainsString('text', $error);
        $this->assertStringContainsString('markdown', $error);
    }

    public function test_create_with_valid_structure_saves_blueprint(): void
    {
        $bpHandle = "valid_{$this->testId}";

        $result = $this->router->execute([
            'action' => 'create',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                ['handle' => 'body', 'field' => ['type' => 'markdown', 'display' => 'Body']],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['created']);
        $this->assertEquals($bpHandle, $result['data']['blueprint']['handle']);

        // Verify we can retrieve it
        $getResult = $this->router->execute([
            'action' => 'get',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
        ]);

        $this->assertTrue($getResult['success']);
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $getResult['data']['blueprint']['fields'];
        $fieldHandles = collect($fields)->pluck('handle')->toArray();
        $this->assertContains('title', $fieldHandles);
        $this->assertContains('body', $fieldHandles);
    }

    public function test_create_with_duplicate_handles_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'handle' => "dup_{$this->testId}",
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'title', 'field' => ['type' => 'textarea']],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Duplicate field handle', $result['errors'][0]);
        $this->assertStringContainsString('title', $result['errors'][0]);
    }

    public function test_template_injection_in_field_value_is_stripped(): void
    {
        $bpHandle = "inject_{$this->testId}";

        $result = $this->router->execute([
            'action' => 'create',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => '{{evil}}']],
            ],
        ]);

        $this->assertTrue($result['success']);

        // Verify the template expression was stripped
        $getResult = $this->router->execute([
            'action' => 'get',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
        ]);

        $this->assertTrue($getResult['success']);
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $getResult['data']['blueprint']['fields'];
        $titleField = collect($fields)->firstWhere('handle', 'title');
        $this->assertNotNull($titleField);
        // The display value should not contain template expressions
        /** @var array<string, mixed> $config */
        $config = $titleField['config'] ?? [];
        $display = is_string($config['display'] ?? null) ? $config['display'] : '';
        $this->assertStringNotContainsString('{{', $display);
    }

    public function test_update_merges_fields_by_default(): void
    {
        $bpHandle = "merge_{$this->testId}";

        // Create blueprint with two fields
        $createResult = $this->router->execute([
            'action' => 'create',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                ['handle' => 'body', 'field' => ['type' => 'markdown', 'display' => 'Body']],
            ],
        ]);
        $this->assertTrue($createResult['success']);

        // Update: add a new field (should merge, not replace)
        $updateResult = $this->router->execute([
            'action' => 'update',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'category', 'field' => ['type' => 'text', 'display' => 'Category']],
            ],
        ]);
        $this->assertTrue($updateResult['success']);

        // Verify all three fields exist
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $updateResult['data']['blueprint']['fields'];
        $fieldHandles = collect($fields)->pluck('handle')->toArray();
        $this->assertContains('title', $fieldHandles, 'Existing "title" field should be preserved after merge');
        $this->assertContains('body', $fieldHandles, 'Existing "body" field should be preserved after merge');
        $this->assertContains('category', $fieldHandles, 'New "category" field should be added');
        $this->assertCount(3, $fieldHandles);
    }

    public function test_update_with_replace_fields_replaces_all_fields(): void
    {
        $bpHandle = "replace_{$this->testId}";

        // Create blueprint with two fields
        $createResult = $this->router->execute([
            'action' => 'create',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                ['handle' => 'body', 'field' => ['type' => 'markdown', 'display' => 'Body']],
            ],
        ]);
        $this->assertTrue($createResult['success']);

        // Update with replace_fields=true: should replace all fields
        $updateResult = $this->router->execute([
            'action' => 'update',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'replace_fields' => true,
            'fields' => [
                ['handle' => 'category', 'field' => ['type' => 'text', 'display' => 'Category']],
            ],
        ]);
        $this->assertTrue($updateResult['success']);

        // Verify only the new field exists
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $updateResult['data']['blueprint']['fields'];
        $fieldHandles = collect($fields)->pluck('handle')->toArray();
        $this->assertNotContains('title', $fieldHandles, 'Old "title" field should be removed in replace mode');
        $this->assertNotContains('body', $fieldHandles, 'Old "body" field should be removed in replace mode');
        $this->assertContains('category', $fieldHandles, 'New "category" field should exist');
        $this->assertCount(1, $fieldHandles);
    }

    public function test_update_merge_overwrites_existing_field_config(): void
    {
        $bpHandle = "overwrite_{$this->testId}";

        // Create blueprint with a text field
        $createResult = $this->router->execute([
            'action' => 'create',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
            ],
        ]);
        $this->assertTrue($createResult['success']);

        // Update: change the title field type to textarea
        $updateResult = $this->router->execute([
            'action' => 'update',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'textarea', 'display' => 'Title (Textarea)']],
            ],
        ]);
        $this->assertTrue($updateResult['success']);

        // Verify the field was updated, not duplicated
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $updateResult['data']['blueprint']['fields'];
        $fieldHandles = collect($fields)->pluck('handle')->toArray();
        $this->assertCount(1, $fieldHandles);
        $titleField = collect($fields)->firstWhere('handle', 'title');
        $this->assertNotNull($titleField);
        $this->assertEquals('textarea', $titleField['type']);
    }

    public function test_update_merge_preserves_tab_structure(): void
    {
        $bpHandle = "multitab_{$this->testId}";

        // Create a blueprint with multiple tabs via Statamic API directly
        $blueprint = Blueprint::make($bpHandle)
            ->setNamespace("collections.{$this->collectionHandle}")
            ->setContents([
                'title' => 'Multi-Tab Blueprint',
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                                    ['handle' => 'body', 'field' => ['type' => 'markdown', 'display' => 'Body']],
                                ],
                            ],
                        ],
                    ],
                    'seo' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'meta_title', 'field' => ['type' => 'text', 'display' => 'Meta Title']],
                                    ['handle' => 'meta_desc', 'field' => ['type' => 'textarea', 'display' => 'Meta Description']],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        $blueprint->save();

        // Update: add a new field and update an existing SEO field
        $updateResult = $this->router->execute([
            'action' => 'update',
            'handle' => $bpHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'meta_title', 'field' => ['type' => 'text', 'display' => 'SEO Title (Updated)']],
                ['handle' => 'category', 'field' => ['type' => 'text', 'display' => 'Category']],
            ],
        ]);
        $this->assertTrue($updateResult['success']);

        // Verify all fields exist (original + new)
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $updateResult['data']['blueprint']['fields'];
        $fieldHandles = collect($fields)->pluck('handle')->toArray();
        $this->assertContains('title', $fieldHandles, 'Existing main tab field should be preserved');
        $this->assertContains('body', $fieldHandles, 'Existing main tab field should be preserved');
        $this->assertContains('meta_title', $fieldHandles, 'Existing SEO tab field should be preserved');
        $this->assertContains('meta_desc', $fieldHandles, 'Existing SEO tab field should be preserved');
        $this->assertContains('category', $fieldHandles, 'New field should be added');
        $this->assertCount(5, $fieldHandles);

        // Verify tab structure is preserved by reading the raw blueprint contents
        $savedBlueprint = collect(Blueprint::in("collections.{$this->collectionHandle}")->all())
            ->firstWhere('handle', $bpHandle);
        $this->assertNotNull($savedBlueprint);
        /** @var \Statamic\Fields\Blueprint $savedBlueprint */
        $savedContents = $savedBlueprint->contents();
        $this->assertArrayHasKey('tabs', $savedContents, 'Tabs structure should be preserved');
        /** @var array<string, mixed> $tabs */
        $tabs = $savedContents['tabs'];
        $this->assertArrayHasKey('main', $tabs, 'Main tab should be preserved');
        $this->assertArrayHasKey('seo', $tabs, 'SEO tab should be preserved');
    }

    public function test_near_miss_param_key_returns_helpful_error(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'handle' => "nearmiss_{$this->testId}",
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'tags', 'field' => ['type' => 'terms', 'taxonomy' => 'tags']],
            ],
        ]);

        $this->assertFalse($result['success']);
        $error = $result['errors'][0];
        // Should suggest "taxonomies" instead of "taxonomy"
        $this->assertStringContainsString('taxonomy', $error);
        $this->assertStringContainsString('taxonomies', $error);
    }
}
