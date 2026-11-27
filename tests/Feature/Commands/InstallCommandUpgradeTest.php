<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Console\InstallCommand;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->originalBase = base_path();
    $this->clientBase = sys_get_temp_dir() . '/mcp-install-v4-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->clientBase . '/.claude');
    app()->setBasePath($this->clientBase);
});

afterEach(function () {
    app()->setBasePath($this->originalBase);
    File::deleteDirectory($this->clientBase);
});

function seedV3Token(string $name, array $scopes): string
{
    return app(TokenStore::class)->create('owner-1', $name, hash('sha256', $name . random_bytes(8)), $scopes, null)->id;
}

it('lists the tokens v4 affects, revokes those that stopped working and narrows the rest', function () {
    $wildcard = seedV3Token('Old full access', ['*']);
    $adminOnly = seedV3Token('Old admin only', ['users:write']);
    $admin = seedV3Token('Old admin', ['users:read', 'users:write']);
    $editor = seedV3Token('Editor', ['entries:read', 'entries:write']);
    $clean = seedV3Token('Reader', ['entries:read']);

    $this->artisan('mcp:statamic:install', ['--upgrade' => true, '--profile' => 'editor', '--skip-migrations' => true])
        ->expectsOutputToContain('Upgrading Statamic MCP Server')
        ->expectsOutputToContain('was replaced by an :admin scope')
        ->expectsOutputToContain('needs entries:publish, entries:delete to publish or delete')
        ->expectsConfirmation('Apply the 3 revoke/narrow step(s) now? Nothing gains access; revoked owners create a new token or authorize the client again.', 'yes')
        ->assertExitCode(0);

    $store = app(TokenStore::class);
    expect($store->find($wildcard))->toBeNull()
        ->and($store->find($adminOnly))->toBeNull()
        // users:read still works in v4; only the dead users:write goes.
        ->and($store->find($admin)?->scopes)->toBe(['users:read'])
        // Losing publish/delete does not stop a token from editing: it stays.
        ->and($store->find($editor))->not->toBeNull()
        ->and($store->find($clean))->not->toBeNull();
});

it('keeps every token when the operator declines', function () {
    $wildcard = seedV3Token('Old full access', ['*']);

    $this->artisan('mcp:statamic:install', ['--upgrade' => true, '--profile' => 'read_only', '--skip-migrations' => true])
        ->expectsConfirmation('Apply the 1 revoke/narrow step(s) now? Nothing gains access; revoked owners create a new token or authorize the client again.', 'no')
        ->expectsOutputToContain('Run php artisan mcp:migrate-v4 --apply when ready.')
        ->assertExitCode(0);

    expect(app(TokenStore::class)->find($wildcard))->not->toBeNull();
});

it('asks before migrating client rules and writes v4 agent guidance', function (bool $approve) {
    $path = base_path('.claude/settings.json');
    $before = '{"permissions":{"allow":["mcp__statamic__statamic-entries"]}}';
    File::put($path, $before);

    $this->artisan('mcp:statamic:install', ['--upgrade' => true, '--profile' => 'read_only', '--skip-migrations' => true])
        ->expectsConfirmation('Migrate these project-local client permission rules? Only reads stay auto-approved; a .pre-mcp-v4 backup is saved.', $approve ? 'yes' : 'no')
        ->assertExitCode(0);

    if ($approve) {
        expect(json_decode(File::get($path), true)['permissions']['allow'])->toBe(['mcp__statamic__statamic-entries-read'])
            ->and(File::get($path . '.pre-mcp-v4'))->toBe($before);
    } else {
        expect(File::get($path))->toBe($before)
            ->and(file_exists($path . '.pre-mcp-v4'))->toBeFalse();
    }
    expect(File::get(base_path('.ai/guidelines/statamic-mcp.md')))
        ->toContain('statamic-discover', 'write_entry', 'expected_version')
        ->not->toContain('statamic-system-schema', 'statamic_agent', '140+');
})->with([true, false]);

it('fails an upgrade when client settings cannot be migrated', function () {
    File::put(base_path('.claude/settings.json'), 'invalid-json');
    $this->artisan('mcp:statamic:install', ['--upgrade' => true, '--profile' => 'read_only', '--skip-migrations' => true])
        ->expectsOutputToContain('Not migrated.')
        ->assertExitCode(1);
    expect(File::get(base_path('.claude/settings.json')))->toBe('invalid-json');
});

function installWithDetectedClients(): void
{
    app(Kernel::class)->registerCommand(new class extends InstallCommand
    {
        protected function detectAiAgents(): array
        {
            return array_fill_keys(['Claude Code', 'Cursor', 'Cline'], []);
        }

        protected function selectTargetAgents(array $availableAgents): array
        {
            return array_keys($availableAgents);
        }
    });
}

it('merges project client files idempotently without a second CLI registration or guessed Cline settings', function () {
    installWithDetectedClients();
    Process::preventStrayProcesses();
    File::put(base_path('.cursorrules'), 'Keep my project rules.');
    File::put(base_path('.mcp.json'), '{"mcpServers":{"other":{"command":"other","env":{}}},"extra":true}');
    $options = ['--profile' => 'read_only', '--skip-migrations' => true, '--force' => true];
    $this->artisan('mcp:statamic:install', $options)->assertExitCode(0);
    $first = File::get(base_path('.cursorrules'));
    File::append(base_path('.cursorrules'), "\nKeep my trailing rules.");
    $this->artisan('mcp:statamic:install', $options)->assertExitCode(0);
    expect(File::get(base_path('.cursorrules')))->toBe($first . "\nKeep my trailing rules.")
        ->and(substr_count($first, '<!-- statamic-mcp:start -->'))->toBe(1)
        ->and($first)->toStartWith('Keep my project rules.');
    foreach (['.mcp.json', '.cursor/mcp.json'] as $path) {
        $config = json_decode(File::get(base_path($path)), true, flags: JSON_THROW_ON_ERROR);
        expect($config['mcpServers']['statamic'])->toBe(['command' => 'php',
            'args' => [base_path('artisan'), 'mcp:start', 'statamic'],
            'env' => ['STATAMIC_MCP_STDIO_PROFILE' => 'read_only']]);
    }
    $claude = json_decode(File::get(base_path('.mcp.json')), flags: JSON_THROW_ON_ERROR);
    expect($claude->extra)->toBeTrue()->and($claude->mcpServers->other->env)->toBeInstanceOf(stdClass::class)
        ->and(File::exists(base_path('.vscode/settings.json')))->toBeFalse();
    Process::assertNothingRan();
});

it('preserves malformed client JSON and malformed Cursor blocks even with force', function (string $json) {
    installWithDetectedClients();
    File::put(base_path('.mcp.json'), $json);
    $rules = "Before\n<!-- statamic-mcp:start -->\nMissing end";
    File::put(base_path('.cursorrules'), $rules);
    $this->artisan('mcp:statamic:install', ['--profile' => 'read_only', '--skip-migrations' => true, '--force' => true])
        ->expectsOutputToContain('Invalid JSON object')->expectsOutputToContain('Malformed Statamic MCP block')->assertExitCode(0);
    expect(File::get(base_path('.mcp.json')))->toBe($json)
        ->and(File::get(base_path('.cursorrules')))->toBe($rules)
        ->and(File::exists(base_path('.cursor/mcp.json')))->toBeFalse();
})->with(['invalid-json', '[]', '{"mcpServers":[]}']);

it('does not report readiness when doctor fails', function () {
    config(['statamic.mcp.security.max_input_depth' => 0]);
    $this->artisan('mcp:statamic:install', ['--upgrade' => true, '--profile' => 'read_only', '--skip-migrations' => true])
        ->expectsOutputToContain('MCP is not ready')->assertExitCode(1);
});

it('refuses installation with cached configuration before writing a different profile', function () {
    File::put(base_path('.env'), "STATAMIC_MCP_STDIO_PROFILE=read_only\n");
    // Laravel records this at bootstrap; creating a cache file afterwards
    // does not represent an application that was booted from that cache.
    app()->instance('config_loaded_from_cache', true);

    $this->artisan('mcp:statamic:install', ['--profile' => 'operator', '--skip-migrations' => true])
        ->expectsOutputToContain('Run php artisan config:clear before installing')->assertExitCode(1);

    expect(File::get(base_path('.env')))->toBe("STATAMIC_MCP_STDIO_PROFILE=read_only\n")
        ->and(File::exists(base_path('.ai/guidelines/statamic-mcp.md')))->toBeFalse();
});
