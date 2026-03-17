<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations/tokens');
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
