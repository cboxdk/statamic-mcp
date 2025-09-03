<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\InfoSystemTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Tags\ListTagsTool;

// Structure tools are now organized into domain-specific directories

test('all taxonomy tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\ListTaxonomyTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\GetTaxonomyTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\CreateTaxonomyTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\UpdateTaxonomyTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\DeleteTaxonomyTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Taxonomies\AnalyzeTaxonomyTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all fieldset tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\ListFieldsetsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\GetFieldsetTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\CreateFieldsetTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\UpdateFieldsetTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets\DeleteFieldsetTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all navigation structure tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Navigations\ListNavigationsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Navigations\GetNavigationTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Navigations\CreateNavigationTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Navigations\UpdateNavigationTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Navigations\DeleteNavigationTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all form tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Forms\ListFormsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Forms\GetFormTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Forms\CreateFormTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Forms\UpdateFormTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Forms\DeleteFormTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all entry tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Entries\ListEntresTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Entries\GetEntryTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Entries\CreateEntryTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Entries\UpdateEntryTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Entries\DeleteEntryTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Entries\PublishEntryTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Entries\UnpublishEntryTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all blueprint tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GetBlueprintTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\UpdateBlueprintTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\DeleteBlueprintTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all term tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Terms\ListTermsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Terms\GetTermTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Terms\CreateTermTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Terms\UpdateTermTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Terms\DeleteTermTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all globals tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Globals\ListGlobalsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Globals\GetGlobalTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Globals\UpdateGlobalTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all navigation content tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Navigations\ListNavigationsTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all user tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Users\ListUsersTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Users\GetUserTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all form submission tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Forms\ListSubmissionsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Forms\ExportSubmissionsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Forms\SubmissionsStatsTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all asset management tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Assets\MoveAssetTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Assets\CopyAssetTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Assets\RenameAssetTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all blocks tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Tags\ListTagsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Modifiers\ListModifiersTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\FieldTypes\ListFieldTypesTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Scopes\ListScopesTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Filters\ListFiltersTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all asset tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Assets\ListAssetsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Assets\GetAssetTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Assets\CreateAssetTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Assets\UpdateAssetTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Assets\DeleteAssetTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all group tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Groups\ListGroupsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Groups\GetGroupTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all permission tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Permissions\ListPermissionsTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all development tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\Development\TemplatesDevelopmentTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Development\AddonsDevelopmentTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Development\GenerateTypesTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Development\ListTypeDefinitionsTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\Development\ConsoleDevelopmentTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('all system tools can be instantiated', function () {
    $tools = [
        \Cboxdk\StatamicMcp\Mcp\Tools\System\InfoSystemTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\System\ClearCacheTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\System\CacheStatusTool::class,
        \Cboxdk\StatamicMcp\Mcp\Tools\System\DocsSystemTool::class,
    ];

    foreach ($tools as $toolClass) {
        $tool = app($toolClass);
        expect($tool)->toBeInstanceOf($toolClass);
        expect($tool->name())->toBeString();
        expect($tool->description())->toBeString();
    }
});

test('list blueprints tool basic functionality', function () {
    $tool = app(ListBlueprintsTool::class);

    $result = $tool->handle([]);

    $resultData = $result->toArray();
    $data = json_decode($resultData['content'][0]['text'], true);
    expect($data)->toHaveKey('success');
    expect($data)->toHaveKey('data');
    expect($data)->toHaveKey('meta');
});

test('info system tool basic functionality', function () {
    $tool = app(InfoSystemTool::class);

    $result = $tool->handle(['action' => 'overview']);

    $resultData = $result->toArray();
    $data = json_decode($resultData['content'][0]['text'], true);
    expect($data)->toHaveKey('success');
    expect($data)->toHaveKey('data');
    expect($data)->toHaveKey('meta');
});

test('tags block tool basic functionality', function () {
    $tool = app(ListTagsTool::class);

    $result = $tool->handle(['action' => 'list']);

    $resultData = $result->toArray();
    $data = json_decode($resultData['content'][0]['text'], true);
    expect($data)->toHaveKey('success');
    expect($data)->toHaveKey('data');
    expect($data)->toHaveKey('meta');
});
