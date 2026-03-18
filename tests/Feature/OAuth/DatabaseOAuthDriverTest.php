<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Drivers\DatabaseOAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Models\OAuthClientModel;
use Cboxdk\StatamicMcp\OAuth\Models\OAuthCodeModel;
use Cboxdk\StatamicMcp\Tests\Concerns\OAuthDriverContractTests;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseOAuthDriverTest extends TestCase
{
    use OAuthDriverContractTests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations/oauth');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    protected function createDriver(): OAuthDriver
    {
        return new DatabaseOAuthDriver;
    }

    public function test_exchange_code_is_atomic(): void
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

        // Verify the code record exists and is not used
        $codeHash = hash('sha256', $code);
        $record = OAuthCodeModel::where('code', $codeHash)->first();
        expect($record)->not->toBeNull();
        expect($record->used)->toBeFalse();

        // Exchange the code
        $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $client->clientId,
            redirectUri: 'https://example.com/callback',
        );

        // Verify atomically marked as used in database
        $record->refresh();
        expect($record->used)->toBeTrue();
    }

    public function test_prune_deletes_database_records(): void
    {
        $driver = $this->createDriver();
        config(['statamic.mcp.oauth.code_ttl' => 60]);

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

        // Exchange to mark as used
        $driver->exchangeCode(
            code: $code,
            codeVerifier: $codeVerifier,
            clientId: $client->clientId,
            redirectUri: 'https://example.com/callback',
        );

        expect(OAuthCodeModel::count())->toBe(1);

        $pruned = $driver->prune();

        expect($pruned)->toBe(1);
        expect(OAuthCodeModel::count())->toBe(0);
    }

    public function test_client_stored_in_database(): void
    {
        $driver = $this->createDriver();
        $client = $driver->registerClient('DB App', ['https://example.com/callback']);

        $model = OAuthClientModel::find($client->clientId);

        expect($model)->not->toBeNull();
        expect($model->client_name)->toBe('DB App');
        expect($model->redirect_uris)->toBe(['https://example.com/callback']);
    }
}
