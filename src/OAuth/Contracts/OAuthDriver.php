<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Contracts;

use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\OAuth\OAuthAuthCode;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;

interface OAuthDriver
{
    /** @param array<int, string> $redirectUris */
    public function registerClient(string $clientName, array $redirectUris, ?string $registeredIp = null): OAuthClient;

    public function findClient(string $clientId): ?OAuthClient;

    /**
     * Count how many clients have been registered from a given IP address.
     */
    public function countClientsByIp(string $ip): int;

    /** @param array<int, string> $scopes */
    public function createAuthCode(
        string $clientId,
        string $userId,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $redirectUri,
    ): string;

    /**
     * @throws OAuthException
     */
    public function exchangeCode(
        string $code,
        string $codeVerifier,
        string $clientId,
        string $redirectUri,
    ): OAuthAuthCode;

    /**
     * Create a refresh token for the given user and client.
     *
     * @param  array<int, string>  $scopes
     */
    public function createRefreshToken(string $userId, string $clientId, array $scopes): string;

    /**
     * Exchange a refresh token for a new OAuthAuthCode (with userId and scopes).
     *
     * The refresh token is single-use: it is marked as used on exchange (rotation).
     *
     * @throws OAuthException
     */
    public function exchangeRefreshToken(string $refreshToken, string $clientId): OAuthAuthCode;

    /**
     * Revoke a refresh token. Returns true if found and revoked.
     */
    public function revokeRefreshToken(string $refreshToken): bool;

    public function prune(): int;
}
