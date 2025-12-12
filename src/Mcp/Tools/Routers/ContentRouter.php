<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Validator as FieldsValidator;
use Statamic\Rules\UniqueEntryValue;
use Statamic\Rules\UniqueTermValue;
use Statamic\Support\Str;

class ContentRouter extends BaseRouter
{
    use ClearsCaches;
    use HasCommonSchemas;
    use RouterHelpers;

    protected function getDomain(): string
    {
        return 'content';
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'type' => JsonSchema::string()
                ->description('Content type')
                ->enum(['entry', 'term', 'global'])
                ->required(),

            'collection' => JsonSchema::string()
                ->description('Collection handle (required for entries)'),

            'taxonomy' => JsonSchema::string()
                ->description('Taxonomy handle (required for terms)'),

            'global_set' => JsonSchema::string()
                ->description('Global set handle (required for globals)'),

            'id' => JsonSchema::string()
                ->description('Content ID (required for get, update, delete, publish, unpublish)'),

            'site' => JsonSchema::string()
                ->description('Site handle (optional, defaults to default site)'),

            'data' => JsonSchema::object()
                ->description('Content data for create/update operations'),

            'filters' => JsonSchema::object()
                ->description('Filtering options for list operations'),

            'include_unpublished' => JsonSchema::boolean()
                ->description('Include unpublished content in list operations (default: false)'),

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

        // Skip validation for help/discovery actions that don't need type
        if (in_array($action, ['help', 'discover', 'examples'])) {
            return parent::executeInternal($arguments);
        }

        $type = $arguments['type'] ?? null;
        if (! $type) {
            return $this->createErrorResponse('Type is required for this action')->toArray();
        }

        // Check if tool is enabled for current context
        if (! $this->isToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Content tool is disabled for web access')->toArray();
        }

        // Validate action-specific requirements
        $validationError = $this->validateActionRequirements($action, $type, $arguments);
        if ($validationError) {
            return $validationError;
        }

        // Apply security checks for web context
        if ($this->isWebContext()) {
            $permissionError = $this->checkPermissions($action, $type, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Execute action with audit logging
        return $this->executeWithAuditLog($action, $type, $arguments);
    }

    // Agent Education Methods Implementation

    protected function getFeatures(): array
    {
        return [
            'unified_content_management' => 'Single interface for entries, terms, and globals',
            'security_first_permissions' => 'Comprehensive permission checking for web context',
            'audit_logging' => 'Complete operation logging for security and compliance',
            'multi_site_support' => 'Full localization and multi-site content management',
            'blueprint_validation' => 'Automatic validation against Statamic blueprints',
            'cache_management' => 'Intelligent cache clearing after operations',
            'safety_protocols' => 'Dry run and confirmation support for destructive operations',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'Unified content management for all Statamic content types (entries, terms, globals) with enterprise-grade security and audit capabilities.';
    }

    protected function getDecisionTree(): array
    {
        return [
            'content_type_selection' => [
                'entry' => 'When working with collection-based content (articles, products, pages)',
                'term' => 'When working with taxonomy classifications (categories, tags)',
                'global' => 'When working with site-wide settings and configuration',
            ],
            'action_selection' => [
                'list' => 'Discover and browse existing content',
                'get' => 'Retrieve full content details for specific item',
                'create' => 'Add new content following blueprint schema',
                'update' => 'Modify existing content with validation',
                'delete' => 'Remove content with relationship checking',
                'publish' => 'Make content publicly available',
                'unpublish' => 'Hide content from public view',
            ],
            'security_considerations' => [
                'cli_context' => 'Full access with minimal restrictions',
                'web_context' => 'Permission-based access with audit logging',
                'user_permissions' => 'Granular permissions per collection/taxonomy',
            ],
        ];
    }

    protected function getContextAwareness(): array
    {
        return [
            'execution_context' => [
                'cli' => 'Direct access for development and maintenance tasks',
                'web' => 'Permission-controlled access with full audit trail',
            ],
            'content_context' => [
                'blueprint_compliance' => 'All operations validate against Statamic blueprints',
                'relationship_awareness' => 'Understands content relationships and dependencies',
                'multi_site_support' => 'Handles localization and site-specific content',
            ],
            'security_context' => [
                'permission_checking' => 'Granular permission validation per operation',
                'audit_logging' => 'Complete operation history for compliance',
                'safe_defaults' => 'Conservative permissions and extensive validation',
            ],
        ];
    }

    protected function getWorkflowIntegration(): array
    {
        return [
            'content_creation_workflow' => [
                'step1' => 'Use list action to understand existing content patterns',
                'step2' => 'Use statamic-blueprints to understand required fields',
                'step3' => 'Use create action with proper data structure',
                'step4' => 'Use publish action to make content live',
            ],
            'content_management_workflow' => [
                'step1' => 'Use list with filters to find content to modify',
                'step2' => 'Use get to retrieve current content state',
                'step3' => 'Use update with merged data for modifications',
                'step4' => 'Use publish/unpublish for visibility control',
            ],
            'content_audit_workflow' => [
                'step1' => 'Use list to inventory all content',
                'step2' => 'Use get to examine content details',
                'step3' => 'Use statamic-blueprints to validate schemas',
                'step4' => 'Use update for corrections and improvements',
            ],
        ];
    }

    protected function getCommonPatterns(): array
    {
        return [
            'content_discovery' => [
                'description' => 'Finding and exploring existing content',
                'pattern' => 'list → get → analyze → plan modifications',
                'example' => ['action' => 'list', 'type' => 'entry', 'collection' => 'articles'],
            ],
            'content_creation' => [
                'description' => 'Creating new content following blueprints',
                'pattern' => 'blueprint analysis → data preparation → create → publish',
                'example' => ['action' => 'create', 'type' => 'entry', 'collection' => 'articles', 'data' => ['title' => 'New Article']],
            ],
            'content_modification' => [
                'description' => 'Updating existing content safely',
                'pattern' => 'get current state → prepare changes → update → validate',
                'example' => ['action' => 'update', 'type' => 'entry', 'id' => 'article-123', 'data' => ['title' => 'Updated Title']],
            ],
            'content_lifecycle' => [
                'description' => 'Managing content publication status',
                'pattern' => 'create draft → review → publish → manage → unpublish/archive',
                'example' => ['action' => 'publish', 'type' => 'entry', 'id' => 'article-123'],
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

        return config('statamic-mcp.tools.statamic-content.web_enabled', false);
    }

    /**
     * Determine if we're in web context.
     */
    private function isWebContext(): bool
    {
        return ! $this->isCliContext();
    }

    // isCliContext() method now provided by RouterHelpers trait

    /**
     * Validate action-specific requirements.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    private function validateActionRequirements(string $action, ?string $type, array $arguments): ?array
    {
        // Skip validation for help/discovery actions - they should work without specific identifiers
        if (in_array($action, ['help', 'discover', 'examples'])) {
            return null;
        }

        // ID required for specific actions (with type-specific messaging)
        // For terms, allow either 'id' or 'slug'
        // For globals, use 'global_set' or 'handle' (handled separately)
        if (in_array($action, ['get', 'update', 'delete', 'publish', 'unpublish'])) {
            if ($type === 'term') {
                if (empty($arguments['id']) && empty($arguments['slug'])) {
                    return $this->createErrorResponse("Term ID or slug is required for {$action} action")->toArray();
                }
            } elseif ($type === 'global') {
                // Global operations use global_set/handle, validated separately below
            } elseif (empty($arguments['id'])) {
                $typeName = ucfirst($type ?? 'Unknown');

                return $this->createErrorResponse("{$typeName} ID is required for {$action} action")->toArray();
            }
        }

        // Data required for create actions
        if ($action === 'create' && empty($arguments['data'])) {
            return $this->createErrorResponse('Data is required for create action')->toArray();
        }

        // Type-specific requirements - skip for help/discovery actions
        if (! in_array($action, ['help', 'discover', 'examples'])) {
            switch ($type) {
                case 'entry':
                    if (empty($arguments['collection'])) {
                        return $this->createErrorResponse('Collection handle is required for entry operations')->toArray();
                    }
                    if (! Collection::find($arguments['collection'])) {
                        return $this->createErrorResponse("Collection not found: {$arguments['collection']}")->toArray();
                    }
                    break;

                case 'term':
                    if (empty($arguments['taxonomy'])) {
                        return $this->createErrorResponse('Taxonomy handle is required for term operations')->toArray();
                    }
                    if (! Taxonomy::find($arguments['taxonomy'])) {
                        return $this->createErrorResponse("Taxonomy not found: {$arguments['taxonomy']}")->toArray();
                    }
                    break;

                case 'global':
                    $globalSetHandle = $arguments['global_set'] ?? $arguments['handle'] ?? null;
                    if (in_array($action, ['get', 'update']) && empty($globalSetHandle)) {
                        return $this->createErrorResponse('Global set handle is required for global operations')->toArray();
                    }
                    if (! empty($globalSetHandle) && ! GlobalSet::find($globalSetHandle)) {
                        return $this->createErrorResponse("Global set not found: {$globalSetHandle}")->toArray();
                    }
                    break;
            }
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
    private function checkPermissions(string $action, string $type, array $arguments): ?array
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
        $requiredPermissions = $this->getRequiredPermissions($action, $type, $arguments);

        // Check each required permission
        foreach ($requiredPermissions as $permission) {
            // @phpstan-ignore-next-line Method exists check is for defensive programming
            if (! method_exists($user, 'hasPermission') || ! $user->hasPermission($permission)) {
                return $this->createErrorResponse("Permission denied: Cannot {$action} {$type}")->toArray();
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
    private function getRequiredPermissions(string $action, string $type, array $arguments): array
    {
        $collection = $arguments['collection'] ?? null;
        $taxonomy = $arguments['taxonomy'] ?? null;

        return match ([$action, $type]) {
            ['list', 'entry'] => $collection ? ["view {$collection} entries"] : ['view entries'],
            ['get', 'entry'] => $collection ? ["view {$collection} entries"] : ['view entries'],
            ['create', 'entry'] => $collection ? ["create {$collection} entries"] : ['create entries'],
            ['update', 'entry'] => $collection ? ["edit {$collection} entries"] : ['edit entries'],
            ['delete', 'entry'] => $collection ? ["delete {$collection} entries"] : ['delete entries'],
            ['publish', 'entry'] => $collection ? ["publish {$collection} entries"] : ['publish entries'],
            ['unpublish', 'entry'] => $collection ? ["publish {$collection} entries"] : ['publish entries'],

            ['list', 'term'] => $taxonomy ? ["view {$taxonomy} terms"] : ['view terms'],
            ['get', 'term'] => $taxonomy ? ["view {$taxonomy} terms"] : ['view terms'],
            ['create', 'term'] => $taxonomy ? ["edit {$taxonomy} terms"] : ['edit terms'],
            ['update', 'term'] => $taxonomy ? ["edit {$taxonomy} terms"] : ['edit terms'],
            ['delete', 'term'] => $taxonomy ? ["edit {$taxonomy} terms"] : ['edit terms'],

            ['list', 'global'] => ['edit globals'],
            ['get', 'global'] => ['edit globals'],
            ['update', 'global'] => ['edit globals'],

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
    private function executeWithAuditLog(string $action, string $type, array $arguments): array
    {
        $startTime = microtime(true);
        $user = auth()->user();

        // Log the operation start if audit logging is enabled
        if (config('statamic-mcp.tools.statamic-content.audit_logging', true)) {
            \Log::info('MCP Content Operation Started', [
                'action' => $action,
                'type' => $type,
                'user' => $user->email ?? ($user ? $user->getAttribute('email') : null),
                'context' => $this->isWebContext() ? 'web' : 'cli',
                'arguments' => $this->sanitizeArgumentsForLogging($arguments),
                'timestamp' => now()->toISOString(),
            ]);
        }

        try {
            // Execute the actual action
            $result = $this->performAction($action, $type, $arguments);

            // Log successful operation
            if (config('statamic-mcp.tools.statamic-content.audit_logging', true)) {
                $duration = microtime(true) - $startTime;
                \Log::info('MCP Content Operation Completed', [
                    'action' => $action,
                    'type' => $type,
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
            if (config('statamic-mcp.tools.statamic-content.audit_logging', true)) {
                $duration = microtime(true) - $startTime;
                \Log::error('MCP Content Operation Failed', [
                    'action' => $action,
                    'type' => $type,
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
    private function performAction(string $action, string $type, array $arguments): array
    {
        return match ([$action, $type]) {
            ['list', 'entry'] => $this->listEntries($arguments),
            ['get', 'entry'] => $this->getEntry($arguments),
            ['create', 'entry'] => $this->createEntry($arguments),
            ['update', 'entry'] => $this->updateEntry($arguments),
            ['delete', 'entry'] => $this->deleteEntry($arguments),
            ['publish', 'entry'] => $this->publishEntry($arguments),
            ['unpublish', 'entry'] => $this->unpublishEntry($arguments),

            ['list', 'term'] => $this->listTerms($arguments),
            ['get', 'term'] => $this->getTerm($arguments),
            ['create', 'term'] => $this->createTerm($arguments),
            ['update', 'term'] => $this->updateTerm($arguments),
            ['delete', 'term'] => $this->deleteTerm($arguments),

            ['list', 'global'] => $this->listGlobals($arguments),
            ['get', 'global'] => $this->getGlobal($arguments),
            ['update', 'global'] => $this->updateGlobal($arguments),

            default => $this->createErrorResponse("Action {$action} not supported for type {$type}")->toArray(),
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
                ->locale($site);

            // Set slug from arguments or generate from title
            if (! empty($arguments['slug'])) {
                $requestedSlug = $arguments['slug'];

                // Use Statamic's built-in validation for unique slugs
                $slugValidator = Validator::make(['slug' => $requestedSlug], [
                    'slug' => [
                        'required',
                        'string',
                        new UniqueEntryValue($collection->handle(), null, $site),
                    ],
                ]);

                if ($slugValidator->fails()) {
                    $errors = $slugValidator->errors()->get('slug');
                    /** @var array<string> $flatErrors */
                    $flatErrors = array_map(fn ($error) => is_string($error) ? $error : implode(', ', (array) $error), $errors);

                    return $this->createErrorResponse('Slug validation failed: ' . implode(', ', $flatErrors))->toArray();
                }

                $entry->slug($requestedSlug);
            } elseif (! $entry->slug() && isset($data['title'])) {
                $entry->slug(Str::slug($data['title']));
            }

            // Get blueprint and validate field data
            $blueprint = $entry->blueprint();

            if ($blueprint && ! empty($data)) {
                // Add slug to data for validation if it's set
                $dataWithSlug = $data;
                if ($entry->slug()) {
                    $dataWithSlug['slug'] = $entry->slug();
                }

                // Use Statamic's Fields Validator for blueprint-based validation
                $fieldsValidator = (new FieldsValidator)
                    ->fields($blueprint->fields()->addValues($dataWithSlug))
                    ->withContext([
                        'entry' => $entry,
                        'collection' => $collection,
                        'site' => $site,
                    ]);

                try {
                    $validatedData = $fieldsValidator->validate();
                    // Remove slug from validated data since it's handled separately
                    unset($validatedData['slug']);
                    $entry->data($validatedData);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $errors = [];
                    foreach ($e->errors() as $field => $fieldErrors) {
                        $errors[] = "{$field}: " . implode(', ', $fieldErrors);
                    }

                    return $this->createErrorResponse('Field validation failed: ' . implode('; ', $errors))->toArray();
                }
            } else {
                // No blueprint validation, set data directly
                $entry->data($data);
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

    /**
     * List terms with filtering and pagination.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listTerms(array $arguments): array
    {
        $taxonomy = $arguments['taxonomy'];
        $site = $arguments['site'] ?? Site::default()->handle();
        $limit = $this->getIntegerArgument($arguments, 'limit', 50, 1, 1000);
        $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

        try {
            $query = Term::query()
                ->where('taxonomy', $taxonomy)
                ->where('site', $site);

            // Apply filters if provided
            if (! empty($arguments['filters'])) {
                foreach ($arguments['filters'] as $field => $value) {
                    $query->where($field, $value);
                }
            }

            $total = $query->count();
            $terms = $query->offset($offset)->limit($limit)->get();

            $data = $terms->map(function ($term) {
                return [
                    'id' => $term->id(),
                    'slug' => $term->slug(),
                    'title' => $term->get('title', $term->slug()),
                    'taxonomy' => $term->taxonomyHandle(),
                    'site' => $term->site()->handle(),
                    'url' => $term->url(),
                    'edit_url' => $term->editUrl(),
                    'entries_count' => $term->queryEntries()->count(),
                ];
            })->all();

            return [
                'terms' => $data,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
                'taxonomy' => $taxonomy,
                'site' => $site,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list terms: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Get a specific term.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getTerm(array $arguments): array
    {
        $taxonomy = $arguments['taxonomy'];
        $site = $arguments['site'] ?? Site::default()->handle();

        try {
            // Try to find by ID first, then by slug
            if (! empty($arguments['id'])) {
                $term = Term::find($arguments['id']);
            } elseif (! empty($arguments['slug'])) {
                $term = Term::query()
                    ->where('taxonomy', $taxonomy)
                    ->where('slug', $arguments['slug'])
                    ->first();
            } else {
                return $this->createErrorResponse('Term ID or slug is required for get action')->toArray();
            }

            if (! $term) {
                $identifier = $arguments['id'] ?? $arguments['slug'];

                return $this->createErrorResponse("Term not found: {$identifier}")->toArray();
            }

            // Get term for specific site if needed
            if ($term->site()->handle() !== $site) {
                $localizedTerm = $term->in($site);
                if ($localizedTerm) {
                    $term = $localizedTerm;
                }
            }

            return [
                'term' => array_merge([
                    'id' => $term->id(),
                    'taxonomy' => $term->taxonomyHandle(),
                    'site' => $term->site()->handle(),
                    'slug' => $term->slug(),
                    'url' => $term->url(),
                    'edit_url' => $term->editUrl(),
                    'data' => $term->data()->all(),
                    'entries_count' => $term->queryEntries()->count(),
                ], $term->data()->all()),
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get term: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Create a new term.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createTerm(array $arguments): array
    {
        $taxonomy = Taxonomy::find($arguments['taxonomy']);
        $site = $arguments['site'] ?? Site::default()->handle();
        $data = $arguments['data'] ?? [];

        try {
            $term = Term::make()
                ->taxonomy($taxonomy);

            // Set slug if provided, otherwise generate from title
            if (! empty($arguments['slug'])) {
                $requestedSlug = $arguments['slug'];

                // Use Statamic's built-in validation for unique slugs
                $slugValidator = Validator::make(['slug' => $requestedSlug], [
                    'slug' => [
                        'required',
                        'string',
                        new UniqueTermValue($taxonomy->handle(), null, $site),
                    ],
                ]);

                if ($slugValidator->fails()) {
                    $errors = $slugValidator->errors()->get('slug');
                    /** @var array<string> $flatErrors */
                    $flatErrors = array_map(fn ($error) => is_string($error) ? $error : implode(', ', (array) $error), $errors);

                    return $this->createErrorResponse('Slug validation failed: ' . implode(', ', $flatErrors))->toArray();
                }

                $term->slug($requestedSlug);
            } elseif (! $term->slug() && isset($data['title'])) {
                $term->slug(Str::slug($data['title']));
            }

            // Get blueprint and validate field data
            $blueprint = $term->blueprint();

            if ($blueprint && ! empty($data)) {
                // Add slug to data for validation if it's set
                $dataWithSlug = $data;
                if ($term->slug()) {
                    $dataWithSlug['slug'] = $term->slug();
                }

                // Use Statamic's Fields Validator for blueprint-based validation
                $fieldsValidator = (new FieldsValidator)
                    ->fields($blueprint->fields()->addValues($dataWithSlug))
                    ->withContext([
                        'term' => $term,
                        'taxonomy' => $taxonomy,
                        'site' => $site,
                    ]);

                try {
                    $validatedData = $fieldsValidator->validate();
                    // Remove slug from validated data since it's handled separately
                    unset($validatedData['slug']);
                    $term->data($validatedData);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $errors = [];
                    foreach ($e->errors() as $field => $fieldErrors) {
                        $errors[] = "{$field}: " . implode(', ', $fieldErrors);
                    }

                    return $this->createErrorResponse('Field validation failed: ' . implode('; ', $errors))->toArray();
                }
            } else {
                // No blueprint validation, set data directly
                $term->data($data);
            }

            $term->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'term' => array_merge([
                    'id' => $term->id(),
                    'slug' => $term->slug(),
                    'taxonomy' => $term->taxonomyHandle(),
                    'site' => $term->site()->handle(),
                    'url' => $term->url(),
                    'edit_url' => $term->editUrl(),
                    'data' => $term->data()->all(),
                ], $term->data()->all()),
                'created' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create term: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Update an existing term.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateTerm(array $arguments): array
    {
        $taxonomy = $arguments['taxonomy'];
        $site = $arguments['site'] ?? Site::default()->handle();
        $data = $arguments['data'] ?? [];

        try {
            // Try to find by ID first, then by slug
            if (! empty($arguments['id'])) {
                $term = Term::find($arguments['id']);
            } elseif (! empty($arguments['slug'])) {
                $term = Term::query()
                    ->where('taxonomy', $taxonomy)
                    ->where('slug', $arguments['slug'])
                    ->first();
            } else {
                return $this->createErrorResponse('Term ID or slug is required for update action')->toArray();
            }

            if (! $term) {
                $identifier = $arguments['id'] ?? $arguments['slug'];

                return $this->createErrorResponse("Term not found: {$identifier}")->toArray();
            }

            // Get term for specific site
            if ($term->site()->handle() !== $site) {
                $localizedTerm = $term->in($site);
                if ($localizedTerm) {
                    $term = $localizedTerm;
                } else {
                    return $this->createErrorResponse("Term not available in site: {$site}")->toArray();
                }
            }

            $term->merge($data)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'term' => [
                    'id' => $term->id(),
                    'slug' => $term->slug(),
                    'taxonomy' => $term->taxonomyHandle(),
                    'site' => $term->site()->handle(),
                    'url' => $term->url(),
                    'edit_url' => $term->editUrl(),
                ],
                'updated' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update term: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Delete a term.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteTerm(array $arguments): array
    {
        $taxonomy = $arguments['taxonomy'];

        try {
            // Try to find by ID first, then by slug
            if (! empty($arguments['id'])) {
                $term = Term::find($arguments['id']);
            } elseif (! empty($arguments['slug'])) {
                $term = Term::query()
                    ->where('taxonomy', $taxonomy)
                    ->where('slug', $arguments['slug'])
                    ->first();
            } else {
                return $this->createErrorResponse('Term ID or slug is required for delete action')->toArray();
            }

            if (! $term) {
                $identifier = $arguments['id'] ?? $arguments['slug'];

                return $this->createErrorResponse("Term not found: {$identifier}")->toArray();
            }

            $termData = [
                'id' => $term->id(),
                'slug' => $term->slug(),
                'taxonomy' => $term->taxonomyHandle(),
                'site' => $term->site()->handle(),
            ];

            // Check for entries using this term
            $entriesCount = $term->queryEntries()->count();
            if ($entriesCount > 0) {
                return $this->createErrorResponse("Cannot delete term: {$entriesCount} entries are using this term")->toArray();
            }

            $term->delete();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'term' => $termData,
                'deleted' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete term: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * List global sets with their current values.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listGlobals(array $arguments): array
    {
        $site = $arguments['site'] ?? Site::default()->handle();
        $limit = $this->getIntegerArgument($arguments, 'limit', 50, 1, 1000);
        $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

        try {
            $globalSets = GlobalSet::all();
            $total = $globalSets->count();

            $paginatedSets = $globalSets->skip($offset)->take($limit);

            $data = $paginatedSets->map(function ($globalSet) use ($site) {
                $variables = $globalSet->in($site);

                return [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                    'site' => $site,
                    'localized' => $globalSet->sites()->count() > 1,
                    'sites' => $globalSet->sites()->all(),
                    'has_values' => $variables ? $variables->data()->isNotEmpty() : false,
                    'edit_url' => $globalSet->editUrl(),
                ];
            })->all();

            return [
                'globals' => $data,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
                'site' => $site,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list globals: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Get a specific global set with its values.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getGlobal(array $arguments): array
    {
        // Accept both 'global_set' and 'handle' parameters
        $globalSetHandle = $arguments['global_set'] ?? $arguments['handle'];
        $site = $arguments['site'] ?? Site::default()->handle();

        try {
            $globalSet = GlobalSet::find($globalSetHandle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$globalSetHandle}")->toArray();
            }

            $variables = $globalSet->in($site);

            return [
                'global' => [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                    'site' => $site,
                    'localized' => $globalSet->sites()->count() > 1,
                    'sites' => $globalSet->sites()->all(),
                    'edit_url' => $globalSet->editUrl(),
                    'data' => $variables ? $variables->data()->all() : [],
                    'values' => $variables ? $variables->data()->all() : [],
                    'blueprint' => $globalSet->blueprint() ? [
                        'handle' => $globalSet->blueprint()->handle(),
                        'title' => $globalSet->blueprint()->title(),
                    ] : null,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get global: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Update values in a global set.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateGlobal(array $arguments): array
    {
        // Accept both 'global_set' and 'handle' parameters
        $globalSetHandle = $arguments['global_set'] ?? $arguments['handle'];
        $site = $arguments['site'] ?? Site::default()->handle();
        $data = $arguments['data'] ?? [];

        try {
            $globalSet = GlobalSet::find($globalSetHandle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$globalSetHandle}")->toArray();
            }

            $variables = $globalSet->in($site);

            if (! $variables) {
                return $this->createErrorResponse("Global set not available in site: {$site}")->toArray();
            }

            $variables->merge($data)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'global' => [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                    'site' => $site,
                    'updated_fields' => array_keys($data),
                    'edit_url' => $globalSet->editUrl(),
                ],
                'updated' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update global: {$e->getMessage()}")->toArray();
        }
    }

    protected function getActions(): array
    {
        return [
            'list' => [
                'description' => 'List content with filtering and pagination',
                'purpose' => 'Content discovery and browsing',
                'required' => ['type'],
                'optional' => ['collection', 'taxonomy', 'global_set', 'filters', 'limit', 'offset'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'list', 'type' => 'entry', 'collection' => 'articles'],
                    ['action' => 'list', 'type' => 'term', 'taxonomy' => 'categories'],
                ],
            ],
            'get' => [
                'description' => 'Get specific content item with full data',
                'purpose' => 'Content detail retrieval',
                'required' => ['type', 'id'],
                'optional' => ['collection', 'taxonomy', 'global_set', 'site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'get', 'type' => 'entry', 'id' => 'article-123'],
                ],
            ],
            'create' => [
                'description' => 'Create new content item',
                'purpose' => 'Content creation following blueprint schema',
                'required' => ['type', 'data'],
                'optional' => ['collection', 'taxonomy', 'global_set', 'site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'create', 'type' => 'entry', 'collection' => 'articles', 'data' => ['title' => 'New Article']],
                ],
            ],
            'update' => [
                'description' => 'Update existing content item',
                'purpose' => 'Content modification with validation',
                'required' => ['type', 'id', 'data'],
                'optional' => ['collection', 'taxonomy', 'global_set', 'site'],
                'destructive' => true,
                'examples' => [
                    ['action' => 'update', 'type' => 'entry', 'id' => 'article-123', 'data' => ['title' => 'Updated Title']],
                ],
            ],
            'delete' => [
                'description' => 'Delete content item',
                'purpose' => 'Content removal with relationship checking',
                'required' => ['type', 'id'],
                'optional' => ['collection', 'taxonomy', 'global_set'],
                'destructive' => true,
                'examples' => [
                    ['action' => 'delete', 'type' => 'entry', 'id' => 'article-123'],
                ],
            ],
            'publish' => [
                'description' => 'Publish content item',
                'purpose' => 'Make content publicly available',
                'required' => ['type', 'id'],
                'optional' => ['collection'],
                'destructive' => true,
                'examples' => [
                    ['action' => 'publish', 'type' => 'entry', 'id' => 'article-123'],
                ],
            ],
            'unpublish' => [
                'description' => 'Unpublish content item',
                'purpose' => 'Hide content from public view',
                'required' => ['type', 'id'],
                'optional' => ['collection'],
                'destructive' => true,
                'examples' => [
                    ['action' => 'unpublish', 'type' => 'entry', 'id' => 'article-123'],
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
            'term' => [
                'description' => 'Taxonomy classification items',
                'properties' => ['taxonomy', 'slug', 'title'],
                'relationships' => ['belongs to taxonomy', 'can be assigned to entries'],
                'examples' => ['categories', 'tags', 'locations', 'authors'],
            ],
            'global' => [
                'description' => 'Site-wide settings and configuration',
                'properties' => ['global_set', 'site', 'localized values'],
                'relationships' => ['referenced by entries and templates'],
                'examples' => ['site settings', 'navigation menus', 'contact information'],
            ],
        ];
    }

    /**
     * Bridge method for existing ContentRouter implementation.
     * Routes executeAction to the existing implementation.
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
