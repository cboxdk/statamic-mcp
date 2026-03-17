<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
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
     * @param  array<int, TokenScope>  $scopes
     *
     * @return array{token: string, model: McpTokenData}
     */
    public function createToken(string $userId, string $name, array $scopes, ?Carbon $expiresAt = null, ?string $oauthClientId = null, ?string $oauthClientName = null): array
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
        $scopeStrings = array_map(fn (TokenScope $scope): string => $scope->value, $scopes);

        $tokenData = $this->tokenStore->create(
            $userId,
            $name,
            $hash,
            $scopeStrings,
            $expiresAt !== null ? Carbon::parse($expiresAt) : null,
            $oauthClientId,
            $oauthClientName,
        );

        return [
            'token' => $plainTextToken,
            'model' => $tokenData,
        ];
    }

    /**
     * Validate a plain-text token and return the data if valid.
     */
    public function validateToken(string $token): ?McpTokenData
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
        );
    }

    /**
     * Update an existing token's name, scopes, and/or expiration.
     *
     * @param  array<int, TokenScope>|null  $scopes
     */
    public function updateToken(string $tokenId, ?string $name = null, ?array $scopes = null, ?Carbon $expiresAt = null, bool $clearExpiry = false): ?McpTokenData
    {
        /** @var array<string, mixed> $data */
        $data = [];

        if ($name !== null) {
            $data['name'] = $name;
        }

        if ($scopes !== null) {
            $data['scopes'] = array_map(fn (TokenScope $scope): string => $scope->value, $scopes);
        }

        if ($expiresAt !== null) {
            $data['expiresAt'] = Carbon::parse($expiresAt);
        } elseif ($clearExpiry) {
            $data['expiresAt'] = null;
        }

        return $this->tokenStore->update($tokenId, $data);
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

        return [
            'token' => $plainTextToken,
            'model' => $updated,
        ];
    }

    /**
     * Revoke a specific token by its ID.
     */
    public function revokeToken(string $tokenId): bool
    {
        return $this->tokenStore->delete($tokenId);
    }

    /**
     * Revoke all tokens for a specific user.
     */
    public function revokeAllForUser(string $userId): int
    {
        return $this->tokenStore->deleteForUser($userId);
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
        return $token->expiresAt !== null && now()->greaterThan($token->expiresAt);
    }

    /**
     * Check whether a token has a specific scope.
     */
    public function hasScope(McpTokenData $token, TokenScope $scope): bool
    {
        return in_array('*', $token->scopes, true)
            || in_array($scope->value, $token->scopes, true);
    }
}
