<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\User;

class AuthorizeEndpointTest extends TestCase
{
    private OAuthClient $testClient;

    private string $validRedirectUri = 'https://example.com/callback';

    protected function setUp(): void
    {
        parent::setUp();

        /** @var OAuthDriver $driver */
        $driver = $this->app->make(OAuthDriver::class);
        $this->testClient = $driver->registerClient('Test MCP Client', [$this->validRedirectUri]);
    }

    /**
     * Build a valid set of authorize query parameters.
     *
     * @param  array<string, string>  $overrides
     *
     * @return array<string, string>
     */
    private function validParams(array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => $this->testClient->clientId,
            'redirect_uri' => $this->validRedirectUri,
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'state' => 'test-state-123',
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

    public function test_get_authorize_without_auth_is_rejected(): void
    {
        $response = $this->get($this->authorizeUrl(http_build_query($this->validParams())));

        // Unauthenticated user should be redirected to login, not see a 200 consent page.
        $response->assertRedirect();
    }

    public function test_get_authorize_with_valid_params_shows_consent_page(): void
    {
        $user = User::make()->id('test-user-1')->email('test@example.com');
        $user->makeSuper();
        $user->save();

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($this->validParams())));

        $response->assertOk();
    }

    public function test_get_authorize_with_invalid_client_id_returns_error(): void
    {
        $user = User::make()->id('test-user-2')->email('test2@example.com');
        $user->makeSuper();
        $user->save();

        $params = $this->validParams(['client_id' => 'nonexistent_client']);

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertStatus(400);
    }

    public function test_get_authorize_without_code_challenge_redirects_with_error(): void
    {
        $user = User::make()->id('test-user-3')->email('test3@example.com');
        $user->makeSuper();
        $user->save();

        $params = $this->validParams();
        unset($params['code_challenge']);

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('error=invalid_request', $location);
    }

    public function test_get_authorize_with_plain_code_challenge_method_redirects_with_error(): void
    {
        $user = User::make()->id('test-user-4')->email('test4@example.com');
        $user->makeSuper();
        $user->save();

        $params = $this->validParams(['code_challenge_method' => 'plain']);

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('error=invalid_request', $location);
    }

    public function test_get_authorize_with_unsupported_response_type_redirects_with_error(): void
    {
        $user = User::make()->id('test-user-5')->email('test5@example.com');
        $user->makeSuper();
        $user->save();

        $params = $this->validParams(['response_type' => 'token']);

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('error=unsupported_response_type', $location);
    }

    public function test_get_authorize_with_invalid_scope_redirects_with_error(): void
    {
        $user = User::make()->id('test-user-6')->email('test6@example.com');
        $user->makeSuper();
        $user->save();

        $params = $this->validParams(['scope' => 'content:read invalid:scope']);

        $response = $this->actingAs($user, 'web')
            ->get($this->authorizeUrl(http_build_query($params)));

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('error=invalid_scope', $location);
    }

    public function test_post_approve_redirects_with_code_and_state(): void
    {
        $user = User::make()->id('test-user-7')->email('test7@example.com');
        $user->makeSuper();
        $user->save();

        $response = $this->actingAs($user, 'web')
            ->post($this->authorizeUrl(), [
                'client_id' => $this->testClient->clientId,
                'redirect_uri' => $this->validRedirectUri,
                'state' => 'test-state-456',
                'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
                'code_challenge_method' => 'S256',
                'scopes' => ['content:read'],
                'decision' => 'approve',
            ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('code=', $location);
        $this->assertStringContainsString('state=test-state-456', $location);
        $this->assertStringNotContainsString('error', $location);
    }

    public function test_post_deny_redirects_with_access_denied_error(): void
    {
        $user = User::make()->id('test-user-8')->email('test8@example.com');
        $user->makeSuper();
        $user->save();

        $response = $this->actingAs($user, 'web')
            ->post($this->authorizeUrl(), [
                'client_id' => $this->testClient->clientId,
                'redirect_uri' => $this->validRedirectUri,
                'state' => 'test-state-789',
                'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
                'code_challenge_method' => 'S256',
                'scopes' => ['content:read'],
                'decision' => 'deny',
            ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString('error=access_denied', $location);
        $this->assertStringContainsString('state=test-state-789', $location);
    }
}
