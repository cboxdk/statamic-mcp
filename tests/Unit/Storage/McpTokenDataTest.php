<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;

it('creates a token data object with all fields', function (): void {
    $now = Carbon::now();
    $token = new McpTokenData(
        id: 'uuid-1',
        userId: 'user-1',
        name: 'Test Token',
        tokenHash: 'hash123',
        scopes: ['content:read', 'content:write'],
        lastUsedAt: $now,
        expiresAt: $now->copy()->addDays(30),
        createdAt: $now,
        updatedAt: $now,
    );

    expect($token->id)->toBe('uuid-1');
    expect($token->userId)->toBe('user-1');
    expect($token->name)->toBe('Test Token');
    expect($token->tokenHash)->toBe('hash123');
    expect($token->scopes)->toBe(['content:read', 'content:write']);
    expect($token->lastUsedAt)->toBeInstanceOf(Carbon::class);
    expect($token->expiresAt)->toBeInstanceOf(Carbon::class);
    expect($token->createdAt)->toBeInstanceOf(Carbon::class);
    expect($token->updatedAt)->toBeInstanceOf(Carbon::class);
});

it('allows nullable fields', function (): void {
    $token = new McpTokenData(
        id: 'uuid-2',
        userId: 'user-1',
        name: 'Minimal Token',
        tokenHash: 'hash456',
        scopes: ['*'],
        lastUsedAt: null,
        expiresAt: null,
        createdAt: Carbon::now(),
    );

    expect($token->lastUsedAt)->toBeNull();
    expect($token->expiresAt)->toBeNull();
    expect($token->updatedAt)->toBeNull();
});
