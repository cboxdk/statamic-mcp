<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\OAuth\Drivers\DatabaseOAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Independent primary and stale replica: no host fixtures or actual replication.
    config(['database.connections.authoritative' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'sticky' => false]]);
    DB::setDefaultConnection('authoritative');
    $this->primary = DB::connection();
    $this->replica = new SQLiteConnection(new PDO('sqlite::memory:'));
    $this->migrations = [
        require __DIR__ . '/../../../database/migrations/tokens/0001_create_mcp_tokens_table.php',
        require __DIR__ . '/../../../database/migrations/tokens/0002_add_unique_token_index_to_mcp_tokens_table.php',
        require __DIR__ . '/../../../database/migrations/tokens/0003_add_oauth_metadata_to_mcp_tokens_table.php',
        require __DIR__ . '/../../../database/migrations/tokens/0004_add_capability_snapshot_to_mcp_tokens_table.php',
        require __DIR__ . '/../../../database/migrations/operations/0001_create_mcp_operations_table.php',
    ];
    foreach (glob(__DIR__ . '/../../../database/migrations/oauth/*.php') as $path) {
        $this->migrations[] = require $path;
    }
    foreach ($this->migrations as $migration) {
        $migration->up();
    }
});

afterEach(function () {
    $this->primary->setReadPdo($this->primary->getPdo());
    foreach (array_reverse($this->migrations) as $migration) {
        $migration->down();
    }
    DB::purge('authoritative');
});

function installMcpStaleReplica(object $test, string $table): void
{
    $schema = $test->primary->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);
    $test->replica->statement($schema->sql);
    foreach ($test->primary->table($table)->get() as $row) {
        $test->replica->table($table)->insert((array) $row);
    }
    $test->primary->setReadPdo($test->replica->getPdo());
}

it('does not authenticate revoked credentials or omit current grants because a replica is stale', function () {
    $store = new DatabaseTokenStore;
    $secret = 'test-credential';
    $old = $store->create('user', 'Revoked', hash('sha256', $secret), ['entries:read'], null);
    installMcpStaleReplica($this, 'mcp_tokens');
    $store->delete($old->id);
    $current = $store->create('user', 'Current', hash('sha256', 'current-credential'), ['entries:read'], null);
    expect($this->primary->table('mcp_tokens')->where('id', $old->id)->exists())->toBeTrue();
    expect((new TokenService($store))->validateToken($secret))->toBeNull()
        ->and($store->find($old->id))->toBeNull()
        ->and($store->findByHash($current->tokenHash)->id)->toBe($current->id)
        ->and($store->listForUser('user')->pluck('id')->all())->toBe([$current->id])
        ->and($store->listAll()->pluck('id')->all())->toBe([$current->id]);
    expect($this->primary->getReadPdo())->toBe($this->replica->getPdo());
});

it('reads the committed operation claim from the primary without changing host read routing', function () {
    $store = new DatabaseOperationStore($this->primary);
    $prepared = new Operation(hash('sha256', 'operation'), hash('sha256', 'binding'), OperationState::Prepared, 1, time() + 300);
    $store->create($prepared);
    $ready = $prepared->transition(OperationState::Ready);
    $store->transition($prepared, $ready);
    installMcpStaleReplica($this, 'mcp_operations');
    $executing = $ready->transition(OperationState::Executing);
    expect($store->transition($ready, $executing))->toBeTrue()
        ->and($this->primary->table('mcp_operations')->value('state'))->toBe('ready')
        ->and($store->find($ready->id)->state)->toBe(OperationState::Executing)
        ->and($store->transition($ready, $executing))->toBeFalse();
    expect($this->primary->getReadPdo())->toBe($this->replica->getPdo());
});

it('revokes the refresh family when a stale replica hides token replay', function () {
    $driver = new DatabaseOAuthDriver;
    $original = $driver->createRefreshToken('user', 'client', ['entries:read']);
    installMcpStaleReplica($this, 'mcp_oauth_refresh_tokens');
    $replacement = $driver->exchangeRefreshToken($original, 'client');
    expect($replacement->refreshToken)->not->toBeNull();
    expect(fn () => $driver->exchangeRefreshToken($original, 'client'))
        ->toThrow(OAuthException::class);
    expect($this->primary->table('mcp_oauth_refresh_tokens')->useWritePdo()->count())->toBe(0);
});

it('refuses token authentication from an ambient transaction without rolling it back', function (bool $raw) {
    $store = new DatabaseTokenStore;
    $token = $store->create('user', 'Current', hash('sha256', 'current'), ['entries:read'], null);
    $raw ? $this->primary->getPdo()->beginTransaction() : $this->primary->beginTransaction();
    try {
        expect(fn () => $store->find($token->id))->toThrow(LogicException::class);
        expect(fn () => $store->findByHash($token->tokenHash))->toThrow(LogicException::class);
        expect($this->primary->getPdo()->inTransaction())->toBeTrue();
    } finally {
        $raw ? $this->primary->getPdo()->rollBack() : $this->primary->rollBack();
    }
})->with([true, false]);

it('refuses authorization code consumption inside an ambient transaction', function (bool $raw) {
    $driver = new DatabaseOAuthDriver;
    $verifier = str_repeat('v', 43);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $code = $driver->createAuthCode('client', 'user', ['entries:read'], $challenge, 'S256', 'https://example.test/callback');
    $raw ? $this->primary->getPdo()->beginTransaction() : $this->primary->beginTransaction();
    try {
        expect(fn () => $driver->exchangeCode($code, $verifier, 'client', 'https://example.test/callback'))
            ->toThrow(LogicException::class);
        expect($this->primary->getPdo()->inTransaction())->toBeTrue();
    } finally {
        $raw ? $this->primary->getPdo()->rollBack() : $this->primary->rollBack();
    }
    expect($this->primary->table('mcp_oauth_codes')->value('used'))->toBe(0);
})->with([true, false]);

it('does not authenticate when a query listener opens a transaction during the read', function (bool $raw) {
    $store = new DatabaseTokenStore;
    $token = $store->create('user', 'Current', hash('sha256', 'current'), ['entries:read'], null);
    $opened = false;
    $this->primary->listen(function ($event) use (&$opened, $raw): void {
        if (! $opened && $event->connection === $this->primary && str_starts_with($event->sql, 'select')) {
            $opened = true;
            $raw ? $this->primary->getPdo()->beginTransaction() : $this->primary->beginTransaction();
        }
    });
    try {
        expect(fn () => $store->find($token->id))->toThrow(LogicException::class);
        expect($opened)->toBeTrue()->and($this->primary->getPdo()->inTransaction())->toBeTrue();
    } finally {
        $raw ? $this->primary->getPdo()->rollBack() : $this->primary->rollBack();
    }
})->with([true, false]);
