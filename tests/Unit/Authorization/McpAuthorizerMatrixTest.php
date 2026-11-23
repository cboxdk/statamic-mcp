<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Authorization\McpAuthorizer;
use Cboxdk\StatamicMcp\Authorization\McpCall;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Support\McpContext;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/*
 * V03 exit criterion: one decision for every built-in surface, checked as a
 * positive/negative matrix across transport, token, actor and resource policy.
 */

function matrixActor(array $permissions, bool $super = false): void
{
    config(['statamic.editions.pro' => true]);
    $role = 'matrix-' . bin2hex(random_bytes(4));
    Role::make()->handle($role)->permissions($permissions)->save();
    $user = User::make()->id('matrix-' . $role)->email($role . '@example.test')->assignRole($role);
    if ($super) {
        $user->makeSuper();
    }
    $user->save();
    auth()->shouldUse('web');
    auth()->setUser($user);
}

function matrixToken(array $scopes): void
{
    request()->attributes->set('mcp_token', new McpTokenData('t', 'u', 'n', 'h', $scopes, null, null, Carbon::now()));
}

function matrixCall(string $mode = 'write', ?string $handle = 'blog'): McpCall
{
    return new McpCall(
        domain: 'entries',
        action: $mode === 'write' ? 'update' : 'get',
        scopes: [$mode === 'write' ? TokenScope::EntriesWrite : TokenScope::EntriesRead],
        permissions: [$mode === 'write' ? 'edit blog entries' : 'view blog entries'],
        handle: $handle,
        mode: $mode,
    );
}

beforeEach(function () {
    request()->attributes->set(McpContext::WEB_ATTRIBUTE, true);
    request()->attributes->remove('mcp_token');
});

afterEach(function () {
    request()->attributes->remove(McpContext::WEB_ATTRIBUTE);
    request()->attributes->remove('mcp_token');
});

it('allows an HTTP call only when token, actor and policy all agree', function (array $scopes, array $permissions, ?string $expected) {
    matrixActor($permissions);
    matrixToken($scopes);

    $denial = app(McpAuthorizer::class)->denial(matrixCall());

    $expected === null ? expect($denial)->toBeNull() : expect($denial)->toContain($expected);
})->with([
    'token and actor' => [['entries:write'], ['view blog entries', 'edit blog entries'], null],
    'read token' => [['entries:read'], ['view blog entries', 'edit blog entries'], 'Token missing required scope: entries:write'],
    'actor lacks permission' => [['entries:write'], ['view blog entries'], 'Permission denied: Cannot update entries'],
    'legacy full access' => [['*'], ['view blog entries', 'edit blog entries'], 'must be approved again'],
]);

it('refuses an unauthenticated HTTP call', function () {
    expect(app(McpAuthorizer::class)->denial(matrixCall()))->toContain('Authentication required');
});

it('lets a super admin past permissions but never past the token', function () {
    matrixActor([], super: true);
    matrixToken(['entries:read']);

    expect(app(McpAuthorizer::class)->denial(matrixCall('read')))->toBeNull()
        ->and(app(McpAuthorizer::class)->denial(matrixCall('write')))->toContain('entries:write');
});

it('applies the resource policy to HTTP and stdio alike, per mode', function (bool $web) {
    config(['statamic.mcp.tools.entries.resources.read' => ['*'], 'statamic.mcp.tools.entries.resources.write' => ['news']]);
    if (! $web) {
        request()->attributes->remove(McpContext::WEB_ATTRIBUTE);
    } else {
        matrixActor([], super: true);
        matrixToken(['entries:read', 'entries:write']);
    }

    expect(app(McpAuthorizer::class)->denial(matrixCall('read')))->toBeNull()
        ->and(app(McpAuthorizer::class)->denial(matrixCall('write')))->toBe("Write access to 'blog' is not permitted by resource policy")
        ->and(app(McpAuthorizer::class)->denial(matrixCall('write', 'news')))->toBeNull();
})->with(['http' => true, 'stdio' => false]);

it('skips token and permission gates on stdio, where the operator is local', function () {
    request()->attributes->remove(McpContext::WEB_ATTRIBUTE);

    expect(app(McpAuthorizer::class)->denial(matrixCall()))->toBeNull();
});

it('fails closed for an extra tool that declares no scope or permission', function () {
    matrixActor(['view blog entries']);
    matrixToken(['entries:read']);
    $call = fn (array $scopes) => new McpCall('site-health', 'get', $scopes, [], null, 'read', extraTool: true);

    expect(app(McpAuthorizer::class)->denial($call([])))->toContain('declares no token scope')
        ->and(app(McpAuthorizer::class)->denial($call([TokenScope::EntriesRead])))->toContain('Permission denied');
});
