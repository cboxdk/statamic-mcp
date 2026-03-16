<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\User;

class OAuthFlowTest extends TestCase
{
    /**
     * Test the complete OAuth 2.1 authorization code flow with PKCE end-to-end.
     *
     * This exercises: discovery → registration → authorize → token exchange → validation.
     */
    public function test_complete_oauth_flow(): void
    {
        // ── Step 1: Protected Resource Discovery ──
        $prResponse = $this->getJson('/.well-known/oauth-protected-resource');

        $prResponse->assertOk();
        $prData = $prResponse->json();
        $this->assertArrayHasKey('authorization_servers', $prData);
        $this->assertIsArray($prData['authorization_servers']);
        $this->assertNotEmpty($prData['authorization_servers']);

        // ── Step 2: Authorization Server Metadata ──
        $asResponse = $this->getJson('/.well-known/oauth-authorization-server');

        $asResponse->assertOk();
        $asData = $asResponse->json();
        $this->assertArrayHasKey('authorization_endpoint', $asData);
        $this->assertArrayHasKey('token_endpoint', $asData);
        $this->assertArrayHasKey('registration_endpoint', $asData);
        $this->assertArrayHasKey('code_challenge_methods_supported', $asData);
        $this->assertContains('S256', $asData['code_challenge_methods_supported']);

        // ── Step 3: Dynamic Client Registration ──
        $redirectUri = 'https://example.com/callback';

        $regResponse = $this->postJson('/mcp/oauth/register', [
            'client_name' => 'TestClient',
            'redirect_uris' => [$redirectUri],
        ]);

        $regResponse->assertCreated();
        $regData = $regResponse->json();
        $this->assertArrayHasKey('client_id', $regData);
        $clientId = $regData['client_id'];

        // ── Step 4: Generate PKCE Pair ──
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // ── Step 5: Authenticate as Statamic User ──
        $user = User::make()->id('oauth-e2e-user')->email('test@example.com');
        $user->makeSuper();
        $user->save();

        // ── Step 6: GET Authorize (Consent Page) ──
        $authorizeParams = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => '*',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => 'test123',
        ]);

        $consentResponse = $this->actingAs($user, 'web')
            ->get('/mcp/oauth/authorize?' . $authorizeParams);

        $consentResponse->assertOk();

        // ── Step 7: POST Authorize (Approve) ──
        $approveResponse = $this->actingAs($user, 'web')
            ->post('/mcp/oauth/authorize', [
                'decision' => 'approve',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'state' => 'test123',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'scope' => '*',
                'scopes' => ['*'],
            ]);

        $approveResponse->assertRedirect();
        $location = $approveResponse->headers->get('Location', '');
        $this->assertStringContainsString('state=test123', $location);
        $this->assertStringContainsString('code=', $location);
        $this->assertStringNotContainsString('error', $location);

        // Extract the authorization code from the redirect URL
        $parsedUrl = parse_url($location);
        $this->assertIsString($parsedUrl['query'] ?? null, 'Redirect URL must contain a query string');
        parse_str($parsedUrl['query'], $queryParams);
        $this->assertArrayHasKey('code', $queryParams);
        $authorizationCode = $queryParams['code'];

        // ── Step 8: Token Exchange ──
        $tokenResponse = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $authorizationCode,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'code_verifier' => $codeVerifier,
        ]);

        $tokenResponse->assertOk();
        $tokenData = $tokenResponse->json();
        $this->assertArrayHasKey('access_token', $tokenData);
        $this->assertSame('Bearer', $tokenData['token_type']);
        $this->assertArrayHasKey('expires_in', $tokenData);
        $this->assertIsInt($tokenData['expires_in']);

        $accessToken = $tokenData['access_token'];

        // ── Step 9: Validate the Token ──
        /** @var TokenService $tokenService */
        $tokenService = $this->app->make(TokenService::class);
        $mcpTokenData = $tokenService->validateToken($accessToken);

        $this->assertNotNull($mcpTokenData, 'The OAuth access token must be a valid MCP token');
        $this->assertSame('oauth-e2e-user', $mcpTokenData->userId);
        $this->assertContains('*', $mcpTokenData->scopes);
    }
}
