<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\User;

class TokenEndpointTest extends TestCase
{
    private OAuthDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        User::make()->id('user-1')->email('oauth-user@example.test')->makeSuper()->save();

        $this->driver = $this->app->make(OAuthDriver::class);
    }

    public function test_successful_token_exchange(): void
    {
        $client = $this->driver->registerClient('Test Client', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $this->driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-1',
            scopes: ['entries:read', 'entries:write'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
            capabilities: new CapabilitySnapshot([], resource: 'http://localhost/mcp/statamic'),
        );

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'resource' => 'http://localhost/mcp/statamic',
            'redirect_uri' => 'https://example.com/callback',
            'client_id' => $client->clientId,
            'code_verifier' => $codeVerifier,
        ];
        foreach ([null, 'http://localhost:8080/mcp/statamic', 'http://localhost/mcp/other', 'http://localhost/mcp/statamic#fragment', ['http://localhost/mcp/statamic']] as $wrong) {
            $this->post('/mcp/oauth/token', array_replace($params, ['resource' => $wrong]))
                ->assertStatus(400)->assertJsonPath('error', 'invalid_target');
        }
        $response = $this->post('/mcp/oauth/token', $params);

        $response->assertOk();

        $data = $response->json();

        $this->assertArrayHasKey('access_token', $data);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertArrayHasKey('expires_in', $data);
        $this->assertIsInt($data['expires_in']);
        $this->assertSame('entries:read entries:write', $data['scope']);

        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'Audience test', 'version' => '1'],
        ]];
        $headers = ['Authorization' => 'Bearer ' . $data['access_token']];
        $this->postJson('http://localhost:8080/mcp/statamic', $body, $headers)->assertUnauthorized();
        $this->postJson('http://other.test/mcp/statamic', $body, $headers)->assertUnauthorized();
        $this->postJson('http://localhost/mcp/statamic', $body, $headers)->assertOk();

        $refresh = ['grant_type' => 'refresh_token', 'client_id' => $client->clientId, 'refresh_token' => $data['refresh_token']];
        $this->post('/mcp/oauth/token', $refresh)->assertStatus(400)->assertJsonPath('error', 'invalid_target');
        $rotated = $this->post('/mcp/oauth/token', $refresh + ['resource' => 'HTTP://LOCALHOST:80/mcp/statamic'])->assertOk()->json();
        $this->postJson('http://localhost:8080/mcp/statamic', $body, ['Authorization' => 'Bearer ' . $rotated['access_token']])->assertUnauthorized();
        $this->postJson('http://localhost/mcp/statamic', $body, ['Authorization' => 'Bearer ' . $rotated['access_token']])->assertOk();
    }

    public function test_invalid_grant_type_returns_error(): void
    {
        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'client_credentials',
            'code' => 'some-code',
            'redirect_uri' => 'https://example.com/callback',
            'client_id' => 'some-client',
            'code_verifier' => 'some-verifier',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'unsupported_grant_type',
        ]);
    }

    public function test_missing_code_returns_error(): void
    {
        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'redirect_uri' => 'https://example.com/callback',
            'client_id' => 'some-client',
            'code_verifier' => 'some-verifier',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_request',
        ]);
    }

    public function test_invalid_code_returns_error(): void
    {
        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => 'invalid-code',
            'resource' => 'http://localhost/mcp/statamic',
            'redirect_uri' => 'https://example.com/callback',
            'client_id' => 'some-client',
            'code_verifier' => 'some-verifier',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_grant',
        ]);
    }

    public function test_invalid_pkce_verifier_returns_error(): void
    {
        $client = $this->driver->registerClient('Test Client', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $this->driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-1',
            scopes: ['entries:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
            capabilities: new CapabilitySnapshot([], resource: 'http://localhost/mcp/statamic'),
        );

        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.com/callback',
            'client_id' => $client->clientId,
            'code_verifier' => 'wrong-verifier-value',
            'resource' => 'http://localhost/mcp/statamic',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_request',
        ]);
    }
}
