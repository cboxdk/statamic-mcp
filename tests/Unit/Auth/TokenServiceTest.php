<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $migration = include __DIR__ . '/../../../database/migrations/tokens/create_mcp_tokens_table.php';
    $migration->up();

    $this->app->singleton(TokenStore::class, DatabaseTokenStore::class);
});

// ---------------------------------------------------------------------------
// createToken()
// ---------------------------------------------------------------------------

describe('createToken', function () {
    it('creates a token with correct attributes', function () {
        config()->set('statamic.mcp.security.max_token_lifetime_days', null);
        $service = app(TokenService::class);

        $result = $service->createToken(
            'user-1',
            'My Token',
            [TokenScope::ContentRead, TokenScope::EntriesWrite],
        );

        expect($result)->toHaveKeys(['token', 'model']);
        expect($result['model'])->toBeInstanceOf(McpTokenData::class);
        expect($result['model']->userId)->toBe('user-1');
        expect($result['model']->name)->toBe('My Token');
        expect($result['model']->scopes)->toBe(['content:read', 'entries:write']);
        expect($result['model']->expiresAt)->toBeNull();
        expect($result['token'])->toBeString()->toHaveLength(64);
    });

    it('stores SHA-256 hash and NOT the plaintext token', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);

        $plaintext = $result['token'];
        $storedHash = $result['model']->tokenHash;

        expect($storedHash)->not->toBe($plaintext);
        expect($storedHash)->toBe(hash('sha256', $plaintext));
    });

    it('returns a plaintext token that validates against the stored hash', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);

        /** @var TokenStore $store */
        $store = app(TokenStore::class);
        $dbToken = $store->find($result['model']->id);

        expect($dbToken)->not->toBeNull();
        expect($dbToken->tokenHash)->toBe(hash('sha256', $result['token']));
    });

    it('maps TokenScope enum values to string values in scopes array', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [
            TokenScope::FullAccess,
            TokenScope::BlueprintsRead,
            TokenScope::GlobalsWrite,
        ]);

        expect($result['model']->scopes)->toBe(['*', 'blueprints:read', 'globals:write']);
    });

    it('handles null expiresAt for no expiry', function () {
        config()->set('statamic.mcp.security.max_token_lifetime_days', null);
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead], null);

        expect($result['model']->expiresAt)->toBeNull();
        expect($service->isExpired($result['model']))->toBeFalse();
    });

    it('enforces max token lifetime when configured', function () {
        config()->set('statamic.mcp.security.max_token_lifetime_days', 30);
        $service = app(TokenService::class);

        // No expiry provided — should be capped at 30 days
        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead], null);
        expect($result['model']->expiresAt)->not->toBeNull();
        expect($result['model']->expiresAt->diffInDays(Carbon::now(), true))->toBeLessThanOrEqual(30);

        // Expiry beyond max — should be capped
        $farFuture = Carbon::now()->addDays(365);
        $result2 = $service->createToken('user-1', 'Token2', [TokenScope::ContentRead], $farFuture);
        expect($result2['model']->expiresAt->diffInDays(Carbon::now(), true))->toBeLessThanOrEqual(30);

        // Expiry within max — should be preserved
        $shortExpiry = Carbon::now()->addDays(7);
        $result3 = $service->createToken('user-1', 'Token3', [TokenScope::ContentRead], $shortExpiry);
        expect($result3['model']->expiresAt->toDateTimeString())->toBe($shortExpiry->toDateTimeString());
    });

    it('handles a specific expiresAt date', function () {
        $service = app(TokenService::class);

        $expiresAt = Carbon::now()->addDays(30);
        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead], $expiresAt);

        expect($result['model']->expiresAt)->not->toBeNull();
        expect($result['model']->expiresAt->toDateTimeString())->toBe($expiresAt->toDateTimeString());
    });
});

// ---------------------------------------------------------------------------
// validateToken()
// ---------------------------------------------------------------------------

describe('validateToken', function () {
    it('returns McpTokenData for a valid token', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);
        $validated = $service->validateToken($result['token']);

        expect($validated)->toBeInstanceOf(McpTokenData::class);
        expect($validated->id)->toBe($result['model']->id);
    });

    it('returns null for an invalid/random token', function () {
        $service = app(TokenService::class);

        $validated = $service->validateToken('totally-random-invalid-token-string');

        expect($validated)->toBeNull();
    });

    it('returns null for an expired token', function () {
        $service = app(TokenService::class);

        $result = $service->createToken(
            'user-1',
            'Expired Token',
            [TokenScope::ContentRead],
            Carbon::now()->subDay(),
        );

        $validated = $service->validateToken($result['token']);

        expect($validated)->toBeNull();
    });

    it('updates lastUsedAt on successful validation', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);

        expect($result['model']->lastUsedAt)->toBeNull();

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $validated = $service->validateToken($result['token']);

        expect($validated->lastUsedAt)->not->toBeNull();
        expect($validated->lastUsedAt->toDateTimeString())->toBe('2026-06-15 12:00:00');

        Carbon::setTestNow();
    });

    it('returns null when token is not in database', function () {
        $service = app(TokenService::class);

        // Create and then delete a token so it no longer exists
        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);
        $service->revokeToken($result['model']->id);

        $validated = $service->validateToken($result['token']);

        expect($validated)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// updateToken()
// ---------------------------------------------------------------------------

describe('updateToken', function () {
    it('updates name only', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Original', [TokenScope::ContentRead]);
        $updated = $service->updateToken($result['model']->id, 'Updated Name');

        expect($updated)->toBeInstanceOf(McpTokenData::class);
        expect($updated->name)->toBe('Updated Name');
        expect($updated->scopes)->toBe(['content:read']);
    });

    it('updates scopes only', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);
        $updated = $service->updateToken(
            $result['model']->id,
            null,
            [TokenScope::EntriesWrite, TokenScope::AssetsRead],
        );

        expect($updated->name)->toBe('Token');
        expect($updated->scopes)->toBe(['entries:write', 'assets:read']);
    });

    it('updates expiry', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);
        $newExpiry = Carbon::now()->addYear();
        $updated = $service->updateToken($result['model']->id, null, null, $newExpiry);

        expect($updated->expiresAt)->not->toBeNull();
        expect($updated->expiresAt->toDateTimeString())->toBe($newExpiry->toDateTimeString());
    });

    it('clears expiry when clearExpiry is true', function () {
        $service = app(TokenService::class);

        $result = $service->createToken(
            'user-1',
            'Token',
            [TokenScope::ContentRead],
            Carbon::now()->addMonth(),
        );

        expect($result['model']->expiresAt)->not->toBeNull();

        $updated = $service->updateToken($result['model']->id, null, null, null, true);

        expect($updated->expiresAt)->toBeNull();
    });

    it('returns null for a non-existent token', function () {
        $service = app(TokenService::class);

        $updated = $service->updateToken('non-existent-uuid', 'Name');

        expect($updated)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// regenerateToken()
// ---------------------------------------------------------------------------

describe('regenerateToken', function () {
    it('returns a new plaintext token', function () {
        $service = app(TokenService::class);

        $original = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);
        $regenerated = $service->regenerateToken($original['model']->id);

        expect($regenerated)->not->toBeNull();
        expect($regenerated)->toHaveKeys(['token', 'model']);
        expect($regenerated['token'])->toBeString()->toHaveLength(64);
        expect($regenerated['token'])->not->toBe($original['token']);
    });

    it('old token no longer validates after regeneration', function () {
        $service = app(TokenService::class);

        $original = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);
        $oldPlaintext = $original['token'];

        $service->regenerateToken($original['model']->id);

        $validated = $service->validateToken($oldPlaintext);

        expect($validated)->toBeNull();
    });

    it('new token validates correctly after regeneration', function () {
        $service = app(TokenService::class);

        $original = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);
        $regenerated = $service->regenerateToken($original['model']->id);

        $validated = $service->validateToken($regenerated['token']);

        expect($validated)->toBeInstanceOf(McpTokenData::class);
        expect($validated->id)->toBe($original['model']->id);
    });

    it('preserves name, scopes, and expiry after regeneration', function () {
        $service = app(TokenService::class);

        $expiresAt = Carbon::now()->addDays(90);
        $original = $service->createToken(
            'user-1',
            'Persistent Token',
            [TokenScope::ContentRead, TokenScope::EntriesWrite],
            $expiresAt,
        );

        $regenerated = $service->regenerateToken($original['model']->id);

        expect($regenerated['model']->name)->toBe('Persistent Token');
        expect($regenerated['model']->scopes)->toBe(['content:read', 'entries:write']);
        expect($regenerated['model']->expiresAt->toDateTimeString())->toBe($expiresAt->toDateTimeString());
    });

    it('returns null for a non-existent token', function () {
        $service = app(TokenService::class);

        $regenerated = $service->regenerateToken('non-existent-uuid');

        expect($regenerated)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// revokeToken()
// ---------------------------------------------------------------------------

describe('revokeToken', function () {
    it('deletes the token and returns true', function () {
        $service = app(TokenService::class);

        $result = $service->createToken('user-1', 'Token', [TokenScope::ContentRead]);
        $revoked = $service->revokeToken($result['model']->id);

        expect($revoked)->toBeTrue();

        /** @var TokenStore $store */
        $store = app(TokenStore::class);
        expect($store->find($result['model']->id))->toBeNull();
    });

    it('returns false for a non-existent token', function () {
        $service = app(TokenService::class);

        $revoked = $service->revokeToken('non-existent-uuid');

        expect($revoked)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// revokeAllForUser()
// ---------------------------------------------------------------------------

describe('revokeAllForUser', function () {
    it('deletes all tokens for a user and returns count', function () {
        $service = app(TokenService::class);

        $service->createToken('user-1', 'Token A', [TokenScope::ContentRead]);
        $service->createToken('user-1', 'Token B', [TokenScope::EntriesRead]);
        $service->createToken('user-1', 'Token C', [TokenScope::AssetsRead]);

        $count = $service->revokeAllForUser('user-1');

        expect($count)->toBe(3);

        /** @var TokenStore $store */
        $store = app(TokenStore::class);
        expect($store->listForUser('user-1'))->toHaveCount(0);
    });

    it('does not affect other users tokens', function () {
        $service = app(TokenService::class);

        $service->createToken('user-1', 'Token A', [TokenScope::ContentRead]);
        $service->createToken('user-1', 'Token B', [TokenScope::EntriesRead]);
        $service->createToken('user-2', 'Token C', [TokenScope::AssetsRead]);

        $count = $service->revokeAllForUser('user-1');

        expect($count)->toBe(2);

        /** @var TokenStore $store */
        $store = app(TokenStore::class);
        expect($store->listForUser('user-2'))->toHaveCount(1);
    });
});

// ---------------------------------------------------------------------------
// listTokensForUser()
// ---------------------------------------------------------------------------

describe('listTokensForUser', function () {
    it('returns all tokens for a specific user', function () {
        $service = app(TokenService::class);

        $service->createToken('user-1', 'Token A', [TokenScope::ContentRead]);
        $service->createToken('user-1', 'Token B', [TokenScope::EntriesRead]);
        $service->createToken('user-2', 'Token C', [TokenScope::AssetsRead]);

        $tokens = $service->listTokensForUser('user-1');

        expect($tokens)->toHaveCount(2);
        expect($tokens->pluck('userId')->unique()->values()->all())->toBe(['user-1']);
    });

    it('returns empty collection when user has no tokens', function () {
        $service = app(TokenService::class);

        $tokens = $service->listTokensForUser('user-with-no-tokens');

        expect($tokens)->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// listAllTokens()
// ---------------------------------------------------------------------------

describe('listAllTokens', function () {
    it('returns all tokens across all users', function () {
        $service = app(TokenService::class);

        $service->createToken('user-1', 'Token A', [TokenScope::ContentRead]);
        $service->createToken('user-2', 'Token B', [TokenScope::EntriesRead]);
        $service->createToken('user-3', 'Token C', [TokenScope::AssetsRead]);

        $tokens = $service->listAllTokens();

        expect($tokens)->toHaveCount(3);
    });
});

// ---------------------------------------------------------------------------
// pruneExpired()
// ---------------------------------------------------------------------------

describe('pruneExpired', function () {
    it('deletes only expired tokens', function () {
        $service = app(TokenService::class);

        $service->createToken('user-1', 'Expired 1', [TokenScope::ContentRead], Carbon::now()->subDays(5));
        $service->createToken('user-1', 'Expired 2', [TokenScope::EntriesRead], Carbon::now()->subHour());
        $service->createToken('user-1', 'Active', [TokenScope::AssetsRead], Carbon::now()->addDays(30));

        $pruned = $service->pruneExpired();

        expect($pruned)->toBe(2);

        /** @var TokenStore $store */
        $store = app(TokenStore::class);
        expect($store->listAll())->toHaveCount(1);
    });

    it('leaves active and non-expiring tokens untouched', function () {
        $service = app(TokenService::class);

        $service->createToken('user-1', 'No Expiry', [TokenScope::ContentRead]);
        $service->createToken('user-1', 'Future Expiry', [TokenScope::EntriesRead], Carbon::now()->addYear());

        $pruned = $service->pruneExpired();

        expect($pruned)->toBe(0);

        /** @var TokenStore $store */
        $store = app(TokenStore::class);
        expect($store->listAll())->toHaveCount(2);
    });

    it('returns count of pruned tokens', function () {
        $service = app(TokenService::class);

        $service->createToken('user-1', 'Expired A', [TokenScope::ContentRead], Carbon::now()->subDays(10));
        $service->createToken('user-2', 'Expired B', [TokenScope::EntriesRead], Carbon::now()->subDays(3));
        $service->createToken('user-3', 'Expired C', [TokenScope::AssetsRead], Carbon::now()->subMinute());

        $pruned = $service->pruneExpired();

        expect($pruned)->toBe(3);

        /** @var TokenStore $store */
        $store = app(TokenStore::class);
        expect($store->listAll())->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// isExpired()
// ---------------------------------------------------------------------------

describe('isExpired', function () {
    it('checks token expiry via isExpired', function () {
        $service = app(TokenService::class);

        $expired = new McpTokenData('id', 'user', 'name', 'hash', ['*'], null, Carbon::now()->subDay(), Carbon::now());
        $valid = new McpTokenData('id', 'user', 'name', 'hash', ['*'], null, Carbon::now()->addDay(), Carbon::now());
        $noExpiry = new McpTokenData('id', 'user', 'name', 'hash', ['*'], null, null, Carbon::now());

        expect($service->isExpired($expired))->toBeTrue();
        expect($service->isExpired($valid))->toBeFalse();
        expect($service->isExpired($noExpiry))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// hasScope()
// ---------------------------------------------------------------------------

describe('hasScope', function () {
    it('checks token scope via hasScope', function () {
        $service = app(TokenService::class);

        $wildcard = new McpTokenData('id', 'user', 'name', 'hash', ['*'], null, null, Carbon::now());
        $scoped = new McpTokenData('id', 'user', 'name', 'hash', ['content:read'], null, null, Carbon::now());

        expect($service->hasScope($wildcard, TokenScope::ContentRead))->toBeTrue();
        expect($service->hasScope($scoped, TokenScope::ContentRead))->toBeTrue();
        expect($service->hasScope($scoped, TokenScope::ContentWrite))->toBeFalse();
    });
});
