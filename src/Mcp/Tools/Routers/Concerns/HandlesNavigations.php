<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Illuminate\Support\Collection;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Structures\BranchIds;

/**
 * Navigation operations for the StructuresRouter.
 */
trait HandlesNavigations
{
    /**
     * Keys a navigation write accepts in `data`. Anything else is refused:
     * these writes used to drop unknown keys and report success, which left
     * a client sure it had written a tree that was never saved (#65).
     *
     * @var list<string>
     */
    private static array $navigationDataKeys = ['handle', 'title', 'max_depth', 'collections', 'tree', 'trees', 'site'];

    /**
     * Keys a navigation item may carry, as Statamic stores them.
     *
     * @var list<string>
     */
    private static array $navigationBranchKeys = ['id', 'entry', 'title', 'url', 'data', 'children'];

    /**
     * Handle navigation operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleNavigationAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listNavigations($arguments),
            'get' => $this->getNavigation($arguments),
            'create' => $this->createNavigation($arguments),
            'update' => $this->updateNavigation($arguments),
            'delete' => $this->deleteNavigation($arguments),
            'configure' => $this->configureNavigation($arguments),
            default => $this->createErrorResponse("Unknown navigation action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listNavigations(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $navigations = Nav::all()->map(function ($navigation) use ($includeDetails) {
                /** @var NavContract $navigation */
                $data = [
                    'handle' => $navigation->handle(),
                    'title' => $navigation->title(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'collections' => $navigation->collections()->map->handle()->all(),
                        'max_depth' => $navigation->maxDepth(),
                        'sites' => $navigation->sites()->all(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'navigations' => $navigations,
                'total' => count($navigations),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list navigations: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getNavigation(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $navigation = Nav::find($handle);

            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $navigation->handle(),
                'title' => $navigation->title(),
                'collections' => $navigation->collections()->map->handle()->all(),
                'max_depth' => $navigation->maxDepth(),
                'sites' => $navigation->sites()->all(),
                'trees' => $this->navigationTreesPayload($navigation),
            ];

            return ['navigation' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createNavigation(array $arguments): array
    {
        if (! $this->hasPermission('create', 'navigation')) {
            return $this->createErrorResponse('Permission denied: Cannot create navigation')->toArray();
        }

        try {
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = is_string($data['handle'] ?? null) ? $data['handle'] : (is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null);

            if (! $handle) {
                return $this->createErrorResponse('Navigation handle is required')->toArray();
            }

            $existsError = $this->checkHandleNotExists(Nav::find($handle), 'Navigation', $handle);
            if ($existsError !== null) {
                return $existsError;
            }

            $navigation = Nav::make($handle);

            $error = $this->applyNavigationData($navigation, $data);
            if ($error !== null) {
                return $this->createErrorResponse($error)->toArray();
            }

            return [
                'navigation' => [
                    ...$this->navigationSummary($navigation),
                    'created' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateNavigation(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'navigation')) {
            return $this->createErrorResponse('Permission denied: Cannot update navigation')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            $navigation = Nav::find($handle);
            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            $error = $this->applyNavigationData($navigation, $data);
            if ($error !== null) {
                return $this->createErrorResponse($error)->toArray();
            }

            return [
                'navigation' => [
                    ...$this->navigationSummary($navigation),
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteNavigation(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'navigation')) {
            return $this->createErrorResponse('Permission denied: Cannot delete navigation')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $navigation = Nav::find($handle);

            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            $navigation->delete();

            // Clear caches
            $this->clearCachesAfterWrite(['stache', 'static']);

            return [
                'navigation' => [
                    'handle' => $handle,
                    'deleted' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * The same write as update. `configure` historically read a `config`
     * argument the tool schema never declared, so a schema-following client
     * could not reach it at all; `data` is read now, `config` still honoured.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureNavigation(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $data = is_array($arguments['config'] ?? null)
                ? $arguments['config']
                : (is_array($arguments['data'] ?? null) ? $arguments['data'] : []);

            $navigation = Nav::find($handle);
            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            $error = $this->applyNavigationData($navigation, $data);
            if ($error !== null) {
                return $this->createErrorResponse($error)->toArray();
            }

            return [
                'navigation' => $this->navigationSummary($navigation),
                'configured' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Validate the whole payload, then write it: settings first, trees after.
     * Nothing is saved when any part is refused.
     *
     * @param  array<mixed>  $data
     *
     * @return string|null the refusal, or null once written
     */
    private function applyNavigationData(NavContract $navigation, array $data): ?string
    {
        $unknown = array_diff(array_map('strval', array_keys($data)), self::$navigationDataKeys);
        if ($unknown !== []) {
            return 'Unknown navigation keys: ' . implode(', ', $unknown)
                . '. Allowed: ' . implode(', ', self::$navigationDataKeys) . '.';
        }

        if (isset($data['handle']) && $data['handle'] !== $navigation->handle()) {
            return 'A navigation cannot be renamed: data.handle must match the navigation handle.';
        }

        $maxDepth = $navigation->maxDepth();
        if (array_key_exists('max_depth', $data)) {
            if ($data['max_depth'] !== null && (! is_int($data['max_depth']) || $data['max_depth'] < 1)) {
                return 'max_depth must be a positive integer, or null for no limit.';
            }
            $maxDepth = $data['max_depth'];
        }

        if (isset($data['collections']) && (! is_array($data['collections']) || array_filter($data['collections'], fn (mixed $c): bool => ! is_string($c)) !== [])) {
            return 'collections must be a list of collection handles.';
        }

        $trees = $this->navigationTreesFromData($data);
        if (is_string($trees)) {
            return $trees;
        }

        /** @var Collection<string, \Statamic\Sites\Site> $sites */
        $sites = Site::all();

        foreach ($trees as $site => $items) {
            if (! $sites->has($site)) {
                return "Unknown site '{$site}'. Sites: " . $sites->keys()->implode(', ') . '.';
            }

            $ids = [];
            $error = $this->validateNavigationBranches($items, "trees.{$site}", 1, is_int($maxDepth) ? $maxDepth : null, $ids);
            if ($error !== null) {
                return $error;
            }
        }

        $encoded = json_encode($trees);
        if ($encoded === false || strlen($encoded) > 1048576) {
            return 'Navigation tree data exceeds maximum size (1MB).';
        }

        if (isset($data['title'])) {
            $navigation->title($data['title']);
        }

        if (array_key_exists('max_depth', $data)) {
            $navigation->maxDepth($data['max_depth']);
        }

        if (isset($data['collections'])) {
            $navigation->collections($data['collections']);
        }

        $navigation->save();

        foreach ($trees as $site => $items) {
            $tree = $navigation->in($site) ?? $navigation->makeTree($site);
            // Items without an id get one, the way the Control Panel's save does.
            $tree->tree((new BranchIds)->ensure($items));
            $tree->save();
        }

        $this->clearCachesAfterWrite(['stache', 'static']);

        return null;
    }

    /**
     * The trees a write carries, keyed by site.
     *
     * Accepts the shape `get` returns — `trees: [{site, items}]` — so a read
     * can be edited and sent back as is; a map of site to items (or to
     * `{items}`); or a single `tree` with an optional `site`.
     *
     * @param  array<mixed>  $data
     *
     * @return array<string, array<int, mixed>>|string the trees, or the refusal
     */
    private function navigationTreesFromData(array $data): array|string
    {
        $hasTree = array_key_exists('tree', $data);
        $hasTrees = array_key_exists('trees', $data);

        if ($hasTree && $hasTrees) {
            return "Send either 'tree' (with an optional 'site') or 'trees', not both.";
        }

        if (array_key_exists('site', $data) && ! $hasTree) {
            return "'site' only applies to 'tree'. With 'trees', key each tree by its site.";
        }

        if ($hasTree) {
            if (! is_array($data['tree']) || ! array_is_list($data['tree'])) {
                return "'tree' must be a list of navigation items.";
            }

            /** @var \Statamic\Sites\Site $defaultSite */
            $defaultSite = Site::default();
            $site = is_string($data['site'] ?? null) ? $data['site'] : $defaultSite->handle();

            return [$site => $data['tree']];
        }

        if (! $hasTrees) {
            return [];
        }

        if (! is_array($data['trees'])) {
            return "'trees' must be a list of {site, items}, or an object of site => items.";
        }

        $trees = [];

        foreach ($data['trees'] as $key => $value) {
            if (is_int($key)) {
                if (! is_array($value) || ! is_string($value['site'] ?? null)) {
                    return "trees[{$key}] must be {site, items}, as statamic-structures get returns it.";
                }
                $site = $value['site'];
                $items = $value['items'] ?? null;
            } else {
                $site = $key;
                $items = is_array($value) && array_key_exists('items', $value) ? $value['items'] : $value;
            }

            if (! is_array($items) || ! array_is_list($items)) {
                return "trees.{$site} must be a list of navigation items.";
            }

            $trees[$site] = $items;
        }

        return $trees;
    }

    /**
     * @param  array<int, mixed>  $branches
     * @param  array<array-key, true>  $ids  ids seen so far, to refuse duplicates
     */
    private function validateNavigationBranches(array $branches, string $path, int $depth, ?int $maxDepth, array &$ids): ?string
    {
        foreach ($branches as $index => $branch) {
            $at = "{$path}[{$index}]";

            if (! is_array($branch) || ($branch !== [] && array_is_list($branch))) {
                return "{$at} must be an object.";
            }

            $unknown = array_diff(array_map('strval', array_keys($branch)), self::$navigationBranchKeys);
            if ($unknown !== []) {
                return "{$at} has unknown keys: " . implode(', ', $unknown)
                    . '. Allowed: ' . implode(', ', self::$navigationBranchKeys) . '.';
            }

            foreach (['id', 'entry', 'title', 'url'] as $key) {
                if (array_key_exists($key, $branch) && ! is_string($branch[$key])) {
                    return "{$at}.{$key} must be a string.";
                }
            }

            if (($branch['entry'] ?? '') === '' && ($branch['url'] ?? '') === '' && ($branch['title'] ?? '') === '') {
                return "{$at} needs an entry, a url or a title.";
            }

            if (isset($branch['entry']) && Entry::find($branch['entry']) === null) {
                return "{$at}.entry: no entry with id '{$branch['entry']}'.";
            }

            if (isset($branch['data']) && ! is_array($branch['data'])) {
                return "{$at}.data must be an object.";
            }

            if (isset($branch['id'])) {
                if (isset($ids[$branch['id']])) {
                    return "{$at}.id '{$branch['id']}' is used more than once.";
                }
                $ids[$branch['id']] = true;
            }

            if ($maxDepth !== null && $depth > $maxDepth) {
                return "{$at} is {$depth} levels deep; this navigation allows {$maxDepth}.";
            }

            if (array_key_exists('children', $branch)) {
                if (! is_array($branch['children']) || ! array_is_list($branch['children'])) {
                    return "{$at}.children must be a list of navigation items.";
                }

                $error = $this->validateNavigationBranches($branch['children'], "{$at}.children", $depth + 1, $maxDepth, $ids);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationSummary(NavContract $navigation): array
    {
        return [
            'handle' => $navigation->handle(),
            'title' => $navigation->title(),
            'max_depth' => $navigation->maxDepth(),
            'collections' => $navigation->collections()->map->handle()->all(),
            'trees' => $this->navigationTreesPayload($navigation),
        ];
    }

    /**
     * Every tree, in the shape a write accepts back.
     *
     * @return list<array{site: string, items: mixed}>
     */
    private function navigationTreesPayload(NavContract $navigation): array
    {
        $trees = [];

        foreach ($navigation->trees() as $tree) {
            $trees[] = ['site' => (string) $tree->locale(), 'items' => $tree->tree()];
        }

        return $trees;
    }
}
