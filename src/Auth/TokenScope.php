<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

enum TokenScope: string
{
    case FullAccess = '*';
    case ContentRead = 'content:read';
    case ContentWrite = 'content:write';
    case StructuresRead = 'structures:read';
    case StructuresWrite = 'structures:write';
    case StructuresAdmin = 'structures:admin';
    case AssetsRead = 'assets:read';
    case AssetsWrite = 'assets:write';
    case AssetsDelete = 'assets:delete';
    case UsersRead = 'users:read';
    case UsersWrite = 'users:write';
    case UsersAdmin = 'users:admin';
    case SystemRead = 'system:read';
    case SystemWrite = 'system:write';
    case SystemAdmin = 'system:admin';
    case BlueprintsRead = 'blueprints:read';
    case BlueprintsWrite = 'blueprints:write';
    case BlueprintsAdmin = 'blueprints:admin';
    case EntriesRead = 'entries:read';
    case EntriesWrite = 'entries:write';
    case EntriesPublish = 'entries:publish';
    case EntriesDelete = 'entries:delete';
    case TermsRead = 'terms:read';
    case TermsWrite = 'terms:write';
    case TermsDelete = 'terms:delete';
    case GlobalsRead = 'globals:read';
    case GlobalsWrite = 'globals:write';
    case ContentFacadeRead = 'content-facade:read';
    case ContentFacadeWrite = 'content-facade:write';

    /**
     * Get a human-readable label for this scope.
     */
    public function label(): string
    {
        return match ($this) {
            self::FullAccess => 'Full Access',
            self::ContentRead => 'Read Content',
            self::ContentWrite => 'Write Content',
            self::StructuresRead => 'Read Structures',
            self::StructuresWrite => 'Write Structures (v3, replaced)',
            self::StructuresAdmin => 'Administer Structures',
            self::AssetsRead => 'Read Assets',
            self::AssetsWrite => 'Edit Assets',
            self::AssetsDelete => 'Delete Assets',
            self::UsersRead => 'Read Users',
            self::UsersWrite => 'Write Users (v3, replaced)',
            self::UsersAdmin => 'Administer Users',
            self::SystemRead => 'Read System',
            self::SystemWrite => 'Write System (v3, replaced)',
            self::SystemAdmin => 'Administer System',
            self::BlueprintsRead => 'Read Blueprints',
            self::BlueprintsWrite => 'Write Blueprints (v3, replaced)',
            self::BlueprintsAdmin => 'Administer Blueprints',
            self::EntriesRead => 'Read Entries',
            self::EntriesWrite => 'Edit Entries',
            self::EntriesPublish => 'Publish Entries',
            self::EntriesDelete => 'Delete Entries',
            self::TermsRead => 'Read Terms',
            self::TermsWrite => 'Edit Terms',
            self::TermsDelete => 'Delete Terms',
            self::GlobalsRead => 'Read Globals',
            self::GlobalsWrite => 'Write Globals',
            self::ContentFacadeRead => 'Read Content Facade',
            self::ContentFacadeWrite => 'Write Content Facade',
        };
    }

    /**
     * Get a human-readable description for this scope.
     */
    public function description(): string
    {
        return match ($this) {
            self::FullAccess => 'Every scope you can hold today. Fixed when the token is issued: scopes added later are not included.',
            self::ContentRead => 'View entries and taxonomy terms.',
            self::ContentWrite => 'Create, update, and delete entries and terms.',
            self::StructuresRead => 'View collections, taxonomies, navigations, and sites.',
            self::StructuresWrite => 'Replaced by structures:admin in v4. Grants nothing; approve the token again.',
            self::StructuresAdmin => 'Create, update, and delete collections, taxonomies, navigations, and sites.',
            self::AssetsRead => 'View asset containers and files.',
            self::AssetsWrite => 'Upload, move, copy, and edit assets. Deleting needs Delete Assets.',
            self::AssetsDelete => 'Delete assets and asset containers. Also needs Edit Assets.',
            self::UsersRead => 'View users, roles, and groups.',
            self::UsersWrite => 'Replaced by users:admin in v4. Grants nothing; approve the token again.',
            self::UsersAdmin => 'Create, update, and delete users, roles, and groups, and assign roles.',
            self::SystemRead => 'View system info, health checks, and configuration.',
            self::SystemWrite => 'Replaced by system:admin in v4. Grants nothing; approve the token again.',
            self::SystemAdmin => 'Clear caches and change system settings.',
            self::BlueprintsRead => 'View blueprints and fieldsets.',
            self::BlueprintsWrite => 'Replaced by blueprints:admin in v4. Grants nothing; approve the token again.',
            self::BlueprintsAdmin => 'Create, update, and delete blueprints and fieldsets.',
            self::EntriesRead => 'View entries across collections.',
            self::EntriesWrite => 'Create and update entries as drafts, and edit published content. Publishing and deleting need their own scopes.',
            self::EntriesPublish => 'Publish and unpublish entries, and create them published. Also needs Edit Entries.',
            self::EntriesDelete => 'Delete entries. Also needs Edit Entries.',
            self::TermsRead => 'View taxonomy terms.',
            self::TermsWrite => 'Create and update taxonomy terms. Deleting needs Delete Terms.',
            self::TermsDelete => 'Delete taxonomy terms. Also needs Edit Terms.',
            self::GlobalsRead => 'View global sets and their values.',
            self::GlobalsWrite => 'Update global set values.',
            self::ContentFacadeRead => 'Run content audits and cross-reference analysis.',
            self::ContentFacadeWrite => 'Execute content workflow operations.',
        };
    }

    /**
     * Get the domain group for this scope.
     */
    public function group(): string
    {
        return match ($this) {
            self::FullAccess => 'access',
            self::ContentRead, self::ContentWrite => 'content',
            self::StructuresRead, self::StructuresWrite, self::StructuresAdmin => 'structures',
            self::AssetsRead, self::AssetsWrite, self::AssetsDelete => 'assets',
            self::UsersRead, self::UsersWrite, self::UsersAdmin => 'users',
            self::SystemRead, self::SystemWrite, self::SystemAdmin => 'system',
            self::BlueprintsRead, self::BlueprintsWrite, self::BlueprintsAdmin => 'blueprints',
            self::EntriesRead, self::EntriesWrite, self::EntriesPublish, self::EntriesDelete => 'entries',
            self::TermsRead, self::TermsWrite, self::TermsDelete => 'terms',
            self::GlobalsRead, self::GlobalsWrite => 'globals',
            self::ContentFacadeRead, self::ContentFacadeWrite => 'content-facade',
        };
    }

    /**
     * The scope a write action needs in a domain: `{domain}:admin` in the
     * admin domains (structures, blueprints, users, system), `{domain}:write`
     * everywhere else.
     */
    public static function writeFor(string $domain): ?self
    {
        return self::tryFrom("{$domain}:admin") ?? self::tryFrom("{$domain}:write");
    }

    /**
     * The v4 scope that replaced this v3 one, if any.
     *
     * A replaced scope stays a valid value so tokens carrying it still load,
     * but it grants nothing: the token has to be approved again.
     */
    public function replacedBy(): ?self
    {
        return match ($this) {
            self::StructuresWrite => self::StructuresAdmin,
            self::BlueprintsWrite => self::BlueprintsAdmin,
            self::UsersWrite => self::UsersAdmin,
            self::SystemWrite => self::SystemAdmin,
            default => null,
        };
    }

    /**
     * What v4 changed for a token holding these scopes, keyed by change:
     * `full_access` (a v3 `*`, refused), `replaced` (v3 admin `:write`
     * scopes, refused) and `publish_delete` (an edit scope that no longer
     * publishes or deletes). Empty when the token is unaffected.
     *
     * @param  array<int, string>  $scopes
     *
     * @return array<string, list<string>>
     */
    public static function v3Changes(array $scopes): array
    {
        $changes = [];

        if (in_array(self::FullAccess->value, $scopes, true)) {
            $changes['full_access'] = [self::FullAccess->value];
        }

        foreach ($scopes as $scope) {
            if (self::tryFrom($scope)?->replacedBy() !== null) {
                $changes['replaced'][] = $scope;
            }
        }

        foreach (['entries:write' => ['entries:publish', 'entries:delete'], 'terms:write' => ['terms:delete'], 'assets:write' => ['assets:delete']] as $write => $extras) {
            $missing = array_values(array_diff($extras, $scopes));
            if (in_array($write, $scopes, true) && $missing !== []) {
                $changes['publish_delete'] = array_merge($changes['publish_delete'] ?? [], $missing);
            }
        }

        return $changes;
    }

    /**
     * The scope an action needs on top of its domain's write scope, if any.
     *
     * Publishing and deleting are separate grants: a v3 `entries:write`
     * token keeps editing in v4, but cannot publish or delete until those
     * scopes are approved for it. Admin domains (structures, blueprints,
     * users, system) have no finer grant; their admin scope covers delete.
     */
    public static function extraFor(string $domain, string $action): ?self
    {
        return match ($action) {
            'publish', 'unpublish', 'publish_working_copy' => self::tryFrom("{$domain}:publish"),
            'delete' => self::tryFrom("{$domain}:delete"),
            default => null,
        };
    }

    /**
     * Get all available scopes.
     *
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Resolve an array of scope strings into TokenScope enum instances.
     *
     * Invalid scope strings are silently skipped.
     *
     * @param  array<int, string>  $scopeStrings
     *
     * @return array<int, self>
     */
    public static function resolveMany(array $scopeStrings): array
    {
        $scopes = [];

        foreach ($scopeStrings as $scopeString) {
            $scope = self::tryFrom($scopeString);

            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }
}
