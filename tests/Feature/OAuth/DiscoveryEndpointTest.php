<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Tests\TestCase;

class DiscoveryEndpointTest extends TestCase
{
    public function test_protected_resource_returns_correct_structure(): void
    {
        config()->set('statamic.mcp.web.path', '/mcp/statamic');

        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertOk();
        $data = $response->json();

        // The controller derives the base URL from the request, not config('app.url')
        $this->assertStringEndsWith('/mcp/statamic', $data['resource']);
        $this->assertIsArray($data['authorization_servers']);
        $this->assertNotEmpty($data['authorization_servers']);
        $this->assertSame(['header'], $data['bearer_methods_supported']);
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
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $data = $response->json();

        // The controller derives endpoints from the request URL, not config('app.url')
        $this->assertArrayHasKey('issuer', $data);
        $this->assertStringEndsWith('/cp/mcp/oauth/authorize', $data['authorization_endpoint']);
        $this->assertStringEndsWith('/mcp/oauth/token', $data['token_endpoint']);
        $this->assertStringEndsWith('/mcp/oauth/register', $data['registration_endpoint']);
        $this->assertSame(['code'], $data['response_types_supported']);
        $this->assertContains('authorization_code', $data['grant_types_supported']);
        $this->assertSame(['S256'], $data['code_challenge_methods_supported']);
        $this->assertSame(['none'], $data['token_endpoint_auth_methods_supported']);
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

    public function test_authorization_server_includes_cimd_support_when_enabled(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('client_id_metadata_document_supported', $data);
        $this->assertTrue($data['client_id_metadata_document_supported']);
    }

    public function test_authorization_server_excludes_cimd_support_when_disabled(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', false);

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();

        $data = $response->json();
        $this->assertArrayNotHasKey('client_id_metadata_document_supported', $data);
    }
}
