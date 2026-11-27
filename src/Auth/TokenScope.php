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
    case FormsRead = 'forms:read';
    case FormsWrite = 'forms:write';
    case SubmissionsRead = 'submissions:read';
    case SubmissionsDelete = 'submissions:delete';
    case ContentFacadeRead = 'content-facade:read';
    case ContentFacadeWrite = 'content-facade:write';

    /**
     * Get a human-readable label for this scope.
     */
    public function label(): string
    {
        return __('statamic-mcp::scopes.labels.' . $this->name);
    }

    /**
     * Get a human-readable description for this scope.
     */
    public function description(): string
    {
        return __('statamic-mcp::scopes.descriptions.' . $this->name);
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
            self::FormsRead, self::FormsWrite, self::SubmissionsRead, self::SubmissionsDelete => 'forms',
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
     * Whether old tokens still load with this scope although it grants
     * nothing: a v3 admin `:write` that v4 replaced, or one of the v3
     * `content:*` umbrellas, which no tool ever asked for. Nobody is offered
     * a retired scope and no client may ask for one.
     */
    public function isRetired(): bool
    {
        return $this->replacedBy() !== null || $this === self::ContentRead || $this === self::ContentWrite;
    }

    /**
     * What v4 changed for a token holding these scopes, keyed by change:
     * `full_access` (a v3 `*`, refused), `replaced` (v3 admin `:write`
     * scopes, refused), `inert` (the `content:*` umbrellas, which grant
     * nothing and are dropped) and `publish_delete` (an edit scope that no
     * longer publishes or deletes). Empty when the token is unaffected.
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
            $known = self::tryFrom($scope);
            if ($known?->replacedBy() !== null) {
                $changes['replaced'][] = $scope;
            } elseif ($known?->isRetired() === true) {
                $changes['inert'][] = $scope;
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
