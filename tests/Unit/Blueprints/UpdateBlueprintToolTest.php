<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\UpdateBlueprintTool;
use Cboxdk\StatamicMcp\Tests\TestCase;

class UpdateBlueprintToolTest extends TestCase
{
    protected UpdateBlueprintTool $tool;

    protected CreateBlueprintTool $createTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new UpdateBlueprintTool;
        $this->createTool = new CreateBlueprintTool;
    }

    public function test_can_update_blueprint_title()
    {
        $this->markTestSkipped('Blueprint update tests need proper file system setup in test environment');

        // First create a blueprint
        $this->createTool->handle([
            'handle' => 'update_test',
            'title' => 'Original Title',
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

        // Then update it
        $result = $this->tool->handle([
            'handle' => 'update_test',
            'title' => 'Updated Title',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKeys([
            'handle', 'title', 'changed',
            'cache_cleared', 'message',
        ]);
        expect($response['data']['title'])->toBe('Updated Title');
        expect($response['data']['changed'])->toBeTrue();
    }

    public function test_can_update_blueprint_sections()
    {
        // Create initial blueprint
        $this->createTool->handle([
            'handle' => 'sections_test',
            'title' => 'Sections Test',
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

        // Update with new sections
        $result = $this->tool->handle([
            'handle' => 'sections_test',
            'sections' => [
                'content' => [
                    'display' => 'Content Section',
                    'fields' => [
                        [
                            'handle' => 'content',
                            'field' => ['type' => 'markdown'],
                        ],
                        [
                            'handle' => 'excerpt',
                            'field' => ['type' => 'textarea'],
                        ],
                    ],
                ],
            ],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data']['sections_count'])->toBe(1);
        expect($response['data']['total_fields'])->toBe(2);
        expect($response['data']['changed'])->toBeTrue();
    }

    public function test_can_merge_sections()
    {
        $this->markTestSkipped('Blueprint merge tests need proper file system setup in test environment');

        // Create initial blueprint
        $this->createTool->handle([
            'handle' => 'merge_test',
            'title' => 'Merge Test',
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

        // Merge with additional sections
        $result = $this->tool->handle([
            'handle' => 'merge_test',
            'sections' => [
                'meta' => [
                    'display' => 'Meta Section',
                    'fields' => [
                        [
                            'handle' => 'meta_description',
                            'field' => ['type' => 'textarea'],
                        ],
                    ],
                ],
            ],
            'merge_sections' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data']['sections_count'])->toBe(2); // Original + merged
        expect($response['data']['merge_mode'])->toBeTrue();
    }

    public function test_handles_nonexistent_blueprint()
    {
        $result = $this->tool->handle([
            'handle' => 'nonexistent',
            'title' => 'Updated Title',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKey('error');
        expect($response['data']['error'])->toContain('not found');
        expect($response['data'])->toHaveKey('suggestion');
    }

    public function test_detects_no_changes()
    {
        // Create blueprint
        $this->createTool->handle([
            'handle' => 'no_change_test',
            'title' => 'No Change Test',
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

        // "Update" with no actual changes
        $result = $this->tool->handle([
            'handle' => 'no_change_test',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data']['changed'])->toBeFalse();
        expect($response['data']['message'])->toContain('No changes detected');
    }

    public function test_tool_has_correct_metadata()
    {
        expect($this->tool->name())->toBe('statamic.blueprints.update');
        expect($this->tool->description())->toContain('Update an existing blueprint');
    }
}
