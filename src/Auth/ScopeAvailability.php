<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;

/**
 * The scopes a user can exercise.
 *
 * A token's scopes are capped by the user's Statamic permissions on every
 * call, so a scope the user cannot exercise is dead weight — and offering
 * it misleads: an editor who sees "Write Users" on the consent screen reads
 * it as something the client is about to do. This mirrors each router's
 * getRequiredPermissions(): a scope is available when the user holds the
 * permission for at least one action behind it. Super admins get every
 * scope; nobody else gets `*`.
 */
class ScopeAvailability
{
    public function enabled(): bool
    {
        return (bool) config('statamic.mcp.security.scopes_follow_permissions', true);
    }

    /**
     * @return array<int, TokenScope>
     */
    public function forUser(?User $user): array
    {
        return array_values(array_filter(
            TokenScope::cases(),
            fn (TokenScope $scope): bool => $this->allows($user, $scope),
        ));
    }

    /**
     * The scopes in the list the user cannot exercise.
     *
     * @param  array<int, TokenScope>  $scopes
     *
     * @return array<int, TokenScope>
     */
    public function reject(?User $user, array $scopes): array
    {
        return array_values(array_filter(
            $scopes,
            fn (TokenScope $scope): bool => ! $this->allows($user, $scope),
        ));
    }

    /**
     * Scope strings the user can exercise, in the order given; unknown
     * strings are dropped.
     *
     * @param  array<int, string>  $values
     *
     * @return array<int, string>
     */
    public function filterValues(?User $user, array $values): array
    {
        return array_values(array_filter($values, function (string $value) use ($user): bool {
            $scope = TokenScope::tryFrom($value);

            return $scope !== null && $this->allows($user, $scope);
        }));
    }

    public function allows(?User $user, TokenScope $scope): bool
    {
        if ($user === null) {
            return false;
        }

        if (! $this->enabled()) {
            return true;
        }

        // The site rules first: a switched-off tool and an empty resource
        // allowlist refuse every call, super admins included.
        if ($this->ruledOutBySite($scope)) {
            return false;
        }

        if ($user->isSuper()) {
            return true;
        }

        return match ($scope) {
            TokenScope::FullAccess,
            TokenScope::SystemWrite,
            TokenScope::ContentFacadeRead,
            TokenScope::ContentFacadeWrite => false,

            TokenScope::EntriesRead => $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => ["view {$h} entries"]),
            TokenScope::EntriesWrite => $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => [
                "create {$h} entries", "edit {$h} entries", "delete {$h} entries", "publish {$h} entries",
            ]),

            TokenScope::TermsRead => $this->holdsForAny($user, $this->taxonomyHandles(), fn (string $h): array => ["view {$h} terms"]),
            TokenScope::TermsWrite => $this->holdsForAny($user, $this->taxonomyHandles(), fn (string $h): array => ["edit {$h} terms"]),

            // The globals router asks for the edit permission on reads too.
            TokenScope::GlobalsRead,
            TokenScope::GlobalsWrite => $user->hasPermission('configure globals')
                || $this->holdsForAny($user, $this->globalHandles(), fn (string $h): array => ["edit {$h} globals"]),

            TokenScope::AssetsRead => $user->hasPermission('configure asset containers')
                || $this->holdsForAny($user, $this->containerHandles(), fn (string $h): array => ["view {$h} assets"]),
            TokenScope::AssetsWrite => $user->hasPermission('configure asset containers')
                || $this->holdsForAny($user, $this->containerHandles(), fn (string $h): array => [
                    "upload {$h} assets", "edit {$h} assets", "move {$h} assets", "rename {$h} assets", "delete {$h} assets",
                ]),

            TokenScope::StructuresRead => $this->holdsAny($user, ['configure collections', 'configure taxonomies', 'configure navs', 'configure sites'])
                || $this->holdsForAny($user, $this->navHandles(), fn (string $h): array => ["view {$h} nav"]),
            TokenScope::StructuresWrite => $this->holdsAny($user, ['configure collections', 'configure taxonomies', 'configure navs', 'configure sites'])
                || $this->holdsForAny($user, $this->navHandles(), fn (string $h): array => ["edit {$h} nav"]),

            // Reads follow the statamic://blueprints rule: a configure
            // permission, or the scope alone once the site waives that.
            TokenScope::BlueprintsRead => ! config('statamic.mcp.resources.require_statamic_permission', true)
                || $this->holdsAny($user, ['configure fields', 'configure collections', 'configure taxonomies']),
            TokenScope::BlueprintsWrite => $user->hasPermission('configure fields'),

            TokenScope::UsersRead => $user->hasPermission('view users'),
            TokenScope::UsersWrite => $this->holdsAny($user, [
                'create users', 'edit users', 'delete users', 'assign roles', 'edit roles', 'edit user groups',
            ]),

            TokenScope::SystemRead => $user->hasPermission('access utilities'),

            // Legacy umbrella scopes: no router asks for them, but a token
            // may still carry them. Available when their content is.
            TokenScope::ContentRead => $this->allows($user, TokenScope::EntriesRead) || $this->allows($user, TokenScope::TermsRead),
            TokenScope::ContentWrite => $this->allows($user, TokenScope::EntriesWrite) || $this->allows($user, TokenScope::TermsWrite),
        };
    }

    /**
     * Whether the site's own config leaves nothing behind the scope: the
     * tool switched off (`tools.{domain}.enabled`), or the resource policy
     * allowing no handle at all (`tools.{domain}.resources.{mode}` set to
     * `[]`). Both refuse every call regardless of who makes it, so the
     * scope is dead weight for everyone. The legacy content scopes cover
     * entries and terms, and go only when both are ruled out.
     */
    private function ruledOutBySite(TokenScope $scope): bool
    {
        $domains = match ($scope) {
            TokenScope::FullAccess => [],
            TokenScope::ContentRead, TokenScope::ContentWrite => ['entries', 'terms'],
            default => [$scope->group()],
        };

        if ($domains === []) {
            return false;
        }

        $mode = str_ends_with($scope->value, ':write') ? 'write' : 'read';

        foreach ($domains as $domain) {
            if (! $this->domainRuledOut($domain, $mode)) {
                return false;
            }
        }

        return true;
    }

    private function domainRuledOut(string $domain, string $mode): bool
    {
        if (! config("statamic.mcp.tools.{$domain}.enabled", true)) {
            return true;
        }

        return config("statamic.mcp.tools.{$domain}.resources.{$mode}") === [];
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function holdsAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $handles
     * @param  callable(string): array<int, string>  $permissions
     */
    private function holdsForAny(User $user, array $handles, callable $permissions): bool
    {
        foreach ($handles as $handle) {
            if ($this->holdsAny($user, $permissions($handle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function collectionHandles(): array
    {
        return array_values(array_filter(Collection::handles()->all(), 'is_string'));
    }

    /**
     * @return array<int, string>
     */
    private function taxonomyHandles(): array
    {
        return array_values(array_filter(Taxonomy::handles()->all(), 'is_string'));
    }

    /**
     * @return array<int, string>
     */
    private function globalHandles(): array
    {
        return $this->handlesOf(GlobalSet::all()->all());
    }

    /**
     * @return array<int, string>
     */
    private function containerHandles(): array
    {
        return $this->handlesOf(AssetContainer::all()->all());
    }

    /**
     * @return array<int, string>
     */
    private function navHandles(): array
    {
        return $this->handlesOf(Nav::all()->all());
    }

    /**
     * @param  array<mixed>  $items
     *
     * @return array<int, string>
     */
    private function handlesOf(array $items): array
    {
        $handles = [];

        foreach ($items as $item) {
            if ($item instanceof GlobalSetContract || $item instanceof AssetContainerContract || $item instanceof NavContract) {
                $handles[] = (string) $item->handle();
            }
        }

        return $handles;
    }
}
