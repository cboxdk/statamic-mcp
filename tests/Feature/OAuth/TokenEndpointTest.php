<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Tests\TestCase;

class TokenEndpointTest extends TestCase
{
    private OAuthDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

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
            scopes: ['content:read', 'content:write'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.com/callback',
            'client_id' => $client->clientId,
            'code_verifier' => $codeVerifier,
        ]);

        $response->assertOk();

        $data = $response->json();

        $this->assertArrayHasKey('access_token', $data);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertArrayHasKey('expires_in', $data);
        $this->assertIsInt($data['expires_in']);
        $this->assertSame('content:read content:write', $data['scope']);
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
            scopes: ['content:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.com/callback',
            'client_id' => $client->clientId,
            'code_verifier' => 'wrong-verifier-value',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_request',
        ]);
    }
}
