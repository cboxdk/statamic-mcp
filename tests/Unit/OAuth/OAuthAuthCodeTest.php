<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\OAuth\OAuthAuthCode;

it('constructs with expected properties', function (): void {
    $authCode = new OAuthAuthCode(
        clientId: 'client-abc',
        userId: 'user-123',
        scopes: ['content:read', 'content:write'],
        redirectUri: 'https://example.com/callback',
    );

    expect($authCode->clientId)->toBe('client-abc')
        ->and($authCode->userId)->toBe('user-123')
        ->and($authCode->scopes)->toBe(['content:read', 'content:write'])
        ->and($authCode->redirectUri)->toBe('https://example.com/callback');
});

it('accepts empty scopes array', function (): void {
    $authCode = new OAuthAuthCode(
        clientId: 'client-abc',
        userId: 'user-123',
        scopes: [],
        redirectUri: 'https://example.com/callback',
    );

    expect($authCode->scopes)->toBeEmpty();
});
