<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $migration = include __DIR__ . '/../../../database/migrations/tokens/create_mcp_tokens_table.php';
    $migration->up();

    $oauthMeta = include __DIR__ . '/../../../database/migrations/tokens/add_oauth_metadata_to_mcp_tokens_table.php';
    $oauthMeta->up();
});

/*
|--------------------------------------------------------------------------
| hasScope()
|--------------------------------------------------------------------------
*/

it('returns true when the token has the requested scope', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Test Token',
        'token' => hash('sha256', 'test-token-value'),
        'scopes' => [TokenScope::EntriesRead->value, TokenScope::EntriesWrite->value],
    ]);

    expect($token->hasScope(TokenScope::EntriesRead))->toBeTrue();
    expect($token->hasScope(TokenScope::EntriesWrite))->toBeTrue();
});

it('returns false when the token does not have the requested scope', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Test Token',
        'token' => hash('sha256', 'test-token-value'),
        'scopes' => [TokenScope::EntriesRead->value],
    ]);

    expect($token->hasScope(TokenScope::EntriesWrite))->toBeFalse();
    expect($token->hasScope(TokenScope::BlueprintsRead))->toBeFalse();
});

it('grants all scopes when token has wildcard full access', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Full Access Token',
        'token' => hash('sha256', 'full-access-token'),
        'scopes' => [TokenScope::FullAccess->value],
    ]);

    expect($token->hasScope(TokenScope::EntriesRead))->toBeTrue();
    expect($token->hasScope(TokenScope::EntriesWrite))->toBeTrue();
    expect($token->hasScope(TokenScope::BlueprintsRead))->toBeTrue();
    expect($token->hasScope(TokenScope::SystemWrite))->toBeTrue();
    expect($token->hasScope(TokenScope::UsersWrite))->toBeTrue();
    expect($token->hasScope(TokenScope::FullAccess))->toBeTrue();
});

it('returns false for any scope when scopes array is empty', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'No Scopes Token',
        'token' => hash('sha256', 'no-scopes-token'),
        'scopes' => [],
    ]);

    expect($token->hasScope(TokenScope::EntriesRead))->toBeFalse();
    expect($token->hasScope(TokenScope::FullAccess))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| isExpired()
|--------------------------------------------------------------------------
*/

it('returns false when expires_at is null (never expires)', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Never Expires Token',
        'token' => hash('sha256', 'never-expires-token'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => null,
    ]);

    expect($token->isExpired())->toBeFalse();
});

it('returns false when expires_at is in the future', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Future Token',
        'token' => hash('sha256', 'future-token'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => Carbon::now()->addHour(),
    ]);

    expect($token->isExpired())->toBeFalse();
});

it('returns true when expires_at is in the past', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Expired Token',
        'token' => hash('sha256', 'expired-token'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => Carbon::now()->subHour(),
    ]);

    expect($token->isExpired())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| markAsUsed()
|--------------------------------------------------------------------------
*/

it('updates last_used_at timestamp when marked as used', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Usage Token',
        'token' => hash('sha256', 'usage-token'),
        'scopes' => [TokenScope::EntriesRead->value],
    ]);

    expect($token->last_used_at)->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-03-13 12:00:00'));

    $token->markAsUsed();
    $token->refresh();

    expect($token->last_used_at)->not->toBeNull();
    expect($token->last_used_at->toDateTimeString())->toBe('2026-03-13 12:00:00');

    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| scopeActive()
|--------------------------------------------------------------------------
*/

it('includes tokens with null expires_at in active scope', function () {
    McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Never Expires',
        'token' => hash('sha256', 'never-expires'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => null,
    ]);

    $activeTokens = McpToken::active()->get();

    expect($activeTokens)->toHaveCount(1);
    expect($activeTokens->first()->name)->toBe('Never Expires');
});

it('includes tokens with future expires_at in active scope', function () {
    McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Future Token',
        'token' => hash('sha256', 'future-active'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => Carbon::now()->addHour(),
    ]);

    $activeTokens = McpToken::active()->get();

    expect($activeTokens)->toHaveCount(1);
    expect($activeTokens->first()->name)->toBe('Future Token');
});

it('excludes tokens with past expires_at from active scope', function () {
    McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Expired Token',
        'token' => hash('sha256', 'expired-active'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => Carbon::now()->subHour(),
    ]);

    $activeTokens = McpToken::active()->get();

    expect($activeTokens)->toHaveCount(0);
});

it('filters correctly with mixed active and expired tokens', function () {
    McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Active No Expiry',
        'token' => hash('sha256', 'active-no-expiry'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => null,
    ]);

    McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Active Future',
        'token' => hash('sha256', 'active-future'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => Carbon::now()->addDay(),
    ]);

    McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Expired Past',
        'token' => hash('sha256', 'expired-past'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => Carbon::now()->subDay(),
    ]);

    $activeTokens = McpToken::active()->get();

    expect($activeTokens)->toHaveCount(2);
    expect($activeTokens->pluck('name')->toArray())->toEqualCanonicalizing([
        'Active No Expiry',
        'Active Future',
    ]);
});

/*
|--------------------------------------------------------------------------
| Token attribute hiding (serialization)
|--------------------------------------------------------------------------
*/

it('hides the token field from array serialization', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Hidden Token',
        'token' => hash('sha256', 'hidden-token-value'),
        'scopes' => [TokenScope::EntriesRead->value],
    ]);

    $array = $token->toArray();

    expect($array)->not->toHaveKey('token');
    expect($array)->toHaveKey('name');
    expect($array)->toHaveKey('scopes');
});

it('hides the token field from JSON serialization', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'JSON Token',
        'token' => hash('sha256', 'json-token-value'),
        'scopes' => [TokenScope::EntriesRead->value],
    ]);

    $json = $token->toJson();
    $decoded = json_decode($json, true);

    expect($decoded)->not->toHaveKey('token');
    expect($decoded)->toHaveKey('name');
});

/*
|--------------------------------------------------------------------------
| Casts
|--------------------------------------------------------------------------
*/

it('casts scopes to an array', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Cast Test Token',
        'token' => hash('sha256', 'cast-test-token'),
        'scopes' => [TokenScope::EntriesRead->value, TokenScope::BlueprintsRead->value],
    ]);

    $token->refresh();

    expect($token->scopes)->toBeArray();
    expect($token->scopes)->toBe(['entries:read', 'blueprints:read']);
});

it('casts expires_at to a Carbon instance', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Date Cast Token',
        'token' => hash('sha256', 'date-cast-token'),
        'scopes' => [TokenScope::EntriesRead->value],
        'expires_at' => '2026-12-31 23:59:59',
    ]);

    $token->refresh();

    expect($token->expires_at)->toBeInstanceOf(Carbon::class);
    expect($token->expires_at->year)->toBe(2026);
    expect($token->expires_at->month)->toBe(12);
    expect($token->expires_at->day)->toBe(31);
});

it('casts last_used_at to a Carbon instance', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'Last Used Cast Token',
        'token' => hash('sha256', 'last-used-cast-token'),
        'scopes' => [TokenScope::EntriesRead->value],
        'last_used_at' => '2026-06-15 10:30:00',
    ]);

    $token->refresh();

    expect($token->last_used_at)->toBeInstanceOf(Carbon::class);
    expect($token->last_used_at->year)->toBe(2026);
    expect($token->last_used_at->month)->toBe(6);
    expect($token->last_used_at->day)->toBe(15);
});

/*
|--------------------------------------------------------------------------
| UUID primary key
|--------------------------------------------------------------------------
*/

it('generates a UUID as the primary key', function () {
    $token = McpToken::create([
        'user_id' => 'user-1',
        'name' => 'UUID Token',
        'token' => hash('sha256', 'uuid-token'),
        'scopes' => [TokenScope::EntriesRead->value],
    ]);

    expect($token->id)->not->toBeNull();
    expect($token->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});
