<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Carbon;

class RevocationTest extends TestCase
{
    private TokenService $tokenService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenService = $this->app->make(TokenService::class);
    }

    public function test_valid_token_is_revoked_and_returns_200(): void
    {
        $result = $this->tokenService->createToken(
            'user-revoke',
            'Revocation Test Token',
            [TokenScope::FullAccess],
            Carbon::now()->addDay(),
        );

        $plainToken = $result['token'];

        // Confirm token is valid before revocation
        $this->assertNotNull($this->tokenService->validateToken($plainToken));

        $response = $this->post('/mcp/oauth/revoke', [
            'token' => $plainToken,
        ]);

        $response->assertOk();

        // Token must no longer be valid after revocation
        $this->assertNull($this->tokenService->validateToken($plainToken));
    }

    public function test_unknown_token_still_returns_200(): void
    {
        // RFC 7009 §2.2: must not leak token validity
        $response = $this->post('/mcp/oauth/revoke', [
            'token' => 'this-token-does-not-exist-anywhere',
        ]);

        $response->assertOk();
    }

    public function test_missing_token_param_returns_400(): void
    {
        $response = $this->post('/mcp/oauth/revoke', []);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_request',
        ]);
    }

    public function test_empty_string_token_returns_400(): void
    {
        $response = $this->post('/mcp/oauth/revoke', [
            'token' => '',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_request',
        ]);
    }

    public function test_revoked_token_cannot_be_used_for_authentication(): void
    {
        $result = $this->tokenService->createToken(
            'user-revoke-auth',
            'Revocation Auth Test Token',
            [TokenScope::FullAccess],
        );

        $plainToken = $result['token'];

        // Revoke via the endpoint
        $this->post('/mcp/oauth/revoke', ['token' => $plainToken])->assertOk();

        // Attempt to use token for auth — validate returns null
        $validated = $this->tokenService->validateToken($plainToken);
        $this->assertNull($validated, 'Revoked token should not authenticate');
    }

    public function test_revoking_already_revoked_token_still_returns_200(): void
    {
        $result = $this->tokenService->createToken(
            'user-double-revoke',
            'Double Revocation Token',
            [TokenScope::ContentRead],
        );

        $plainToken = $result['token'];

        // First revocation
        $this->post('/mcp/oauth/revoke', ['token' => $plainToken])->assertOk();

        // Second revocation of same token — must still return 200
        $this->post('/mcp/oauth/revoke', ['token' => $plainToken])->assertOk();
    }
}
