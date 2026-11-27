<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Contracts;

use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\OAuth\OAuthAuthCode;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;
use Cboxdk\StatamicMcp\OAuth\OAuthGrant;

interface OAuthDriver extends RevokesUserGrants
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
        ?CapabilitySnapshot $capabilities = null,
    ): string;

    /**
     * @throws OAuthException
     */
    public function exchangeCode(
        string $code,
        string $codeVerifier,
        string $clientId,
        string $redirectUri,
        ?string $resource = null,
    ): OAuthAuthCode;

    /**
     * Create a refresh token for the given user and client.
     *
     * @param  array<int, string>  $scopes
     */
    public function createRefreshToken(string $userId, string $clientId, array $scopes, ?CapabilitySnapshot $capabilities = null): string;

    /**
     * Exchange a refresh token for a new OAuthAuthCode (with userId and scopes).
     *
     * The refresh token is single-use: it is marked as used on exchange (rotation).
     *
     * @throws OAuthException
     */
    public function exchangeRefreshToken(string $refreshToken, string $clientId, ?string $resource = null): OAuthAuthCode;

    /**
     * Revoke a refresh token. Returns true if found and revoked.
     */
    public function revokeRefreshToken(string $refreshToken): bool;

    /**
     * Revoke every refresh token issued for a given user and client, so that
     * revoking the access token ends the whole grant. Returns the number removed.
     */
    public function revokeRefreshTokensFor(string $userId, string $clientId): int;

    /** Unexpired, unused authorization codes and refresh tokens, independent of access tokens.
     * @return iterable<OAuthGrant>
     */
    public function pendingGrants(): iterable;

    /** Revoke authorization codes AND refresh tokens for one owner/client pair.
     * Caller holds the OAuth mutation lock so a rotation cannot escape.
     */
    public function revokeGrantsForClient(string $userId, string $clientId): int;

    public function prune(): int;
}
