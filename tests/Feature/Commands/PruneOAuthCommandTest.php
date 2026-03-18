<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;

it('prunes expired oauth entries', function (): void {
    $driver = app(OAuthDriver::class);

    // Register a client and create a used code
    $client = $driver->registerClient('Test', ['https://example.com/callback']);
    $driver->createAuthCode($client->clientId, 'user-1', ['*'], 'challenge', 'S256', 'https://example.com/callback');

    // Set code_ttl to 0 so codes expire immediately
    config(['statamic.mcp.oauth.code_ttl' => 0]);

    $this->artisan('mcp:prune-oauth')
        ->expectsOutputToContain('Pruned')
        ->assertSuccessful();
});
