<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdClientId;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdMetadata;
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

it('defaults new CIMD properties to null/false for backward compatibility', function (): void {
    $client = new OAuthClient(
        clientId: 'client-abc',
        clientName: 'My App',
        redirectUris: ['https://example.com/callback'],
        createdAt: Carbon::now(),
    );

    expect($client->clientUri)->toBeNull()
        ->and($client->logoUri)->toBeNull()
        ->and($client->isCimd)->toBeFalse();
});

it('accepts explicit CIMD properties', function (): void {
    $client = new OAuthClient(
        clientId: 'https://example.com/',
        clientName: 'CIMD App',
        redirectUris: ['https://example.com/callback'],
        createdAt: Carbon::now(),
        clientUri: 'https://example.com',
        logoUri: 'https://example.com/logo.png',
        isCimd: true,
    );

    expect($client->clientUri)->toBe('https://example.com')
        ->and($client->logoUri)->toBe('https://example.com/logo.png')
        ->and($client->isCimd)->toBeTrue();
});

it('creates from CimdMetadata with isCimd true', function (): void {
    $clientId = CimdClientId::tryFrom('https://example.com/oauth/metadata.json');
    expect($clientId)->not->toBeNull();

    $metadata = CimdMetadata::fromArray([
        'client_id' => 'https://example.com/oauth/metadata.json',
        'client_name' => 'Example CIMD App',
        'redirect_uris' => ['https://example.com/callback'],
        'client_uri' => 'https://example.com',
        'logo_uri' => 'https://example.com/logo.png',
    ], $clientId);

    $client = OAuthClient::fromCimdMetadata($metadata);

    expect($client->clientId)->toBe('https://example.com/oauth/metadata.json')
        ->and($client->clientName)->toBe('Example CIMD App')
        ->and($client->redirectUris)->toBe(['https://example.com/callback'])
        ->and($client->clientUri)->toBe('https://example.com')
        ->and($client->logoUri)->toBe('https://example.com/logo.png')
        ->and($client->isCimd)->toBeTrue()
        ->and($client->registeredIp)->toBeNull();
});

it('creates from CimdMetadata with null optional fields', function (): void {
    $clientId = CimdClientId::tryFrom('https://example.com/oauth/metadata.json');
    expect($clientId)->not->toBeNull();

    $metadata = CimdMetadata::fromArray([
        'client_id' => 'https://example.com/oauth/metadata.json',
        'client_name' => 'Minimal App',
        'redirect_uris' => ['https://example.com/callback'],
    ], $clientId);

    $client = OAuthClient::fromCimdMetadata($metadata);

    expect($client->clientUri)->toBeNull()
        ->and($client->logoUri)->toBeNull()
        ->and($client->isCimd)->toBeTrue();
});

it('sets createdAt to current time from CimdMetadata factory', function (): void {
    $clientId = CimdClientId::tryFrom('https://example.com/oauth/metadata.json');
    expect($clientId)->not->toBeNull();

    $before = Carbon::now();

    $metadata = CimdMetadata::fromArray([
        'client_id' => 'https://example.com/oauth/metadata.json',
        'client_name' => 'Time Test App',
        'redirect_uris' => ['https://example.com/callback'],
    ], $clientId);

    $client = OAuthClient::fromCimdMetadata($metadata);

    $after = Carbon::now();

    expect($client->createdAt->gte($before))->toBeTrue()
        ->and($client->createdAt->lte($after))->toBeTrue();
});
