<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Statamic\Facades\Collection;

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
            'delete' => $this->deleteCollection($arguments),
            'configure' => $this->configureCollection($arguments),
            default => $this->createErrorResponse("Unknown collection action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listCollections(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $pagination = $this->getPaginationArgs($arguments);
            $limit = $pagination['limit'];
            $offset = $pagination['offset'];

            $allCollections = Collection::all();
            $total = $allCollections->count();

            $collections = $allCollections->skip($offset)->take($limit)->map(function ($collection) use ($includeDetails) {
                /** @var \Statamic\Contracts\Entries\Collection $collection */
                $data = [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'blueprint' => $collection->entryBlueprints()->first()?->handle(),
                ];

                if ($includeDetails) {
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

                return $data;
            })->values()->all();

            return [
                'collections' => $collections,
                'total' => $total,
                'pagination' => $this->buildPaginationMeta($total, $limit, $offset),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list collections: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getCollection(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $collection = Collection::find($handle);

            if (! $collection) {
                return $this->createErrorResponse("Collection not found: {$handle}")->toArray();
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
                'orderable' => $collection->orderable(),
                'taxonomies' => $collection->taxonomies()->map->handle()->all(),
                'sites' => $collection->sites()->all(),
                'revisions' => $collection->revisionsEnabled(),
                'default_status' => $collection->defaultPublishState(),
                'entry_count' => $this->getBooleanArgument($arguments, 'include_counts', true)
                    ? $collection->queryEntries()->count()
                    : null,
            ];

            return ['collection' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get collection: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createCollection(array $arguments): array
    {
        if (! $this->hasPermission('create', 'collections')) {
            return $this->createErrorResponse('Permission denied: Cannot create collections')->toArray();
        }

        try {
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = is_string($data['handle'] ?? null) ? $data['handle'] : (is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null);

            if (! $handle) {
                return $this->createErrorResponse('Collection handle is required')->toArray();
            }

            $existsError = $this->checkHandleNotExists(Collection::find($handle), 'Collection', $handle);
            if ($existsError !== null) {
                return $existsError;
            }

            $collection = Collection::make($handle);

            // Set configuration
            if (isset($data['title'])) {
                $collection->title($data['title']);
            }
            if (isset($data['route'])) {
                $collection->route($data['route']);
            }
            if (isset($data['template'])) {
                $collection->template($data['template']);
            }
            if (isset($data['layout'])) {
                $collection->layout($data['layout']);
            }
            if (isset($data['dated'])) {
                $collection->dated($data['dated']);
            }
            if (isset($data['orderable'])) {
                if ($data['orderable']) {
                    $collection->orderable();
                }
            }
            if (isset($data['sort_field'])) {
                $collection->sortField($data['sort_field']);
            }
            if (isset($data['sort_direction'])) {
                $collection->sortDirection($data['sort_direction']);
            }
            if (isset($data['default_status'])) {
                $collection->defaultStatus($data['default_status']);
            }
            if (isset($data['past_date_behavior'])) {
                $collection->pastDateBehavior($data['past_date_behavior']);
            }
            if (isset($data['future_date_behavior'])) {
                $collection->futureDateBehavior($data['future_date_behavior']);
            }

            $collection->save();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'collection' => [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'created' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create collection: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateCollection(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'collections')) {
            return $this->createErrorResponse('Permission denied: Cannot update collections')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            $collection = Collection::find($handle);
            if (! $collection) {
                return $this->createErrorResponse("Collection not found: {$handle}")->toArray();
            }

            // Update configuration
            foreach ($data as $key => $value) {
                match ($key) {
                    'title' => $collection->title($value),
                    'route' => $collection->route($value),
                    'template' => $collection->template($value),
                    'layout' => $collection->layout($value),
                    'dated' => $collection->dated($value),
                    'orderable' => $value ? $collection->orderable() : null,
                    'sort_field' => $collection->sortField($value),
                    'sort_direction' => $collection->sortDirection($value),
                    'default_status' => $collection->defaultStatus($value),
                    'past_date_behavior' => $collection->pastDateBehavior($value),
                    'future_date_behavior' => $collection->futureDateBehavior($value),
                    default => null, // Ignore unknown fields
                };
            }

            $collection->save();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'collection' => [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update collection: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteCollection(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'collections')) {
            return $this->createErrorResponse('Permission denied: Cannot delete collections')->toArray();
        }

        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $collection = Collection::find($handle);

            if (! $collection) {
                return $this->createErrorResponse("Collection not found: {$handle}")->toArray();
            }

            $force = $this->getBooleanArgument($arguments, 'force', false);
            $entryCount = $collection->queryEntries()->count();

            if ($entryCount > 0 && ! $force) {
                return $this->createErrorResponse(
                    "Cannot delete collection '{$handle}' — it contains {$entryCount} entries. "
                    . 'Use force: true to delete the collection with all its entries and blueprints.'
                )->toArray();
            }

            // Cascade: delete entries first
            if ($entryCount > 0) {
                $collection->queryEntries()->get()->each->delete();
            }

            // Cascade: delete blueprints
            foreach ($collection->entryBlueprints() as $blueprint) {
                $blueprint->delete();
            }

            $collection->delete();

            // Clear caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'collection' => [
                    'handle' => $handle,
                    'deleted' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete collection: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureCollection(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            $config = is_array($arguments['config'] ?? null) ? $arguments['config'] : [];

            $collection = Collection::find($handle);
            if (! $collection) {
                return $this->createErrorResponse("Collection not found: {$handle}")->toArray();
            }

            // Update collection configuration
            $currentConfig = $collection->toArray();
            $updatedConfig = array_merge($currentConfig, $config);

            // Handle specific configuration options
            if (isset($config['title'])) {
                $collection->title($config['title']);
            }

            if (isset($config['sort_dir'])) {
                $collection->sortDir($config['sort_dir']);
            }

            if (isset($config['sort_field'])) {
                $collection->sortField($config['sort_field']);
            }

            if (isset($config['dated'])) {
                $collection->dated($config['dated']);
            }

            if (isset($config['template'])) {
                $collection->template($config['template']);
            }

            if (isset($config['layout'])) {
                $collection->layout($config['layout']);
            }

            if (isset($config['routes'])) {
                $collection->routes($config['routes']);
            }

            if (isset($config['mount'])) {
                $collection->mount($config['mount']);
            }

            if (isset($config['structure'])) {
                $collection->structure($config['structure']);
            }

            if (isset($config['taxonomies'])) {
                $collection->taxonomies($config['taxonomies']);
            }

            if (isset($config['default_status'])) {
                $collection->defaultStatus($config['default_status']);
            }

            // Save the collection
            $collection->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'collection' => [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'config' => $collection->toArray(),
                ],
                'configured' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure collection: {$e->getMessage()}")->toArray();
        }
    }
}
