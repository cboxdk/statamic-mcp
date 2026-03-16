<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;

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
