<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ExecutesWithAudit;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\JsonSchema\JsonSchema;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

class StructuresRouter extends BaseRouter
{
    use ExecutesWithAudit;
    use RouterHelpers;

    protected function getToolName(): string
    {
        return 'statamic-structures';
    }

    protected function getToolDescription(): string
    {
        return 'Manage Statamic structures: collections, taxonomies, navigations, and sites configuration';
    }

    protected function getDomain(): string
    {
        return 'structures';
    }

    protected function getActions(): array
    {
        return [
            'list' => [
                'description' => 'List structures by type with configuration details',
                'purpose' => 'Structure discovery and organization overview',
                'destructive' => false,
                'examples' => [
                    ['action' => 'list', 'type' => 'collection'],
                    ['action' => 'list', 'type' => 'taxonomy'],
                ],
            ],
            'get' => [
                'description' => 'Get specific structure configuration and details',
                'purpose' => 'Structure inspection and analysis',
                'destructive' => false,
                'examples' => [
                    ['action' => 'get', 'type' => 'collection', 'handle' => 'blog'],
                ],
            ],
            'create' => [
                'description' => 'Create new structures with configuration',
                'purpose' => 'Structure creation and setup',
                'destructive' => false,
                'examples' => [
                    ['action' => 'create', 'type' => 'collection', 'handle' => 'products'],
                ],
            ],
            'update' => [
                'description' => 'Update structure configuration and settings',
                'purpose' => 'Structure modification and optimization',
                'destructive' => true,
                'examples' => [
                    ['action' => 'update', 'type' => 'collection', 'handle' => 'blog'],
                ],
            ],
            'delete' => [
                'description' => 'Delete structures with safety checks',
                'purpose' => 'Structure removal and cleanup',
                'destructive' => true,
                'examples' => [
                    ['action' => 'delete', 'type' => 'collection', 'handle' => 'old-collection'],
                ],
            ],
            'configure' => [
                'description' => 'Configure structure-specific settings and options',
                'purpose' => 'Advanced structure configuration',
                'destructive' => true,
                'examples' => [
                    ['action' => 'configure', 'type' => 'site', 'handle' => 'default'],
                ],
            ],
        ];
    }

    protected function getTypes(): array
    {
        return [
            'collection' => [
                'description' => 'Content collections that organize entries',
                'properties' => ['handle', 'title', 'blueprint', 'route', 'sort_direction'],
                'relationships' => ['entries', 'blueprints'],
                'examples' => ['blog', 'pages', 'products'],
            ],
            'taxonomy' => [
                'description' => 'Taxonomies for categorizing and tagging content',
                'properties' => ['handle', 'title', 'blueprint', 'collections'],
                'relationships' => ['terms', 'collections'],
                'examples' => ['categories', 'tags', 'regions'],
            ],
            'navigation' => [
                'description' => 'Navigation trees and menu structures',
                'properties' => ['handle', 'title', 'tree', 'max_depth'],
                'relationships' => ['entries', 'pages'],
                'examples' => ['main_nav', 'footer_nav', 'sidebar'],
            ],
            'site' => [
                'description' => 'Multi-site configuration and localization',
                'properties' => ['handle', 'name', 'url', 'locale', 'direction'],
                'relationships' => ['collections', 'entries'],
                'examples' => ['default', 'fr', 'admin'],
            ],
            'globalset' => [
                'description' => 'Global sets for site-wide settings and configuration',
                'properties' => ['handle', 'title', 'blueprint', 'sites'],
                'relationships' => ['blueprints', 'sites'],
                'examples' => ['site_settings', 'footer_content', 'seo_defaults'],
            ],
        ];
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'type' => JsonSchema::string()
                ->description('Structure type to operate on')
                ->enum(['collection', 'taxonomy', 'navigation', 'site', 'globalset'])
                ->required(),
            'handle' => JsonSchema::string()
                ->description('Structure handle (required for get, update, delete operations)'),
            'data' => JsonSchema::object()
                ->description('Structure configuration data for create/update operations'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed configuration information (default: true)'),
            'filters' => JsonSchema::object()
                ->description('Filtering options for list operations'),
        ]);
    }

    /**
     * Route structure operations to appropriate handlers with security checks and audit logging.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = $arguments['action'];

        // Check if tool is enabled for current context
        if (! $this->isCliContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Structures tool is disabled for web access')->toArray();
        }

        // Apply security checks for web context
        if ($this->isWebContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Execute action with audit logging
        return $this->executeWithAuditLog($action, $arguments);
    }

    /**
     * Perform the actual domain action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function performDomainAction(string $action, array $arguments): array
    {
        // Validate required parameters
        $type = $arguments['type'];

        // Validate action-specific requirements
        if (in_array($action, ['get', 'update', 'delete']) && empty($arguments['handle'])) {
            return $this->createErrorResponse("Handle is required for {$action} action")->toArray();
        }

        // Route to type-specific handlers
        return match ($type) {
            'collection' => $this->handleCollectionAction($action, $arguments),
            'taxonomy' => $this->handleTaxonomyAction($action, $arguments),
            'navigation' => $this->handleNavigationAction($action, $arguments),
            'site' => $this->handleSiteAction($action, $arguments),
            'globalset' => $this->handleGlobalSetAction($action, $arguments),
            default => $this->createErrorResponse("Unknown structure type: {$type}")->toArray(),
        };
    }

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
     * Handle taxonomy operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleTaxonomyAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listTaxonomies($arguments),
            'get' => $this->getTaxonomy($arguments),
            'create' => $this->createTaxonomy($arguments),
            'update' => $this->updateTaxonomy($arguments),
            'delete' => $this->deleteTaxonomy($arguments),
            'configure' => $this->configureTaxonomy($arguments),
            default => $this->createErrorResponse("Unknown taxonomy action: {$action}")->toArray(),
        };
    }

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
     * Handle site operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleSiteAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listSites($arguments),
            'get' => $this->getSite($arguments),
            'create' => $this->createSite($arguments),
            'update' => $this->updateSite($arguments),
            'delete' => $this->deleteSite($arguments),
            'configure' => $this->configureSite($arguments),
            default => $this->createErrorResponse("Unknown site action: {$action}")->toArray(),
        };
    }

    /**
     * Handle globalset operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleGlobalSetAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listGlobalSets($arguments),
            'get' => $this->getGlobalSet($arguments),
            'create' => $this->createGlobalSet($arguments),
            'update' => $this->updateGlobalSet($arguments),
            'delete' => $this->deleteGlobalSet($arguments),
            'configure' => $this->configureGlobalSet($arguments),
            default => $this->createErrorResponse("Unknown globalset action: {$action}")->toArray(),
        };
    }

    // Collection Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listCollections(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $collections = Collection::all()->map(function ($collection) use ($includeDetails) {
                $data = [
                    'handle' => $collection->handle(),
                    'title' => $collection->title(),
                    'blueprint' => $collection->entryBlueprints()->first()?->handle(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'mount' => $collection->mount(),
                        'route' => $collection->route('en'),
                        'sort_field' => $collection->sortField(),
                        'sort_direction' => $collection->sortDirection(),
                        'dated' => $collection->dated(),
                        'orderable' => $collection->orderable(),
                        'taxonomies' => $collection->taxonomies()->all(),
                        'sites' => $collection->sites()->all(),
                        'search_index' => $collection->searchIndex(),
                        'revisions' => $collection->revisionsEnabled(),
                        'default_status' => $collection->defaultPublishState(),
                        'entry_count' => $collection->queryEntries()->count(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'collections' => $collections,
                    'total' => count($collections),
                ],
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
            $handle = $arguments['handle'];
            $collection = Collection::find($handle);

            if (! $collection) {
                return $this->createErrorResponse("Collection not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'blueprint' => $collection->entryBlueprints()->first()?->handle(),
                'mount' => $collection->mount(),
                'route' => $collection->route('en'),
                'template' => $collection->template(),
                'layout' => $collection->layout(),
                'sort_field' => $collection->sortField(),
                'sort_direction' => $collection->sortDirection(),
                'dated' => $collection->dated(),
                'orderable' => $collection->orderable(),
                'taxonomies' => $collection->taxonomies()->all(),
                'sites' => $collection->sites()->all(),
                'search_index' => $collection->searchIndex(),
                'revisions' => $collection->revisionsEnabled(),
                'default_status' => $collection->defaultPublishState(),
                'past_date_behavior' => $collection->pastDateBehavior(),
                'future_date_behavior' => $collection->futureDateBehavior(),
                'entry_count' => $collection->queryEntries()->count(),
                'blueprints' => $collection->entryBlueprints()->map->handle()->all(),
            ];

            return [
                'success' => true,
                'data' => ['collection' => $data],
            ];
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
            $data = $arguments['data'] ?? [];
            $handle = $data['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Collection handle is required']];
            }

            if (Collection::find($handle)) {
                return ['success' => false, 'errors' => ["Collection '{$handle}' already exists"]];
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
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'collection' => [
                        'handle' => $collection->handle(),
                        'title' => $collection->title(),
                        'created' => true,
                    ],
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
            $handle = $arguments['handle'];
            $data = $arguments['data'] ?? [];

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
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'collection' => [
                        'handle' => $collection->handle(),
                        'title' => $collection->title(),
                        'updated' => true,
                    ],
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
            $handle = $arguments['handle'];
            $collection = Collection::find($handle);

            if (! $collection) {
                return $this->createErrorResponse("Collection not found: {$handle}")->toArray();
            }

            // Check for existing entries
            $entryCount = $collection->queryEntries()->count();
            if ($entryCount > 0) {
                return [
                    'success' => false,
                    'errors' => ["Cannot delete collection '{$handle}' - it contains {$entryCount} entries"],
                ];
            }

            $collection->delete();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'collection' => [
                        'handle' => $handle,
                        'deleted' => true,
                    ],
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
            $handle = $arguments['handle'];
            $config = $arguments['config'] ?? [];

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
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'collection' => [
                        'handle' => $collection->handle(),
                        'title' => $collection->title(),
                        'config' => $collection->toArray(),
                    ],
                    'configured' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure collection: {$e->getMessage()}")->toArray();
        }
    }

    // Taxonomy Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listTaxonomies(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $taxonomies = Taxonomy::all()->map(function ($taxonomy) use ($includeDetails) {
                $data = [
                    'handle' => $taxonomy->handle(),
                    'title' => $taxonomy->title(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'blueprint' => $taxonomy->termBlueprints()->first()?->handle(),
                        'sites' => $taxonomy->sites()->all(),
                        'collections' => $taxonomy->collections()->map->handle()->all(),
                        'term_count' => $taxonomy->queryTerms()->count(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'taxonomies' => $taxonomies,
                    'total' => count($taxonomies),
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list taxonomies: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getTaxonomy(array $arguments): array
    {
        try {
            $handle = $arguments['handle'];
            $taxonomy = Taxonomy::find($handle);

            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $taxonomy->handle(),
                'title' => $taxonomy->title(),
                'blueprint' => $taxonomy->termBlueprints()->first()?->handle(),
                'sites' => $taxonomy->sites()->all(),
                'collections' => $taxonomy->collections()->map->handle()->all(),
                'term_count' => $taxonomy->queryTerms()->count(),
                'blueprints' => $taxonomy->termBlueprints()->map->handle()->all(),
            ];

            return [
                'success' => true,
                'data' => ['taxonomy' => $data],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createTaxonomy(array $arguments): array
    {
        if (! $this->hasPermission('create', 'taxonomies')) {
            return $this->createErrorResponse('Permission denied: Cannot create taxonomies')->toArray();
        }

        try {
            $data = $arguments['data'] ?? [];
            $handle = $data['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Taxonomy handle is required']];
            }

            if (Taxonomy::find($handle)) {
                return ['success' => false, 'errors' => ["Taxonomy '{$handle}' already exists"]];
            }

            $taxonomy = Taxonomy::make($handle);

            if (isset($data['title'])) {
                $taxonomy->title($data['title']);
            }

            $taxonomy->save();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'taxonomy' => [
                        'handle' => $taxonomy->handle(),
                        'title' => $taxonomy->title(),
                        'created' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateTaxonomy(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'taxonomies')) {
            return $this->createErrorResponse('Permission denied: Cannot update taxonomies')->toArray();
        }

        try {
            $handle = $arguments['handle'];
            $data = $arguments['data'] ?? [];

            $taxonomy = Taxonomy::find($handle);
            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy not found: {$handle}")->toArray();
            }

            if (isset($data['title'])) {
                $taxonomy->title($data['title']);
            }

            $taxonomy->save();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'taxonomy' => [
                        'handle' => $taxonomy->handle(),
                        'title' => $taxonomy->title(),
                        'updated' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteTaxonomy(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'taxonomies')) {
            return $this->createErrorResponse('Permission denied: Cannot delete taxonomies')->toArray();
        }

        try {
            $handle = $arguments['handle'];
            $taxonomy = Taxonomy::find($handle);

            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy not found: {$handle}")->toArray();
            }

            // Check for existing terms
            $termCount = $taxonomy->queryTerms()->count();
            if ($termCount > 0) {
                return [
                    'success' => false,
                    'errors' => ["Cannot delete taxonomy '{$handle}' - it contains {$termCount} terms"],
                ];
            }

            $taxonomy->delete();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'taxonomy' => [
                        'handle' => $handle,
                        'deleted' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureTaxonomy(array $arguments): array
    {
        try {
            $handle = $arguments['handle'];
            $config = $arguments['config'] ?? [];

            $taxonomy = Taxonomy::find($handle);
            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy not found: {$handle}")->toArray();
            }

            // Handle specific configuration options
            if (isset($config['title'])) {
                $taxonomy->title($config['title']);
            }

            if (isset($config['preview_targets'])) {
                $taxonomy->previewTargets($config['preview_targets']);
            }

            if (isset($config['default_status'])) {
                $taxonomy->defaultStatus($config['default_status']);
            }

            if (isset($config['collections'])) {
                $taxonomy->collections($config['collections']);
            }

            // Save the taxonomy
            $taxonomy->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'taxonomy' => [
                        'handle' => $taxonomy->handle(),
                        'title' => $taxonomy->title(),
                        'config' => $taxonomy->toArray(),
                    ],
                    'configured' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure taxonomy: {$e->getMessage()}")->toArray();
        }
    }

    // Navigation Operations

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
                $data = [
                    'handle' => $navigation->handle(),
                    'title' => $navigation->title(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'collections' => $navigation->collections()->all(),
                        'max_depth' => $navigation->maxDepth(),
                        'sites' => $navigation->sites()->all(),
                        'tree_count' => count($navigation->trees()),
                    ]);
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'navigations' => $navigations,
                    'total' => count($navigations),
                ],
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
            $handle = $arguments['handle'];
            $navigation = Nav::find($handle);

            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $navigation->handle(),
                'title' => $navigation->title(),
                'collections' => $navigation->collections()->all(),
                'max_depth' => $navigation->maxDepth(),
                'sites' => $navigation->sites()->all(),
                'trees' => $navigation->trees()->map(function ($tree) {
                    return [
                        'site' => $tree->locale(),
                        'items' => $tree->tree(),
                    ];
                })->all(),
            ];

            return [
                'success' => true,
                'data' => ['navigation' => $data],
            ];
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
            $data = $arguments['data'] ?? [];
            $handle = $data['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Navigation handle is required']];
            }

            if (Nav::find($handle)) {
                return ['success' => false, 'errors' => ["Navigation '{$handle}' already exists"]];
            }

            $navigation = Nav::make($handle);

            if (isset($data['title'])) {
                $navigation->title($data['title']);
            }

            if (isset($data['max_depth'])) {
                $navigation->maxDepth($data['max_depth']);
            }

            $navigation->save();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'navigation' => [
                        'handle' => $navigation->handle(),
                        'title' => $navigation->title(),
                        'created' => true,
                    ],
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
            $handle = $arguments['handle'];
            $data = $arguments['data'] ?? [];

            $navigation = Nav::find($handle);
            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            if (isset($data['title'])) {
                $navigation->title($data['title']);
            }

            if (isset($data['max_depth'])) {
                $navigation->maxDepth($data['max_depth']);
            }

            $navigation->save();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'navigation' => [
                        'handle' => $navigation->handle(),
                        'title' => $navigation->title(),
                        'updated' => true,
                    ],
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
            $handle = $arguments['handle'];
            $navigation = Nav::find($handle);

            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            $navigation->delete();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'navigation' => [
                        'handle' => $handle,
                        'deleted' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete navigation: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureNavigation(array $arguments): array
    {
        try {
            $handle = $arguments['handle'];
            $config = $arguments['config'] ?? [];

            $navigation = Nav::find($handle);
            if (! $navigation) {
                return $this->createErrorResponse("Navigation not found: {$handle}")->toArray();
            }

            // Determine site to use (with fallback to default)
            $site = $config['site'] ?? Site::default()->handle();

            // Handle specific configuration options
            if (isset($config['title'])) {
                $navigation->title($config['title']);
            }

            if (isset($config['max_depth'])) {
                $navigation->maxDepth($config['max_depth']);
            }

            if (isset($config['collections'])) {
                $navigation->collections($config['collections']);
            }

            if (isset($config['tree'])) {
                // Set the navigation tree structure
                $tree = $navigation->in($site);
                if (! $tree) {
                    $tree = $navigation->makeTree($site);
                }
                $tree->tree($config['tree']);
                $tree->save();
            }

            // Save the navigation
            $navigation->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'navigation' => [
                        'handle' => $navigation->handle(),
                        'title' => $navigation->title(),
                        'config' => $navigation->toArray(),
                        'tree' => $navigation->in($site)?->tree() ?? [],
                    ],
                    'configured' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure navigation: {$e->getMessage()}")->toArray();
        }
    }

    // Site Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listSites(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $sites = Site::all()->map(function ($site) use ($includeDetails) {
                $data = [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'locale' => $site->locale(),
                    'short_locale' => $site->shortLocale(),
                    'url' => $site->url(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'direction' => $site->direction(),
                        'lang' => $site->lang(),
                        'attributes' => $site->attributes(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'sites' => $sites,
                    'total' => count($sites),
                    'default' => Site::default()->handle(),
                    'selected' => Site::selected()?->handle(),
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list sites: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getSite(array $arguments): array
    {
        try {
            $handle = $arguments['handle'];
            $site = Site::get($handle);

            if (! $site) {
                return $this->createErrorResponse("Site not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $site->handle(),
                'name' => $site->name(),
                'locale' => $site->locale(),
                'short_locale' => $site->shortLocale(),
                'url' => $site->url(),
                'direction' => $site->direction(),
                'lang' => $site->lang(),
                'attributes' => $site->attributes(),
                'is_default' => $site === Site::default(),
                'is_selected' => $site === Site::selected(),
            ];

            return [
                'success' => true,
                'data' => ['site' => $data],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get site: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createSite(array $arguments): array
    {
        // Site creation requires configuration file changes - not supported via API
        return $this->createErrorResponse('Site creation requires configuration file modification')->toArray();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateSite(array $arguments): array
    {
        // Site updates require configuration file changes - not supported via API
        return $this->createErrorResponse('Site updates require configuration file modification')->toArray();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteSite(array $arguments): array
    {
        // Site deletion requires configuration file changes - not supported via API
        return $this->createErrorResponse('Site deletion requires configuration file modification')->toArray();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureSite(array $arguments): array
    {
        // Site configuration requires configuration file changes - not supported via API
        return $this->createErrorResponse('Site configuration requires configuration file modification')->toArray();
    }

    // GlobalSet Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listGlobalSets(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $globalSets = GlobalSet::all()->map(function ($globalSet) use ($includeDetails) {
                $data = [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'blueprint' => $globalSet->blueprint()?->handle(),
                        'sites' => $globalSet->sites()->all(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'globalsets' => $globalSets,
                    'total' => count($globalSets),
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list global sets: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getGlobalSet(array $arguments): array
    {
        try {
            $handle = $arguments['handle'];
            $globalSet = GlobalSet::find($handle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'blueprint' => $globalSet->blueprint()?->handle(),
                'sites' => $globalSet->sites()->all(),
            ];

            return [
                'success' => true,
                'data' => ['globalset' => $data],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get global set: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createGlobalSet(array $arguments): array
    {
        if (! $this->hasPermission('create', 'globals')) {
            return $this->createErrorResponse('Permission denied: Cannot create global sets')->toArray();
        }

        try {
            $data = $arguments['data'] ?? [];
            $handle = $data['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Global set handle is required']];
            }

            if (GlobalSet::find($handle)) {
                return ['success' => false, 'errors' => ["Global set '{$handle}' already exists"]];
            }

            $globalSet = GlobalSet::make($handle);

            if (isset($data['title'])) {
                $globalSet->title($data['title']);
            }

            $globalSet->save();

            // Initialize global variables for default site
            $variables = $globalSet->in(Site::default()->handle());
            if (! $variables) {
                $variables = $globalSet->makeLocalization(Site::default()->handle());
                $variables->save();
            }

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'globalset' => [
                        'handle' => $globalSet->handle(),
                        'title' => $globalSet->title(),
                        'created' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create global set: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateGlobalSet(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'globals')) {
            return $this->createErrorResponse('Permission denied: Cannot update global sets')->toArray();
        }

        try {
            $handle = $arguments['handle'];
            $data = $arguments['data'] ?? [];

            $globalSet = GlobalSet::find($handle);
            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$handle}")->toArray();
            }

            if (isset($data['title'])) {
                $globalSet->title($data['title']);
            }

            $globalSet->save();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'globalset' => [
                        'handle' => $globalSet->handle(),
                        'title' => $globalSet->title(),
                        'updated' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update global set: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteGlobalSet(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'globals')) {
            return $this->createErrorResponse('Permission denied: Cannot delete global sets')->toArray();
        }

        try {
            $handle = $arguments['handle'];
            $globalSet = GlobalSet::find($handle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$handle}")->toArray();
            }

            $globalSet->delete();

            // Clear caches
            $this->clearCaches(['stache', 'static']);

            return [
                'success' => true,
                'data' => [
                    'globalset' => [
                        'handle' => $handle,
                        'deleted' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete global set: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function configureGlobalSet(array $arguments): array
    {
        try {
            $handle = $arguments['handle'];
            $config = $arguments['config'] ?? [];

            $globalSet = GlobalSet::find($handle);
            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$handle}")->toArray();
            }

            // Handle specific configuration options
            if (isset($config['title'])) {
                $globalSet->title($config['title']);
            }

            // Save the global set
            $globalSet->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'globalset' => [
                        'handle' => $globalSet->handle(),
                        'title' => $globalSet->title(),
                        'config' => $globalSet->toArray(),
                    ],
                    'configured' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to configure global set: {$e->getMessage()}")->toArray();
        }
    }

    // Helper Methods - Now provided by RouterHelpers trait

    // BaseRouter Abstract Method Implementations

    /**
     * @return array<string, mixed>
     */
    protected function getFeatures(): array
    {
        return [
            'structure_management' => 'Complete management of collections, taxonomies, navigations, and sites',
            'configuration_control' => 'Advanced structure configuration and customization',
            'multi_site_support' => 'Full multi-site and localization management',
            'relationship_handling' => 'Structure relationships and dependencies',
            'organization_tools' => 'Structure organization and optimization features',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'Organize and configure the foundational structures of Statamic websites';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDecisionTree(): array
    {
        return [
            'structure_selection' => 'Choose type based on purpose: collections for content, taxonomies for categorization, navigations for menus, sites for localization',
            'operation_flow' => 'List existing structures → Get configuration → Create/Update with validation → Test integration',
            'configuration_strategy' => 'Plan structure relationships → Configure settings → Validate dependencies → Deploy changes',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getContextAwareness(): array
    {
        return [
            'content_architecture' => 'Structures define the foundation for all content organization',
            'site_hierarchy' => 'Understanding multi-site relationships and inheritance',
            'blueprint_integration' => 'Structures work closely with blueprints for schema definition',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getWorkflowIntegration(): array
    {
        return [
            'content_architecture' => 'Structures provide the foundation for content organization',
            'blueprint_workflow' => 'Structure configuration enables blueprint assignment and validation',
            'navigation_workflow' => 'Navigation structures enable menu and routing configuration',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCommonPatterns(): array
    {
        return [
            'structure_discovery' => [
                'description' => 'Explore existing structure configuration',
                'pattern' => 'list structures by type → get specific structure → analyze configuration',
                'example' => ['action' => 'list', 'type' => 'collection'],
            ],
            'structure_setup' => [
                'description' => 'Create and configure new structures',
                'pattern' => 'create structure → configure settings → validate relationships → test functionality',
                'example' => ['action' => 'create', 'type' => 'collection', 'handle' => 'products'],
            ],
            'multi_site_management' => [
                'description' => 'Manage multi-site configuration and localization',
                'pattern' => 'configure sites → set up localization → manage site-specific content → validate cross-site relationships',
                'example' => ['action' => 'configure', 'type' => 'site', 'handle' => 'french'],
            ],
        ];
    }

    /**
     * Get required permissions for action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        $type = $arguments['type'] ?? '';

        if ($type === 'collection') {
            return match ($action) {
                'list', 'get' => ['view collections'],
                'create' => ['create collections'],
                'update', 'configure' => ['edit collections'],
                'delete' => ['delete collections'],
                default => ['super'],
            };
        }

        if ($type === 'taxonomy') {
            return match ($action) {
                'list', 'get' => ['view taxonomies'],
                'create' => ['create taxonomies'],
                'update', 'configure' => ['edit taxonomies'],
                'delete' => ['delete taxonomies'],
                default => ['super'],
            };
        }

        if ($type === 'navigation') {
            return match ($action) {
                'list', 'get' => ['view navigation'],
                'create' => ['create navigation'],
                'update', 'configure' => ['edit navigation'],
                'delete' => ['delete navigation'],
                default => ['super'],
            };
        }

        if ($type === 'site') {
            return match ($action) {
                'list', 'get' => ['view sites'],
                'create', 'update', 'delete', 'configure' => ['configure sites'],
                default => ['super'],
            };
        }

        if ($type === 'globalset') {
            return match ($action) {
                'list', 'get' => ['view globals'],
                'create' => ['create globals'],
                'update', 'configure' => ['edit globals'],
                'delete' => ['delete globals'],
                default => ['super'],
            };
        }

        return ['super'];
    }
}
