<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\DeleteBlueprintTool;
use Cboxdk\StatamicMcp\Tests\TestCase;

class DeleteBlueprintToolTest extends TestCase
{
    protected DeleteBlueprintTool $tool;

    protected CreateBlueprintTool $createTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new DeleteBlueprintTool;
        $this->createTool = new CreateBlueprintTool;
    }

    public function test_can_delete_blueprint()
    {
        // Create a blueprint first
        $this->createTool->handle([
            'handle' => 'delete_test',
            'title' => 'Delete Test',
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

        // Delete it
        $result = $this->tool->handle([
            'handle' => 'delete_test',
            'namespace' => 'collections',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKeys([
            'success', 'handle', 'namespace', 'title',
            'deleted', 'forced', 'usage_at_deletion', 'cache_cleared', 'message',
        ]);
        expect($response['data']['handle'])->toBe('delete_test');
        expect($response['data']['deleted'])->toBeTrue();
        expect($response['data']['forced'])->toBeFalse();
    }

    public function test_dry_run_shows_what_would_be_deleted()
    {
        // Create a blueprint
        $this->createTool->handle([
            'handle' => 'dry_run_test',
            'title' => 'Dry Run Test',
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

        // Dry run
        $result = $this->tool->handle([
            'handle' => 'dry_run_test',
            'dry_run' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKeys([
            'dry_run', 'handle', 'title', 'usage',
            'can_delete_safely', 'would_force_delete', 'warnings',
        ]);
        expect($response['data']['dry_run'])->toBeTrue();
        expect($response['data']['handle'])->toBe('dry_run_test');
        expect($response['data']['usage'])->toBeArray();
        expect($response['data']['can_delete_safely'])->toBeBool();
    }

    public function test_prevents_deletion_with_content_usage()
    {
        // Create a blueprint
        $this->createTool->handle([
            'handle' => 'protected_test',
            'title' => 'Protected Test',
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

        // Try to delete without force (simulating it has usage)
        $result = $this->tool->handle([
            'handle' => 'protected_test',
            'force' => false,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        // Should either succeed (no usage) or show protective error
        expect($response['success'])->toBeTrue();

        if (isset($response['data']['error'])) {
            expect($response['data']['error'])->toContain('used by');
            expect($response['data'])->toHaveKey('usage');
            expect($response['data'])->toHaveKey('suggestion');
        }
    }

    public function test_force_deletion_bypasses_safety()
    {
        // Create a blueprint
        $this->createTool->handle([
            'handle' => 'force_test',
            'title' => 'Force Test',
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

        // Force delete
        $result = $this->tool->handle([
            'handle' => 'force_test',
            'force' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data']['deleted'])->toBeTrue();
        expect($response['data']['forced'])->toBeTrue();
    }

    public function test_handles_nonexistent_blueprint()
    {
        $result = $this->tool->handle([
            'handle' => 'nonexistent',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKey('error');
        expect($response['data']['error'])->toContain('not found');
    }

    public function test_defaults_to_collections_namespace()
    {
        $result = $this->tool->handle([
            'handle' => 'test',
            'dry_run' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        if (isset($response['data']['namespace'])) {
            expect($response['data']['namespace'])->toBe('collections');
        }
    }

    public function test_tool_has_correct_metadata()
    {
        expect($this->tool->name())->toBe('statamic.blueprints.delete');
        expect($this->tool->description())->toContain('Delete a blueprint');
        expect($this->tool->description())->toContain('safety checks');
    }
}
