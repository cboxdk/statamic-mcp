<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Support\McpContext;

afterEach(function () {
    request()->attributes->remove(McpContext::WEB_ATTRIBUTE);
    request()->headers->remove('X-MCP-Remote');
    config(['statamic.mcp.security.force_web_mode' => false]);
});

it('treats a bare console call as CLI', function () {
    // Tests run under the console SAPI with no web marker.
    expect(McpContext::isWebRequest())->toBeFalse()
        ->and(McpContext::isCli())->toBeTrue();
});

it('treats a request with the server-set web attribute as web, even under a console SAPI', function () {
    request()->attributes->set(McpContext::WEB_ATTRIBUTE, true);

    expect(McpContext::isWebRequest())->toBeTrue()
        ->and(McpContext::isCli())->toBeFalse();
});

it('still honours force_web_mode and the X-MCP-Remote header', function () {
    config(['statamic.mcp.security.force_web_mode' => true]);
    expect(McpContext::isCli())->toBeFalse();
    config(['statamic.mcp.security.force_web_mode' => false]);

    request()->headers->set('X-MCP-Remote', '1');
    expect(McpContext::isWebRequest())->toBeTrue();
});
