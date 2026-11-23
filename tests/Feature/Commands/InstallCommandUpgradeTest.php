<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::delete(base_path('.ai/guidelines/statamic-mcp.md'));
});

function seedV3Token(string $name, array $scopes): string
{
    return app(TokenStore::class)->create('owner-1', $name, hash('sha256', $name . random_bytes(8)), $scopes, null)->id;
}

it('lists the tokens v4 affects and revokes only those that stopped working', function () {
    $wildcard = seedV3Token('Old full access', ['*']);
    $admin = seedV3Token('Old admin', ['users:read', 'users:write']);
    $editor = seedV3Token('Editor', ['entries:read', 'entries:write']);
    $clean = seedV3Token('Reader', ['entries:read']);

    $this->artisan('mcp:statamic:install', ['--upgrade' => true, '--profile' => 'editor', '--skip-migrations' => true])
        ->expectsOutputToContain('Upgrading Statamic MCP Server')
        ->expectsOutputToContain('admin :write scopes were replaced by :admin')
        ->expectsOutputToContain('needs entries:publish, entries:delete to publish or delete')
        ->expectsConfirmation('2 token(s) no longer work at all. Revoke them now? Their owners create a new token or authorize the client again.', 'yes')
        ->assertExitCode(0);

    $store = app(TokenStore::class);
    expect($store->find($wildcard))->toBeNull()
        ->and($store->find($admin))->toBeNull()
        // Losing publish/delete does not stop a token from editing: it stays.
        ->and($store->find($editor))->not->toBeNull()
        ->and($store->find($clean))->not->toBeNull();
});

it('keeps every token when the operator declines', function () {
    $wildcard = seedV3Token('Old full access', ['*']);

    $this->artisan('mcp:statamic:install', ['--upgrade' => true, '--profile' => 'read_only', '--skip-migrations' => true])
        ->expectsConfirmation('1 token(s) no longer work at all. Revoke them now? Their owners create a new token or authorize the client again.', 'no')
        ->assertExitCode(0);

    expect(app(TokenStore::class)->find($wildcard))->not->toBeNull();
});
