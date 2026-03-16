<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\OAuthAuthCode;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;

/**
 * Shared helpers for creating OAuth clients and performing PKCE flows in tests.
 */
trait CreatesOAuthClient
{
    protected function registerOAuthClient(
        string $name = 'TestClient',
        string $redirectUri = 'https://example.com/callback',
    ): OAuthClient {
        /** @var OAuthDriver $driver */
        $driver = app(OAuthDriver::class);

        return $driver->registerClient($name, [$redirectUri]);
    }

    /**
     * Generate a PKCE code verifier and S256 challenge pair.
     *
     * @return array{verifier: string, challenge: string}
     */
    protected function generatePkce(): array
    {
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * Create an authorization code for the given client and user.
     *
     * @param  array<int, string>  $scopes
     */
    protected function getAuthCode(
        OAuthClient $client,
        array $pkce,
        string $userId,
        array $scopes = ['*'],
    ): string {
        /** @var OAuthDriver $driver */
        $driver = app(OAuthDriver::class);

        return $driver->createAuthCode(
            clientId: $client->clientId,
            userId: $userId,
            scopes: $scopes,
            codeChallenge: $pkce['challenge'],
            codeChallengeMethod: 'S256',
            redirectUri: $client->redirectUris[0],
        );
    }

    /**
     * Exchange an authorization code for an OAuthAuthCode (validated code data).
     */
    protected function exchangeForToken(
        string $code,
        string $codeVerifier,
        string $clientId,
        string $redirectUri,
    ): OAuthAuthCode {
        /** @var OAuthDriver $driver */
        $driver = app(OAuthDriver::class);

        return $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $clientId,
            redirectUri: $redirectUri,
        );
    }
}
