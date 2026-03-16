<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Tests\TestCase;

class DiscoveryEndpointTest extends TestCase
{
    public function test_protected_resource_returns_correct_structure(): void
    {
        config()->set('app.url', 'https://example.test');
        config()->set('statamic.mcp.web.path', '/mcp/statamic');

        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertOk();
        $response->assertJson([
            'resource' => 'https://example.test/mcp/statamic',
            'authorization_servers' => ['https://example.test'],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function test_protected_resource_contains_all_scopes(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertOk();

        $data = $response->json();
        $scopes = $data['scopes_supported'];

        // Ensure all TokenScope values are present
        foreach (TokenScope::cases() as $scope) {
            $this->assertContains($scope->value, $scopes, "Scope {$scope->value} should be present");
        }

        $this->assertContains('*', $scopes);
        $this->assertContains('content:read', $scopes);
        $this->assertContains('content:write', $scopes);
    }

    public function test_authorization_server_returns_correct_structure(): void
    {
        config()->set('app.url', 'https://example.test');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $response->assertJson([
            'issuer' => 'https://example.test',
            'authorization_endpoint' => 'https://example.test/mcp/oauth/authorize',
            'token_endpoint' => 'https://example.test/mcp/oauth/token',
            'registration_endpoint' => 'https://example.test/mcp/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    public function test_authorization_server_contains_all_scopes(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();

        $data = $response->json();
        $scopes = $data['scopes_supported'];

        foreach (TokenScope::cases() as $scope) {
            $this->assertContains($scope->value, $scopes, "Scope {$scope->value} should be present");
        }
    }

    public function test_authorization_server_includes_s256_code_challenge(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();

        $data = $response->json();
        $this->assertContains('S256', $data['code_challenge_methods_supported']);
    }
}
