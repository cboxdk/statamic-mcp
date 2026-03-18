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
    case AssetsRead = 'assets:read';
    case AssetsWrite = 'assets:write';
    case UsersRead = 'users:read';
    case UsersWrite = 'users:write';
    case SystemRead = 'system:read';
    case SystemWrite = 'system:write';
    case BlueprintsRead = 'blueprints:read';
    case BlueprintsWrite = 'blueprints:write';
    case EntriesRead = 'entries:read';
    case EntriesWrite = 'entries:write';
    case TermsRead = 'terms:read';
    case TermsWrite = 'terms:write';
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
            self::StructuresWrite => 'Write Structures',
            self::AssetsRead => 'Read Assets',
            self::AssetsWrite => 'Write Assets',
            self::UsersRead => 'Read Users',
            self::UsersWrite => 'Write Users',
            self::SystemRead => 'Read System',
            self::SystemWrite => 'Write System',
            self::BlueprintsRead => 'Read Blueprints',
            self::BlueprintsWrite => 'Write Blueprints',
            self::EntriesRead => 'Read Entries',
            self::EntriesWrite => 'Write Entries',
            self::TermsRead => 'Read Terms',
            self::TermsWrite => 'Write Terms',
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
            self::FullAccess => 'Grants unrestricted access to all MCP tools and actions.',
            self::ContentRead => 'View entries and taxonomy terms.',
            self::ContentWrite => 'Create, update, and delete entries and terms.',
            self::StructuresRead => 'View collections, taxonomies, navigations, and sites.',
            self::StructuresWrite => 'Create, update, and delete collections, taxonomies, and navigations.',
            self::AssetsRead => 'View asset containers and files.',
            self::AssetsWrite => 'Upload, move, and delete assets.',
            self::UsersRead => 'View users, roles, and groups.',
            self::UsersWrite => 'Create, update, and delete users and assign roles.',
            self::SystemRead => 'View system info, health checks, and configuration.',
            self::SystemWrite => 'Manage caches and system settings.',
            self::BlueprintsRead => 'View blueprints and fieldsets.',
            self::BlueprintsWrite => 'Create, update, and delete blueprints.',
            self::EntriesRead => 'View entries across collections.',
            self::EntriesWrite => 'Create, update, delete, and publish entries.',
            self::TermsRead => 'View taxonomy terms.',
            self::TermsWrite => 'Create, update, and delete taxonomy terms.',
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
            self::StructuresRead, self::StructuresWrite => 'structures',
            self::AssetsRead, self::AssetsWrite => 'assets',
            self::UsersRead, self::UsersWrite => 'users',
            self::SystemRead, self::SystemWrite => 'system',
            self::BlueprintsRead, self::BlueprintsWrite => 'blueprints',
            self::EntriesRead, self::EntriesWrite => 'entries',
            self::TermsRead, self::TermsWrite => 'terms',
            self::GlobalsRead, self::GlobalsWrite => 'globals',
            self::ContentFacadeRead, self::ContentFacadeWrite => 'content-facade',
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
