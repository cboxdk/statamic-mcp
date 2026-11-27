<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Structures\BranchIds;
use Statamic\Structures\Tree;

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
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $policy = app(ResourcePolicy::class);
        $navigations = Nav::all()->filter(
            fn ($navigation): bool => $navigation instanceof NavContract
                && $policy->canAccess('structures', $navigation->handle(), 'read')
        )->map(function ($navigation) use ($includeDetails) {
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

        return $this->paginateItems($navigations, $arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getNavigation(array $arguments): array
    {
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $navigation = Nav::find($handle);
        if (! $navigation) {
            return $this->missingResource('Navigation', $handle, candidates: Nav::all()->map->handle()->all(), arguments: $arguments)->toArray();
        }

        return ['navigation' => $this->navigationSummary($navigation)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createNavigation(array $arguments): array
    {
        if (! $this->hasPermission('create', 'navigation')) {
            return $this->createErrorResponse('Permission denied: Cannot create navigation', code: ErrorCode::Forbidden)->toArray();
        }
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
        if (! $handle) {
            return $this->createErrorResponse('Navigation handle is required')->toArray();
        }
        if (array_key_exists('handle', $data) && $data['handle'] !== $handle) {
            return $this->createErrorResponse('The new navigation\'s handle is the handle argument; data.handle may only repeat it.')->toArray();
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
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateNavigation(array $arguments): array
    {
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $navigation = Nav::find($handle);
        if (! $navigation) {
            return $this->missingResource('Navigation', $handle, candidates: Nav::all()->map->handle()->all(), arguments: $arguments)->toArray();
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
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteNavigation(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'navigation')) {
            return $this->createErrorResponse('Permission denied: Cannot delete navigation', code: ErrorCode::Forbidden)->toArray();
        }
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $navigation = Nav::find($handle);
        if (! $navigation) {
            return $this->missingResource('Navigation', $handle, candidates: Nav::all()->map->handle()->all(), arguments: $arguments)->toArray();
        }
        $snapshot = $this->navigationSummary($navigation);
        $this->persistStatamic(fn () => $navigation->delete());
        // Clear caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'navigation' => [
                ...$snapshot,
                'deleted' => true,
            ],
        ];
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

            if ($this->isWebContext()) {
                Gate::authorize('edit', $navigation->in($site) ?? $navigation->makeTree($site));
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

        $this->persistStatamic(fn () => $navigation->save());

        foreach ($trees as $site => $items) {
            $existing = $navigation->in($site);
            $tree = $existing ?? $navigation->makeTree($site);
            // Items without an id get one, the way the Control Panel's save does.
            $tree->tree((new BranchIds)->ensure($items));
            // What a read must return: the tree as Statamic normalizes it for storage.
            $items = $tree->fileData()['tree'];
            $this->persistStatamic(fn () => $tree->save());

            // statamic/eloquent-driver stores a tree's first save as [] (a new
            // NavTree has no model yet), so the items go onto the stored tree.
            if ($existing === null && $this->storedNavItems($navigation, $site) !== $items) {
                $stored = $navigation->in($site);
                $stored?->tree($items);
                $this->persistStatamic(fn () => $stored?->save());
            }
            if ($this->storedNavItems($navigation, $site) !== $items) {
                throw new ToolFailure(ErrorCode::OutcomeUnknown, "The navigation tree for site '{$site}' was not stored as sent. Read it before retrying.");
            }
        }

        $this->clearCachesAfterWrite(['stache', 'static']);

        return null;
    }

    /** @return array<mixed>|null the stored items, read past Blink */
    private function storedNavItems(NavContract $navigation, string $site): ?array
    {
        Blink::flush();
        $tree = $navigation->in($site);

        return $tree instanceof Tree ? $tree->fileData()['tree'] : null;
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
                    return "trees[{$key}] must be {site, items}, as statamic-structures-read get returns it.";
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
        $data = [
            'handle' => $navigation->handle(),
            'title' => $navigation->title(),
            'max_depth' => $navigation->maxDepth(),
            'collections' => $navigation->collections()->map->handle()->all(),
            'sites' => $navigation->sites()->all(),
            'trees' => $this->navigationTreesPayload($navigation),
        ];

        return [...$data, 'version' => self::resourceVersion($data)];
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
            if ($this->isWebContext() && ! Gate::allows('view', $tree)) {
                continue;
            }
            $trees[] = ['site' => (string) $tree->locale(), 'items' => $tree->tree()];
        }

        return $trees;
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    protected function filterOutputFields(array $result): array
    {
        // Authorize using the stored identity before denied_fields can hide it.
        // The parent recurses into wrapped results through this method too.
        $record = $result['navigation'] ?? null;
        if ($this->isWebContext() && is_array($record) && is_string($record['handle'] ?? null) && is_array($record['trees'] ?? null)) {
            // Stored receipts must not retain access to trees a user has lost.
            $navigation = Nav::find($record['handle']) ?? Nav::make($record['handle']);
            $record['trees'] = array_values(array_filter($record['trees'], static function (mixed $tree) use ($navigation): bool {
                if (! is_array($tree) || ! is_string($site = $tree['site'] ?? null) || Site::get($site) === null) {
                    return false;
                }

                return Gate::allows('view', $navigation->in($site) ?? $navigation->makeTree($site));
            }));
            $result['navigation'] = $record;
        }

        return parent::filterOutputFields($result);
    }
}
