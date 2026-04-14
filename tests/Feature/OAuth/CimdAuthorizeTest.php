<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;

class CimdAuthorizeTest extends TestCase
{
    private string $cimdClientId = 'https://app.example.com/oauth/metadata.json';

    private string $cimdRedirectUri = 'https://app.example.com/callback';

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
            'client_name' => 'Test CIMD App',
            'redirect_uris' => [$this->cimdRedirectUri],
        ], $overrides);
    }

    /**
     * Build a valid set of authorize query parameters for a CIMD client.
     *
     * @param  array<string, string>  $overrides
     *
     * @return array<string, string>
     */
    private function validParams(array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => $this->cimdClientId,
            'redirect_uri' => $this->cimdRedirectUri,
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'state' => 'test-state-cimd',
            'scope' => 'content:read',
        ], $overrides);
    }

    /**
     * Get the authorize endpoint URL (under CP prefix).
     */
    private function authorizeUrl(string $query = ''): string
    {
        /** @var string $cpRoute */
        $cpRoute = config('statamic.cp.route', 'cp');

        $url = '/' . trim($cpRoute, '/') . '/mcp/oauth/authorize';

        return $query !== '' ? $url . '?' . $query : $url;
    }

    /**
     * Fake HTTP responses for CIMD metadata and configure test defaults.
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
    private function authenticatedUser(string $id = 'cimd-test-user', string $email = 'cimd@example.com')
    {
        $user = User::make()->id($id)->email($email);
        $user->makeSuper();
        $user->save();

        return $user;
    }

    // --- Successful consent screen with CIMD client_id ---

    public function test_get_authorize_with_cimd_client_id_shows_consent_screen(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-1', 'cimd1@example.com');

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($this->validParams())));

        $response->assertOk();
        $response->assertSee('Test CIMD App');
        $response->assertSee('app.example.com');
    }

    public function test_consent_screen_displays_cimd_client_hostname(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-2', 'cimd2@example.com');

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($this->validParams())));

        $response->assertOk();
        $response->assertSee('from app.example.com');
    }

    public function test_consent_screen_displays_client_logo_when_present(): void
    {
        $this->fakeCimdMetadata(['logo_uri' => 'https://app.example.com/logo.png']);
        $user = $this->authenticatedUser('cimd-3', 'cimd3@example.com');

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($this->validParams())));

        $response->assertOk();
        $response->assertSee('https://app.example.com/logo.png');
    }

    public function test_consent_screen_shows_localhost_warning_for_localhost_redirect_uris(): void
    {
        $this->fakeCimdMetadata(['redirect_uris' => ['http://localhost:3000/callback']]);
        $user = $this->authenticatedUser('cimd-4', 'cimd4@example.com');

        $params = $this->validParams(['redirect_uri' => 'http://localhost:3000/callback']);

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertOk();
        $response->assertSee('localhost redirect URIs');
    }

    // --- Approval flow with CIMD client_id ---

    public function test_post_approve_with_cimd_client_id_creates_auth_code_and_redirects(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-5', 'cimd5@example.com');

        $response = $this->actingAs($user, 'web')
            ->post($this->authorizeUrl(), [
                'client_id' => $this->cimdClientId,
                'redirect_uri' => $this->cimdRedirectUri,
                'state' => 'cimd-state-approve',
                'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
                'code_challenge_method' => 'S256',
                'scope' => 'content:read',
                'scopes' => ['content:read'],
                'decision' => 'approve',
            ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('code=', $location);
        $this->assertStringContainsString('state=cimd-state-approve', $location);
        $this->assertStringNotContainsString('error', $location);
    }

    // --- Denial flow with CIMD client_id ---

    public function test_post_deny_with_cimd_client_id_redirects_with_access_denied(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-6', 'cimd6@example.com');

        $response = $this->actingAs($user, 'web')
            ->post($this->authorizeUrl(), [
                'client_id' => $this->cimdClientId,
                'redirect_uri' => $this->cimdRedirectUri,
                'state' => 'cimd-state-deny',
                'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
                'code_challenge_method' => 'S256',
                'scope' => 'content:read',
                'scopes' => ['content:read'],
                'decision' => 'deny',
            ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('error=access_denied', $location);
        $this->assertStringContainsString('state=cimd-state-deny', $location);
    }

    // --- Invalid CIMD URL returns 400 ---

    public function test_get_authorize_with_non_https_cimd_url_returns_400(): void
    {
        $user = $this->authenticatedUser('cimd-7', 'cimd7@example.com');

        // A non-HTTPS URL won't parse as CimdClientId, so falls through to abort(400)
        $params = $this->validParams(['client_id' => 'http://example.com/metadata.json']);

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertStatus(400);
    }

    // --- CIMD fetch failure returns 400 ---

    public function test_get_authorize_with_unreachable_cimd_url_returns_400(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);
        config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
        config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

        Http::fake([
            'app.example.com/*' => function (): never {
                throw new ConnectionException('Connection refused');
            },
        ]);

        $user = $this->authenticatedUser('cimd-8', 'cimd8@example.com');

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($this->validParams())));

        $response->assertStatus(400);
    }

    // --- CIMD validation failure (mismatched client_id) returns 400 ---

    public function test_get_authorize_with_mismatched_cimd_client_id_returns_400(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);
        config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
        config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

        Http::fake([
            'app.example.com/*' => Http::response(
                (string) json_encode([
                    'client_id' => 'https://other.example.com/metadata.json',
                    'client_name' => 'Wrong App',
                    'redirect_uris' => ['https://app.example.com/callback'],
                ]),
                200,
            ),
        ]);

        $user = $this->authenticatedUser('cimd-9', 'cimd9@example.com');

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($this->validParams())));

        $response->assertStatus(400);
    }

    // --- CIMD URL returning invalid JSON returns 400 ---

    public function test_get_authorize_with_cimd_url_returning_invalid_json_returns_400(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);
        config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
        config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

        Http::fake([
            'app.example.com/*' => Http::response('not json at all', 200),
        ]);

        $user = $this->authenticatedUser('cimd-10', 'cimd10@example.com');

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($this->validParams())));

        $response->assertStatus(400);
    }

    // --- Redirect URI not in CIMD metadata returns 400 ---

    public function test_get_authorize_with_redirect_uri_not_in_cimd_metadata_returns_400(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('cimd-11', 'cimd11@example.com');

        $params = $this->validParams(['redirect_uri' => 'https://evil.com/callback']);

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertStatus(400);
    }

    // --- CIMD disabled in config falls through to abort(400) ---

    public function test_get_authorize_with_cimd_url_when_cimd_disabled_returns_400(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', false);

        $user = $this->authenticatedUser('cimd-12', 'cimd12@example.com');

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($this->validParams())));

        $response->assertStatus(400);
    }

    // --- Existing DCR-registered clients still work unchanged ---

    public function test_dcr_registered_client_still_works_when_cimd_enabled(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);

        /** @var OAuthDriver $driver */
        $driver = $this->app->make(OAuthDriver::class);
        $dcrClient = $driver->registerClient('DCR Test Client', ['https://dcr.example.com/callback']);

        $user = $this->authenticatedUser('cimd-13', 'cimd13@example.com');

        $params = [
            'response_type' => 'code',
            'client_id' => $dcrClient->clientId,
            'redirect_uri' => 'https://dcr.example.com/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'state' => 'dcr-state',
            'scope' => 'content:read',
        ];

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertOk();
        $response->assertSee('DCR Test Client');
        // DCR client should NOT show CIMD-specific hostname display
        $response->assertDontSee('from ');
    }
}
