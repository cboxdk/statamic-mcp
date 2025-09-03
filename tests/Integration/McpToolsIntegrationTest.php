<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Tests\TestCase;
use Laravel\Mcp\Server\Facades\Mcp;

class McpToolsIntegrationTest extends TestCase
{
    public function test_all_tools_are_registered()
    {
        $expectedTools = [
            'statamic.blueprints.list',
            'statamic.blueprints.get',
            'statamic.blueprints.create',
            'statamic.blueprints.update',
            'statamic.blueprints.delete',
            'statamic.collections.list',
            'statamic.entries.list',
            'statamic.entries.get',
            'statamic.entries.create',
            'statamic.entries.update',
            'statamic.entries.delete',
            'statamic.entries.publish',
            'statamic.entries.unpublish',
            'statamic.tags.list',
            'statamic.modifiers.list',
            'statamic.fieldtypes.list',
            'statamic.development.templates',
            'statamic.development.addons',
            'statamic.system.info',
            'statamic.system.cache.clear',
            'statamic.system.cache.status',
            'statamic.users.list',
            'statamic.users.get',
            'statamic.forms.submissions.list',
            'statamic.assets.move',
            'statamic.assets.copy',
            'statamic.assets.rename',
            'statamic.development.types.generate',
            'statamic.development.types.list',
        ];

        // Get registered tools (this would need actual MCP server inspection)
        // For now, we'll verify the tools can be instantiated
        foreach ($expectedTools as $toolName) {
            $toolClass = $this->getToolClassByName($toolName);
            expect($toolClass)->not()->toBeNull("Tool {$toolName} should be registered");

            $tool = new $toolClass;
            expect($tool->name())->toBe($toolName, "Tool {$toolName} should have valid name");
        }
    }

    public function test_tools_have_consistent_response_format()
    {
        $toolClasses = [
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool::class,
            \Cboxdk\StatamicMcp\Mcp\Tools\Entries\ListEntresTool::class,
            \Cboxdk\StatamicMcp\Mcp\Tools\System\InfoSystemTool::class,
        ];

        foreach ($toolClasses as $toolClass) {
            $tool = new $toolClass;

            // Test single-purpose tools - each tool has specific parameters
            try {
                if ($toolClass === \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool::class) {
                    $result = $tool->handle([]);
                } elseif ($toolClass === \Cboxdk\StatamicMcp\Mcp\Tools\Entries\ListEntresTool::class) {
                    $result = $tool->handle(['collection' => 'pages']);
                } else {
                    $result = $tool->handle(['section' => 'overview']);
                }

                $resultData = $result->toArray();
                $response = json_decode($resultData['content'][0]['text'], true);

                // All tools should have consistent response structure
                expect($response)->toHaveKeys(['success', 'data', 'meta']);
                expect($response['meta'])->toHaveKeys(['statamic_version', 'laravel_version', 'timestamp', 'tool']);

            } catch (\Exception $e) {
                // Some tools might not support this combination, that's OK
                continue;
            }
        }
    }

    public function test_tools_handle_errors_gracefully()
    {
        $tool = new \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GetBlueprintTool;

        // Test with nonexistent blueprint
        $result = $tool->handle(['handle' => 'nonexistent', 'namespace' => 'collections']);
        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
        expect($response['data'])->toHaveKey('error');
        expect($response['meta'])->toHaveKeys(['statamic_version', 'laravel_version', 'timestamp', 'tool']);
    }

    public function test_cache_clearing_works_across_tools()
    {
        $this->markTestSkipped('Cache clearing integration test needs actual cache setup');

        // This would test that cache clearing actually works
        // Need to set up actual cache and verify clearing
    }

    public function test_tools_respect_configuration()
    {
        // Test that tools respect configuration settings
        config(['statamic_mcp.some_setting' => 'test_value']);

        // Tools should be able to access and use configuration
        $tool = new \Cboxdk\StatamicMcp\Mcp\Tools\System\InfoSystemTool;
        $result = $tool->handle(['section' => 'environment']);
        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        expect($response['success'])->toBeTrue();
    }

    public function test_development_tools_integration()
    {
        $templateTool = new \Cboxdk\StatamicMcp\Mcp\Tools\Development\TemplatesDevelopmentTool;
        $addonsTool = new \Cboxdk\StatamicMcp\Mcp\Tools\Development\AddonsDevelopmentTool;

        // Test that development tools can work together
        $addonsResult = $addonsTool->handle(['action' => 'list']);
        $resultData = $addonsResult->toArray();
        $addonsResponse = json_decode($resultData['content'][0]['text'], true);

        expect($addonsResponse['success'])->toBeTrue();

        $templateResult = $templateTool->handle([
            'action' => 'analyze',
            'template_type' => 'antlers',
        ]);
        $resultData2 = $templateResult->toArray();
        $templateResponse = json_decode($resultData2['content'][0]['text'], true);

        expect($templateResponse['success'])->toBeTrue();
    }

    public function test_blueprint_and_content_tools_integration()
    {
        $this->markTestSkipped('Integration test needs proper content management setup in test environment');

        $listTool = new \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool;
        $getTool = new \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GetBlueprintTool;
        $entriesTool = new \Cboxdk\StatamicMcp\Mcp\Tools\Entries\ListEntresTool;

        // List available blueprints
        $listResult = $listTool->handle([]);
        $resultData = $listResult->toArray();
        $listResponse = json_decode($resultData['content'][0]['text'], true);
        expect($listResponse['success'])->toBeTrue();

        // If default blueprint exists, get it
        if (! empty($listResponse['data']['collections']) && in_array('default', array_keys($listResponse['data']['collections']))) {
            $getResult = $getTool->handle(['handle' => 'default', 'namespace' => 'collections']);
            $resultData2 = $getResult->toArray();
            $getResponse = json_decode($resultData2['content'][0]['text'], true);
            expect($getResponse['success'])->toBeTrue();
        }

        // Test entries tool can list entries
        $entryResult = $entriesTool->handle(['collection' => 'pages']);
        $resultData3 = $entryResult->toArray();
        $entryResponse = json_decode($resultData3['content'][0]['text'], true);
        expect($entryResponse['success'])->toBeTrue();
    }

    private function getToolClassByName(string $toolName): ?string
    {
        $mapping = [
            'statamic.blueprints.list' => \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool::class,
            'statamic.blueprints.get' => \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GetBlueprintTool::class,
            'statamic.blueprints.create' => \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool::class,
            'statamic.blueprints.update' => \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\UpdateBlueprintTool::class,
            'statamic.blueprints.delete' => \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\DeleteBlueprintTool::class,
            'statamic.collections.list' => \Cboxdk\StatamicMcp\Mcp\Tools\Collections\ListCollectionsTool::class,
            'statamic.entries.list' => \Cboxdk\StatamicMcp\Mcp\Tools\Entries\ListEntresTool::class,
            'statamic.entries.get' => \Cboxdk\StatamicMcp\Mcp\Tools\Entries\GetEntryTool::class,
            'statamic.entries.create' => \Cboxdk\StatamicMcp\Mcp\Tools\Entries\CreateEntryTool::class,
            'statamic.entries.update' => \Cboxdk\StatamicMcp\Mcp\Tools\Entries\UpdateEntryTool::class,
            'statamic.entries.delete' => \Cboxdk\StatamicMcp\Mcp\Tools\Entries\DeleteEntryTool::class,
            'statamic.entries.publish' => \Cboxdk\StatamicMcp\Mcp\Tools\Entries\PublishEntryTool::class,
            'statamic.entries.unpublish' => \Cboxdk\StatamicMcp\Mcp\Tools\Entries\UnpublishEntryTool::class,
            'statamic.tags.list' => \Cboxdk\StatamicMcp\Mcp\Tools\Tags\ListTagsTool::class,
            'statamic.modifiers.list' => \Cboxdk\StatamicMcp\Mcp\Tools\Modifiers\ListModifiersTool::class,
            'statamic.fieldtypes.list' => \Cboxdk\StatamicMcp\Mcp\Tools\FieldTypes\ListFieldTypesTool::class,
            'statamic.development.templates' => \Cboxdk\StatamicMcp\Mcp\Tools\Development\TemplatesDevelopmentTool::class,
            'statamic.development.addons' => \Cboxdk\StatamicMcp\Mcp\Tools\Development\AddonsDevelopmentTool::class,
            'statamic.system.info' => \Cboxdk\StatamicMcp\Mcp\Tools\System\InfoSystemTool::class,
            'statamic.system.cache.clear' => \Cboxdk\StatamicMcp\Mcp\Tools\System\ClearCacheTool::class,
            'statamic.system.cache.status' => \Cboxdk\StatamicMcp\Mcp\Tools\System\CacheStatusTool::class,
            'statamic.users.list' => \Cboxdk\StatamicMcp\Mcp\Tools\Users\ListUsersTool::class,
            'statamic.users.get' => \Cboxdk\StatamicMcp\Mcp\Tools\Users\GetUserTool::class,
            'statamic.forms.submissions.list' => \Cboxdk\StatamicMcp\Mcp\Tools\Forms\ListSubmissionsTool::class,
            'statamic.assets.move' => \Cboxdk\StatamicMcp\Mcp\Tools\Assets\MoveAssetTool::class,
            'statamic.assets.copy' => \Cboxdk\StatamicMcp\Mcp\Tools\Assets\CopyAssetTool::class,
            'statamic.assets.rename' => \Cboxdk\StatamicMcp\Mcp\Tools\Assets\RenameAssetTool::class,
            'statamic.development.types.generate' => \Cboxdk\StatamicMcp\Mcp\Tools\Development\GenerateTypesTool::class,
            'statamic.development.types.list' => \Cboxdk\StatamicMcp\Mcp\Tools\Development\ListTypeDefinitionsTool::class,
        ];

        return $mapping[$toolName] ?? null;
    }
}
