<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Tests\TestCase;

/**
 * Revoking an OAuth access token must end the grant, so the refresh token
 * cannot renew it afterwards (the previous behaviour silently defeated
 * revocation as an incident-response control).
 */
class RefreshRevocationTest extends TestCase
{
    public function test_revoking_the_access_token_also_revokes_the_refresh_token(): void
    {
        /** @var OAuthDriver $driver */
        $driver = $this->app->make(OAuthDriver::class);
        /** @var TokenService $tokens */
        $tokens = $this->app->make(TokenService::class);

        $client = $driver->registerClient('Desk client', ['https://desk.example.com/callback']);
        $refresh = $driver->createRefreshToken('user-1', $client->clientId, ['content:read']);

        $accessToken = $tokens->createToken(
            'user-1', 'Desk client (OAuth)', [TokenScope::ContentRead], null, $client->clientId, 'Desk client'
        )['model'];

        // Revoking the access token must also drop the grant's refresh token.
        $tokens->revokeToken($accessToken->id);

        $this->expectException(\Throwable::class);
        $driver->exchangeRefreshToken($refresh, $client->clientId);
    }
}
