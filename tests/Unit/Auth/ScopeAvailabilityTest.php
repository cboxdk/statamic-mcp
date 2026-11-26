<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
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

/**
 * Every scope value in enum order, minus the six retired v3 ones (listed by
 * hand so the expectation does not come from the code under test).
 *
 * @return list<string>
 */
function liveScopeValues(): array
{
    $retired = ['content:read', 'content:write', 'structures:write', 'users:write', 'system:write', 'blueprints:write'];

    return array_values(array_filter(scopeValues(TokenScope::cases()), fn (string $v): bool => ! in_array($v, $retired, true)));
}

it('gives a super admin every scope', function () {
    $user = User::make()->id('super-1')->email('super@example.com');
    $user->makeSuper();
    $user->save();

    // Every scope except the retired v3 ones, which grant nothing.
    expect(scopeValues($this->availability->forUser($user)))->toBe(liveScopeValues());
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

    expect($values)->not->toContain('*', 'system:admin', 'content-facade:read', 'content-facade:write');
});

it('follows the entries router: view gives read, any write permission gives write', function () {
    expect(scopeValues($this->availability->forUser(userHolding(['view blog entries']))))
        ->toBe(['structures:read', 'entries:read']);

    expect(scopeValues($this->availability->forUser(userHolding(['publish blog entries']))))
        ->toBe(['entries:write', 'entries:publish']);
});

it('follows the terms, globals, assets and structures routers', function () {
    expect(scopeValues($this->availability->forUser(userHolding(['view tags terms']))))->toBe(['terms:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['edit tags terms']))))->toBe(['terms:write']);

    // The globals router asks for edit on reads as well.
    expect(scopeValues($this->availability->forUser(userHolding(['edit footer globals']))))->toBe(['globals:read', 'globals:write']);

    expect(scopeValues($this->availability->forUser(userHolding(['view images assets']))))->toBe(['assets:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['upload images assets']))))->toBe(['assets:write']);

    expect(scopeValues($this->availability->forUser(userHolding(['view main nav']))))->toBe(['structures:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['edit main nav']))))->toBe(['structures:admin']);
    expect(scopeValues($this->availability->forUser(userHolding(['configure collections']))))
        ->toBe(['structures:read', 'structures:admin', 'blueprints:read']);
});

it('follows the users, system and blueprints routers', function () {
    expect(scopeValues($this->availability->forUser(userHolding(['view users']))))->toBe(['users:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['assign roles']))))->toBe(['users:admin']);
    expect(scopeValues($this->availability->forUser(userHolding(['access utilities']))))->toBe(['system:read']);
    expect(scopeValues($this->availability->forUser(userHolding(['configure fields']))))->toBe(['blueprints:read', 'blueprints:admin']);
});

it('offers blueprints:read to every user once the site waives the permission on schema reads', function () {
    $editor = userHolding(['view blog entries']);

    expect(scopeValues($this->availability->forUser($editor)))->not->toContain('blueprints:read');

    Config::set('statamic.mcp.resources.require_statamic_permission', false);

    expect(scopeValues($this->availability->forUser($editor)))->toContain('blueprints:read');
    expect(scopeValues($this->availability->forUser($editor)))->not->toContain('blueprints:admin');
});

it('names the scopes a user cannot exercise', function () {
    $editor = userHolding(['view blog entries', 'edit blog entries']);

    $rejected = $this->availability->reject($editor, [TokenScope::EntriesWrite, TokenScope::UsersAdmin, TokenScope::FullAccess]);

    expect(scopeValues($rejected))->toBe(['users:admin', '*']);
    expect($this->availability->filterValues($editor, ['entries:read', 'users:admin', 'nonsense', 'entries:write']))
        ->toBe(['entries:read', 'entries:write']);
});

it('drops a scope whose domain the resource policy closes, super admins included', function () {
    Config::set('statamic.mcp.tools.structures.resources.write', []);

    $super = User::make()->id('super-2')->email('super2@example.com');
    $super->makeSuper();
    $super->save();

    expect(scopeValues($this->availability->forUser($super)))
        ->not->toContain('structures:admin')
        ->toContain('structures:read', '*');

    expect(scopeValues($this->availability->forUser(userHolding(['edit main nav']))))->toBe([]);
});

it('drops both scopes of a tool that is switched off', function () {
    Config::set('statamic.mcp.tools.users.enabled', false);

    $values = scopeValues($this->availability->forUser(userHolding(['view users', 'edit users'])));

    expect($values)->toBe([]);
});

it('never offers a retired scope, whatever the user holds', function () {
    $editor = userHolding(['view blog entries', 'edit blog entries', 'view tags terms', 'edit tags terms']);

    foreach ([TokenScope::ContentRead, TokenScope::ContentWrite, TokenScope::StructuresWrite, TokenScope::UsersWrite] as $retired) {
        expect($this->availability->allows($editor, $retired))->toBeFalse($retired->value);
    }
    expect($this->availability->advertised())->not->toContain('content:read', 'content:write', 'users:write');
});

it('offers everything to everyone when switched off', function () {
    Config::set('statamic.mcp.security.scopes_follow_permissions', false);
    Config::set('statamic.mcp.tools.structures.resources.write', []);

    expect(scopeValues($this->availability->forUser(userHolding([]))))->toBe(liveScopeValues());
});

it('offers structures to a user who configures globals, as the structures router lets them', function () {
    $user = userHolding(['configure globals']);

    expect($this->availability->allows($user, TokenScope::StructuresRead))->toBeTrue()
        ->and($this->availability->allows($user, TokenScope::StructuresAdmin))->toBeTrue();
});

it('narrows a wildcard to the user\'s own reach instead of dropping it', function () {
    $user = userHolding(['view blog entries']);

    expect($this->availability->filterValues($user, ['*']))->toBe([])
        ->and($this->availability->grantable($user, ['*', 'entries:read', 'users:admin']))
        ->toBe(['structures:read', 'entries:read']);
});

it('expands a wildcard to an explicit list even for a super admin', function () {
    $user = User::make()->id('super-2')->email('super2@example.com');
    $user->makeSuper();
    $user->save();

    // A stored `*` would grow with every scope a later release adds.
    $granted = $this->availability->grantable($user, ['*', 'entries:read']);

    expect($granted)->not->toContain('*')
        ->and($granted)->not->toContain('content:write')
        ->and($granted)->toContain('entries:publish', 'entries:delete', 'terms:delete', 'assets:delete', 'users:admin', 'system:admin')
        ->and($granted)->not->toContain('users:write', 'system:write')
        ->and($granted)->toHaveCount(count(TokenScope::cases()) - 7);
});

it('offers publish and delete only to users who may publish or delete', function () {
    $editor = userHolding(['view blog entries', 'edit blog entries', 'publish blog entries']);

    expect($this->availability->allows($editor, TokenScope::EntriesWrite))->toBeTrue()
        ->and($this->availability->allows($editor, TokenScope::EntriesPublish))->toBeTrue()
        ->and($this->availability->allows($editor, TokenScope::EntriesDelete))->toBeFalse();

    // Publishing is a write: a site that allows no entry writes rules it out.
    Config::set('statamic.mcp.tools.entries.resources.write', []);

    expect($this->availability->allows($editor, TokenScope::EntriesPublish))->toBeFalse()
        ->and($this->availability->allows($editor, TokenScope::EntriesRead))->toBeTrue();
});

it('offers entry viewers structure reads without structure writes', function () {
    $viewer = userHolding(['view blog entries']);

    expect($this->availability->allows($viewer, TokenScope::StructuresRead))->toBeTrue()
        ->and($this->availability->allows($viewer, TokenScope::StructuresAdmin))->toBeFalse();

    Config::set('statamic.mcp.tools.structures.resources.read', []);

    expect($this->availability->allows($viewer, TokenScope::StructuresRead))->toBeFalse();
});

it('offers structure writes to collection reorderers without navigation permission', function () {
    $editor = userHolding(['view blog entries', 'edit blog entries', 'reorder blog entries']);

    expect($this->availability->allows($editor, TokenScope::StructuresAdmin))->toBeTrue();
    Config::set('statamic.mcp.tools.structures.resources.write', []);
    expect($this->availability->allows($editor, TokenScope::StructuresAdmin))->toBeFalse();
});

/*
 * ScopeAvailability mirrors every router's getRequiredPermissions() by hand.
 * This keeps the mirror honest with the routers themselves as the oracle:
 * for every declared action, resource type and fixture handle, a user holding
 * exactly the permissions the router asks for must be offered every scope the
 * call needs, and a user is offered nothing that no such call lets them use.
 * A router whose permissions change without the mirror fails here.
 */
it('offers exactly the scopes the routers let a permission set use', function () {
    $routers = array_map(fn (string $class) => new $class, array_values(array_filter(
        (new ReflectionClass(StatamicMcpServer::class))->getDefaultProperties()['tools'],
        fn ($tool) => is_string($tool) && is_subclass_of($tool, BaseRouter::class),
    )));
    $describe = fn (object $router, string $action, array $arguments) => (new ReflectionMethod($router, 'describeCall'))->invoke($router, $action, $arguments);

    // permission set (sorted, joined) => scopes the calls it satisfies need
    $reach = [];
    foreach ($routers as $router) {
        $types = array_keys((new ReflectionMethod($router, 'getTypes'))->invoke($router)) ?: [null];
        foreach ($router->declaredActions() as $declared) {
            foreach ($types as $type) {
                if (! $declared->appliesTo($type)) {
                    continue;
                }
                foreach (['blog', 'tags', 'footer', 'images', 'main', ''] as $handle) {
                    foreach ([[], ['entry' => 'some-entry', 'parent' => null]] as $data) {
                        $call = $describe($router, $declared->name, array_filter([
                            'action' => $declared->name, 'resource_type' => $type, 'handle' => $handle,
                            'collection' => 'blog', 'taxonomy' => 'tags', 'container' => 'images', 'data' => $data,
                        ], fn ($value) => $value !== null && $value !== '' && $value !== []));
                        if ($call->permissions === []) {
                            continue; // Filtered per item, or open by site config: proves nothing about a permission.
                        }
                        // Every way to satisfy the requirement: one permission from each any-of entry.
                        $sets = [[]];
                        foreach ($call->permissions as $required) {
                            $next = [];
                            foreach ($sets as $set) {
                                foreach ((array) $required as $alternative) {
                                    $next[] = [...$set, $alternative];
                                }
                            }
                            $sets = $next;
                        }
                        foreach ($sets as $set) {
                            // 'super' is no permission; one naming a record that does not exist proves nothing.
                            $existing = ['entries' => 'blog', 'terms' => 'tags', 'globals' => 'footer', 'assets' => 'images', 'nav' => 'main'];
                            if (in_array('super', $set, true) || array_filter($set, fn (string $p) => preg_match('/^[a-z]+ (\\S+) (entries|terms|globals|assets|nav)$/', $p, $m) === 1 && $existing[$m[2]] !== $m[1]) !== []) {
                                continue;
                            }
                            $set = array_values(array_unique($set));
                            sort($set);
                            $key = implode('|', $set);
                            $reach[$key] = array_values(array_unique([...($reach[$key] ?? []), ...array_map(fn ($s) => $s->value, $call->scopes)]));
                        }
                    }
                }
            }
        }
    }

    expect($reach)->not->toBeEmpty();
    foreach ($reach as $key => $needed) {
        $permissions = explode('|', $key);
        // What any call this exact set satisfies needs, including calls of smaller sets.
        $usable = [];
        foreach ($reach as $other => $scopes) {
            if (array_diff(explode('|', $other), $permissions) === []) {
                $usable = [...$usable, ...$scopes];
            }
        }
        $offered = scopeValues($this->availability->forUser(userHolding($permissions)));

        expect(array_values(array_diff($needed, $offered)))->toBe([], "Holding [{$key}] passes a router call needing these scopes, but they are not offered");
        expect(array_values(array_diff($offered, $usable)))->toBe([], "Holding [{$key}] is offered scopes no router call lets it use");
    }
});
