<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Drivers\BuiltInOAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\File;

class RefreshTokenTest extends TestCase
{
    private string $clientsDir;

    private string $codesDir;

    private string $refreshDir;

    private BuiltInOAuthDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientsDir = sys_get_temp_dir() . '/statamic-mcp-test-oauth-clients-' . uniqid();
        $this->codesDir = sys_get_temp_dir() . '/statamic-mcp-test-oauth-codes-' . uniqid();
        $this->refreshDir = sys_get_temp_dir() . '/statamic-mcp-test-oauth-refresh-' . uniqid();

        $this->driver = new BuiltInOAuthDriver($this->clientsDir, $this->codesDir, $this->refreshDir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->clientsDir, $this->codesDir, $this->refreshDir] as $dir) {
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }

        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_full_flow_auth_code_to_refresh_to_new_tokens(): void
    {
        // Use the app container's driver so the HTTP endpoint shares the same storage
        /** @var OAuthDriver $appDriver */
        $appDriver = $this->app->make(OAuthDriver::class);

        $client = $appDriver->registerClient('Test Client', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $appDriver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-1',
            scopes: ['content:read', 'content:write'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        // Exchange auth code via HTTP endpoint — should return refresh_token
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
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame('content:read content:write', $data['scope']);

        // Use refresh token to get new tokens
        $refreshResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $data['refresh_token'],
            'client_id' => $client->clientId,
        ]);

        $refreshResponse->assertOk();
        $refreshData = $refreshResponse->json();

        $this->assertArrayHasKey('access_token', $refreshData);
        $this->assertArrayHasKey('refresh_token', $refreshData);
        $this->assertSame('Bearer', $refreshData['token_type']);
        $this->assertSame('content:read content:write', $refreshData['scope']);
        // New tokens should be different
        $this->assertNotSame($data['access_token'], $refreshData['access_token']);
        $this->assertNotSame($data['refresh_token'], $refreshData['refresh_token']);
    }

    public function test_create_and_exchange_refresh_token(): void
    {
        $client = $this->driver->registerClient('Test App', ['https://example.com/callback']);

        $refreshToken = $this->driver->createRefreshToken('user-1', $client->clientId, ['content:read']);

        expect($refreshToken)->toBeString();
        expect(strlen($refreshToken))->toBe(64); // 32 bytes = 64 hex chars

        $authCode = $this->driver->exchangeRefreshToken($refreshToken, $client->clientId);

        expect($authCode->userId)->toBe('user-1');
        expect($authCode->clientId)->toBe($client->clientId);
        expect($authCode->scopes)->toBe(['content:read']);
    }

    public function test_refresh_token_is_single_use(): void
    {
        $client = $this->driver->registerClient('Test App', ['https://example.com/callback']);

        $refreshToken = $this->driver->createRefreshToken('user-1', $client->clientId, ['content:read']);

        // First exchange succeeds
        $this->driver->exchangeRefreshToken($refreshToken, $client->clientId);

        // Second exchange fails (rotation)
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Refresh token has already been used');

        $this->driver->exchangeRefreshToken($refreshToken, $client->clientId);
    }

    public function test_expired_refresh_token_is_rejected(): void
    {
        config(['statamic.mcp.oauth.refresh_token_ttl' => 0]);

        $client = $this->driver->registerClient('Test App', ['https://example.com/callback']);

        $refreshToken = $this->driver->createRefreshToken('user-1', $client->clientId, ['content:read']);

        // Advance time to ensure expiry
        Carbon::setTestNow(Carbon::now()->addSeconds(2));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Refresh token has expired');

        $this->driver->exchangeRefreshToken($refreshToken, $client->clientId);
    }

    public function test_wrong_client_id_is_rejected(): void
    {
        $client = $this->driver->registerClient('Test App', ['https://example.com/callback']);

        $refreshToken = $this->driver->createRefreshToken('user-1', $client->clientId, ['content:read']);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Client ID mismatch');

        $this->driver->exchangeRefreshToken($refreshToken, 'mcp_wrong_client_id_here_000000');
    }

    public function test_invalid_refresh_token_is_rejected(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Refresh token not found');

        $this->driver->exchangeRefreshToken('nonexistent_token_value', 'mcp_some_client');
    }

    public function test_missing_refresh_token_param_returns_error(): void
    {
        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => 'some-client',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_request',
        ]);
    }

    public function test_missing_client_id_param_returns_error(): void
    {
        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'some-token',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_request',
        ]);
    }

    public function test_prune_removes_used_and_expired_refresh_tokens(): void
    {
        config(['statamic.mcp.oauth.refresh_token_ttl' => 60]);

        $client = $this->driver->registerClient('Test App', ['https://example.com/callback']);

        // Create a refresh token and use it
        $usedToken = $this->driver->createRefreshToken('user-1', $client->clientId, ['content:read']);
        $this->driver->exchangeRefreshToken($usedToken, $client->clientId);

        // Create an expired refresh token
        Carbon::setTestNow(Carbon::now()->subSeconds(120));
        $this->driver->createRefreshToken('user-2', $client->clientId, ['content:read']);
        Carbon::setTestNow(null);

        // Create a valid refresh token that should survive pruning
        $this->driver->createRefreshToken('user-3', $client->clientId, ['content:read']);

        $refreshFiles = glob($this->refreshDir . '/*.json');
        $this->assertNotFalse($refreshFiles);
        $this->assertCount(3, $refreshFiles);

        $pruned = $this->driver->prune();

        // Should have pruned the used + expired tokens
        expect($pruned)->toBeGreaterThanOrEqual(2);

        $remainingFiles = glob($this->refreshDir . '/*.json');
        $this->assertNotFalse($remainingFiles);
        $this->assertCount(1, $remainingFiles);
    }
}
