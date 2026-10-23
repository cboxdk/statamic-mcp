<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class McpEndpointWwwAuthenticateTest extends TestCase
{
    public function test_unauthenticated_mcp_post_returns_401_with_resource_metadata_header(): void
    {
        $response = $this->postJson('/mcp/statamic', [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $wwwAuth = $response->headers->get('WWW-Authenticate');
        $this->assertIsString($wwwAuth);
        $this->assertStringContainsString(
            'resource_metadata',
            $wwwAuth,
            "Expected WWW-Authenticate header to include 'resource_metadata' pointer, got: {$wwwAuth}"
        );
        $this->assertStringContainsString('/.well-known/oauth-protected-resource/', $wwwAuth);
    }

    public function test_oauth_disabled_returns_401_with_fallback_header(): void
    {
        config()->set('statamic.mcp.oauth.enabled', false);

        $response = $this->postJson('/mcp/statamic', [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $wwwAuth = $response->headers->get('WWW-Authenticate');
        $this->assertIsString($wwwAuth);
        $this->assertStringNotContainsString('resource_metadata', $wwwAuth);
        $this->assertStringContainsString('Bearer', $wwwAuth);
    }

    public function test_non_mcp_route_401_keeps_its_own_header(): void
    {
        Route::get('/basic-auth-probe', fn () => response('', 401, [
            'WWW-Authenticate' => 'Basic realm="Site"',
        ]));
        // Statamic's catch-all frontend route is registered at boot, so it would match the probe first.
        Route::getRoutes()->getByName('statamic.site')?->fallback();

        $response = $this->get('/basic-auth-probe');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Basic realm="Site"', $response->headers->get('WWW-Authenticate'));
    }
}
