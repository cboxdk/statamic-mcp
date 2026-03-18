<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;

function cleanupDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir) ?: [], ['.', '..']);

    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            cleanupDir($path);
        } else {
            unlink($path);
        }
    }

    rmdir($dir);
}

/*
|--------------------------------------------------------------------------
| Validation Tests
|--------------------------------------------------------------------------
*/

it('rejects invalid store argument', function (): void {
    $this->artisan('mcp:migrate-store', [
        'store' => 'invalid',
        '--from' => 'file',
        '--to' => 'database',
    ])
        ->expectsOutput('Store must be "tokens" or "audit".')
        ->assertExitCode(1);
});

it('rejects missing --from option', function (): void {
    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--to' => 'database',
    ])
        ->expectsOutput('The --from option must be "file" or "database".')
        ->assertExitCode(1);
});

it('rejects missing --to option', function (): void {
    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--from' => 'file',
    ])
        ->expectsOutput('The --to option must be "file" or "database".')
        ->assertExitCode(1);
});

it('rejects invalid --from option', function (): void {
    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--from' => 'redis',
        '--to' => 'database',
    ])
        ->expectsOutput('The --from option must be "file" or "database".')
        ->assertExitCode(1);
});

it('rejects invalid --to option', function (): void {
    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--from' => 'file',
        '--to' => 'redis',
    ])
        ->expectsOutput('The --to option must be "file" or "database".')
        ->assertExitCode(1);
});

it('rejects same source and target driver', function (): void {
    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--from' => 'file',
        '--to' => 'file',
    ])
        ->expectsOutput('The --from and --to drivers must be different.')
        ->assertExitCode(1);
});

/*
|--------------------------------------------------------------------------
| Token Migration Tests
|--------------------------------------------------------------------------
*/

it('reports no tokens when source is empty', function (): void {
    $tempDir = sys_get_temp_dir() . '/mcp-test-tokens-' . uniqid();
    mkdir($tempDir, 0755, true);

    config()->set('statamic.mcp.storage.tokens_path', $tempDir);

    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--from' => 'file',
        '--to' => 'database',
    ])
        ->expectsOutput('No tokens found in source store. Nothing to migrate.')
        ->assertExitCode(0);

    // Cleanup
    cleanupDir($tempDir);
});

it('migrates tokens between file stores when confirmed', function (): void {
    $sourceDir = sys_get_temp_dir() . '/mcp-test-source-' . uniqid();
    $targetDir = sys_get_temp_dir() . '/mcp-test-target-' . uniqid();
    mkdir($sourceDir, 0755, true);
    mkdir($targetDir, 0755, true);

    // Create tokens in source
    $source = new FileTokenStore($sourceDir);
    $source->create('user-1', 'Token A', hash('sha256', 'secret-a'), ['*'], null);
    $source->create('user-2', 'Token B', hash('sha256', 'secret-b'), ['content:read'], Carbon::now()->addDays(30));

    $target = new FileTokenStore($targetDir);

    // Override config so the command uses our directories
    config()->set('statamic.mcp.storage.tokens_path', $sourceDir);

    // Mock the app to return target store for database driver
    $this->app->bind(DatabaseTokenStore::class, function () use ($target) {
        return $target;
    });

    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--from' => 'file',
        '--to' => 'database',
    ])
        ->expectsConfirmation('Found 2 token(s) in file store. Migrate to database?', 'yes')
        ->expectsOutputToContain('Migrated 2 token(s)')
        ->assertExitCode(0);

    // Verify target has the tokens
    $targetTokens = $target->listAll();
    expect($targetTokens)->toHaveCount(2);
    expect($targetTokens->pluck('name')->sort()->values()->all())->toBe(['Token A', 'Token B']);

    // Cleanup
    cleanupDir($sourceDir);
    cleanupDir($targetDir);
});

it('cancels token migration when not confirmed', function (): void {
    $sourceDir = sys_get_temp_dir() . '/mcp-test-cancel-' . uniqid();
    mkdir($sourceDir, 0755, true);

    $source = new FileTokenStore($sourceDir);
    $source->create('user-1', 'Token A', hash('sha256', 'secret-a'), ['*'], null);

    config()->set('statamic.mcp.storage.tokens_path', $sourceDir);

    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--from' => 'file',
        '--to' => 'database',
    ])
        ->expectsConfirmation('Found 1 token(s) in file store. Migrate to database?', 'no')
        ->expectsOutput('Migration cancelled.')
        ->assertExitCode(0);

    // Cleanup
    cleanupDir($sourceDir);
});

it('preserves token IDs during migration', function (): void {
    $sourceDir = sys_get_temp_dir() . '/mcp-test-ids-source-' . uniqid();
    $targetDir = sys_get_temp_dir() . '/mcp-test-ids-target-' . uniqid();
    mkdir($sourceDir, 0755, true);
    mkdir($targetDir, 0755, true);

    $source = new FileTokenStore($sourceDir);
    $created = $source->create('user-1', 'Token A', hash('sha256', 'secret-a'), ['*'], null);
    $originalId = $created->id;

    $target = new FileTokenStore($targetDir);

    config()->set('statamic.mcp.storage.tokens_path', $sourceDir);

    $this->app->bind(DatabaseTokenStore::class, function () use ($target) {
        return $target;
    });

    $this->artisan('mcp:migrate-store', [
        'store' => 'tokens',
        '--from' => 'file',
        '--to' => 'database',
    ])
        ->expectsConfirmation('Found 1 token(s) in file store. Migrate to database?', 'yes')
        ->assertExitCode(0);

    $migrated = $target->find($originalId);
    expect($migrated)->not->toBeNull();
    expect($migrated->id)->toBe($originalId);
    expect($migrated->name)->toBe('Token A');

    // Cleanup
    cleanupDir($sourceDir);
    cleanupDir($targetDir);
});

/*
|--------------------------------------------------------------------------
| Audit Migration Tests
|--------------------------------------------------------------------------
*/

it('reports no audit entries when source is empty', function (): void {
    $tempFile = sys_get_temp_dir() . '/mcp-test-audit-' . uniqid() . '.log';
    config()->set('statamic.mcp.storage.audit_path', $tempFile);

    $this->artisan('mcp:migrate-store', [
        'store' => 'audit',
        '--from' => 'file',
        '--to' => 'database',
    ])
        ->expectsOutput('No audit entries found in source store. Nothing to migrate.')
        ->assertExitCode(0);

    // Cleanup
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
});

it('migrates audit entries between file stores when confirmed', function (): void {
    $sourceFile = sys_get_temp_dir() . '/mcp-test-audit-source-' . uniqid() . '.log';
    $targetFile = sys_get_temp_dir() . '/mcp-test-audit-target-' . uniqid() . '.log';

    $source = new FileAuditStore($sourceFile);
    $source->write([
        'level' => 'info',
        'message' => 'Test entry 1',
        'tool' => 'statamic-blueprints',
        'status' => 'success',
        'timestamp' => Carbon::now()->toIso8601String(),
    ]);
    $source->write([
        'level' => 'error',
        'message' => 'Test entry 2',
        'tool' => 'statamic-entries',
        'status' => 'error',
        'timestamp' => Carbon::now()->toIso8601String(),
    ]);

    $target = new FileAuditStore($targetFile);

    config()->set('statamic.mcp.storage.audit_path', $sourceFile);

    $this->app->bind(DatabaseAuditStore::class, function () use ($target) {
        return $target;
    });

    $this->artisan('mcp:migrate-store', [
        'store' => 'audit',
        '--from' => 'file',
        '--to' => 'database',
    ])
        ->expectsConfirmation('Found 2 audit entry/entries in file store. Migrate to database?', 'yes')
        ->expectsOutputToContain('Migrated 2 audit entry/entries')
        ->assertExitCode(0);

    $targetResult = $target->query(null, null, 1, PHP_INT_MAX);
    expect($targetResult->total)->toBe(2);

    // Cleanup
    if (file_exists($sourceFile)) {
        unlink($sourceFile);
    }
    if (file_exists($targetFile)) {
        unlink($targetFile);
    }
});

it('cancels audit migration when not confirmed', function (): void {
    $sourceFile = sys_get_temp_dir() . '/mcp-test-audit-cancel-' . uniqid() . '.log';

    $source = new FileAuditStore($sourceFile);
    $source->write([
        'level' => 'info',
        'message' => 'Test entry',
        'timestamp' => Carbon::now()->toIso8601String(),
    ]);

    config()->set('statamic.mcp.storage.audit_path', $sourceFile);

    $this->artisan('mcp:migrate-store', [
        'store' => 'audit',
        '--from' => 'file',
        '--to' => 'database',
    ])
        ->expectsConfirmation('Found 1 audit entry/entries in file store. Migrate to database?', 'no')
        ->expectsOutput('Migration cancelled.')
        ->assertExitCode(0);

    // Cleanup
    if (file_exists($sourceFile)) {
        unlink($sourceFile);
    }
});
