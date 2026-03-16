<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;

it('registers tools when domain is enabled', function () {
    config(['statamic.mcp.tools.entries.enabled' => true]);

    $tool = app(EntriesRouter::class);

    expect($tool->shouldRegister())->toBeTrue();
});

it('skips tools when domain is disabled', function () {
    config(['statamic.mcp.tools.entries.enabled' => false]);

    $tool = app(EntriesRouter::class);

    expect($tool->shouldRegister())->toBeFalse();
});

it('registers tools when domain config is missing', function () {
    config(['statamic.mcp.tools.blueprints' => null]);

    $tool = app(BlueprintsRouter::class);

    expect($tool->shouldRegister())->toBeTrue();
});

it('always registers education tools', function () {
    $tool = app(DiscoveryTool::class);

    expect($tool->shouldRegister())->toBeTrue();
});

it('always registers content facade tool', function () {
    $tool = app(ContentFacadeRouter::class);

    expect($tool->shouldRegister())->toBeTrue();
});
