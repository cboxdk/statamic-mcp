<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/tokens');

    $this->app->singleton(TokenStore::class, DatabaseTokenStore::class);
});

// ---------------------------------------------------------------------------
// No tokens
// ---------------------------------------------------------------------------

it('runs without error when no tokens exist', function () {
    $this->artisan('mcp:prune-tokens')
        ->expectsOutput('No expired MCP tokens found.')
        ->assertExitCode(0);
});

// ---------------------------------------------------------------------------
// Prunes expired tokens
// ---------------------------------------------------------------------------

it('prunes expired tokens and reports the count', function () {
    $service = app(TokenService::class);

    // Create 2 expired tokens
    $service->createToken('user-1', 'Expired A', [TokenScope::ContentRead], Carbon::now()->subDays(5));
    $service->createToken('user-1', 'Expired B', [TokenScope::EntriesRead], Carbon::now()->subHour());

    // Create 1 active token
    $service->createToken('user-1', 'Active', [TokenScope::AssetsRead], Carbon::now()->addDays(30));

    $this->artisan('mcp:prune-tokens')
        ->expectsOutput('Pruned 2 expired MCP token(s).')
        ->assertExitCode(0);

    /** @var TokenStore $store */
    $store = app(TokenStore::class);
    expect($store->listAll())->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// No expired tokens
// ---------------------------------------------------------------------------

it('reports no expired tokens when all are active', function () {
    $service = app(TokenService::class);

    $service->createToken('user-1', 'No Expiry', [TokenScope::ContentRead]);
    $service->createToken('user-1', 'Future', [TokenScope::EntriesRead], Carbon::now()->addYear());

    $this->artisan('mcp:prune-tokens')
        ->expectsOutput('No expired MCP tokens found.')
        ->assertExitCode(0);

    /** @var TokenStore $store */
    $store = app(TokenStore::class);
    expect($store->listAll())->toHaveCount(2);
});
