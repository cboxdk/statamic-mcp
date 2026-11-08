<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;

/**
 * Native MCP clients register a loopback redirect URI without a port and come
 * back on the port the operating system gave them (RFC 8252 §7.3). The whole
 * authorization code flow must accept that port, and nothing else may differ.
 */
class LoopbackRedirectPortTest extends TestCase
{
    private string $cimdClientId = 'https://app.example.com/oauth/client-metadata.json';

    private string $registeredRedirectUri = 'http://localhost/callback';

    private string $requestedRedirectUri = 'http://localhost:53682/callback';

    private function fakeCimdMetadata(): void
    {
        config()->set('statamic.mcp.oauth.cimd_enabled', true);
        config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
        config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

        Http::fake([
            'app.example.com/*' => Http::response((string) json_encode([
                'client_id' => $this->cimdClientId,
                'client_name' => 'Native Test Client',
                'redirect_uris' => [$this->registeredRedirectUri],
            ]), 200),
        ]);
    }

    /**
     * @return \Statamic\Contracts\Auth\User
     */
    private function authenticatedUser(string $id)
    {
        $user = User::make()->id($id)->email($id . '@example.com');
        $user->makeSuper();
        $user->save();

        return $user;
    }

    /**
     * @return array{verifier: string, challenge: string}
     */
    private function generatePkce(): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    private function authorizeUrl(string $query = ''): string
    {
        /** @var string $cpRoute */
        $cpRoute = config('statamic.cp.route', 'cp');
        $url = '/' . trim($cpRoute, '/') . '/mcp/oauth/authorize';

        return $query !== '' ? $url . '?' . $query : $url;
    }

    /**
     * @return array<string, string>
     */
    private function authorizeParams(string $clientId, string $redirectUri, string $challenge): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'loopback-state',
            'scope' => 'content:read',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approveParams(string $clientId, string $redirectUri, string $challenge): array
    {
        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => 'loopback-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => 'content:read',
            'scopes' => ['content:read'],
            'decision' => 'approve',
        ];
    }

    public function test_cimd_client_completes_the_flow_on_an_ephemeral_loopback_port(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('loopback-cimd');
        $pkce = $this->generatePkce();

        $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query(
                $this->authorizeParams($this->cimdClientId, $this->requestedRedirectUri, $pkce['challenge']),
            )))
            ->assertOk();

        $approve = $this->actingAs($user, 'web')
            ->post($this->authorizeUrl(), $this->approveParams($this->cimdClientId, $this->requestedRedirectUri, $pkce['challenge']));

        $approve->assertRedirect();
        $location = (string) $approve->headers->get('Location', '');
        $this->assertStringStartsWith($this->requestedRedirectUri . '?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);

        $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => $this->requestedRedirectUri,
            'client_id' => $this->cimdClientId,
            'code_verifier' => $pkce['verifier'],
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_registered_client_is_accepted_on_an_ephemeral_loopback_port(): void
    {
        $clientId = $this->postJson('/mcp/oauth/register', [
            'client_name' => 'Native DCR Client',
            'redirect_uris' => ['http://127.0.0.1/callback'],
        ])->assertCreated()->json('client_id');

        $user = $this->authenticatedUser('loopback-dcr');
        $pkce = $this->generatePkce();

        $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query(
                $this->authorizeParams($clientId, 'http://127.0.0.1:61000/callback', $pkce['challenge']),
            )))
            ->assertOk();
    }

    public function test_authorize_refuses_a_loopback_uri_with_another_path(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('loopback-path');
        $pkce = $this->generatePkce();

        $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query(
                $this->authorizeParams($this->cimdClientId, 'http://localhost:53682/elsewhere', $pkce['challenge']),
            )))
            ->assertStatus(400);
    }

    public function test_authorize_refuses_another_loopback_host(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('loopback-host');
        $pkce = $this->generatePkce();

        $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query(
                $this->authorizeParams($this->cimdClientId, 'http://127.0.0.1:53682/callback', $pkce['challenge']),
            )))
            ->assertStatus(400);
    }

    public function test_approve_refuses_a_loopback_uri_with_another_path(): void
    {
        $this->fakeCimdMetadata();
        $user = $this->authenticatedUser('loopback-approve');
        $pkce = $this->generatePkce();

        $this->actingAs($user, 'web')
            ->post($this->authorizeUrl(), $this->approveParams($this->cimdClientId, 'http://localhost:53682/elsewhere', $pkce['challenge']))
            ->assertStatus(400);
    }

    public function test_https_redirect_uris_still_match_exactly(): void
    {
        $clientId = $this->postJson('/mcp/oauth/register', [
            'client_name' => 'Web Client',
            'redirect_uris' => ['https://web.example.com/callback'],
        ])->assertCreated()->json('client_id');

        $user = $this->authenticatedUser('loopback-https');
        $pkce = $this->generatePkce();

        $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query(
                $this->authorizeParams($clientId, 'https://web.example.com:8443/callback', $pkce['challenge']),
            )))
            ->assertStatus(400);
    }
}
