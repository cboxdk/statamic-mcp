<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Mcp\Tools\System\SchemaTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('returns output schema with standard envelope from all tools', function (string $toolClass) {
    $tool = app($toolClass);
    $schema = new JsonSchemaTypeFactory;
    $output = $tool->outputSchema($schema);

    expect($output)->toBeArray()
        ->and($output)->toHaveKey('success')
        ->and($output)->toHaveKey('data')
        ->and($output)->toHaveKey('meta')
        ->and($output)->toHaveKey('errors')
        ->and($output)->toHaveKey('warnings');
})->with([
    EntriesRouter::class,
    TermsRouter::class,
    GlobalsRouter::class,
    BlueprintsRouter::class,
    StructuresRouter::class,
    AssetsRouter::class,
    UsersRouter::class,
    SystemRouter::class,
    DiscoveryTool::class,
    SchemaTool::class,
]);
