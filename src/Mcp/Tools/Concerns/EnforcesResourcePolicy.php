<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;

/**
 * Enforces resource-level access control and field filtering on router actions.
 *
 * Resource policy is a site-wide admin config — it applies in ALL contexts
 * (CLI and web), unlike token scopes which are web-only.
 */
trait EnforcesResourcePolicy
{
    /**
     * Check if the current action is allowed on the target resource.
     *
     * Returns an error response array if denied, or null if allowed.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    protected function checkResourceAccess(string $action, array $arguments): ?array
    {
        $handle = $this->resolveResourceHandle($arguments);

        // No handle to check (e.g., list without collection filter) — allow
        if ($handle === null) {
            return null;
        }

        $mode = $this->isWriteAction($action) ? 'write' : 'read';

        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        if (! $policy->canAccess($this->getDomain(), $handle, $mode)) {
            return $this->createErrorResponse(
                ucfirst($mode) . " access to '{$handle}' is not permitted by resource policy"
            )->toArray();
        }

        return null;
    }

    /**
     * Filter denied fields from input arguments.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function filterInputFields(array $arguments): array
    {
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        $domain = $this->getDomain();

        if ($policy->getDeniedFields($domain) === []) {
            return $arguments;
        }

        // Filter 'data' key if present (entries, terms, globals)
        if (isset($arguments['data']) && is_array($arguments['data'])) {
            /** @var array<string, mixed> $data */
            $data = $arguments['data'];
            $arguments['data'] = $policy->filterFields($domain, $data);
        }

        // Filter 'fields' key if present (blueprints)
        if (isset($arguments['fields']) && is_array($arguments['fields'])) {
            /** @var array<string, mixed> $fields */
            $fields = $arguments['fields'];
            $arguments['fields'] = $policy->filterFields($domain, $fields);
        }

        return $arguments;
    }

    /**
     * Filter denied fields from output data.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    protected function filterOutputFields(array $result): array
    {
        /** @var ResourcePolicy $policy */
        $policy = app(ResourcePolicy::class);

        $domain = $this->getDomain();

        if ($policy->getDeniedFields($domain) === []) {
            return $result;
        }

        // Filter the 'data' key in the result
        if (isset($result['data']) && is_array($result['data'])) {
            /** @var array<string, mixed> $resultData */
            $resultData = $result['data'];
            $result['data'] = $policy->filterFields($domain, $resultData);
        }

        return $result;
    }

    /**
     * Extract the resource handle from arguments for policy evaluation.
     *
     * Returns null for actions that don't target a specific resource
     * (e.g., list without a filter). When null, resource-level check is skipped.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveResourceHandle(array $arguments): ?string
    {
        // Try common argument names in priority order
        foreach (['collection', 'taxonomy', 'container', 'handle', 'navigation'] as $key) {
            if (isset($arguments[$key]) && is_string($arguments[$key]) && $arguments[$key] !== '') {
                return $arguments[$key];
            }
        }

        return null;
    }

    /**
     * Check if an action is a write action.
     */
    private function isWriteAction(string $action): bool
    {
        return in_array($action, [
            'create', 'update', 'delete', 'publish', 'unpublish',
            'activate', 'deactivate', 'assign_role', 'remove_role',
            'move', 'copy', 'upload', 'configure',
            'cache_clear', 'cache_warm', 'config_set',
        ], true);
    }
}
