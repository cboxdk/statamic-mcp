<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Drivers;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\OAuth\Concerns\ValidatesRedirectUris;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\OAuth\Models\OAuthClientModel;
use Cboxdk\StatamicMcp\OAuth\Models\OAuthCodeModel;
use Cboxdk\StatamicMcp\OAuth\Models\OAuthRefreshTokenModel;
use Cboxdk\StatamicMcp\OAuth\OAuthAuthCode;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;

/**
 * Database-backed OAuth driver using Eloquent models.
 *
 * This driver uses atomic database operations for code exchange,
 * making it safe for horizontally scaled deployments with multiple
 * servers or container instances.
 */
class DatabaseOAuthDriver implements OAuthDriver
{
    use ValidatesRedirectUris;

    /** @param array<int, string> $redirectUris */
    public function registerClient(string $clientName, array $redirectUris): OAuthClient
    {
        foreach ($redirectUris as $uri) {
            if (! $this->validateRedirectUri($uri)) {
                throw new OAuthException(
                    'invalid_request',
                    "Invalid redirect URI: {$uri}. Must be an absolute HTTPS URL (http allowed for localhost/127.0.0.1) with no fragment.",
                );
            }
        }

        /** @var int $maxClients */
        $maxClients = config('statamic.mcp.oauth.max_clients', 1000);
        $count = OAuthClientModel::count();

        if ($count >= $maxClients) {
            throw new OAuthException(
                'invalid_request',
                'Maximum client registrations exceeded',
            );
        }

        $clientId = 'mcp_' . bin2hex(random_bytes(16));

        $model = OAuthClientModel::create([
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
        ]);

        return $this->modelToClient($model);
    }

    public function findClient(string $clientId): ?OAuthClient
    {
        $model = OAuthClientModel::find($clientId);

        if (! $model instanceof OAuthClientModel) {
            return null;
        }

        return $this->modelToClient($model);
    }

    /** @param array<int, string> $scopes */
    public function createAuthCode(
        string $clientId,
        string $userId,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $redirectUri,
    ): string {
        $code = bin2hex(random_bytes(32));
        $codeHash = hash('sha256', $code);

        /** @var int $codeTtl */
        $codeTtl = config('statamic.mcp.oauth.code_ttl', 600);

        OAuthCodeModel::create([
            'code' => $codeHash,
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => $scopes,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'redirect_uri' => $redirectUri,
            'expires_at' => Carbon::now()->addSeconds($codeTtl),
            'used' => false,
        ]);

        return $code;
    }

    public function exchangeCode(
        string $code,
        string $codeVerifier,
        string $clientId,
        string $redirectUri,
    ): OAuthAuthCode {
        $codeHash = hash('sha256', $code);

        // Atomic mark-as-used: only consume the code if client_id, redirect_uri,
        // and expiry all match — preventing an attacker from burning a code they
        // possess without also knowing the correct client binding.
        $affected = OAuthCodeModel::where('code', $codeHash)
            ->where('used', false)
            ->where('client_id', $clientId)
            ->where('redirect_uri', $redirectUri)
            ->where('expires_at', '>', now())
            ->update(['used' => true]);

        if ($affected === 0) {
            // Fetch the record to return a specific, meaningful error.
            $codeRecord = OAuthCodeModel::where('code', $codeHash)->first();

            if (! $codeRecord instanceof OAuthCodeModel) {
                throw new OAuthException('invalid_grant', 'Authorization code not found');
            }

            if ($codeRecord->used) {
                throw new OAuthException('invalid_grant', 'Authorization code has already been used');
            }

            if ($codeRecord->expires_at->isPast()) {
                throw new OAuthException('invalid_grant', 'Authorization code has expired');
            }

            if ($codeRecord->client_id !== $clientId) {
                throw new OAuthException('invalid_client', 'Client ID mismatch');
            }

            throw new OAuthException('invalid_request', 'Redirect URI mismatch');
        }

        // Fetch the consumed record for PKCE verification
        $model = OAuthCodeModel::where('code', $codeHash)->first();

        if (! $model instanceof OAuthCodeModel) {
            throw new OAuthException('invalid_grant', 'Authorization code not found');
        }

        // Note: expiry, client_id, and redirect_uri were already validated atomically
        // in the UPDATE WHERE clause above. Only PKCE remains to check.

        // PKCE verification — only S256 is supported
        if ($model->code_challenge_method !== 'S256') {
            throw new OAuthException('invalid_request', 'Unsupported code challenge method: ' . $model->code_challenge_method);
        }

        $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        if (! hash_equals($model->code_challenge, $computedChallenge)) {
            throw new OAuthException('invalid_request', 'PKCE verification failed');
        }

        return new OAuthAuthCode(
            clientId: $model->client_id,
            userId: $model->user_id,
            scopes: $model->scopes,
            redirectUri: $model->redirect_uri,
        );
    }

    /** @param array<int, string> $scopes */
    public function createRefreshToken(string $userId, string $clientId, array $scopes): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        /** @var int $refreshTtl */
        $refreshTtl = config('statamic.mcp.oauth.refresh_token_ttl', 2592000);

        OAuthRefreshTokenModel::create([
            'token_hash' => $tokenHash,
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => $scopes,
            'expires_at' => Carbon::now()->addSeconds($refreshTtl),
            'used' => false,
        ]);

        return $token;
    }

    public function exchangeRefreshToken(string $refreshToken, string $clientId): OAuthAuthCode
    {
        $tokenHash = hash('sha256', $refreshToken);

        // Atomic mark-as-used: only consume the token if client_id and expiry
        // match — preventing a token from being burned by the wrong client.
        $affected = OAuthRefreshTokenModel::where('token_hash', $tokenHash)
            ->where('used', false)
            ->where('client_id', $clientId)
            ->where('expires_at', '>', now())
            ->update(['used' => true]);

        if ($affected === 0) {
            // Fetch the record to return a specific, meaningful error.
            $tokenRecord = OAuthRefreshTokenModel::where('token_hash', $tokenHash)->first();

            if (! $tokenRecord instanceof OAuthRefreshTokenModel) {
                throw new OAuthException('invalid_grant', 'Refresh token not found');
            }

            if ($tokenRecord->used) {
                throw new OAuthException('invalid_grant', 'Refresh token has already been used');
            }

            if ($tokenRecord->expires_at->isPast()) {
                throw new OAuthException('invalid_grant', 'Refresh token has expired');
            }

            throw new OAuthException('invalid_client', 'Client ID mismatch');
        }

        // Fetch the consumed record for scopes/userId
        $model = OAuthRefreshTokenModel::where('token_hash', $tokenHash)->first();

        if (! $model instanceof OAuthRefreshTokenModel) {
            throw new OAuthException('invalid_grant', 'Refresh token not found');
        }

        return new OAuthAuthCode(
            clientId: $model->client_id,
            userId: $model->user_id,
            scopes: $model->scopes,
            redirectUri: '',
        );
    }

    public function revokeRefreshToken(string $refreshToken): bool
    {
        $tokenHash = hash('sha256', $refreshToken);

        return OAuthRefreshTokenModel::where('token_hash', $tokenHash)->delete() > 0;
    }

    public function prune(): int
    {
        $pruned = 0;

        // Prune used or expired codes
        /** @var int $codePruned */
        $codePruned = OAuthCodeModel::where('used', true)
            ->orWhere('expires_at', '<', Carbon::now())
            ->delete();
        $pruned += $codePruned;

        // Prune used or expired refresh tokens
        /** @var int $refreshPruned */
        $refreshPruned = OAuthRefreshTokenModel::where('used', true)
            ->orWhere('expires_at', '<', Carbon::now())
            ->delete();
        $pruned += $refreshPruned;

        // Prune expired clients (if client_ttl is configured)
        /** @var int $clientTtl */
        $clientTtl = config('statamic.mcp.oauth.client_ttl', 0);

        if ($clientTtl > 0) {
            $cutoff = Carbon::now()->subSeconds($clientTtl);

            /** @var int $clientPruned */
            $clientPruned = OAuthClientModel::where('created_at', '<', $cutoff)->delete();
            $pruned += $clientPruned;
        }

        return $pruned;
    }

    private function modelToClient(OAuthClientModel $model): OAuthClient
    {
        return new OAuthClient(
            clientId: $model->client_id,
            clientName: $model->client_name,
            redirectUris: $model->redirect_uris,
            createdAt: $model->created_at !== null ? Carbon::instance($model->created_at) : Carbon::now(),
        );
    }
}
