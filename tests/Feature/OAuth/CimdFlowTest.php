<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;

class CimdFlowTest extends TestCase
{
    private string $cimdClientId = 'https://app.example.com/oauth/metadata.json';

    private string $cimdRedirectUri = 'https://app.example.com/callback';

    private string $cimdClientName = 'Test CIMD App';

    /**
     * Build a valid CIMD metadata JSON payload.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function cimdMetadata(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->cimdClientId,
            'client_name' => $this->cimdClientName,
            'redirect_uris' => [$this->cimdRedirectUri],
        ], $overrides);
    }

    /**
     * Configure CIMD and fake HTTP responses for the metadata URL.
     *
     * @param  array<string, mixed>  $metadataOverrides
     */
    private function fakeCimdMetadata(array $metadataOverrides = []): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);
        config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
        config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

        Http::fake([
            'app.example.com/*' => Http::response(
                (string) json_encode($this->cimdMetadata($metadataOverrides)),
                200,
            ),
        ]);
    }

    /**
     * Create and return an authenticated super user.
     *
     * @return \Statamic\Contracts\Auth\User
     */
    private function authenticatedUser(string $id, string $email)
    {
        $user = User::make()->id($id)->email($email);
        $user->makeSuper();
        $user->save();

        return $user;
    }

    /**
     * Generate a PKCE code_verifier and code_challenge pair.
     *
     * @return array{verifier: string, challenge: string}
     */
    private function generatePkce(): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * Get the authorize endpoint URL (under CP prefix).
     */
    private function authorizeUrl(): string
    {
        /** @var string $cpRoute */
        $cpRoute = config('statamic.cp.route', 'cp');

        return '/' . trim($cpRoute, '/') . '/mcp/oauth/authorize';
    }

    /**
     * Approve a CIMD authorization request and return the auth code.
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     */
    private function approveAndGetCode($user, string $codeChallenge, string $scope = 'content:read'): string
    {
        $response = $this->actingAs($user, 'web')
            ->post($this->authorizeUrl(), [
                'client_id' => $this->cimdClientId,
                'redirect_uri' => $this->cimdRedirectUri,
                'state' => 'e2e-state',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'scope' => $scope,
                'scopes' => explode(' ', $scope),
                'decision' => 'approve',
            ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');

        // Extract the code from the redirect URL
        $query = (string) parse_url($location, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertArrayHasKey('code', $params, 'Authorize redirect must contain a code parameter');

        /** @var string $code */
        $code = $params['code'];

        return $code;
    }

    // --- Full E2E flow: authorize → approve → token exchange → verify → refresh → verify ---

    public function test_full_e2e_cimd_oauth_flow(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-e2e-1', 'e2e1@example.com');
        $pkce = $this->generatePkce();

        // Step 1: Approve authorization → get auth code
        $code = $this->approveAndGetCode($user, $pkce['challenge']);

        // Step 2: Exchange auth code for tokens
        $tokenResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cimdRedirectUri,
            'client_id' => $this->cimdClientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        $tokenResponse->assertOk();
        $tokenData = $tokenResponse->json();

        $this->assertArrayHasKey('access_token', $tokenData);
        $this->assertArrayHasKey('refresh_token', $tokenData);
        $this->assertSame('Bearer', $tokenData['token_type']);
        $this->assertSame('content:read', $tokenData['scope']);

        // Step 3: Verify access token has correct CIMD properties
        /** @var TokenService $tokenService */
        $tokenService = $this->app->make(TokenService::class);
        $mcpToken = $tokenService->findByPlainText($tokenData['access_token']);

        $this->assertNotNull($mcpToken);
        $this->assertSame($this->cimdClientId, $mcpToken->oauthClientId);
        $this->assertSame($this->cimdClientName, $mcpToken->oauthClientName);

        // Step 4: Refresh token to get new tokens
        $refreshResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokenData['refresh_token'],
            'client_id' => $this->cimdClientId,
        ]);

        $refreshResponse->assertOk();
        $refreshData = $refreshResponse->json();

        $this->assertArrayHasKey('access_token', $refreshData);
        $this->assertArrayHasKey('refresh_token', $refreshData);
        $this->assertSame('Bearer', $refreshData['token_type']);
        $this->assertSame('content:read', $refreshData['scope']);

        // New tokens should be different from originals
        $this->assertNotSame($tokenData['access_token'], $refreshData['access_token']);
        $this->assertNotSame($tokenData['refresh_token'], $refreshData['refresh_token']);

        // Step 5: Verify refreshed access token also has CIMD properties
        $refreshedMcpToken = $tokenService->findByPlainText($refreshData['access_token']);

        $this->assertNotNull($refreshedMcpToken);
        $this->assertSame($this->cimdClientId, $refreshedMcpToken->oauthClientId);
        $this->assertSame($this->cimdClientName, $refreshedMcpToken->oauthClientName);
    }

    // --- Refresh token flow with CIMD client_id ---

    public function test_refresh_token_flow_with_cimd_client_id(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-e2e-2', 'e2e2@example.com');
        $pkce = $this->generatePkce();

        $code = $this->approveAndGetCode($user, $pkce['challenge'], 'content:read content:write');

        // Exchange auth code
        $tokenResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cimdRedirectUri,
            'client_id' => $this->cimdClientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        $tokenResponse->assertOk();
        $tokenData = $tokenResponse->json();

        // Refresh
        $refreshResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokenData['refresh_token'],
            'client_id' => $this->cimdClientId,
        ]);

        $refreshResponse->assertOk();
        $refreshData = $refreshResponse->json();

        $this->assertSame('content:read content:write', $refreshData['scope']);
        $this->assertNotSame($tokenData['access_token'], $refreshData['access_token']);
    }

    // --- Token revocation with CIMD-issued tokens ---

    public function test_revocation_works_for_cimd_issued_access_token(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-e2e-3', 'e2e3@example.com');
        $pkce = $this->generatePkce();

        $code = $this->approveAndGetCode($user, $pkce['challenge']);

        $tokenResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cimdRedirectUri,
            'client_id' => $this->cimdClientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        $tokenResponse->assertOk();
        $tokenData = $tokenResponse->json();

        // Revoke the access token
        $revokeResponse = $this->post('/mcp/oauth/revoke', [
            'token' => $tokenData['access_token'],
        ]);

        $revokeResponse->assertOk();

        // Verify the access token is no longer valid
        /** @var TokenService $tokenService */
        $tokenService = $this->app->make(TokenService::class);
        $mcpToken = $tokenService->findByPlainText($tokenData['access_token']);

        $this->assertNull($mcpToken);
    }

    public function test_revocation_works_for_cimd_issued_refresh_token(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-e2e-4', 'e2e4@example.com');
        $pkce = $this->generatePkce();

        $code = $this->approveAndGetCode($user, $pkce['challenge']);

        $tokenResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cimdRedirectUri,
            'client_id' => $this->cimdClientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        $tokenResponse->assertOk();
        $tokenData = $tokenResponse->json();

        // Revoke the refresh token
        $revokeResponse = $this->post('/mcp/oauth/revoke', [
            'token' => $tokenData['refresh_token'],
        ]);

        $revokeResponse->assertOk();

        // Attempting to use the revoked refresh token should fail
        $refreshResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokenData['refresh_token'],
            'client_id' => $this->cimdClientId,
        ]);

        $refreshResponse->assertStatus(400);
        $refreshResponse->assertJson([
            'error' => 'invalid_grant',
        ]);
    }

    // --- Token endpoint with invalid CIMD URL returns error ---

    public function test_token_endpoint_with_invalid_cimd_url_returns_error(): void
    {
        $this->fakeCimdMetadata();

        /** @var OAuthDriver $driver */
        $driver = $this->app->make(OAuthDriver::class);
        $pkce = $this->generatePkce();

        // Create an auth code with a non-HTTPS URL client_id directly via the driver
        // (bypasses authorize controller validation to test token endpoint specifically)
        $invalidClientId = 'http://not-https.example.com/metadata.json';

        $code = $driver->createAuthCode(
            clientId: $invalidClientId,
            userId: 'user-invalid-cimd',
            scopes: ['content:read'],
            codeChallenge: $pkce['challenge'],
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        // Token exchange — the client_id is not a valid CIMD URL (non-HTTPS),
        // CimdClientId::tryFrom() returns null, so findClient() fails and
        // falls through to 'OAuth Client' fallback (not an error for non-URL IDs)
        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.com/callback',
            'client_id' => $invalidClientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        // Non-URL client_ids that don't match DCR or CIMD still get tokens
        // with generic 'OAuth Client' name — the error case is for valid
        // CIMD URLs that fail resolution
        $response->assertOk();
    }

    // --- Token endpoint with unreachable CIMD URL returns invalid_client ---

    public function test_token_endpoint_with_unreachable_cimd_url_returns_invalid_client(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);
        config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
        config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

        // First, fake successful resolution for the authorize step
        Http::fake([
            'unreachable.example.com/*' => Http::response(
                (string) json_encode([
                    'client_id' => 'https://unreachable.example.com/oauth/metadata.json',
                    'client_name' => 'Unreachable App',
                    'redirect_uris' => ['https://unreachable.example.com/callback'],
                ]),
                200,
            ),
        ]);

        $cimdUrl = 'https://unreachable.example.com/oauth/metadata.json';
        $redirectUri = 'https://unreachable.example.com/callback';
        $user = $this->authenticatedUser('cimd-e2e-5', 'e2e5@example.com');
        $pkce = $this->generatePkce();

        // Approve to get an auth code
        $approveResponse = $this->actingAs($user, 'web')
            ->post($this->authorizeUrl(), [
                'client_id' => $cimdUrl,
                'redirect_uri' => $redirectUri,
                'state' => 'e2e-unreachable',
                'code_challenge' => $pkce['challenge'],
                'code_challenge_method' => 'S256',
                'scope' => 'content:read',
                'scopes' => ['content:read'],
                'decision' => 'approve',
            ]);

        $approveResponse->assertRedirect();
        $location = $approveResponse->headers->get('Location', '');
        $query = (string) parse_url($location, PHP_URL_QUERY);
        parse_str($query, $params);
        $this->assertArrayHasKey('code', $params);

        /** @var string $code */
        $code = $params['code'];

        // Now make CIMD URL unreachable for the token exchange step
        Http::fake([
            'unreachable.example.com/*' => function (): never {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $cimdUrl,
            'code_verifier' => $pkce['verifier'],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_client',
        ]);
    }

    // --- Token endpoint with CIMD disabled returns invalid_client ---

    public function test_token_endpoint_with_cimd_url_when_cimd_disabled_returns_invalid_client(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', false);
        config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
        config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

        /** @var OAuthDriver $driver */
        $driver = $this->app->make(OAuthDriver::class);
        $pkce = $this->generatePkce();

        // Create an auth code directly with a CIMD URL as client_id
        $code = $driver->createAuthCode(
            clientId: $this->cimdClientId,
            userId: 'user-cimd-disabled',
            scopes: ['content:read'],
            codeChallenge: $pkce['challenge'],
            codeChallengeMethod: 'S256',
            redirectUri: $this->cimdRedirectUri,
        );

        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cimdRedirectUri,
            'client_id' => $this->cimdClientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_client',
        ]);
    }

    // --- Verify tokens have correct oauth_client_id and oauth_client_name ---

    public function test_cimd_token_has_correct_oauth_client_id_and_name(): void
    {
        $this->fakeCimdMetadata(['client_name' => 'My Custom CIMD App']);
        $user = $this->authenticatedUser('cimd-e2e-6', 'e2e6@example.com');
        $pkce = $this->generatePkce();

        $code = $this->approveAndGetCode($user, $pkce['challenge']);

        $tokenResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cimdRedirectUri,
            'client_id' => $this->cimdClientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        $tokenResponse->assertOk();
        $tokenData = $tokenResponse->json();

        /** @var TokenService $tokenService */
        $tokenService = $this->app->make(TokenService::class);
        $mcpToken = $tokenService->findByPlainText($tokenData['access_token']);

        $this->assertNotNull($mcpToken);
        $this->assertSame($this->cimdClientId, $mcpToken->oauthClientId);
        $this->assertSame('My Custom CIMD App', $mcpToken->oauthClientName);
    }

    // --- Existing DCR-based token exchange still works ---

    public function test_dcr_based_token_exchange_still_works_with_cimd_enabled(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);

        /** @var OAuthDriver $driver */
        $driver = $this->app->make(OAuthDriver::class);
        $client = $driver->registerClient('DCR Flow Client', ['https://dcr.example.com/callback']);

        $pkce = $this->generatePkce();

        $code = $driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'dcr-user',
            scopes: ['content:read', 'content:write'],
            codeChallenge: $pkce['challenge'],
            codeChallengeMethod: 'S256',
            redirectUri: 'https://dcr.example.com/callback',
        );

        $response = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://dcr.example.com/callback',
            'client_id' => $client->clientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertSame('content:read content:write', $data['scope']);

        // Verify DCR client name is used, not CIMD fallback
        /** @var TokenService $tokenService */
        $tokenService = $this->app->make(TokenService::class);
        $mcpToken = $tokenService->findByPlainText($data['access_token']);

        $this->assertNotNull($mcpToken);
        $this->assertSame($client->clientId, $mcpToken->oauthClientId);
        $this->assertSame('DCR Flow Client', $mcpToken->oauthClientName);
    }

    // --- Refresh with CIMD validates metadata on each exchange ---

    public function test_refresh_with_cimd_url_unreachable_returns_invalid_client(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-e2e-7', 'e2e7@example.com');
        $pkce = $this->generatePkce();

        $code = $this->approveAndGetCode($user, $pkce['challenge']);

        // Exchange auth code successfully
        $tokenResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cimdRedirectUri,
            'client_id' => $this->cimdClientId,
            'code_verifier' => $pkce['verifier'],
        ]);

        $tokenResponse->assertOk();
        $tokenData = $tokenResponse->json();

        // Now make CIMD URL unreachable
        Http::fake([
            'app.example.com/*' => function (): never {
                throw new ConnectionException('Connection refused');
            },
        ]);

        // Refresh should fail because CIMD metadata can't be resolved
        $refreshResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokenData['refresh_token'],
            'client_id' => $this->cimdClientId,
        ]);

        $refreshResponse->assertStatus(400);
        $refreshResponse->assertJson([
            'error' => 'invalid_client',
        ]);
    }
}
