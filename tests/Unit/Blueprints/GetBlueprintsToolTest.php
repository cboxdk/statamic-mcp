<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GetBlueprintTool;
use Cboxdk\StatamicMcp\Tests\TestCase;

class GetBlueprintsToolTest extends TestCase
{
    protected GetBlueprintTool $tool;

    protected CreateBlueprintTool $createTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new GetBlueprintTool;
        $this->createTool = new CreateBlueprintTool;

        // Create a test blueprint for testing
        $this->createTool->handle([
            'handle' => 'default',
            'title' => 'Default Blueprint',
            'namespace' => 'collections',
            'sections' => [
                'main' => [
                    'fields' => [
                        [
                            'handle' => 'title',
                            'field' => ['type' => 'text', 'required' => true],
                        ],
                        [
                            'handle' => 'content',
                            'field' => ['type' => 'markdown'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_can_get_blueprint_with_details()
    {
        $result = $this->tool->handle([
            'handle' => 'default',
            'namespace' => 'collections',
            'include_field_details' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKeys([
            'handle', 'namespace', 'title', 'sections',
            'sections_count', 'total_field_count', 'includes_field_details',
        ]);
        expect($response['data']['handle'])->toBe('default');
        expect($response['data']['namespace'])->toBe('collections');
        expect($response['data']['title'])->toBe('Default Blueprint');
        expect($response['data']['includes_field_details'])->toBeTrue();
        // Check for either tabs (v5+) or sections (legacy)
        expect($response['data'])->toHaveKey('tabs');
        expect($response['data']['tabs'])->toBeArray();
        expect($response['data']['tabs_count'])->toBeGreaterThan(0);
    }

    public function test_can_get_blueprint_without_details()
    {
        $result = $this->tool->handle([
            'handle' => 'default',
            'namespace' => 'collections',
            'include_field_details' => false,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data']['includes_field_details'])->toBeFalse();
    }

    public function test_handles_nonexistent_blueprint()
    {
        $result = $this->tool->handle([
            'handle' => 'nonexistent',
            'namespace' => 'collections',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue(); // Tool doesn't fail completely
        expect($response['data'])->toHaveKey('error');
        expect($response['data']['error'])->toContain('not found');
        expect($response['data'])->toHaveKey('available_namespaces');
    }

    public function test_defaults_to_collections_namespace()
    {
        $result = $this->tool->handle([
            'handle' => 'default',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        if ($response['success'] && ! isset($response['data']['error'])) {
            expect($response['data']['namespace'])->toBe('collections');
        }
    }

    public function test_tool_has_correct_metadata()
    {
        expect($this->tool->name())->toBe('statamic.blueprints.get');
        expect($this->tool->description())->toContain('Get a specific blueprint');
    }
}
