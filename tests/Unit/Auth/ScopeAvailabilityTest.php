<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Illuminate\Support\Facades\Config;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;

beforeEach(function () {
    Config::set('statamic.mcp.resources.require_statamic_permission', true);
    config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/assets')]]);

    Collection::make('blog')->title('Blog')->save();
    Taxonomy::make('tags')->title('Tags')->save();
    GlobalSet::make()->handle('footer')->title('Footer')->save();
    AssetContainer::make('images')->disk('assets')->save();
    Nav::make()->handle('main')->title('Main')->save();

    $this->availability = new ScopeAvailability;
});

/**
 * @param  array<int, string>  $permissions
 */
function userHolding(array $permissions): UserContract
{
    $handle = 'role-' . bin2hex(random_bytes(4));
    Role::make()->handle($handle)->title('Role')->permissions($permissions)->save();

    $user = User::make()->id('user-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.com');
    $user->assignRole($handle);
    $user->save();

    return $user;
}

/**
 * @return array<int, string>
 */
function scopeValues(array $scopes): array
{
    return array_map(fn (TokenScope $s): string => $s->value, $scopes);
}

it('gives a super admin every scope', function () {
    $user = User::make()->id('super-1')->email('super@example.com');
    $user->makeSuper();
    $user->save();

    expect($this->availability->forUser($user))->toBe(TokenScope::cases());
});

it('gives nobody anything', function () {
    expect($this->availability->forUser(null))->toBe([]);
});

it('gives a user without permissions no scope at all', function () {
    expect($this->availability->forUser(userHolding([])))->toBe([]);
});

it('never offers full access, system writes or the content facade below super', function () {
    $user = userHolding([
        'view blog entries', 'edit blog entries', 'configure fields', 'view users', 'edit users', 'access utilities',
    ]);

    $values = scopeValues($this->availability->forUser($user));

    expect($values)->not->toContain('*', 'system:write', 'content-facade:read', 'content-facade:write');
});

it('follows the entries router: view gives read, any write permission gives write', function () {
    expect(scopeValues($this->availability->forUser(userHolding(['view blog entries']))))
        ->toBe(['content:read', 'entries:read']);

    expect(scopeValues($this->availability->forUser(userHolding(['publish blog entries']))))
        ->toBe(['content:write', 'entries:write']);
});

it('follows the terms, globals, assets and structures routers', function () {
    expect(scopeValues($this->availability->forUser(userHolding(['view tags terms']))))->toBe(['content:read', 'terms:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['edit tags terms']))))->toBe(['content:write', 'terms:write']);

    // The globals router asks for edit on reads as well.
    expect(scopeValues($this->availability->forUser(userHolding(['edit footer globals']))))->toBe(['globals:read', 'globals:write']);

    expect(scopeValues($this->availability->forUser(userHolding(['view images assets']))))->toBe(['assets:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['upload images assets']))))->toBe(['assets:write']);

    expect(scopeValues($this->availability->forUser(userHolding(['view main nav']))))->toBe(['structures:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['edit main nav']))))->toBe(['structures:write']);
    expect(scopeValues($this->availability->forUser(userHolding(['configure collections']))))
        ->toBe(['structures:read', 'structures:write', 'blueprints:read']);
});

it('follows the users, system and blueprints routers', function () {
    expect(scopeValues($this->availability->forUser(userHolding(['view users']))))->toBe(['users:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['assign roles']))))->toBe(['users:write']);
    expect(scopeValues($this->availability->forUser(userHolding(['access utilities']))))->toBe(['system:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['configure fields']))))->toBe(['blueprints:read', 'blueprints:write']);
});

it('offers blueprints:read to every user once the site waives the permission on schema reads', function () {
    $editor = userHolding(['view blog entries']);

    expect(scopeValues($this->availability->forUser($editor)))->not->toContain('blueprints:read');

    Config::set('statamic.mcp.resources.require_statamic_permission', false);

    expect(scopeValues($this->availability->forUser($editor)))->toContain('blueprints:read');
    expect(scopeValues($this->availability->forUser($editor)))->not->toContain('blueprints:write');
});

it('names the scopes a user cannot exercise', function () {
    $editor = userHolding(['view blog entries', 'edit blog entries']);

    $rejected = $this->availability->reject($editor, [TokenScope::EntriesWrite, TokenScope::UsersWrite, TokenScope::FullAccess]);

    expect(scopeValues($rejected))->toBe(['users:write', '*']);
    expect($this->availability->filterValues($editor, ['entries:read', 'users:write', 'nonsense', 'entries:write']))
        ->toBe(['entries:read', 'entries:write']);
});

it('drops a scope whose domain the resource policy closes, super admins included', function () {
    Config::set('statamic.mcp.tools.structures.resources.write', []);

    $super = User::make()->id('super-2')->email('super2@example.com');
    $super->makeSuper();
    $super->save();

    expect(scopeValues($this->availability->forUser($super)))
        ->not->toContain('structures:write')
        ->toContain('structures:read', '*');

    expect(scopeValues($this->availability->forUser(userHolding(['edit main nav']))))->toBe([]);
});

it('drops both scopes of a tool that is switched off', function () {
    Config::set('statamic.mcp.tools.users.enabled', false);

    $values = scopeValues($this->availability->forUser(userHolding(['view users', 'edit users'])));

    expect($values)->toBe([]);
});

it('keeps a legacy content scope while entries or terms still allow it', function () {
    Config::set('statamic.mcp.tools.terms.resources.write', []);

    expect(scopeValues($this->availability->forUser(userHolding(['edit blog entries', 'edit tags terms']))))
        ->toBe(['content:write', 'entries:write']);

    Config::set('statamic.mcp.tools.entries.resources.write', []);

    expect(scopeValues($this->availability->forUser(userHolding(['edit blog entries', 'edit tags terms']))))->toBe([]);
});

it('offers everything to everyone when switched off', function () {
    Config::set('statamic.mcp.security.scopes_follow_permissions', false);
    Config::set('statamic.mcp.tools.structures.resources.write', []);

    expect($this->availability->forUser(userHolding([])))->toBe(TokenScope::cases());
});

it('offers structures to a user who configures globals, as the structures router lets them', function () {
    $user = userHolding(['configure globals']);

    expect($this->availability->allows($user, TokenScope::StructuresRead))->toBeTrue()
        ->and($this->availability->allows($user, TokenScope::StructuresWrite))->toBeTrue();
});

it('narrows a wildcard to the user\'s own reach instead of dropping it', function () {
    $user = userHolding(['view blog entries']);

    expect($this->availability->filterValues($user, ['*']))->toBe([])
        ->and($this->availability->grantable($user, ['*', 'entries:read', 'users:write']))
        ->toBe(['content:read', 'entries:read']);
});

it('keeps a wildcard for a user who can hold it', function () {
    $user = User::make()->id('super-2')->email('super2@example.com');
    $user->makeSuper();
    $user->save();

    expect($this->availability->grantable($user, ['*', 'entries:read']))->toBe(['*', 'entries:read']);
});
