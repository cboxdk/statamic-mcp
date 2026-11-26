<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Auth\V4TokenMigration;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Statamic\Facades\User;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->journals = sys_get_temp_dir() . '/mcp-migrate-v4-' . bin2hex(random_bytes(4));
    config(['statamic.mcp.storage.migrations_path' => $this->journals]);
});

afterEach(function () {
    File::deleteDirectory($this->journals);
});

function v3Token(string $name, array $scopes, ?string $client = null, string $owner = 'owner-1'): string
{
    return app(TokenStore::class)->create($owner, $name, hash('sha256', $name . random_bytes(8)), $scopes, null, $client, $client === null ? null : 'Client')->id;
}

/** @return array<string, list<string>|null> scopes per token id, null when revoked */
function scopesOf(array $ids): array
{
    return array_map(fn (string $id) => app(TokenStore::class)->find($id)?->scopes, $ids);
}

function migrateV4(array $options = []): array
{
    $code = Artisan::call('mcp:migrate-v4', $options + ['--json' => true]);

    return [$code, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

describe('project-local client permissions', function () {
    beforeEach(function () {
        $this->originalBase = base_path();
        $this->clientBase = $this->journals . '/site';
        File::ensureDirectoryExists($this->clientBase . '/.claude');
        File::ensureDirectoryExists($this->clientBase . '/.cursor');
        app()->setBasePath($this->clientBase);
    });

    afterEach(function () {
        app()->setBasePath($this->originalBase);
        File::deleteDirectory($this->clientBase);
    });

    it('previews exact rules, preserves unrelated settings and never adds automatic writes', function (string $path, string $old, string $read, string $write, string $discovery) {
        $settings = json_encode([
            'permissions' => [
                'allow' => [$old, $discovery, 'unrelated-rule'],
                'ask' => [$old],
                'deny' => [$old],
            ],
            'env' => (object) [],
            'theme' => 'dark',
        ], JSON_THROW_ON_ERROR);
        File::put(base_path($path), $settings);
        [$code, $preview] = migrateV4();
        expect($code)->toBe(0)
            ->and($preview['clients'][0]['path'])->toBe($path)
            ->and($preview['clients'][0]['applied'])->toBeFalse()
            ->and(File::get(base_path($path)))->toBe($settings)
            ->and(file_exists(base_path($path . '.pre-mcp-v4')))->toBeFalse();

        [$code, $applied] = migrateV4(['--apply' => true]);
        $result = json_decode(File::get(base_path($path)), flags: JSON_THROW_ON_ERROR);
        expect($code)->toBe(0)->and($applied['clients'][0]['applied'])->toBeTrue()
            ->and($result->permissions->allow)->toBe([$read, 'unrelated-rule'])
            ->and($result->permissions->ask)->toBe([$read, $write])
            ->and($result->permissions->deny)->toBe([$read, $write])
            ->and($result->env)->toBeInstanceOf(stdClass::class)
            ->and($result->theme)->toBe('dark')
            ->and(File::get(base_path($path . '.pre-mcp-v4')))->toBe($settings)
            ->and(fileperms(base_path($path . '.pre-mcp-v4')) & 0777)->toBe(0600);

        [$code, $again] = migrateV4(['--apply' => true]);
        expect($code)->toBe(0)->and($again['clients'])->toBe([]);
        [$code] = migrateV4(['--rollback' => true, '--apply' => true]);
        expect($code)->toBe(0)
            ->and(json_decode(File::get(base_path($path))))->toEqual($result);
    })->with([
        ['.claude/settings.json', 'mcp__statamic__statamic-entries', 'mcp__statamic__statamic-entries-read', 'mcp__statamic__statamic-entries-write', 'mcp__statamic__statamic-system-discover'],
        ['.claude/settings.local.json', 'mcp__statamic__statamic-users', 'mcp__statamic__statamic-users-read', 'mcp__statamic__statamic-users-admin', 'mcp__statamic__statamic-system-schema'],
        ['.cursor/cli.json', 'Mcp(statamic:statamic-blueprints)', 'Mcp(statamic:statamic-blueprints-read)', 'Mcp(statamic:statamic-blueprints-admin)', 'Mcp(statamic:statamic-system-schema)'],
    ]);

    it('leaves wildcard rules, renamed servers and current tools for manual review', function () {
        $settings = '{"permissions":{"allow":["mcp__statamic__*","mcp__other__statamic-entries","mcp__statamic__statamic-entries-write"]}}';
        File::put(base_path('.claude/settings.json'), $settings);
        [$code, $report] = migrateV4(['--apply' => true]);
        expect($code)->toBe(0)->and($report['clients'])->toBe([])
            ->and(File::get(base_path('.claude/settings.json')))->toBe($settings);
    });

    it('reports migrated clients when the token journal subsequently fails', function () {
        File::put(base_path('.claude/settings.json'), '{"permissions":{"allow":["mcp__statamic__statamic-entries"]}}');
        File::put(base_path('not-a-directory'), 'blocker');
        config(['statamic.mcp.storage.migrations_path' => base_path('not-a-directory/journal')]);
        $token = v3Token('Full', ['*']);
        [$code, $report] = migrateV4(['--apply' => true]);
        expect($code)->toBe(1)
            ->and($report['clients'][0]['applied'])->toBeTrue()
            ->and($report['error'])->toContain('tokens were not changed')->not->toContain('nothing was changed')
            ->and(json_decode(File::get(base_path('.claude/settings.json')), true)['permissions']['allow'])->toBe(['mcp__statamic__statamic-entries-read'])
            ->and(app(TokenStore::class)->find($token)?->scopes)->toBe(['*']);
    });

    it('refuses unsafe settings without changing tokens or exposing config contents', function (string $failure) {
        $token = v3Token('Old token', ['*']);
        $path = base_path('.claude/settings.json');
        $settings = '{"permissions":{"allow":["mcp__statamic__statamic-entries"]},"secret":"private-marker"}';
        File::put($path, $settings);
        if ($failure === 'invalid-json') {
            File::put($path, 'private-marker invalid JSON');
        } elseif ($failure === 'existing-backup') {
            File::put($path . '.pre-mcp-v4', 'keep-backup');
        } elseif ($failure === 'symlink') {
            File::move($path, base_path('outside.json'));
            symlink(base_path('outside.json'), $path);
        } else {
            File::move(base_path('.claude'), base_path('linked-directory'));
            symlink(base_path('linked-directory'), base_path('.claude'));
        }
        $before = File::get($path);
        [$code, $report] = migrateV4(['--apply' => true]);
        expect($code)->toBe(1)
            ->and($report['clients'][0]['error'])->not->toBeNull()
            ->and(json_encode($report))->not->toContain('private-marker')
            ->and(File::get($path))->toBe($before)
            ->and(app(TokenStore::class)->find($token)?->scopes)->toBe(['*']);
        if ($failure === 'existing-backup') {
            expect(File::get($path . '.pre-mcp-v4'))->toBe('keep-backup');
        }
    })->with(['invalid-json', 'existing-backup', 'symlink', 'parent-symlink']);
});

it('changes nothing on a dry run and never prints token hashes', function () {
    $ids = ['full' => v3Token('Full', ['*']), 'admin' => v3Token('Admin', ['users:read', 'users:write'])];
    $before = scopesOf($ids);
    $hash = app(TokenStore::class)->find($ids['full'])->tokenHash;

    [$code, $report] = migrateV4();

    expect($code)->toBe(0)->and($report['dry_run'])->toBeTrue()
        ->and(array_column($report['steps'], 'action', 'name'))->toBe(['Admin' => 'narrow', 'Full' => 'revoke'])
        ->and(scopesOf($ids))->toBe($before)
        ->and(json_encode($report))->not->toContain($hash)
        ->and(is_dir($this->journals))->toBeFalse();
});

it('revokes or narrows v3 tokens without ever adding a scope, and a second run finds nothing', function () {
    $ids = [
        'full' => v3Token('Full', ['*', 'entries:read']),
        'mixed' => v3Token('Mixed', ['entries:read', 'structures:write', 'blueprints:write']),
        'admin_only' => v3Token('Admin only', ['users:write']),
        'editor' => v3Token('Editor', ['entries:read', 'entries:write']),
        'reader' => v3Token('Reader', ['entries:read']),
    ];
    $before = scopesOf($ids);

    [$code, $report] = migrateV4(['--apply' => true]);

    $after = scopesOf($ids);
    expect($code)->toBe(0)->and($report['applied'])->toBe(3)
        ->and($after['full'])->toBeNull()
        ->and($after['admin_only'])->toBeNull()
        // Live scopes survive; the replaced ones granted nothing in v4 and
        // would come back to life under v3.
        ->and($after['mixed'])->toBe(['entries:read'])
        // Losing publish/delete leaves an editing token working.
        ->and($after['editor'])->toBe($before['editor'])
        ->and($after['reader'])->toBe($before['reader']);
    foreach ($after as $id => $scopes) {
        expect(array_diff($scopes ?? [], $before[$id]))->toBe([]);
    }

    $journal = json_decode(File::get($report['journal']), true);
    expect($journal['state'])->toBe('completed')->and($journal['mode'])->toBe('upgrade')
        ->and($journal['applied'])->toHaveCount(3)
        ->and(fileperms($report['journal']) & 0777)->toBe(0600)
        ->and(File::get($report['journal']))->not->toContain('token_hash')->not->toContain('tokenHash');

    [, $again] = migrateV4(['--apply' => true]);
    expect(array_column($again['steps'], 'action'))->toBe(['keep'])
        ->and($again)->not->toHaveKey('journal');
});

it('ends an OAuth grant carrying a replaced scope, refresh token included', function () {
    $oauth = app(OAuthDriver::class);
    $client = $oauth->registerClient('Client', ['https://client.example/callback']);
    $id = v3Token('Client (OAuth)', ['entries:read', 'system:write'], $client->clientId);
    $refresh = $oauth->createRefreshToken('owner-1', $client->clientId, ['entries:read', 'system:write']);
    $other = $oauth->registerClient('Other', ['https://other.example/callback']);
    v3Token('Other (OAuth)', ['entries:read'], $other->clientId);
    $untouched = $oauth->createRefreshToken('owner-1', $other->clientId, ['entries:read']);

    [$code] = migrateV4(['--apply' => true]);

    expect($code)->toBe(0)->and(app(TokenStore::class)->find($id))->toBeNull()
        ->and(fn () => $oauth->exchangeRefreshToken($refresh, $client->clientId))->toThrow(OAuthException::class, 'Refresh token not found')
        ->and($oauth->exchangeRefreshToken($untouched, $other->clientId)->scopes)->toBe(['entries:read']);
});

it('drops the content umbrellas, which never granted anything, without ending an OAuth grant', function () {
    $oauth = app(OAuthDriver::class);
    $client = $oauth->registerClient('Client', ['https://client.example/callback']);
    $ids = [
        'mixed' => v3Token('Mixed', ['content:read', 'entries:read', 'content:write']),
        'only' => v3Token('Only content', ['content:read']),
        'oauth' => v3Token('Client (OAuth)', ['content:read', 'entries:read'], $client->clientId),
    ];
    $refresh = $oauth->createRefreshToken('owner-1', $client->clientId, ['content:read', 'entries:read'], new CapabilitySnapshot([], resource: 'http://localhost/mcp/statamic'));

    [$code, $report] = migrateV4(['--apply' => true]);

    $after = scopesOf($ids);
    expect($code)->toBe(0)
        ->and(array_column($report['steps'], 'action', 'name'))->toBe(['Client (OAuth)' => 'keep', 'Mixed' => 'narrow', 'Only content' => 'revoke'])
        ->and($after['mixed'])->toBe(['entries:read'])
        ->and($after['only'])->toBeNull()
        ->and($after['oauth'])->toBe(['content:read', 'entries:read']);

    // The client keeps its grant; refreshing it drops the umbrella.
    User::make()->id('owner-1')->email('owner@example.com')->makeSuper()->save();
    $refreshed = $this->post('/mcp/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $client->clientId, 'resource' => 'http://localhost/mcp/statamic']);
    expect($refreshed->status())->toBe(200, (string) $refreshed->getContent());
    expect($refreshed->json('scope'))->toBe('entries:read');
});

it('revokes before a downgrade only the tokens v3 would let publish or delete', function () {
    $ids = [
        'editor' => v3Token('Editor', ['entries:read', 'entries:write']),
        'publisher' => v3Token('Publisher', ['entries:write', 'entries:publish', 'entries:delete']),
        'admin' => v3Token('Admin', ['structures:admin']),
    ];

    [$code, $report] = migrateV4(['--rollback' => true, '--apply' => true]);

    expect($code)->toBe(0)->and($report['mode'])->toBe('rollback')
        ->and(scopesOf($ids)['editor'])->toBeNull()
        ->and(scopesOf($ids)['publisher'])->not->toBeNull()
        ->and(scopesOf($ids)['admin'])->not->toBeNull()
        ->and(json_decode(File::get($report['journal']), true)['mode'])->toBe('rollback');
});

it('changes nothing when the journal cannot be written', function () {
    $blocker = $this->journals . '-file';
    File::put($blocker, 'not a directory');
    config(['statamic.mcp.storage.migrations_path' => $blocker . '/journals']);
    $id = v3Token('Full', ['*']);

    try {
        [$code, $report] = migrateV4(['--apply' => true]);
    } finally {
        File::delete($blocker);
    }

    expect($code)->toBe(1)->and($report['error'])->toContain('tokens were not changed')
        ->and(app(TokenStore::class)->find($id))->not->toBeNull();
});

it('leaves a token alone when it changed after the plan was made', function () {
    $id = v3Token('Admin', ['users:write']);
    $migration = app(V4TokenMigration::class);
    $steps = $migration->plan(V4TokenMigration::UPGRADE);

    app(TokenStore::class)->update($id, ['scopes' => ['users:write', 'users:admin']]);
    $result = $migration->apply(V4TokenMigration::UPGRADE, $steps);

    expect($result['error'])->not->toBeNull()->and($result['applied'])->toBe(0)
        ->and(app(TokenStore::class)->find($id)?->scopes)->toBe(['users:write', 'users:admin']);
});

it('migrates token files written by v3.6.2 in place', function () {
    $dir = $this->journals . '-tokens';
    File::ensureDirectoryExists($dir);
    // The exact record shape v3.6.2's FileTokenStore::create() wrote.
    $records = [
        '0b8c3a5e-0000-4000-8000-000000000001' => ['Full', ['*']],
        '0b8c3a5e-0000-4000-8000-000000000002' => ['Builder', ['entries:read', 'blueprints:write']],
        '0b8c3a5e-0000-4000-8000-000000000003' => ['Writer', ['entries:write']],
    ];
    $index = [];
    foreach ($records as $id => [$name, $scopes]) {
        $hash = hash('sha256', $name);
        $index[$hash] = $id;
        File::put("{$dir}/{$id}.yaml", Yaml::dump([
            'id' => $id, 'user_id' => 'owner-1', 'name' => $name, 'token_hash' => $hash, 'scopes' => $scopes,
            'oauth_client_id' => null, 'oauth_client_name' => null, 'last_used_at' => null, 'expires_at' => null,
            'created_at' => '2026-05-01T10:00:00+00:00', 'updated_at' => null,
        ], 2, 4, Yaml::DUMP_NULL_AS_TILDE));
    }
    File::put("{$dir}/.index", json_encode($index));
    config(['statamic.mcp.stores.tokens' => FileTokenStore::class]);
    app()->forgetInstance(TokenStore::class);
    app()->forgetInstance(TokenService::class);
    app()->instance(TokenStore::class, new FileTokenStore($dir));

    try {
        [$code] = migrateV4(['--apply' => true]);
        $store = app(TokenStore::class);

        expect($code)->toBe(0)
            ->and($store->find('0b8c3a5e-0000-4000-8000-000000000001'))->toBeNull()
            ->and($store->findByHash(hash('sha256', 'Full')))->toBeNull()
            ->and($store->find('0b8c3a5e-0000-4000-8000-000000000002')?->scopes)->toBe(['entries:read'])
            ->and($store->findByHash(hash('sha256', 'Builder'))?->scopes)->toBe(['entries:read'])
            ->and($store->find('0b8c3a5e-0000-4000-8000-000000000003')?->scopes)->toBe(['entries:write']);
    } finally {
        File::deleteDirectory($dir);
    }
});
