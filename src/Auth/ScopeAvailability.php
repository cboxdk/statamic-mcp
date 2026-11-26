<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Form;
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
    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_unique(array_merge(
            array_map(fn (TokenScope $scope): string => $scope->value, TokenScope::cases()),
            app(ScopeRegistry::class)->names(),
        )));
    }

    /**
     * What a client may ask for: every scope but the retired v3 ones, which
     * still load on old tokens but grant nothing. `*` stays: consent expands it.
     *
     * @return list<string>
     */
    public function advertised(): array
    {
        return array_values(array_filter($this->names(), fn (string $name): bool => TokenScope::tryFrom($name)?->isRetired() !== true));
    }

    /** @return list<array{value: string, label: string, description: string, group: string}> */
    public function options(?User $user): array
    {
        return array_map(function (string $value): array {
            $core = TokenScope::tryFrom($value);

            return ['value' => $value, 'label' => $this->label($value),
                'description' => $core?->description() ?? 'Access to the integration actions shown when this token was created.',
                'group' => $core?->group() ?? 'integrations'];
        }, $this->filterValues($user, $this->names()));
    }

    public function label(string $value): string
    {
        return TokenScope::tryFrom($value)?->label()
            ?? app(ScopeRegistry::class)->label($value)
            ?? throw new \InvalidArgumentException('Unknown scope.');
    }

    public function allowsValue(?User $user, string $value): bool
    {
        $scope = TokenScope::tryFrom($value);

        return $scope !== null ? $this->allows($user, $scope) : app(ScopeRegistry::class)->allows($user, $value);
    }

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
     * @return list<string>
     */
    public function filterValues(?User $user, array $values): array
    {
        return array_values(array_filter($values, fn (string $value): bool => $this->allowsValue($user, $value)));
    }

    /**
     * Scope strings a grant may carry for this user, in the order given.
     *
     * Unlike filterValues(), a `*` is expanded to every scope the user can
     * hold rather than dropped: a client asking for everything is asking for
     * the user's full reach, and an editor should be offered that on the
     * consent screen, not turned away with invalid_scope.
     *
     * @param  array<int, string>  $values
     *
     * @return list<string>
     */
    public function grantable(?User $user, array $values): array
    {
        return array_values(array_unique($this->filterValues($user, $this->expandFullAccess($user, $values))));
    }

    /**
     * Replace `*` with the explicit scopes the user can hold right now.
     *
     * A stored `*` would silently grow to cover every scope added in a later
     * release, which is a grant nobody approved. Issuing full access
     * therefore fixes it to today's list.
     *
     * @param  array<int, string>  $values
     *
     * @return list<string>
     */
    public function expandFullAccess(?User $user, array $values): array
    {
        $expanded = [];

        foreach ($values as $value) {
            if ($value !== TokenScope::FullAccess->value) {
                $expanded[] = $value;

                continue;
            }

            foreach ($this->forUser($user) as $scope) {
                if ($scope !== TokenScope::FullAccess) {
                    $expanded[] = $scope->value;
                }
            }
        }

        return array_values(array_unique($expanded));
    }

    public function allows(?User $user, TokenScope $scope): bool
    {
        // A retired scope grants nothing, so nobody is offered it.
        if ($user === null || $scope->isRetired()) {
            return false;
        }

        if (! $this->enabled()) {
            return true;
        }

        // Collection trees belong to structures, but also require entry scopes.
        // Disabling the entries tool does not disable this path; closing either
        // resource policy does. Per-record and site policies still run on calls.
        if (in_array($scope, [TokenScope::EntriesRead, TokenScope::EntriesWrite], true)) {
            $write = $scope === TokenScope::EntriesWrite;
            $mode = $write ? 'write' : 'read';
            $permission = $write ? 'reorder' : 'view';
            if (config("statamic.mcp.tools.entries.resources.{$mode}") !== []
                && ! $this->ruledOutBySite($write ? TokenScope::StructuresAdmin : TokenScope::StructuresRead)
                && ($user->isSuper() || $user->hasPermission('configure collections')
                    || $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => ["{$permission} {$h} entries"]))) {
                return true;
            }
        }

        // For the scope's own domain, check site rules before permissions:
        // disabled tools and empty allowlists refuse super admins too.
        if ($this->ruledOutBySite($scope)) {
            return false;
        }

        if ($user->isSuper()) {
            return true;
        }

        return match ($scope) {
            TokenScope::FullAccess,
            TokenScope::SystemAdmin,
            TokenScope::ContentFacadeRead,
            TokenScope::ContentFacadeWrite => false,

            TokenScope::EntriesRead => $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => ["view {$h} entries"]),
            TokenScope::EntriesWrite => $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => [
                "create {$h} entries", "edit {$h} entries", "delete {$h} entries", "publish {$h} entries",
            ]),
            TokenScope::EntriesPublish => $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => ["publish {$h} entries"]),
            TokenScope::EntriesDelete => $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => ["delete {$h} entries"]),

            TokenScope::TermsRead => $this->holdsForAny($user, $this->taxonomyHandles(), fn (string $h): array => ["view {$h} terms"]),
            TokenScope::TermsWrite => $this->holdsForAny($user, $this->taxonomyHandles(), fn (string $h): array => ["create {$h} terms", "edit {$h} terms", "delete {$h} terms"]),
            TokenScope::TermsDelete => $this->holdsForAny($user, $this->taxonomyHandles(), fn (string $h): array => ["delete {$h} terms"]),

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
            TokenScope::AssetsDelete => $user->hasPermission('configure asset containers')
                || $this->holdsForAny($user, $this->containerHandles(), fn (string $h): array => ["delete {$h} assets"]),

            TokenScope::StructuresRead => $this->holdsAny($user, ['configure collections', 'configure taxonomies', 'configure navs', 'configure sites', 'configure globals'])
                || $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => ["view {$h} entries"])
                || $this->holdsForAny($user, $this->navHandles(), fn (string $h): array => ["view {$h} nav"]),
            // Sites are read-only over MCP, so configure sites gives no admin scope.
            TokenScope::StructuresAdmin => $this->holdsAny($user, ['configure collections', 'configure taxonomies', 'configure navs', 'configure globals'])
                || $this->holdsForAny($user, $this->collectionHandles(), fn (string $h): array => ["reorder {$h} entries"])
                || $this->holdsForAny($user, $this->navHandles(), fn (string $h): array => ["edit {$h} nav"]),

            // Reads follow the statamic://blueprints rule: a configure
            // permission, or the scope alone once the site waives that.
            TokenScope::BlueprintsRead => ! config('statamic.mcp.resources.require_statamic_permission', true)
                || $this->holdsAny($user, ['configure fields', 'configure collections', 'configure taxonomies']),
            TokenScope::BlueprintsAdmin => $user->hasPermission('configure fields'),

            // Roles and groups are read with the permission that edits them.
            TokenScope::UsersRead => $this->holdsAny($user, ['view users', 'edit roles', 'edit user groups']),
            TokenScope::UsersAdmin => $this->holdsAny($user, [
                'create users', 'edit users', 'delete users', 'assign roles', 'edit roles', 'edit user groups',
            ]),

            TokenScope::SystemRead => $user->hasPermission('access utilities'),

            TokenScope::FormsRead => $this->holdsAny($user, ['configure forms', 'edit forms', 'configure form fields', 'view form submissions'])
                || $this->holdsForAny($user, Form::all()->map->handle()->all(), fn (string $h): array => ["edit {$h} form", "view {$h} form submissions"]),
            TokenScope::FormsWrite => $this->holdsAny($user, ['configure forms', 'create forms', 'edit forms', 'configure form fields'])
                || $this->holdsForAny($user, Form::all()->map->handle()->all(), fn (string $h): array => ["edit {$h} form"])
                || $this->canManageFormSpam($user),
            TokenScope::SubmissionsRead => $user->hasPermission('configure forms') || $user->hasPermission('view form submissions')
                || $this->holdsForAny($user, Form::all()->map->handle()->all(), fn (string $h): array => ["view {$h} form submissions"]),
            TokenScope::SubmissionsDelete => $user->hasPermission('configure forms') || $user->hasPermission('delete form submissions')
                || $this->holdsForAny($user, Form::all()->map->handle()->all(), fn (string $h): array => ["delete {$h} form submissions"]),

            // Retired; refused above.
            TokenScope::ContentRead,
            TokenScope::ContentWrite,
            TokenScope::StructuresWrite,
            TokenScope::BlueprintsWrite,
            TokenScope::UsersWrite,
            TokenScope::SystemWrite => false,
        };
    }

    /**
     * Whether the site's own config leaves nothing behind the scope: the
     * tool switched off (`tools.{domain}.enabled`), or the resource policy
     * allowing no handle at all (`tools.{domain}.resources.{mode}` set to
     * `[]`). Both refuse every call regardless of who makes it, so the
     * scope is dead weight for everyone.
     */
    private function ruledOutBySite(TokenScope $scope): bool
    {
        if ($scope === TokenScope::FullAccess) {
            return false;
        }

        return $this->domainRuledOut($scope->group(), str_ends_with($scope->value, ':read') ? 'read' : 'write');
    }

    private function domainRuledOut(string $domain, string $mode): bool
    {
        if (! config("statamic.mcp.tools.{$domain}.enabled", true)) {
            return true;
        }

        return config("statamic.mcp.tools.{$domain}.resources.{$mode}") === [];
    }

    private function canManageFormSpam(User $user): bool
    {
        if ($user->hasPermission('manage form spam') && $user->hasPermission('view form submissions')) {
            return true;
        }
        foreach (Form::all() as $form) {
            if ($user->can('manageSpam', $form) && $user->can('viewSubmissions', $form)) {
                return true;
            }
        }

        return false;
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
