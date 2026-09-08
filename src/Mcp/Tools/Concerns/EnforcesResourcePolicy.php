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

        // Filter the 'data' key in the result (single-item responses)
        if (isset($result['data']) && is_array($result['data'])) {
            /** @var array<string, mixed> $resultData */
            $resultData = $result['data'];
            $result['data'] = $policy->filterFields($domain, $resultData);
        }

        // Filter nested 'data' within domain-specific wrapper keys (e.g. entry.data, term.data)
        foreach (['entry', 'term', 'global', 'asset', 'user'] as $wrapper) {
            if (! isset($result[$wrapper]) || ! is_array($result[$wrapper])) {
                continue;
            }

            /** @var array<string, mixed> $wrappedItem */
            $wrappedItem = $result[$wrapper];

            if (isset($wrappedItem['data']) && is_array($wrappedItem['data'])) {
                /** @var array<string, mixed> $wrappedData */
                $wrappedData = $wrappedItem['data'];
                $wrappedItem['data'] = $policy->filterFields($domain, $wrappedData);
                $result[$wrapper] = $wrappedItem;
            }
        }

        // Filter list responses where items live under domain-specific keys
        foreach (['entries', 'terms', 'globals', 'assets', 'users'] as $listKey) {
            if (! isset($result[$listKey]) || ! is_array($result[$listKey])) {
                continue;
            }

            /** @var array<int, mixed> $items */
            $items = $result[$listKey];
            $result[$listKey] = array_map(function (mixed $item) use ($policy, $domain): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                if (isset($item['data']) && is_array($item['data'])) {
                    /** @var array<string, mixed> $itemData */
                    $itemData = $item['data'];
                    $item['data'] = $policy->filterFields($domain, $itemData);
                }

                return $item;
            }, $items);
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
            'create', 'update', 'delete', 'publish', 'unpublish', 'localize',
            'activate', 'deactivate', 'assign_role', 'remove_role',
            'move', 'copy', 'upload', 'configure',
            'cache_clear', 'cache_warm', 'config_set',
            'restore_revision', 'publish_working_copy',
        ], true);
    }
}
