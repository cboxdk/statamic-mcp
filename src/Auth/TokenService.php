<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\Actions\Events\GrantIssued;
use Cboxdk\StatamicMcp\Actions\Events\GrantRevoked;
use Cboxdk\StatamicMcp\Actions\LifecycleEvents;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Events\McpTokenDeleted;
use Cboxdk\StatamicMcp\Events\McpTokenSaved;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\OAuthMutationLock;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TokenService
{
    public function __construct(
        private readonly TokenStore $tokenStore,
    ) {}

    /**
     * Create a new MCP token for a user.
     *
     * @param  array<int, TokenScope|string>  $scopes
     *
     * @return array{token: string, model: McpTokenData}
     */
    public function createToken(string $userId, string $name, array $scopes, ?Carbon $expiresAt = null, ?string $oauthClientId = null, ?string $oauthClientName = null, ?CapabilitySnapshot $capabilities = null, bool $newGrant = true): array
    {
        // Enforce max token lifetime if configured
        /** @var int|null $maxDays */
        $maxDays = config('statamic.mcp.security.max_token_lifetime_days');
        if ($maxDays !== null && $maxDays > 0) {
            $maxExpiry = Carbon::now()->addDays($maxDays);
            if ($expiresAt === null) {
                $expiresAt = $maxExpiry;
            } elseif ($expiresAt->greaterThan($maxExpiry)) {
                $expiresAt = $maxExpiry;
            }
        }

        $plainTextToken = Str::random(64);
        $hash = hash('sha256', $plainTextToken);
        $scopeStrings = array_map(fn (TokenScope|string $scope): string => $scope instanceof TokenScope ? $scope->value : $scope, $scopes);
        if (array_diff($scopeStrings, app(ScopeAvailability::class)->names()) !== []) {
            throw new \InvalidArgumentException('Cannot issue a token with unregistered scopes.');
        }

        if ($capabilities === null && array_filter($scopeStrings, fn (string $scope): bool => TokenScope::tryFrom($scope) === null) !== []) {
            throw new \InvalidArgumentException('Registered actions require explicit capability consent.');
        }

        $tokenData = $this->tokenStore->create(
            $userId,
            $name,
            $hash,
            $scopeStrings,
            $expiresAt !== null ? Carbon::parse($expiresAt) : null,
            $oauthClientId,
            $oauthClientName,
            $capabilities,
        );

        LifecycleEvents::legacy(new McpTokenSaved($tokenData));

        if ($newGrant) {
            LifecycleEvents::dispatch(new GrantIssued((string) Str::uuid(), self::grantId($tokenData),
                $oauthClientId === null ? 'internal' : 'http', gmdate('c'), count($tokenData->scopes)));
        }

        return [
            'token' => $plainTextToken,
            'model' => $tokenData,
        ];
    }

    /**
     * Validate a plain-text token and return the data if valid.
     */
    public function validateToken(#[\SensitiveParameter] string $token): ?McpTokenData
    {
        $hashedToken = hash('sha256', $token);

        $tokenData = $this->tokenStore->findByHash($hashedToken);

        if ($tokenData === null) {
            return null;
        }

        // Defense-in-depth: verify the stored hash matches. Timing attacks are
        // already mitigated by hashing the token before lookup (the DB query uses
        // the hash, not the plain-text token). This comparison guards against
        // data corruption or store implementation quirks.
        if (! hash_equals($tokenData->tokenHash, $hashedToken)) {
            return null;
        }

        if ($this->isExpired($tokenData)) {
            return null;
        }

        $this->tokenStore->markAsUsed($tokenData->id);

        // Return a fresh copy with updated lastUsedAt
        return new McpTokenData(
            id: $tokenData->id,
            userId: $tokenData->userId,
            name: $tokenData->name,
            tokenHash: $tokenData->tokenHash,
            scopes: $tokenData->scopes,
            lastUsedAt: Carbon::instance(now()),
            expiresAt: $tokenData->expiresAt,
            createdAt: $tokenData->createdAt,
            updatedAt: $tokenData->updatedAt,
            oauthClientId: $tokenData->oauthClientId,
            oauthClientName: $tokenData->oauthClientName,
            capabilities: $tokenData->capabilities,
        );
    }

    /**
     * Update an existing token's name, scopes, and/or expiration.
     *
     * @param  array<int, TokenScope|string>|null  $scopes
     */
    public function updateToken(string $tokenId, ?string $name = null, ?array $scopes = null, ?Carbon $expiresAt = null, bool $clearExpiry = false): ?McpTokenData
    {
        return app(OAuthMutationLock::class)->run(function () use ($tokenId, $name, $scopes, $expiresAt, $clearExpiry): ?McpTokenData {
            /** @var array<string, mixed> $data */
            $data = [];

            if ($name !== null) {
                $data['name'] = $name;
            }

            if ($scopes !== null) {
                $data['scopes'] = array_map(fn (TokenScope|string $scope): string => $scope instanceof TokenScope ? $scope->value : $scope, $scopes);
            }

            if ($expiresAt !== null) {
                $data['expiresAt'] = Carbon::parse($expiresAt);
            } elseif ($clearExpiry) {
                $data['expiresAt'] = null;
            }

            $existing = $this->tokenStore->find($tokenId);
            $scopeValues = $scopes === null ? null : array_map(fn (TokenScope|string $scope): string => $scope instanceof TokenScope ? $scope->value : $scope, $scopes);
            $scopesChanged = $existing !== null && $scopeValues !== null
                && (array_diff($scopeValues, $existing->scopes) !== [] || array_diff($existing->scopes, $scopeValues) !== []);
            $expiryChanged = $existing !== null && (($expiresAt !== null && ($existing->expiresAt === null || ! $expiresAt->equalTo($existing->expiresAt)))
                || ($clearExpiry && $existing->expiresAt !== null));
            if ($existing !== null && $existing->oauthClientId !== null && ($scopesChanged || $expiryChanged)) {
                app(OAuthDriver::class)->revokeRefreshTokensFor($existing->userId, $existing->oauthClientId);
            }
            $updated = $this->tokenStore->update($tokenId, $data);

            if ($updated !== null) {
                LifecycleEvents::legacy(new McpTokenSaved($updated));
                if ($existing !== null && $existing->capabilities !== null && $updated->capabilities === null) {
                    $this->notifyRevoked($existing);
                }
            }

            return $updated;
        });
    }

    /**
     * Regenerate a token's secret while keeping its name, scopes, and expiration.
     *
     * @return array{token: string, model: McpTokenData}|null
     */
    public function regenerateToken(string $tokenId): ?array
    {
        $existing = $this->tokenStore->find($tokenId);

        if ($existing === null) {
            return null;
        }

        $plainTextToken = Str::random(64);
        $newHash = hash('sha256', $plainTextToken);

        $updated = $this->tokenStore->update($tokenId, ['tokenHash' => $newHash]);

        if ($updated === null) {
            return null;
        }

        LifecycleEvents::legacy(new McpTokenSaved($updated));

        return [
            'token' => $plainTextToken,
            'model' => $updated,
        ];
    }

    /**
     * Find a token by its plain-text value without marking it as used.
     * Used by the revocation endpoint to look up tokens without polluting last_used_at.
     */
    public function findByPlainText(#[\SensitiveParameter] string $token): ?McpTokenData
    {
        $hashedToken = hash('sha256', $token);
        $tokenData = $this->tokenStore->findByHash($hashedToken);

        if ($tokenData === null) {
            return null;
        }

        if (! hash_equals($tokenData->tokenHash, $hashedToken)) {
            return null;
        }

        return $tokenData;
    }

    /**
     * Revoke all tokens for a specific OAuth client and user.
     * Used during token refresh to prevent accumulation of old access tokens.
     */
    public function revokeOAuthTokens(string $userId, string $oauthClientId, bool $endGrant = true): int
    {
        $tokens = $this->tokenStore->listForUser($userId);
        $revoked = 0;

        foreach ($tokens as $token) {
            if ($token->oauthClientId === $oauthClientId) {
                if (! $this->tokenStore->delete($token->id)) {
                    if ($this->tokenStore->find($token->id) !== null) {
                        throw new \RuntimeException('Access credential could not be revoked.');
                    }

                    continue;
                }
                LifecycleEvents::legacy(new McpTokenDeleted($token->name));
                if ($endGrant) {
                    $this->notifyRevoked($token);
                }
                $revoked++;
            }
        }

        return $revoked;
    }

    /**
     * Revoke a specific token by its ID.
     */
    public function revokeToken(string $tokenId): bool
    {
        return app(OAuthMutationLock::class)->run(fn (): bool => $this->deleteToken($tokenId));
    }

    /** Revoke a presented token without racing concurrent refresh rotation. */
    public function revokePlainTextToken(#[\SensitiveParameter] string $plainTextToken): void
    {
        app(OAuthMutationLock::class)->run(function () use ($plainTextToken): void {
            $token = $this->findByPlainText($plainTextToken);
            if ($token !== null) {
                $this->deleteToken($token->id);
            } else {
                app(OAuthDriver::class)->revokeRefreshToken($plainTextToken);
            }
        });
    }

    /** Caller must hold the OAuth mutation lock. */
    private function deleteToken(string $tokenId): bool
    {
        $existing = $this->tokenStore->find($tokenId);
        $deleted = $this->tokenStore->delete($tokenId);

        if ($existing !== null && $existing->oauthClientId !== null) {
            app(OAuthDriver::class)->revokeRefreshTokensFor($existing->userId, $existing->oauthClientId);
        }

        if ($deleted && $existing !== null) {
            LifecycleEvents::legacy(new McpTokenDeleted($existing->name));
            $this->notifyRevoked($existing);
        }

        return $deleted;
    }

    /**
     * Revoke all tokens for a specific user.
     */
    public function revokeAllForUser(string $userId): int
    {
        return app(OAuthMutationLock::class)->run(function () use ($userId): int {
            $driver = app(OAuthDriver::class);
            $driver->revokeGrantsForUser($userId);

            $tokens = $this->tokenStore->listForUser($userId);
            $deleted = $this->tokenStore->deleteForUser($userId);
            foreach ($tokens as $token) {
                if ($this->tokenStore->find($token->id) === null) {
                    $this->notifyRevoked($token);
                }
            }

            return $deleted;
        });
    }

    /**
     * List all tokens for a specific user.
     *
     * @return Collection<int, McpTokenData>
     */
    public function listTokensForUser(string $userId): Collection
    {
        return $this->tokenStore->listForUser($userId);
    }

    /**
     * List all tokens across all users (admin only).
     *
     * @return Collection<int, McpTokenData>
     */
    public function listAllTokens(): Collection
    {
        return $this->tokenStore->listAll();
    }

    /**
     * Delete all expired tokens.
     */
    public function pruneExpired(): int
    {
        return $this->tokenStore->pruneExpired();
    }

    /**
     * Check whether a token has expired.
     */
    public function isExpired(McpTokenData $token): bool
    {
        return $token->isExpired();
    }

    /**
     * Check whether a token has a specific scope.
     */
    public function hasScope(McpTokenData $token, TokenScope $scope): bool
    {
        return in_array('*', $token->scopes, true)
            || in_array($scope->value, $token->scopes, true);
    }

    private static function grantId(McpTokenData $token): string
    {
        return $token->capabilities->grantId ?? hash('sha256', 'legacy-access:' . $token->id);
    }

    private function notifyRevoked(McpTokenData $token): void
    {
        LifecycleEvents::dispatch(new GrantRevoked((string) Str::uuid(), self::grantId($token),
            $token->oauthClientId === null ? 'internal' : 'http', gmdate('c'), count($token->scopes)));
    }
}
