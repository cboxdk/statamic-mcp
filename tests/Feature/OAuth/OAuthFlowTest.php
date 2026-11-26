<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Tests\Concerns\InteractsWithOAuthConsent;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\User;

class OAuthFlowTest extends TestCase
{
    use InteractsWithOAuthConsent;

    /**
     * Test the complete OAuth 2.1 authorization code flow with PKCE end-to-end.
     *
     * This exercises: discovery → registration → authorize → token exchange → validation.
     */
    /**
     * A client sends back the `resource` that protected-resource metadata
     * advertised. On a non-default port (php artisan serve, Docker on 8080)
     * the authorize endpoint left the port out of what it expected and
     * refused it with "Invalid resource parameter", so no client could finish.
     */
    public function test_authorize_accepts_the_advertised_resource_on_a_non_default_port(): void
    {
        config()->set('app.url', 'https://cms.example.com');
        $origin = 'http://mcp.test:8080';
        $resource = $this->getJson($origin . '/.well-known/oauth-protected-resource')->json('resource');
        $this->assertSame($origin . '/mcp/statamic', $resource);

        $redirectUri = 'https://example.com/callback';
        $clientId = $this->postJson($origin . '/mcp/oauth/register', ['client_name' => 'PortClient', 'redirect_uris' => [$redirectUri]])->json('client_id');
        $user = User::make()->id('oauth-port-user')->email('port@example.com')->makeSuper();
        $user->save();

        $query = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'scope' => 'entries:read',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'verifier', true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'state' => 's'];
        $authorize = $origin . '/' . trim((string) config('statamic.cp.route', 'cp'), '/') . '/mcp/oauth/authorize?';

        $this->actingAs($user, 'web')->get($authorize . http_build_query($query + ['resource' => $resource]))->assertOk();
        // Another port is still another resource.
        $this->actingAs($user, 'web')->get($authorize . http_build_query($query + ['resource' => 'http://mcp.test:9090/mcp/statamic']))
            ->assertStatus(400)->assertSee('invalid_target');
    }

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
            'resource' => $prData['resource'],
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => '*',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => 'test123',
        ]);

        /** @var string $cpRoute */
        $cpRoute = config('statamic.cp.route', 'cp');
        $authorizeUrl = '/' . trim($cpRoute, '/') . '/mcp/oauth/authorize';

        $consentResponse = $this->actingAs($user, 'web')
            ->get($authorizeUrl . '?' . $authorizeParams);

        $consentResponse->assertOk();

        // ── Step 7: POST Authorize (Approve) ──
        $approveResponse = $this->actingAs($user, 'web')
            ->post($authorizeUrl, [
                'resource' => $prData['resource'],
                'consent_offer' => $this->consentOfferFrom($consentResponse),
                'decision' => 'approve',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'state' => 'test123',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'scope' => '*',
                // The consent screen spells `*` out; approving posts that list.
                'scopes' => $this->app->make(ScopeAvailability::class)->expandFullAccess($user, ['*']),
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
        $this->assertSame($asData['issuer'], $queryParams['iss']);
        $authorizationCode = $queryParams['code'];

        // ── Step 8: Token Exchange ──
        $tokenResponse = $this->post('/mcp/oauth/token', [
            'resource' => $prData['resource'],
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
        // Full access is stored as today's explicit list, never as `*`.
        $this->assertNotContains('*', $mcpTokenData->scopes);
        $this->assertContains('entries:publish', $mcpTokenData->scopes);
        $this->assertContains('users:admin', $mcpTokenData->scopes);
    }
}
