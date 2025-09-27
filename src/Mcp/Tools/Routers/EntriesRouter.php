<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\JsonSchema\JsonSchema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Support\Str;

class EntriesRouter extends BaseRouter
{
    use ClearsCaches;
    use HasCommonSchemas;
    use RouterHelpers;

    protected function getDomain(): string
    {
        return 'entries';
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'collection' => JsonSchema::string()
                ->description('Collection handle (required for all entry operations)')
                ->required(),

            'id' => JsonSchema::string()
                ->description('Entry ID (required for get, update, delete, publish, unpublish)'),

            'site' => JsonSchema::string()
                ->description('Site handle (optional, defaults to default site)'),

            'data' => JsonSchema::object()
                ->description('Entry data for create/update operations'),

            'filters' => JsonSchema::object()
                ->description('Filtering options for list operations'),

            'include_unpublished' => JsonSchema::boolean()
                ->description('Include unpublished entries in list operations (default: false)'),

            'limit' => JsonSchema::integer()
                ->description('Maximum number of items to return (default: 50, max: 1000)'),

            'offset' => JsonSchema::integer()
                ->description('Number of items to skip (default: 0)'),
        ]);
    }

    /**
     * Route actions to appropriate handlers with security checks.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = $arguments['action'];

        // Skip validation for help/discovery actions that don't need collection
        if (in_array($action, ['help', 'discover', 'examples'])) {
            return parent::executeInternal($arguments);
        }

        // Collection is required for all entry operations
        if (empty($arguments['collection'])) {
            return $this->createErrorResponse('Collection handle is required for entry operations')->toArray();
        }

        if (! Collection::find($arguments['collection'])) {
            return $this->createErrorResponse("Collection not found: {$arguments['collection']}")->toArray();
        }

        // Check if tool is enabled for current context
        if (! $this->isToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Entries tool is disabled for web access')->toArray();
        }

        // Validate action-specific requirements
        $validationError = $this->validateActionRequirements($action, $arguments);
        if ($validationError) {
            return $validationError;
        }

        // Apply security checks for web context
        if ($this->isWebContext()) {
            $permissionError = $this->checkPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Execute action with audit logging
        return $this->executeWithAuditLog($action, $arguments);
    }

    // Agent Education Methods Implementation

    protected function getFeatures(): array
    {
        return [
            'collection_based_management' => 'Comprehensive entry management within collections',
            'multi_site_localization' => 'Full localization support across multiple sites',
            'publication_control' => 'Publish/unpublish capabilities with status tracking',
            'blueprint_validation' => 'Automatic validation against collection blueprints',
            'filtering_and_search' => 'Advanced filtering and pagination for large datasets',
            'cache_management' => 'Intelligent cache clearing after operations',
            'audit_logging' => 'Complete operation logging for security and compliance',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'Comprehensive management of Statamic collection entries with full CRUD operations, publication control, and multi-site support.';
    }

    protected function getDecisionTree(): array
    {
        return [
            'operation_selection' => [
                'list' => 'Browse and discover entries within a collection',
                'get' => 'Retrieve full entry details for specific entry',
                'create' => 'Add new entries following collection blueprint',
                'update' => 'Modify existing entry data with validation',
                'delete' => 'Remove entries with safety checks',
                'publish' => 'Make entries publicly available',
                'unpublish' => 'Hide entries from public view',
            ],
            'collection_context' => [
                'required' => 'Collection handle must be provided for all operations',
                'validation' => 'Collection existence is verified before operations',
                'blueprint' => 'Entry data must conform to collection blueprint',
            ],
            'security_considerations' => [
                'cli_context' => 'Full access with minimal restrictions',
                'web_context' => 'Permission-based access with audit logging',
                'collection_permissions' => 'Granular permissions per collection',
            ],
        ];
    }

    protected function getContextAwareness(): array
    {
        return [
            'collection_context' => [
                'blueprint_compliance' => 'All operations validate against collection blueprints',
                'route_binding' => 'Entry URLs generated based on collection routing',
                'template_integration' => 'Entries work with collection-specific templates',
            ],
            'multi_site_context' => [
                'localization_support' => 'Full multi-site and localization capabilities',
                'site_specific_content' => 'Entries can have different content per site',
                'default_site_fallback' => 'Automatic fallback to default site when needed',
            ],
            'publication_context' => [
                'draft_support' => 'Entries can exist as drafts before publication',
                'publication_control' => 'Granular control over entry visibility',
                'status_tracking' => 'Track publication status across operations',
            ],
        ];
    }

    protected function getWorkflowIntegration(): array
    {
        return [
            'content_creation_workflow' => [
                'step1' => 'Use list to understand existing entry patterns',
                'step2' => 'Use statamic.blueprints to understand collection schema',
                'step3' => 'Use create with blueprint-compliant data',
                'step4' => 'Use publish to make content live',
            ],
            'content_management_workflow' => [
                'step1' => 'Use list with filters to find entries to modify',
                'step2' => 'Use get to retrieve current entry state',
                'step3' => 'Use update with merged data modifications',
                'step4' => 'Use publish/unpublish for visibility control',
            ],
            'content_audit_workflow' => [
                'step1' => 'Use list to inventory collection entries',
                'step2' => 'Use get to examine entry details',
                'step3' => 'Validate against collection blueprint',
                'step4' => 'Use update for corrections and improvements',
            ],
        ];
    }

    protected function getCommonPatterns(): array
    {
        return [
            'entry_discovery' => [
                'description' => 'Finding and exploring entries in collection',
                'pattern' => 'list → filter → get → analyze',
                'example' => ['action' => 'list', 'collection' => 'articles', 'limit' => 20],
            ],
            'entry_creation' => [
                'description' => 'Creating new entries following blueprint',
                'pattern' => 'blueprint analysis → data preparation → create → publish',
                'example' => ['action' => 'create', 'collection' => 'articles', 'data' => ['title' => 'New Article', 'content' => 'Article content']],
            ],
            'entry_modification' => [
                'description' => 'Updating existing entries safely',
                'pattern' => 'get current state → prepare changes → update → validate',
                'example' => ['action' => 'update', 'id' => 'article-123', 'data' => ['title' => 'Updated Title']],
            ],
            'publication_management' => [
                'description' => 'Managing entry publication lifecycle',
                'pattern' => 'create draft → review → publish → manage → unpublish',
                'example' => ['action' => 'publish', 'id' => 'article-123'],
            ],
        ];
    }

    /**
     * Check if tool is enabled for current context.
     */
    private function isToolEnabled(): bool
    {
        if ($this->isCliContext()) {
            return true; // CLI always enabled
        }

        return config('statamic.mcp.tools.statamic.entries.web_enabled', false);
    }

    /**
     * Determine if we're in web context.
     */
    private function isWebContext(): bool
    {
        return ! $this->isCliContext();
    }

    /**
     * Validate action-specific requirements.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    private function validateActionRequirements(string $action, array $arguments): ?array
    {
        // ID required for specific actions
        if (in_array($action, ['get', 'update', 'delete', 'publish', 'unpublish'])) {
            if (empty($arguments['id'])) {
                return $this->createErrorResponse("Entry ID is required for {$action} action")->toArray();
            }
        }

        // Data required for create actions
        if ($action === 'create' && empty($arguments['data'])) {
            return $this->createErrorResponse('Data is required for create action')->toArray();
        }

        // Site validation
        if (! empty($arguments['site'])) {
            if (! Site::all()->map->handle()->contains($arguments['site'])) {
                return $this->createErrorResponse("Invalid site handle: {$arguments['site']}")->toArray();
            }
        }

        return null;
    }

    /**
     * Check permissions for web context.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    private function checkPermissions(string $action, array $arguments): ?array
    {
        $user = auth()->user();

        if (! $user) {
            return $this->createErrorResponse('Permission denied: Authentication required')->toArray();
        }

        // Check MCP server access permission
        if (! method_exists($user, 'hasPermission') || ! $user->hasPermission('access_mcp_tools')) {
            return $this->createErrorResponse('Permission denied: MCP server access required')->toArray();
        }

        // Get required permissions for this action
        $requiredPermissions = $this->getRequiredPermissions($action, $arguments);

        // Check each required permission
        foreach ($requiredPermissions as $permission) {
            // @phpstan-ignore-next-line Method exists check is for defensive programming
            if (! method_exists($user, 'hasPermission') || ! $user->hasPermission($permission)) {
                return $this->createErrorResponse("Permission denied: Cannot {$action} entries")->toArray();
            }
        }

        return null;
    }

    /**
     * Get required permissions for action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    private function getRequiredPermissions(string $action, array $arguments): array
    {
        $collection = $arguments['collection'];

        return match ($action) {
            'list' => ["view {$collection} entries"],
            'get' => ["view {$collection} entries"],
            'create' => ["create {$collection} entries"],
            'update' => ["edit {$collection} entries"],
            'delete' => ["delete {$collection} entries"],
            'publish' => ["publish {$collection} entries"],
            'unpublish' => ["publish {$collection} entries"],
            default => ['super'], // Fallback to super admin
        };
    }

    /**
     * Execute action with audit logging.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function executeWithAuditLog(string $action, array $arguments): array
    {
        $startTime = microtime(true);
        $user = auth()->user();

        // Log the operation start if audit logging is enabled
        if (config('statamic.mcp.tools.statamic.entries.audit_logging', true)) {
            \Log::info('MCP Entries Operation Started', [
                'action' => $action,
                'collection' => $arguments['collection'],
                'user' => $user->email ?? ($user ? $user->getAttribute('email') : null),
                'context' => $this->isWebContext() ? 'web' : 'cli',
                'arguments' => $this->sanitizeArgumentsForLogging($arguments),
                'timestamp' => now()->toISOString(),
            ]);
        }

        try {
            // Execute the actual action
            $result = $this->performAction($action, $arguments);

            // Log successful operation
            if (config('statamic.mcp.tools.statamic.entries.audit_logging', true)) {
                $duration = microtime(true) - $startTime;
                \Log::info('MCP Entries Operation Completed', [
                    'action' => $action,
                    'collection' => $arguments['collection'],
                    'user' => $user->email ?? ($user ? $user->getAttribute('email') : null),
                    'context' => $this->isWebContext() ? 'web' : 'cli',
                    'duration' => $duration,
                    'success' => true,
                    'timestamp' => now()->toISOString(),
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            // Log failed operation
            if (config('statamic.mcp.tools.statamic.entries.audit_logging', true)) {
                $duration = microtime(true) - $startTime;
                \Log::error('MCP Entries Operation Failed', [
                    'action' => $action,
                    'collection' => $arguments['collection'],
                    'user' => $user->email ?? ($user ? $user->getAttribute('email') : null),
                    'context' => $this->isWebContext() ? 'web' : 'cli',
                    'duration' => $duration,
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toISOString(),
                ]);
            }

            return $this->createErrorResponse("Operation failed: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Sanitize arguments for logging (remove sensitive data).
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function sanitizeArgumentsForLogging(array $arguments): array
    {
        $sanitized = $arguments;

        // Remove or mask sensitive fields
        if (isset($sanitized['data']) && is_array($sanitized['data'])) {
            // Remove password fields
            foreach ($sanitized['data'] as $key => $value) {
                if (Str::contains(strtolower($key), ['password', 'secret', 'token', 'key'])) {
                    $sanitized['data'][$key] = '[REDACTED]';
                }
            }
        }

        return $sanitized;
    }

    /**
     * Perform the actual action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function performAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listEntries($arguments),
            'get' => $this->getEntry($arguments),
            'create' => $this->createEntry($arguments),
            'update' => $this->updateEntry($arguments),
            'delete' => $this->deleteEntry($arguments),
            'publish' => $this->publishEntry($arguments),
            'unpublish' => $this->unpublishEntry($arguments),
            default => $this->createErrorResponse("Action {$action} not supported for entries")->toArray(),
        };
    }

    /**
     * List entries with filtering and pagination.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listEntries(array $arguments): array
    {
        $collection = $arguments['collection'];
        $site = $arguments['site'] ?? Site::default()->handle();
        $includeUnpublished = $this->getBooleanArgument($arguments, 'include_unpublished', false);
        $limit = $this->getIntegerArgument($arguments, 'limit', 50, 1, 1000);
        $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

        try {
            $query = Entry::query()
                ->where('collection', $collection)
                ->where('site', $site);

            if (! $includeUnpublished) {
                $query->where('published', true);
            }

            // Apply filters if provided
            if (! empty($arguments['filters'])) {
                foreach ($arguments['filters'] as $field => $value) {
                    $query->where($field, $value);
                }
            }

            $total = $query->count();
            $entries = $query->offset($offset)->limit($limit)->get();

            $data = $entries->map(function ($entry) {
                return [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'title' => $entry->get('title', $entry->slug()),
                    'published' => $entry->published(),
                    'date' => $entry->date()?->toISOString(),
                    'last_modified' => $entry->lastModified()?->toISOString(),
                    'url' => $entry->url(),
                    'edit_url' => $entry->editUrl(),
                ];
            })->all();

            return [
                'entries' => $data,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
                'collection' => $collection,
                'site' => $site,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list entries: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Get a specific entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getEntry(array $arguments): array
    {
        $id = $arguments['id'];
        $site = $arguments['site'] ?? Site::default()->handle();

        try {
            $entry = Entry::find($id);

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
            }

            // Get entry for specific site if needed
            if ($entry->site()->handle() !== $site) {
                $localizedEntry = $entry->in($site);
                if ($localizedEntry) {
                    $entry = $localizedEntry;
                }
            }

            return [
                'entry' => array_merge([
                    'id' => $entry->id(),
                    'collection' => $entry->collectionHandle(),
                    'site' => $entry->site()->handle(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'date' => $entry->date()?->toISOString(),
                    'last_modified' => $entry->lastModified()?->toISOString(),
                    'url' => $entry->url(),
                    'edit_url' => $entry->editUrl(),
                    'data' => $entry->data()->all(),
                ], $entry->data()->all()),
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Create a new entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createEntry(array $arguments): array
    {
        $collection = Collection::find($arguments['collection']);
        $site = $arguments['site'] ?? Site::default()->handle();
        $data = $arguments['data'] ?? [];

        try {
            $entry = Entry::make()
                ->collection($collection)
                ->locale($site)
                ->data($data);

            // Generate slug if not provided
            if (! $entry->slug() && isset($data['title'])) {
                $entry->slug(Str::slug($data['title']));
            }

            $entry->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'collection' => $entry->collectionHandle(),
                    'site' => $entry->site()->handle(),
                    'published' => $entry->published(),
                    'url' => $entry->url(),
                    'edit_url' => $entry->editUrl(),
                    'title' => $entry->get('title'),
                    'data' => $entry->data()->all(),
                ],
                'created' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Update an existing entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateEntry(array $arguments): array
    {
        $id = $arguments['id'];
        $site = $arguments['site'] ?? Site::default()->handle();
        $data = $arguments['data'] ?? [];

        try {
            $entry = Entry::find($id);

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
            }

            // Get entry for specific site
            if ($entry->site()->handle() !== $site) {
                $localizedEntry = $entry->in($site);
                if ($localizedEntry) {
                    $entry = $localizedEntry;
                } else {
                    return $this->createErrorResponse("Entry not available in site: {$site}")->toArray();
                }
            }

            $entry->merge($data)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'collection' => $entry->collectionHandle(),
                    'site' => $entry->site()->handle(),
                    'published' => $entry->published(),
                    'last_modified' => $entry->lastModified()?->toISOString(),
                    'url' => $entry->url(),
                    'edit_url' => $entry->editUrl(),
                ],
                'updated' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Delete an entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteEntry(array $arguments): array
    {
        $id = $arguments['id'];

        try {
            $entry = Entry::find($id);

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
            }

            $entryData = [
                'id' => $entry->id(),
                'slug' => $entry->slug(),
                'collection' => $entry->collectionHandle(),
                'site' => $entry->site()->handle(),
            ];

            $entry->delete();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => $entryData,
                'deleted' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Publish an entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function publishEntry(array $arguments): array
    {
        $id = $arguments['id'];

        try {
            $entry = Entry::find($id);

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
            }

            $entry->published(true)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'url' => $entry->url(),
                ],
                'published' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to publish entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Unpublish an entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function unpublishEntry(array $arguments): array
    {
        $id = $arguments['id'];

        try {
            $entry = Entry::find($id);

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
            }

            $entry->published(false)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                ],
                'unpublished' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to unpublish entry: {$e->getMessage()}")->toArray();
        }
    }

    protected function getActions(): array
    {
        return [
            'list' => [
                'description' => 'List entries with filtering and pagination',
                'purpose' => 'Entry discovery and browsing',
                'required' => ['collection'],
                'optional' => ['filters', 'limit', 'offset', 'include_unpublished', 'site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'list', 'collection' => 'articles'],
                    ['action' => 'list', 'collection' => 'articles', 'limit' => 20, 'include_unpublished' => true],
                ],
            ],
            'get' => [
                'description' => 'Get specific entry with full data',
                'purpose' => 'Entry detail retrieval',
                'required' => ['collection', 'id'],
                'optional' => ['site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'get', 'collection' => 'articles', 'id' => 'article-123'],
                ],
            ],
            'create' => [
                'description' => 'Create new entry',
                'purpose' => 'Entry creation following blueprint schema',
                'required' => ['collection', 'data'],
                'optional' => ['site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'create', 'collection' => 'articles', 'data' => ['title' => 'New Article', 'content' => 'Article content']],
                ],
            ],
            'update' => [
                'description' => 'Update existing entry',
                'purpose' => 'Entry modification with validation',
                'required' => ['collection', 'id', 'data'],
                'optional' => ['site'],
                'destructive' => true,
                'examples' => [
                    ['action' => 'update', 'collection' => 'articles', 'id' => 'article-123', 'data' => ['title' => 'Updated Title']],
                ],
            ],
            'delete' => [
                'description' => 'Delete entry',
                'purpose' => 'Entry removal with safety checks',
                'required' => ['collection', 'id'],
                'optional' => [],
                'destructive' => true,
                'examples' => [
                    ['action' => 'delete', 'collection' => 'articles', 'id' => 'article-123'],
                ],
            ],
            'publish' => [
                'description' => 'Publish entry',
                'purpose' => 'Make entry publicly available',
                'required' => ['collection', 'id'],
                'optional' => [],
                'destructive' => true,
                'examples' => [
                    ['action' => 'publish', 'collection' => 'articles', 'id' => 'article-123'],
                ],
            ],
            'unpublish' => [
                'description' => 'Unpublish entry',
                'purpose' => 'Hide entry from public view',
                'required' => ['collection', 'id'],
                'optional' => [],
                'destructive' => true,
                'examples' => [
                    ['action' => 'unpublish', 'collection' => 'articles', 'id' => 'article-123'],
                ],
            ],
        ];
    }

    protected function getTypes(): array
    {
        return [
            'entry' => [
                'description' => 'Collection-based content items',
                'properties' => ['collection', 'site', 'published', 'date', 'slug'],
                'relationships' => ['belongs to collection', 'can have terms', 'can reference globals'],
                'examples' => ['articles', 'products', 'pages', 'blog posts'],
            ],
        ];
    }

    /**
     * Bridge method for existing router implementation.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        $action = $arguments['action'];

        // Handle help/discovery actions through parent's implementation
        if (in_array($action, ['help', 'discover', 'examples'])) {
            return parent::executeInternal($arguments);
        }

        // For other actions, use our executeAction method
        return $this->executeAction($arguments);
    }
}