<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\OAuth\OAuthAuthCode;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;

/**
 * Shared contract tests for all OAuthDriver implementations.
 *
 * Classes using this trait must implement createDriver().
 */
trait OAuthDriverContractTests
{
    abstract protected function createDriver(): OAuthDriver;

    public function test_register_client(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        expect($client)->toBeInstanceOf(OAuthClient::class);
        expect($client->clientName)->toBe('Test App');
        expect($client->redirectUris)->toBe(['https://example.com/callback']);
        expect($client->clientId)->toStartWith('mcp_');
        expect(strlen($client->clientId))->toBe(36); // 'mcp_' + 32 hex chars
        expect($client->createdAt)->toBeInstanceOf(Carbon::class);
    }

    public function test_register_client_validates_redirect_uris(): void
    {
        $driver = $this->createDriver();

        $this->expectException(OAuthException::class);
        $driver->registerClient('Test App', ['http://example.com/callback']);
    }

    public function test_register_client_rejects_fragment_in_redirect_uri(): void
    {
        $driver = $this->createDriver();

        $this->expectException(OAuthException::class);
        $driver->registerClient('Test App', ['https://example.com/callback#fragment']);
    }

    public function test_register_client_allows_localhost_http(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Dev App', ['http://localhost/callback']);

        expect($client)->toBeInstanceOf(OAuthClient::class);
        expect($client->redirectUris)->toBe(['http://localhost/callback']);
    }

    public function test_register_client_allows_127_0_0_1_http(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Dev App', ['http://127.0.0.1/callback']);

        expect($client)->toBeInstanceOf(OAuthClient::class);
        expect($client->redirectUris)->toBe(['http://127.0.0.1/callback']);
    }

    public function test_register_client_enforces_max_clients(): void
    {
        $driver = $this->createDriver();
        config(['statamic.mcp.oauth.max_clients' => 1]);

        $driver->registerClient('App 1', ['https://example.com/cb1']);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Maximum client registrations exceeded');

        $driver->registerClient('App 2', ['https://example.com/cb2']);
    }

    public function test_find_client(): void
    {
        $driver = $this->createDriver();
        $created = $driver->registerClient('Find Me', ['https://example.com/callback']);

        $found = $driver->findClient($created->clientId);

        expect($found)->toBeInstanceOf(OAuthClient::class);
        expect($found->clientId)->toBe($created->clientId);
        expect($found->clientName)->toBe('Find Me');
        expect($found->redirectUris)->toBe(['https://example.com/callback']);
    }

    public function test_find_client_returns_null_for_missing(): void
    {
        $driver = $this->createDriver();
        $found = $driver->findClient('mcp_nonexistent');

        expect($found)->toBeNull();
    }

    public function test_create_and_exchange_auth_code(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-123',
            scopes: ['content:read', 'entries:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        expect($code)->toBeString();
        expect(strlen($code))->toBe(64); // 32 bytes = 64 hex chars

        $authCode = $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $client->clientId,
            redirectUri: 'https://example.com/callback',
        );

        expect($authCode)->toBeInstanceOf(OAuthAuthCode::class);
        expect($authCode->clientId)->toBe($client->clientId);
        expect($authCode->userId)->toBe('user-123');
        expect($authCode->scopes)->toBe(['content:read', 'entries:read']);
        expect($authCode->redirectUri)->toBe('https://example.com/callback');
    }

    public function test_exchange_code_rejects_expired(): void
    {
        $driver = $this->createDriver();
        config(['statamic.mcp.oauth.code_ttl' => 0]);

        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-123',
            scopes: ['content:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        // Advance time to ensure expiry
        Carbon::setTestNow(Carbon::now()->addSeconds(2));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Authorization code has expired');

        $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $client->clientId,
            redirectUri: 'https://example.com/callback',
        );
    }

    public function test_exchange_code_rejects_used(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-123',
            scopes: ['content:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        // First exchange succeeds
        $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $client->clientId,
            redirectUri: 'https://example.com/callback',
        );

        // Second exchange fails
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Authorization code has already been used');

        $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $client->clientId,
            redirectUri: 'https://example.com/callback',
        );
    }

    public function test_exchange_code_rejects_wrong_client(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-123',
            scopes: ['content:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Client ID mismatch');

        $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: 'mcp_wrong_client_id_here_000000',
            redirectUri: 'https://example.com/callback',
        );
    }

    public function test_exchange_code_rejects_wrong_redirect_uri(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-123',
            scopes: ['content:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Redirect URI mismatch');

        $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $client->clientId,
            redirectUri: 'https://example.com/wrong-callback',
        );
    }

    public function test_exchange_code_rejects_invalid_pkce(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $driver->createAuthCode(
            clientId: $client->clientId,
            userId: 'user-123',
            scopes: ['content:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('PKCE verification failed');

        $driver->exchangeCode(
            code: $code,
            codeVerifier: 'wrong_verifier_value_that_does_not_match_challenge',
            clientId: $client->clientId,
            redirectUri: 'https://example.com/callback',
        );
    }

    public function test_prune_removes_expired_clients_and_codes(): void
    {
        $driver = $this->createDriver();
        config(['statamic.mcp.oauth.client_ttl' => 60]);
        config(['statamic.mcp.oauth.code_ttl' => 60]);

        // Create a client in the past
        Carbon::setTestNow(Carbon::now()->subSeconds(120));
        $oldClient = $driver->registerClient('Old App', ['https://example.com/callback']);

        // Create a code and mark it used
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $code = $driver->createAuthCode(
            clientId: $oldClient->clientId,
            userId: 'user-123',
            scopes: ['content:read'],
            codeChallenge: $codeChallenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.com/callback',
        );

        // Exchange the code to mark it used
        $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $oldClient->clientId,
            redirectUri: 'https://example.com/callback',
        );

        // Return to present
        Carbon::setTestNow(null);

        // Create a fresh client that should survive pruning
        $freshClient = $driver->registerClient('Fresh App', ['https://example.com/callback2']);

        $pruned = $driver->prune();

        // Should have pruned the old client + the used code
        expect($pruned)->toBeGreaterThanOrEqual(2);

        // Fresh client should still exist
        expect($driver->findClient($freshClient->clientId))->not->toBeNull();

        // Old client should be gone
        expect($driver->findClient($oldClient->clientId))->toBeNull();
    }

    public function test_create_and_exchange_refresh_token(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $token = $driver->createRefreshToken(
            userId: 'user-123',
            clientId: $client->clientId,
            scopes: ['content:read', 'entries:read'],
        );

        expect($token)->toBeString();
        expect(strlen($token))->toBe(64); // 32 bytes = 64 hex chars

        $authCode = $driver->exchangeRefreshToken($token, $client->clientId);

        expect($authCode)->toBeInstanceOf(OAuthAuthCode::class);
        expect($authCode->clientId)->toBe($client->clientId);
        expect($authCode->userId)->toBe('user-123');
        expect($authCode->scopes)->toBe(['content:read', 'entries:read']);
        expect($authCode->redirectUri)->toBe('');
    }

    public function test_refresh_token_is_single_use(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $token = $driver->createRefreshToken(
            userId: 'user-123',
            clientId: $client->clientId,
            scopes: ['content:read'],
        );

        // First exchange succeeds
        $driver->exchangeRefreshToken($token, $client->clientId);

        // Second exchange fails (token file deleted after single use)
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Refresh token not found');

        $driver->exchangeRefreshToken($token, $client->clientId);
    }

    public function test_refresh_token_rejects_wrong_client(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $token = $driver->createRefreshToken(
            userId: 'user-123',
            clientId: $client->clientId,
            scopes: ['content:read'],
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Client ID mismatch');

        $driver->exchangeRefreshToken($token, 'mcp_wrong_client_id_here_000000');
    }

    public function test_refresh_token_rejects_expired(): void
    {
        $driver = $this->createDriver();
        config(['statamic.mcp.oauth.refresh_token_ttl' => 0]);

        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $token = $driver->createRefreshToken(
            userId: 'user-123',
            clientId: $client->clientId,
            scopes: ['content:read'],
        );

        // Advance time to ensure expiry
        Carbon::setTestNow(Carbon::now()->addSeconds(2));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Refresh token has expired');

        $driver->exchangeRefreshToken($token, $client->clientId);
    }

    public function test_refresh_token_not_found(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('Test App', ['https://example.com/callback']);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Refresh token not found');

        $driver->exchangeRefreshToken('nonexistent_token_value_here', $client->clientId);
    }
}
