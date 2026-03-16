<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;

it('constructs with expected properties', function (): void {
    $createdAt = Carbon::parse('2026-01-01 12:00:00');

    $client = new OAuthClient(
        clientId: 'client-abc',
        clientName: 'My App',
        redirectUris: ['https://example.com/callback'],
        createdAt: $createdAt,
    );

    expect($client->clientId)->toBe('client-abc')
        ->and($client->clientName)->toBe('My App')
        ->and($client->redirectUris)->toBe(['https://example.com/callback'])
        ->and($client->createdAt)->toEqual($createdAt);
});

it('accepts multiple redirect URIs', function (): void {
    $client = new OAuthClient(
        clientId: 'client-xyz',
        clientName: 'Multi Redirect App',
        redirectUris: ['https://app.example.com/callback', 'https://app.example.com/oauth'],
        createdAt: Carbon::now(),
    );

    expect($client->redirectUris)->toHaveCount(2);
});
