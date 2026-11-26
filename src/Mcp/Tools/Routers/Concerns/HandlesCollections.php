<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Contracts\CollectionTreeEditor;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Collection operations for the StructuresRouter.
 */
trait HandlesCollections
{
    /**
     * Handle collection operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleCollectionAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listCollections($arguments),
            'get' => $this->getCollection($arguments),
            'create' => $this->createCollection($arguments),
            'update' => $this->updateCollection($arguments),
            'move' => $this->moveCollectionEntry($arguments),
            'delete' => $this->deleteCollection($arguments),
            default => $this->createErrorResponse("Unknown collection action: {$action}")->toArray(),
        };
    }

    private function canConfigureCollections(): bool
    {
        $user = auth()->user();

        return $this->isCliContext() || ($user instanceof User && ($user->isSuper() || $user->hasPermission('configure collections')));
    }

    private function canViewCollection(CollectionContract $collection): bool
    {
        $user = auth()->user();

        return $this->isCliContext() || ($user instanceof User && Gate::forUser($user)->allows('view', $collection));
    }

    /** @return iterable<string> */
    private function readableCollectionHandles(): iterable
    {
        /** @var \Illuminate\Support\Collection<array-key, string> $handles */
        $handles = Collection::handles();
        foreach ($handles as $handle) {
            $collection = Collection::find($handle);
            if ($collection !== null && $this->canViewCollection($collection)) {
                yield $handle;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function collectionTreeDetails(CollectionContract $collection, array $arguments): array
    {
        return ($arguments['include_tree'] ?? false)
            ? app(CollectionTreeEditor::class)->read($collection, $this->resolveSiteHandle($arguments))
            : [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function moveCollectionEntry(array $arguments): array
    {
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        if (array_diff(array_keys($data), ['entry', 'parent']) !== [] || ! is_string($data['entry'] ?? null) || ! array_key_exists('parent', $data)) {
            return $this->createErrorResponse('A collection move accepts only data.entry and data.parent (id or null). Do not combine it with collection settings.')->toArray();
        }
        $entry = Entry::find($data['entry']);
        if (! $entry || $entry->collectionHandle() !== $arguments['handle'] || $entry->locale() !== $this->resolveSiteHandle($arguments)) {
            return $this->createErrorResponse('Entry must exist in the requested collection and site.')->toArray();
        }
        $parent = $this->persistStatamic(fn () => app(CollectionTreeEditor::class)->move($entry, $data['parent']));

        return ['collection' => ['handle' => $entry->collectionHandle()], 'entry' => $entry->id(), 'parent' => $parent, 'updated' => true];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listCollections(array $arguments): array
    {
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $pagination = $this->getPaginationArgs($arguments);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];
        $policy = app(ResourcePolicy::class);
        $allCollections = Collection::all()->filter(
            fn ($collection): bool => $collection instanceof CollectionContract
                && $policy->canAccess('structures', $collection->handle(), 'read')
                && $this->canViewCollection($collection)
        );
        $total = $allCollections->count();
        $collections = $allCollections->skip($offset)->take($limit)->map(function ($collection) use ($includeDetails) {
            /** @var CollectionContract $collection */
            $data = [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'blueprint' => $collection->entryBlueprints()->first()?->handle(),
            ];

            if ($includeDetails && $this->canConfigureCollections()) {
                $data = array_merge($data, [
                    'route' => $collection->route($collection->sites()->first() ?? 'default'),
                    'dated' => $collection->dated(),
                    'orderable' => $collection->orderable(),
                    'taxonomies' => $collection->taxonomies()->map->handle()->all(),
                    'sites' => $collection->sites()->all(),
                    'revisions' => $collection->revisionsEnabled(),
                    'default_status' => $collection->defaultPublishState(),
                ]);
            }

            return app(ResourcePolicy::class)->filterFields('structures', $data);
        })->values()->all();

        return [
            'collections' => $collections,
            'total' => $total,
            'pagination' => $this->buildPaginationMeta($total, $limit, $offset),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getCollection(array $arguments): array
    {
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $collection = Collection::find($handle);
        if (! $collection) {
            return $this->missingResource('Collection', $handle, candidates: $this->readableCollectionHandles(), arguments: $arguments)->toArray();
        }
        // Entry viewers need editing metadata, not the collection's
        // administrative configuration or cross-site entry statistics.
        $user = auth()->user();
        $canConfigure = $this->isCliContext()
            || ($user instanceof User && ($user->isSuper() || $user->hasPermission('configure collections')));
        if (! $canConfigure) {
            if (! $user instanceof User || ! Gate::forUser($user)->allows('view', $collection)) {
                return $this->createErrorResponse('Permission denied: your account may not view this collection (site restriction)', code: ErrorCode::Forbidden)->toArray();
            }

            $viewerData = [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'blueprints' => $collection->entryBlueprints()->map->handle()->all(),
                'dated' => $collection->dated(),
                'orderable' => $collection->orderable(),
                'past_date_behavior' => $collection->pastDateBehavior(),
                'future_date_behavior' => $collection->futureDateBehavior(),
                'default_status' => $collection->defaultPublishState(),
            ];

            return ['collection' => app(ResourcePolicy::class)->filterFields('structures', array_merge($viewerData, $this->collectionTreeDetails($collection, $arguments)))];
        }
        $data = [
            'handle' => $collection->handle(),
            'title' => $collection->title(),
            'blueprints' => $collection->entryBlueprints()->map->handle()->all(),
            'mount' => $collection->mount(),
            'route' => $collection->route($collection->sites()->first() ?? 'default'),
            'template' => $collection->template(),
            'layout' => $collection->layout(),
            'sort_field' => $collection->sortField(),
            'sort_direction' => $collection->sortDirection(),
            'dated' => $collection->dated(),
            'past_date_behavior' => $collection->pastDateBehavior(),
            'future_date_behavior' => $collection->futureDateBehavior(),
            'orderable' => $collection->orderable(),
            'taxonomies' => $collection->taxonomies()->map->handle()->all(),
            'sites' => $collection->sites()->all(),
            'revisions' => $collection->revisionsEnabled(),
            'default_status' => $collection->defaultPublishState(),
            'entry_count' => $this->getBooleanArgument($arguments, 'include_counts', true)
                ? $collection->queryEntries()->count()
                : null,
        ];

        return ['collection' => app(ResourcePolicy::class)->filterFields('structures', array_merge($data, $this->collectionTreeDetails($collection, $arguments)))];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createCollection(array $arguments): array
    {
        if (! $this->hasPermission('create', 'collections')) {
            return $this->createErrorResponse('Permission denied: Cannot create collections', code: ErrorCode::Forbidden)->toArray();
        }
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
        if (! $handle) {
            return $this->createErrorResponse('Collection handle is required')->toArray();
        }
        if (array_key_exists('handle', $data) && $data['handle'] !== $handle) {
            return $this->createErrorResponse('The new collection\'s handle is the handle argument; data.handle may only repeat it.')->toArray();
        }
        $existsError = $this->checkHandleNotExists(Collection::find($handle), 'Collection', $handle);
        if ($existsError !== null) {
            return $existsError;
        }
        $collection = Collection::make($handle);
        $data = array_diff_key($data, ['handle' => true]);
        if (($refused = $this->applyCollectionSettings($collection, $data, 'create')) !== null) {
            return $this->createErrorResponse($refused)->toArray();
        }
        $this->persistStatamic(fn () => $collection->save());
        // CollectionSaved does not reach Statamic's static-cache
        // invalidator, so a template or layout change would otherwise leave
        // cached entry pages serving the old output.
        $this->invalidateStaticCache($collection);
        // Clear caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'collection' => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'created' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateCollection(array $arguments): array
    {
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        if (array_intersect(array_keys($data), ['entry', 'parent', 'tree', 'trees']) !== []) {
            return $this->createErrorResponse('Collection update changes settings only. Use action move with data.entry and data.parent to move a page; replacing a collection tree is not supported.')->toArray();
        }
        if (! $this->hasPermission('edit', 'collections')) {
            return $this->createErrorResponse('Permission denied: Cannot update collections', code: ErrorCode::Forbidden)->toArray();
        }
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $collection = Collection::find($handle);
        if (! $collection) {
            return $this->missingResource('Collection', $handle, candidates: $this->readableCollectionHandles(), arguments: $arguments)->toArray();
        }
        // The handle sent back from a get is not a change; another one would be a rename this cannot do.
        if (array_key_exists('handle', $data) && $data['handle'] === $collection->handle()) {
            unset($data['handle']);
        }
        if (($refused = $this->applyCollectionSettings($collection, $data, 'update')) !== null) {
            return $this->createErrorResponse($refused)->toArray();
        }
        $this->persistStatamic(fn () => $collection->save());
        // CollectionSaved does not reach Statamic's static-cache
        // invalidator, so a template or layout change would otherwise leave
        // cached entry pages serving the old output.
        $this->invalidateStaticCache($collection);
        // Clear caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'collection' => [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'updated' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteCollection(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'collections')) {
            return $this->createErrorResponse('Permission denied: Cannot delete collections', code: ErrorCode::Forbidden)->toArray();
        }
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $collection = Collection::find($handle);
        if (! $collection) {
            return $this->missingResource('Collection', $handle, candidates: $this->readableCollectionHandles(), arguments: $arguments)->toArray();
        }
        $entryCount = $collection->queryEntries()->count();
        if ($entryCount > 0) {
            return $this->createErrorResponse(
                "Cannot delete collection '{$handle}' — it contains {$entryCount} entries. "
                . 'Delete its content explicitly first. V4 does not support forced cascading deletion.'
            )->toArray();
        }
        // Resolve blueprints before removing their parent, but preserve them
        // when the native parent deletion is vetoed or throws.
        $blueprints = $collection->entryBlueprints();
        $this->persistStatamic(fn () => $collection->delete());
        foreach ($blueprints as $blueprint) {
            $this->persistStatamic(fn () => $blueprint->delete());
        }
        // Clear caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'collection' => [
                'handle' => $handle,
                'deleted' => true,
            ],
        ];
    }

    /**
     * Apply collection settings through Statamic's setters, or say why not.
     *
     * Unknown keys are refused rather than skipped: `route` used to reach the
     * getter `route($site)`, so a route change reported success and saved
     * nothing, as navigation trees once did (#65).
     *
     * @param  array<mixed>  $settings
     */
    private function applyCollectionSettings(CollectionContract $collection, array $settings, string $action): ?string
    {
        $setters = [
            'title' => fn ($v) => $collection->title($v),
            'route' => fn ($v) => $collection->routes($v),
            'routes' => fn ($v) => $collection->routes($v),
            'template' => fn ($v) => $collection->template($v),
            'layout' => fn ($v) => $collection->layout($v),
            'dated' => fn ($v) => $collection->dated((bool) $v),
            'sort_field' => fn ($v) => $collection->sortField($v),
            'sort_direction' => fn ($v) => $collection->sortDirection($v),
            'sort_dir' => fn ($v) => $collection->sortDirection($v),
            'default_status' => fn ($v) => $collection->defaultStatus($v),
            'past_date_behavior' => fn ($v) => $collection->pastDateBehavior($v),
            'future_date_behavior' => fn ($v) => $collection->futureDateBehavior($v),
            'taxonomies' => fn ($v) => $collection->taxonomies(is_array($v) ? $v : [$v]),
            'mount' => fn ($v) => $collection->mount($v),
            'structure' => fn ($v) => $collection->structureContents(is_array($v) ? $v : null),
            'orderable' => fn ($v) => $collection->structureContents($v ? ['max_depth' => 1] : null),
        ];

        $unknown = array_diff(array_map('strval', array_keys($settings)), array_keys($setters));
        if ($unknown !== []) {
            return 'Unsupported collection settings for ' . $action . ': ' . implode(', ', $unknown)
                . '. Supported: ' . implode(', ', array_keys($setters)) . '. Nothing was saved.';
        }
        foreach ($settings as $key => $value) {
            $setters[(string) $key]($value);
        }

        return null;
    }
}
