<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Drivers;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\OAuth\Concerns\ValidatesRedirectUris;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\OAuth\OAuthAuthCode;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;
use Symfony\Component\Yaml\Yaml;

/**
 * Built-in OAuth driver using file-based storage.
 *
 * IMPORTANT: This driver uses flock() for mutual exclusion, which only works
 * reliably on local filesystems. For horizontally scaled deployments (multiple
 * servers, NFS, or container shared volumes), use a database-backed driver instead.
 */
class BuiltInOAuthDriver implements OAuthDriver
{
    use ValidatesRedirectUris;

    private readonly string $clientsDir;

    private readonly string $codesDir;

    private readonly string $refreshDir;

    public function __construct(?string $clientsDir = null, ?string $codesDir = null, ?string $refreshDir = null)
    {
        if ($clientsDir === null) {
            /** @var string $default */
            $default = config('statamic.mcp.storage.oauth_clients_path', storage_path('statamic-mcp/oauth/clients'));
            $this->clientsDir = $default;
        } else {
            $this->clientsDir = $clientsDir;
        }

        if ($codesDir === null) {
            /** @var string $default */
            $default = config('statamic.mcp.storage.oauth_codes_path', storage_path('statamic-mcp/oauth/codes'));
            $this->codesDir = $default;
        } else {
            $this->codesDir = $codesDir;
        }

        if ($refreshDir === null) {
            /** @var string $default */
            $default = config('statamic.mcp.storage.oauth_refresh_path', storage_path('statamic-mcp/oauth/refresh'));
            $this->refreshDir = $default;
        } else {
            $this->refreshDir = $refreshDir;
        }

        if (! is_dir($this->clientsDir)) {
            mkdir($this->clientsDir, 0755, true);
        }

        if (! is_dir($this->codesDir)) {
            mkdir($this->codesDir, 0755, true);
        }

        if (! is_dir($this->refreshDir)) {
            mkdir($this->refreshDir, 0755, true);
        }
    }

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
        $existingFiles = glob($this->clientsDir . '/*.yaml');
        $count = $existingFiles !== false ? count($existingFiles) : 0;

        if ($count >= $maxClients) {
            throw new OAuthException(
                'invalid_request',
                'Maximum client registrations exceeded',
            );
        }

        $clientId = 'mcp_' . bin2hex(random_bytes(16));
        $this->sanitizeFilename($clientId); // Validate generated ID is filesystem-safe
        $now = Carbon::now();

        /** @var array<string, mixed> $data */
        $data = [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'created_at' => $now->toIso8601String(),
        ];

        $filePath = $this->clientsDir . '/' . $clientId . '.yaml';
        file_put_contents($filePath, Yaml::dump($data, 2, 4, Yaml::DUMP_NULL_AS_TILDE), LOCK_EX);

        return $this->toClient($data);
    }

    /**
     * Validate that a filename component is safe for filesystem use.
     *
     * @throws OAuthException
     */
    private function sanitizeFilename(string $input): string
    {
        if ($input !== basename($input) || str_contains($input, "\x00") || $input === '' || $input === '.' || $input === '..') {
            throw new OAuthException('invalid_request', 'Invalid identifier format');
        }

        return $input;
    }

    public function findClient(string $clientId): ?OAuthClient
    {
        $filePath = $this->clientsDir . '/' . $this->sanitizeFilename($clientId) . '.yaml';

        if (! file_exists($filePath)) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = Yaml::parseFile($filePath);

        if (! is_array($data)) {
            return null;
        }

        return $this->toClient($data);
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

        /** @var array<string, mixed> $data */
        $data = [
            'code_hash' => $codeHash,
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => $scopes,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'redirect_uri' => $redirectUri,
            'expires_at' => Carbon::now()->addSeconds($codeTtl)->toIso8601String(),
            'used' => false,
        ];

        $filePath = $this->codesDir . '/' . $this->sanitizeFilename($codeHash) . '.json';
        file_put_contents($filePath, (string) json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);

        return $code;
    }

    public function exchangeCode(
        string $code,
        string $codeVerifier,
        string $clientId,
        string $redirectUri,
    ): OAuthAuthCode {
        $codeHash = hash('sha256', $code);
        $filePath = $this->codesDir . '/' . $this->sanitizeFilename($codeHash) . '.json';

        if (! file_exists($filePath)) {
            throw new OAuthException('invalid_grant', 'Authorization code not found');
        }

        $handle = fopen($filePath, 'c+');

        if ($handle === false) {
            throw new OAuthException('invalid_grant', 'Authorization code not found');
        }

        flock($handle, LOCK_EX);

        try {
            $stat = fstat($handle);
            $size = $stat !== false ? $stat['size'] : 0;

            if ($size <= 0) {
                throw new OAuthException('invalid_grant', 'Authorization code not found');
            }

            rewind($handle);
            /** @var int<1, max> $size */
            $contents = fread($handle, $size);

            if ($contents === false) {
                throw new OAuthException('invalid_grant', 'Authorization code not found');
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($contents, true);

            if (! is_array($data)) {
                throw new OAuthException('invalid_grant', 'Authorization code not found');
            }

            // Check if already used
            if (($data['used'] ?? false) === true) {
                throw new OAuthException('invalid_grant', 'Authorization code has already been used');
            }

            // Check expiry
            $expiresAt = is_string($data['expires_at'] ?? null) ? $data['expires_at'] : '';

            if ($expiresAt !== '' && Carbon::parse($expiresAt)->isPast()) {
                throw new OAuthException('invalid_grant', 'Authorization code has expired');
            }

            // Check client_id
            if (($data['client_id'] ?? '') !== $clientId) {
                throw new OAuthException('invalid_client', 'Client ID mismatch');
            }

            // Check redirect_uri
            if (($data['redirect_uri'] ?? '') !== $redirectUri) {
                throw new OAuthException('invalid_request', 'Redirect URI mismatch');
            }

            // PKCE verification — only S256 is supported
            $method = $data['code_challenge_method'] ?? '';
            if ($method !== 'S256') {
                throw new OAuthException('invalid_request', 'Unsupported code challenge method: ' . (is_string($method) ? $method : ''));
            }

            $storedChallenge = is_string($data['code_challenge'] ?? null) ? $data['code_challenge'] : '';
            $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

            if (! hash_equals($storedChallenge, $computedChallenge)) {
                throw new OAuthException('invalid_request', 'PKCE verification failed');
            }

            // Mark as used
            $data['used'] = true;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data, JSON_PRETTY_PRINT));

            /** @var array<int, string> $scopes */
            $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

            return new OAuthAuthCode(
                clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : '',
                userId: is_string($data['user_id'] ?? null) ? $data['user_id'] : '',
                scopes: $scopes,
                redirectUri: is_string($data['redirect_uri'] ?? null) ? $data['redirect_uri'] : '',
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<int, string> $scopes */
    public function createRefreshToken(string $userId, string $clientId, array $scopes): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        /** @var int $refreshTtl */
        $refreshTtl = config('statamic.mcp.oauth.refresh_token_ttl', 2592000);

        /** @var array<string, mixed> $data */
        $data = [
            'token_hash' => $tokenHash,
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => $scopes,
            'expires_at' => Carbon::now()->addSeconds($refreshTtl)->toIso8601String(),
            'used' => false,
        ];

        $filePath = $this->refreshDir . '/' . $this->sanitizeFilename($tokenHash) . '.json';
        file_put_contents($filePath, (string) json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);

        return $token;
    }

    public function exchangeRefreshToken(string $refreshToken, string $clientId): OAuthAuthCode
    {
        $tokenHash = hash('sha256', $refreshToken);
        $filePath = $this->refreshDir . '/' . $this->sanitizeFilename($tokenHash) . '.json';

        if (! file_exists($filePath)) {
            throw new OAuthException('invalid_grant', 'Refresh token not found');
        }

        $handle = fopen($filePath, 'c+');

        if ($handle === false) {
            throw new OAuthException('invalid_grant', 'Refresh token not found');
        }

        flock($handle, LOCK_EX);

        try {
            $stat = fstat($handle);
            $size = $stat !== false ? $stat['size'] : 0;

            if ($size <= 0) {
                throw new OAuthException('invalid_grant', 'Refresh token not found');
            }

            rewind($handle);
            /** @var int<1, max> $size */
            $contents = fread($handle, $size);

            if ($contents === false) {
                throw new OAuthException('invalid_grant', 'Refresh token not found');
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($contents, true);

            if (! is_array($data)) {
                throw new OAuthException('invalid_grant', 'Refresh token not found');
            }

            // Check if already used (single-use rotation)
            if (($data['used'] ?? false) === true) {
                throw new OAuthException('invalid_grant', 'Refresh token has already been used');
            }

            // Check expiry
            $expiresAt = is_string($data['expires_at'] ?? null) ? $data['expires_at'] : '';

            if ($expiresAt !== '' && Carbon::parse($expiresAt)->isPast()) {
                throw new OAuthException('invalid_grant', 'Refresh token has expired');
            }

            // Check client_id
            if (($data['client_id'] ?? '') !== $clientId) {
                throw new OAuthException('invalid_client', 'Client ID mismatch');
            }

            // Mark as used (rotation)
            $data['used'] = true;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data, JSON_PRETTY_PRINT));

            /** @var array<int, string> $scopes */
            $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

            return new OAuthAuthCode(
                clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : '',
                userId: is_string($data['user_id'] ?? null) ? $data['user_id'] : '',
                scopes: $scopes,
                redirectUri: '',
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function revokeRefreshToken(string $refreshToken): bool
    {
        $tokenHash = hash('sha256', $refreshToken);
        $filePath = $this->refreshDir . '/' . $this->sanitizeFilename($tokenHash) . '.json';

        if (! file_exists($filePath)) {
            return false;
        }

        return unlink($filePath);
    }

    public function prune(): int
    {
        $pruned = 0;
        $now = Carbon::now();

        // Prune expired clients
        /** @var int $clientTtl */
        $clientTtl = config('statamic.mcp.oauth.client_ttl', 0);

        if ($clientTtl > 0) {
            $clientFiles = glob($this->clientsDir . '/*.yaml');

            if ($clientFiles !== false) {
                foreach ($clientFiles as $file) {
                    /** @var array<string, mixed>|null $data */
                    $data = Yaml::parseFile($file);

                    if (! is_array($data)) {
                        continue;
                    }

                    $createdAt = is_string($data['created_at'] ?? null) ? $data['created_at'] : null;

                    if ($createdAt === null) {
                        continue;
                    }

                    $expiry = Carbon::parse($createdAt)->addSeconds($clientTtl);

                    if ($expiry->isPast()) {
                        unlink($file);
                        $pruned++;
                    }
                }
            }
        }

        // Prune used or expired codes
        $codeFiles = glob($this->codesDir . '/*.json');

        if ($codeFiles !== false) {
            foreach ($codeFiles as $file) {
                $contents = file_get_contents($file);

                if ($contents === false) {
                    continue;
                }

                /** @var array<string, mixed>|null $data */
                $data = json_decode($contents, true);

                if (! is_array($data)) {
                    continue;
                }

                $shouldPrune = false;

                // Prune if used
                if (($data['used'] ?? false) === true) {
                    $shouldPrune = true;
                }

                // Prune if expired
                $expiresAt = is_string($data['expires_at'] ?? null) ? $data['expires_at'] : null;

                if ($expiresAt !== null && Carbon::parse($expiresAt)->isBefore($now)) {
                    $shouldPrune = true;
                }

                if ($shouldPrune) {
                    unlink($file);
                    $pruned++;
                }
            }
        }

        // Prune used or expired refresh tokens
        $refreshFiles = glob($this->refreshDir . '/*.json');

        if ($refreshFiles !== false) {
            foreach ($refreshFiles as $file) {
                $contents = file_get_contents($file);

                if ($contents === false) {
                    continue;
                }

                /** @var array<string, mixed>|null $data */
                $data = json_decode($contents, true);

                if (! is_array($data)) {
                    continue;
                }

                $shouldPrune = false;

                // Prune if used
                if (($data['used'] ?? false) === true) {
                    $shouldPrune = true;
                }

                // Prune if expired
                $expiresAt = is_string($data['expires_at'] ?? null) ? $data['expires_at'] : null;

                if ($expiresAt !== null && Carbon::parse($expiresAt)->isBefore($now)) {
                    $shouldPrune = true;
                }

                if ($shouldPrune) {
                    unlink($file);
                    $pruned++;
                }
            }
        }

        return $pruned;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toClient(array $data): OAuthClient
    {
        /** @var array<int, string> $redirectUris */
        $redirectUris = is_array($data['redirect_uris'] ?? null) ? $data['redirect_uris'] : [];

        $createdAtRaw = is_string($data['created_at'] ?? null) ? $data['created_at'] : null;
        $createdAt = $createdAtRaw !== null ? Carbon::parse($createdAtRaw) : Carbon::now();

        return new OAuthClient(
            clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : '',
            clientName: is_string($data['client_name'] ?? null) ? $data['client_name'] : '',
            redirectUris: $redirectUris,
            createdAt: $createdAt,
        );
    }
}
