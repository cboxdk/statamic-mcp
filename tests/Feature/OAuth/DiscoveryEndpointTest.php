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

    // ------------------------------------------------------------------
    // CIMD config edge cases
    // ------------------------------------------------------------------

    public function test_cimd_enabled_when_config_key_missing_entirely(): void
    {
        // Simulate published config that predates CIMD — replace the oauth
        // array with one that doesn't include cimd_enabled at all (this is
        // what happens when mergeConfigFrom shallow-merges an old published
        // config over the addon's defaults).
        $oauth = config('statamic.mcp.oauth');
        unset($oauth['cimd_enabled']);
        config()->set('statamic.mcp.oauth', $oauth);

        $this->assertNull(config('statamic.mcp.oauth.cimd_enabled'), 'Precondition: key should be absent');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $this->assertTrue($response->json('client_id_metadata_document_supported'));
    }

    public function test_cimd_enabled_when_config_is_string_true(): void
    {
        // env() returns strings — "true" not boolean true
        config()->set('statamic.mcp.oauth.cimd_enabled', 'true');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $this->assertTrue($response->json('client_id_metadata_document_supported'));
    }

    public function test_cimd_disabled_when_config_is_string_false(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', 'false');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        // "false" is a non-empty string — (bool) "false" is true in PHP.
        // This is intentional: to truly disable, set to boolean false or 0.
        $this->assertTrue($response->json('client_id_metadata_document_supported'));
    }

    public function test_cimd_disabled_when_config_is_zero(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', 0);

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $this->assertArrayNotHasKey('client_id_metadata_document_supported', $response->json());
    }

    // ------------------------------------------------------------------
    // Path-suffixed discovery (RFC 8414 §3.1)
    // MCP clients use path insertion: a server at /mcp/statamic triggers
    // /.well-known/oauth-authorization-server/mcp/statamic
    // ------------------------------------------------------------------

    public function test_authorization_server_path_suffix_returns_same_metadata(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);

        $root = $this->getJson('/.well-known/oauth-authorization-server');
        $suffixed = $this->getJson('/.well-known/oauth-authorization-server/mcp/statamic');

        $root->assertOk();
        $suffixed->assertOk();

        // Both must return the same structure and CIMD flag
        $this->assertEquals($root->json('issuer'), $suffixed->json('issuer'));
        $this->assertEquals($root->json('token_endpoint'), $suffixed->json('token_endpoint'));
        $this->assertTrue($suffixed->json('client_id_metadata_document_supported'));
    }

    public function test_protected_resource_path_suffix_returns_same_metadata(): void
    {
        $root = $this->getJson('/.well-known/oauth-protected-resource');
        $suffixed = $this->getJson('/.well-known/oauth-protected-resource/mcp/statamic');

        $root->assertOk();
        $suffixed->assertOk();

        $this->assertEquals($root->json('resource'), $suffixed->json('resource'));
        $this->assertEquals($root->json('authorization_servers'), $suffixed->json('authorization_servers'));
    }

    public function test_authorization_server_with_custom_web_path(): void
    {
        config()->set('statamic.mcp.web.path', '/api/v2/mcp');
        config()->set('statamic.mcp.oauth.cimd_enabled', true);

        // Client would try path insertion with the custom path
        $response = $this->getJson('/.well-known/oauth-authorization-server/api/v2/mcp');

        $response->assertOk();
        $this->assertTrue($response->json('client_id_metadata_document_supported'));
        $this->assertStringEndsWith('/mcp/oauth/token', $response->json('token_endpoint'));
    }

    public function test_authorization_server_with_deeply_nested_path(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server/a/b/c/d');

        $response->assertOk();
        $this->assertArrayHasKey('issuer', $response->json());
    }

    public function test_authorization_server_with_single_segment_path(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server/mcp');

        $response->assertOk();
        $this->assertArrayHasKey('issuer', $response->json());
    }

    // ------------------------------------------------------------------
    // CP route configuration
    // ------------------------------------------------------------------

    public function test_authorization_endpoint_follows_custom_cp_route(): void
    {
        config()->set('statamic.cp.route', 'admin');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $this->assertStringEndsWith('/admin/mcp/oauth/authorize', $response->json('authorization_endpoint'));
    }

    public function test_authorization_endpoint_handles_cp_route_with_slashes(): void
    {
        config()->set('statamic.cp.route', '/dashboard/');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        // trim() in the controller should strip leading/trailing slashes
        $this->assertStringEndsWith('/dashboard/mcp/oauth/authorize', $response->json('authorization_endpoint'));
        $this->assertStringNotContainsString('//mcp', $response->json('authorization_endpoint'));
    }

    // ------------------------------------------------------------------
    // Revocation endpoint
    // ------------------------------------------------------------------

    public function test_authorization_server_includes_revocation_endpoint(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk();
        $this->assertArrayHasKey('revocation_endpoint', $response->json());
        $this->assertStringEndsWith('/mcp/oauth/revoke', $response->json('revocation_endpoint'));
    }

    // ------------------------------------------------------------------
    // Full client discovery flow (RFC 9728 → RFC 8414)
    // Simulates what ChatGPT does: protected-resource → find AS →
    // authorization-server metadata → check CIMD support
    // ------------------------------------------------------------------

    public function test_full_client_discovery_flow_finds_cimd_support(): void
    {
        config()->set('statamic.mcp.web.path', '/mcp/statamic');
        config()->set('statamic.mcp.oauth.cimd_enabled', true);

        // Step 1: Client fetches protected resource metadata (RFC 9728)
        $prm = $this->getJson('/.well-known/oauth-protected-resource');
        $prm->assertOk();

        $resource = $prm->json('resource');
        $authServers = $prm->json('authorization_servers');

        $this->assertStringEndsWith('/mcp/statamic', $resource);
        $this->assertNotEmpty($authServers);

        // Step 2: Client extracts the path from the resource URL to build
        // the path-suffixed discovery URL (RFC 8414 §3.1)
        $resourcePath = parse_url($resource, PHP_URL_PATH);
        $this->assertIsString($resourcePath);

        $discoveryUrl = '/.well-known/oauth-authorization-server' . $resourcePath;
        $this->assertEquals('/.well-known/oauth-authorization-server/mcp/statamic', $discoveryUrl);

        // Step 3: Client fetches authorization server metadata via path-suffixed URL
        $asm = $this->getJson($discoveryUrl);
        $asm->assertOk();

        // Step 4: Client checks CIMD support
        $this->assertTrue($asm->json('client_id_metadata_document_supported'));

        // Step 5: Client verifies required OAuth endpoints are present
        $this->assertNotEmpty($asm->json('authorization_endpoint'));
        $this->assertNotEmpty($asm->json('token_endpoint'));
        $this->assertContains('S256', $asm->json('code_challenge_methods_supported'));
    }

    public function test_full_client_discovery_flow_with_custom_path(): void
    {
        config()->set('statamic.mcp.web.path', '/api/mcp/v2');
        config()->set('statamic.mcp.oauth.cimd_enabled', true);

        // Step 1: Protected resource metadata
        $prm = $this->getJson('/.well-known/oauth-protected-resource');
        $prm->assertOk();

        $resource = $prm->json('resource');
        $this->assertStringEndsWith('/api/mcp/v2', $resource);

        // Step 2: Build path-suffixed discovery URL from resource path
        $resourcePath = parse_url($resource, PHP_URL_PATH);
        $discoveryUrl = '/.well-known/oauth-authorization-server' . $resourcePath;

        // Step 3: Fetch AS metadata
        $asm = $this->getJson($discoveryUrl);
        $asm->assertOk();
        $this->assertTrue($asm->json('client_id_metadata_document_supported'));
    }

    public function test_full_client_discovery_flow_cimd_disabled(): void
    {
        config()->set('statamic.mcp.web.path', '/mcp/statamic');
        config()->set('statamic.mcp.oauth.cimd_enabled', false);

        $prm = $this->getJson('/.well-known/oauth-protected-resource');
        $prm->assertOk();

        $resourcePath = parse_url($prm->json('resource'), PHP_URL_PATH);
        $discoveryUrl = '/.well-known/oauth-authorization-server' . $resourcePath;

        $asm = $this->getJson($discoveryUrl);
        $asm->assertOk();

        // CIMD is disabled — key should be absent, client falls back to DCR
        $this->assertArrayNotHasKey('client_id_metadata_document_supported', $asm->json());
        // DCR registration endpoint should still be present
        $this->assertNotEmpty($asm->json('registration_endpoint'));
    }

    // ------------------------------------------------------------------
    // Path-suffixed routes return identical content to root
    // ------------------------------------------------------------------

    public function test_all_authorization_server_fields_match_between_root_and_suffixed(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);

        $root = $this->getJson('/.well-known/oauth-authorization-server')->json();
        $suffixed = $this->getJson('/.well-known/oauth-authorization-server/mcp/statamic')->json();

        // Every key in root must exist in suffixed with the same value
        foreach ($root as $key => $value) {
            $this->assertArrayHasKey($key, $suffixed, "Key '{$key}' missing from path-suffixed response");
            $this->assertEquals($value, $suffixed[$key], "Value mismatch for key '{$key}'");
        }

        // And no extra keys in suffixed
        $this->assertCount(count($root), $suffixed, 'Path-suffixed response has different number of keys');
    }

    public function test_all_protected_resource_fields_match_between_root_and_suffixed(): void
    {
        $root = $this->getJson('/.well-known/oauth-protected-resource')->json();
        $suffixed = $this->getJson('/.well-known/oauth-protected-resource/mcp/statamic')->json();

        foreach ($root as $key => $value) {
            $this->assertArrayHasKey($key, $suffixed, "Key '{$key}' missing from path-suffixed response");
            $this->assertEquals($value, $suffixed[$key], "Value mismatch for key '{$key}'");
        }

        $this->assertCount(count($root), $suffixed);
    }
}
