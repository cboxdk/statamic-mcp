<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Authorization\McpCall;
use Cboxdk\StatamicMcp\Mcp\ExtraTools;
use Cboxdk\StatamicMcp\Support\McpContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;

/**
 * Router helper methods for common functionality across all routers.
 */
trait RouterHelpers
{
    /**
     * Check if CLI context (bypasses permissions).
     */
    protected function isCliContext(): bool
    {
        return McpContext::isCli();
    }

    /**
     * Check if web tool is enabled for the current router.
     */
    protected function isWebToolEnabled(): bool
    {
        $domain = $this->getDomain();

        return config("statamic.mcp.tools.{$domain}.enabled", true) && config('statamic.mcp.web.enabled', false);
    }

    /**
     * Check permissions for the given action and resource.
     */
    protected function hasPermission(string $action, string $resource): bool
    {
        // In CLI context, bypass all permissions
        if ($this->isCliContext()) {
            return true;
        }

        // Check if user is authenticated
        if (! auth()->check()) {
            return false;
        }

        // Check Statamic permissions based on resource type
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Super admins bypass all permission checks
        if ($user->isSuper()) {
            return true;
        }

        return match ($resource) {
            'system' => $user->hasPermission('access utilities'),
            'blueprints', 'structures', 'collections' => $user->hasPermission('configure collections') || $user->hasPermission('configure taxonomies'),
            'content' => $user->hasPermission("{$action} entries") || $user->hasPermission("{$action} terms"),
            'user_groups' => $user->hasPermission("{$action} user groups"),
            'taxonomies' => $user->hasPermission('configure taxonomies'),
            'navigations' => $user->hasPermission('configure navigations'),
            default => $user->hasPermission("{$action} {$resource}"),
        };
    }

    /**
     * Determine if we're in web context.
     */
    protected function isWebContext(): bool
    {
        return ! $this->isCliContext();
    }

    /**
     * This call as McpAuthorizer needs to know it.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function describeCall(string $action, array $arguments): McpCall
    {
        $scope = $this->getRequiredTokenScope($action);
        $scopes = $scope === null ? [] : [$scope];

        // Publishing and deleting need their own scope on top of write.
        $extra = TokenScope::extraFor($this->getDomain(), $action);
        if ($scope !== null && $extra !== null) {
            $scopes[] = $extra;
        }

        return new McpCall(
            domain: $this->getDomain(),
            action: $action,
            scopes: $scopes,
            permissions: $this->getRequiredPermissions($action, $arguments),
            handle: $this->resolveResourceHandle($arguments),
            mode: $this->isWriteAction($action) ? 'write' : 'read',
            extraTool: ExtraTools::isRegistered(static::class),
        );
    }

    /**
     * Get required token scope for action. Maps actions to TokenScope enum.
     * Override in routers for domain-specific scope mapping.
     */
    protected function getRequiredTokenScope(string $action): ?TokenScope
    {
        $domain = $this->getDomain();
        // One classification for scope and resource policy: EnforcesResourcePolicy::isWriteAction().
        $isWrite = $this->isWriteAction($action);

        return $isWrite ? TokenScope::writeFor($domain) : TokenScope::tryFrom("{$domain}:read");
    }

    /**
     * Get required Statamic permissions for action - can be overridden by each router.
     *
     * Every top-level entry must be held. An entry that is itself a list is
     * satisfied by any one of the permissions in it.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string|array<int, string>>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        return [];
    }

    /**
     * Check if a resource handle already exists and return error if so.
     *
     * @return array<string, mixed>|null Error response if exists, null if available
     */
    protected function checkHandleNotExists(mixed $existing, string $resourceType, string $handle): ?array
    {
        if ($existing !== null) {
            return $this->createErrorResponse("{$resourceType} '{$handle}' already exists")->toArray();
        }

        return null;
    }

    /**
     * Validate that a site handle exists.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null Error response if invalid, null if valid
     */
    protected function validateSiteHandle(array $arguments): ?array
    {
        if (empty($arguments['site'])) {
            return null;
        }

        if (! is_string($arguments['site'])) {
            return $this->createErrorResponse('Site handle must be a string')->toArray();
        }

        /** @var Collection<int|string, \Statamic\Sites\Site> $allSites */
        $allSites = Site::all();
        if (! $allSites->map->handle()->contains($arguments['site'])) {
            return $this->createErrorResponse("Invalid site handle: {$arguments['site']}")->toArray();
        }

        return null;
    }

    /**
     * Resolve site handle from arguments, falling back to default site.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveSiteHandle(array $arguments): string
    {
        $site = $arguments['site'] ?? null;

        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();

        return is_string($site) ? $site : $defaultSite->handle();
    }

    /**
     * Check a list action's filters before they reach the query.
     *
     * The Stache builds an index for every key it is queried by, records the
     * key in the store's `_indexes` and keeps that index up to date on every
     * later save. An unknown key is therefore not a harmless empty result but
     * a permanent cost on the site, so only field handles of the given
     * blueprints and the named properties are accepted, each with a scalar
     * value. Denied fields were already removed from the filters by
     * `filterInputFields()` and are not offered here.
     *
     * @param  array<mixed>  $filters
     * @param  iterable<Blueprint>  $blueprints
     * @param  list<string>  $properties
     *
     * @return array<string, mixed>|null Error response if invalid, null if valid
     */
    protected function validateListFilters(array $filters, iterable $blueprints, array $properties): ?array
    {
        if ($filters === []) {
            return null;
        }

        $handles = $properties;
        foreach ($blueprints as $blueprint) {
            foreach ($blueprint->fields()->all()->keys() as $handle) {
                $handles[] = (string) $handle;
            }
        }

        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);
        $allowed = array_values(array_diff(array_unique($handles), $policy->getDeniedFields($this->getDomain())));
        sort($allowed);

        $unknown = array_values(array_filter(
            array_keys($filters),
            static fn (int|string $key): bool => ! in_array($key, $allowed, true),
        ));

        if ($unknown !== []) {
            return $this->createErrorResponse(
                'Unknown filter key(s): ' . implode(', ', array_map(strval(...), $unknown))
                . '. Filter by one of: ' . implode(', ', $allowed) . '.'
            )->toArray();
        }

        foreach ($filters as $key => $value) {
            if ($value !== null && ! is_scalar($value)) {
                return $this->createErrorResponse(
                    "Filter '{$key}' must be a string, number, boolean or null; it matches the stored value exactly."
                )->toArray();
            }
        }

        return null;
    }

    /**
     * Format a ValidationException into a standardized error response.
     *
     * @return array<string, mixed>
     */
    protected function formatValidationError(ValidationException $e): array
    {
        $errors = [];
        foreach ($e->errors() as $field => $fieldErrors) {
            $errors[] = "{$field}: " . implode(', ', $fieldErrors);
        }

        return $this->createErrorResponse('Field validation failed: ' . implode('; ', $errors))->toArray();
    }

    /**
     * Get domain name - must be implemented by concrete router classes.
     */
    abstract protected function getDomain(): string;

    abstract protected function isWriteAction(string $action): bool;
}
