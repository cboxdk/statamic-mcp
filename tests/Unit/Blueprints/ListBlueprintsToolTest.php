<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool;
use Cboxdk\StatamicMcp\Tests\TestCase;

class ListBlueprintsToolTest extends TestCase
{
    protected ListBlueprintsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ListBlueprintsTool;
    }

    public function test_can_list_blueprints_in_default_namespace()
    {
        $result = $this->tool->handle([]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKeys(['blueprints', 'count', 'namespace', 'includes_details']);
        expect($response['data']['namespace'])->toBe('collections');
        expect($response['data']['includes_details'])->toBeFalse();
        expect($response['data']['blueprints'])->toBeArray();
        expect($response['data']['count'])->toBeInt();
    }

    public function test_can_list_blueprints_in_specific_namespace()
    {
        $result = $this->tool->handle([
            'namespace' => 'forms',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data']['namespace'])->toBe('forms');
        expect($response['data']['blueprints'])->toBeArray();
    }

    public function test_can_list_blueprints_with_details()
    {
        $result = $this->tool->handle([
            'namespace' => 'collections',
            'include_details' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data']['includes_details'])->toBeTrue();

        if ($response['data']['count'] > 0) {
            $firstBlueprint = $response['data']['blueprints'][0];
            expect($firstBlueprint)->toHaveKeys(['handle', 'title', 'field_count', 'sections_count']);
            expect($firstBlueprint['handle'])->toBeString();
            expect($firstBlueprint['title'])->toBeString();
            expect($firstBlueprint['field_count'])->toBeInt();
            expect($firstBlueprint['sections_count'])->toBeInt();
        }
    }

    public function test_handles_invalid_namespace_gracefully()
    {
        $result = $this->tool->handle([
            'namespace' => 'nonexistent_namespace',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue(); // Should not fail completely
        expect($response['data'])->toHaveKey('namespace');
        expect($response['data']['namespace'])->toBe('nonexistent_namespace');
    }

    public function test_tool_has_correct_metadata()
    {
        expect($this->tool->name())->toBe('statamic.blueprints.list');
        expect($this->tool->description())->toContain('List all blueprints');
    }
}
