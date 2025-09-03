<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool;
use Cboxdk\StatamicMcp\Tests\TestCase;

class CreateBlueprintToolTest extends TestCase
{
    protected CreateBlueprintTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateBlueprintTool;
    }

    public function test_can_create_blueprint()
    {
        $this->markTestSkipped('Blueprint creation tests need proper file system setup in test environment');

        $result = $this->tool->handle([
            'handle' => 'test_blueprint',
            'title' => 'Test Blueprint',
            'namespace' => 'collections',
            'sections' => [
                'main' => [
                    'display' => 'Main Section',
                    'fields' => [
                        [
                            'handle' => 'title',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Title',
                                'required' => true,
                            ],
                        ],
                        [
                            'handle' => 'content',
                            'field' => [
                                'type' => 'markdown',
                                'display' => 'Content',
                                'required' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKeys([
            'handle', 'namespace', 'title',
            'sections_count', 'total_fields', 'cache_cleared', 'message',
        ]);
        expect($response['data']['handle'])->toBe('test_blueprint');
        expect($response['data']['title'])->toBe('Test Blueprint');
        expect($response['data']['sections_count'])->toBe(1);
        expect($response['data']['total_fields'])->toBe(2);
    }

    public function test_prevents_duplicate_blueprint_creation()
    {
        // First creation should succeed
        $this->tool->handle([
            'handle' => 'duplicate_test',
            'title' => 'Duplicate Test',
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'title',
                            'field' => ['type' => 'text'],
                        ],
                    ],
                ],
            ],
        ]);

        // Second creation should fail
        $result = $this->tool->handle([
            'handle' => 'duplicate_test',
            'title' => 'Duplicate Test Again',
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'title',
                            'field' => ['type' => 'text'],
                        ],
                    ],
                ],
            ],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue(); // Tool doesn't fail completely
        expect($response['data'])->toHaveKey('error');
        expect($response['data']['error'])->toContain('already exists');
        expect($response['data'])->toHaveKey('suggestion');
    }

    public function test_requires_sections()
    {
        $result = $this->tool->handle([
            'handle' => 'empty_blueprint',
            'title' => 'Empty Blueprint',
            'sections' => [],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKey('error');
        expect($response['data']['error'])->toContain('tabs (v5+ format) or sections (legacy format) with fields');
    }

    public function test_supports_optional_parameters()
    {
        $result = $this->tool->handle([
            'handle' => 'full_blueprint',
            'title' => 'Full Blueprint',
            'namespace' => 'forms',
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'title',
                            'field' => ['type' => 'text'],
                        ],
                    ],
                ],
            ],
            'hidden' => ['slug'],
            'order' => 10,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data']['namespace'])->toBe('forms');
    }

    public function test_tool_has_correct_metadata()
    {
        expect($this->tool->name())->toBe('statamic.blueprints.create');
        expect($this->tool->description())->toContain('Create a new blueprint');
        expect($this->tool->description())->toContain('no hardcoded templates');
    }
}
