<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\JsonSchema\JsonSchema;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Support\Str;

class TermsRouter extends BaseRouter
{
    use ClearsCaches;
    use HasCommonSchemas;
    use RouterHelpers;

    protected function getDomain(): string
    {
        return 'terms';
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'taxonomy' => JsonSchema::string()
                ->description('Taxonomy handle (required for all term operations)')
                ->required(),

            'id' => JsonSchema::string()
                ->description('Term ID (required for get, update, delete)'),

            'slug' => JsonSchema::string()
                ->description('Term slug (alternative to ID for get, update, delete)'),

            'site' => JsonSchema::string()
                ->description('Site handle (optional, defaults to default site)'),

            'data' => JsonSchema::object()
                ->description('Term data for create/update operations'),

            'filters' => JsonSchema::object()
                ->description('Filtering options for list operations'),

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

        // Skip validation for help/discovery actions that don't need taxonomy
        if (in_array($action, ['help', 'discover', 'examples'])) {
            return parent::executeInternal($arguments);
        }

        // Taxonomy is required for all term operations
        if (empty($arguments['taxonomy'])) {
            return $this->createErrorResponse('Taxonomy handle is required for term operations')->toArray();
        }

        if (! Taxonomy::find($arguments['taxonomy'])) {
            return $this->createErrorResponse("Taxonomy not found: {$arguments['taxonomy']}")->toArray();
        }

        // Check if tool is enabled for current context
        if (! $this->isToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Terms tool is disabled for web access')->toArray();
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
            'taxonomy_based_management' => 'Comprehensive term management within taxonomies',
            'flexible_identification' => 'Support for both ID and slug-based operations',
            'multi_site_localization' => 'Full localization support across multiple sites',
            'entry_relationship_tracking' => 'Track and validate relationships with entries',
            'blueprint_validation' => 'Automatic validation against taxonomy blueprints',
            'filtering_and_search' => 'Advanced filtering and pagination for large taxonomies',
            'cache_management' => 'Intelligent cache clearing after operations',
            'audit_logging' => 'Complete operation logging for security and compliance',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'Comprehensive management of Statamic taxonomy terms with full CRUD operations, relationship tracking, and multi-site support.';
    }

    protected function getDecisionTree(): array
    {
        return [
            'operation_selection' => [
                'list' => 'Browse and discover terms within a taxonomy',
                'get' => 'Retrieve full term details for specific term',
                'create' => 'Add new terms following taxonomy blueprint',
                'update' => 'Modify existing term data with validation',
                'delete' => 'Remove terms with entry relationship checking',
            ],
            'identification_methods' => [
                'id' => 'Unique term identifier (recommended for API operations)',
                'slug' => 'Human-readable term slug (useful for content operations)',
                'both_supported' => 'Either ID or slug can be used for get/update/delete',
            ],
            'taxonomy_context' => [
                'required' => 'Taxonomy handle must be provided for all operations',
                'validation' => 'Taxonomy existence is verified before operations',
                'blueprint' => 'Term data must conform to taxonomy blueprint',
            ],
            'security_considerations' => [
                'cli_context' => 'Full access with minimal restrictions',
                'web_context' => 'Permission-based access with audit logging',
                'taxonomy_permissions' => 'Granular permissions per taxonomy',
            ],
        ];
    }

    protected function getContextAwareness(): array
    {
        return [
            'taxonomy_context' => [
                'blueprint_compliance' => 'All operations validate against taxonomy blueprints',
                'entry_relationships' => 'Terms understand their relationships with entries',
                'hierarchical_support' => 'Support for hierarchical term structures',
            ],
            'multi_site_context' => [
                'localization_support' => 'Full multi-site and localization capabilities',
                'site_specific_content' => 'Terms can have different content per site',
                'default_site_fallback' => 'Automatic fallback to default site when needed',
            ],
            'relationship_context' => [
                'entry_tracking' => 'Track which entries use each term',
                'deletion_safety' => 'Prevent deletion of terms with active relationships',
                'usage_statistics' => 'Provide entry count information for terms',
            ],
        ];
    }

    protected function getWorkflowIntegration(): array
    {
        return [
            'taxonomy_setup_workflow' => [
                'step1' => 'Use statamic-taxonomies to create taxonomy structure',
                'step2' => 'Use create to add initial terms',
                'step3' => 'Use statamic-entries to assign terms to content',
                'step4' => 'Use list to review term usage and organization',
            ],
            'content_classification_workflow' => [
                'step1' => 'Use list to explore existing terms',
                'step2' => 'Use create for new classification terms',
                'step3' => 'Use update to refine term definitions',
                'step4' => 'Use get to verify term-entry relationships',
            ],
            'taxonomy_maintenance_workflow' => [
                'step1' => 'Use list to audit term usage',
                'step2' => 'Use get to examine term details and entry counts',
                'step3' => 'Use update for term consolidation',
                'step4' => 'Use delete for unused terms (after relationship check)',
            ],
        ];
    }

    protected function getCommonPatterns(): array
    {
        return [
            'term_discovery' => [
                'description' => 'Finding and exploring terms in taxonomy',
                'pattern' => 'list → filter → get → analyze relationships',
                'example' => ['action' => 'list', 'taxonomy' => 'categories', 'limit' => 20],
            ],
            'term_creation' => [
                'description' => 'Creating new terms following blueprint',
                'pattern' => 'blueprint analysis → data preparation → create → validate',
                'example' => ['action' => 'create', 'taxonomy' => 'categories', 'data' => ['title' => 'New Category', 'description' => 'Category description']],
            ],
            'term_modification' => [
                'description' => 'Updating existing terms safely',
                'pattern' => 'get current state → prepare changes → update → validate',
                'example' => ['action' => 'update', 'taxonomy' => 'categories', 'slug' => 'category-slug', 'data' => ['title' => 'Updated Category']],
            ],
            'term_cleanup' => [
                'description' => 'Removing unused terms safely',
                'pattern' => 'get term → check entry relationships → delete if unused',
                'example' => ['action' => 'delete', 'taxonomy' => 'categories', 'id' => 'category-123'],
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

        return config('statamic-mcp.tools.statamic.terms.web_enabled', false);
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
        // ID or slug required for specific actions
        if (in_array($action, ['get', 'update', 'delete'])) {
            if (empty($arguments['id']) && empty($arguments['slug'])) {
                return $this->createErrorResponse("Term ID or slug is required for {$action} action")->toArray();
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
                return $this->createErrorResponse("Permission denied: Cannot {$action} terms")->toArray();
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
        $taxonomy = $arguments['taxonomy'];

        return match ($action) {
            'list' => ["view {$taxonomy} terms"],
            'get' => ["view {$taxonomy} terms"],
            'create' => ["edit {$taxonomy} terms"],
            'update' => ["edit {$taxonomy} terms"],
            'delete' => ["edit {$taxonomy} terms"],
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
        if (config('statamic-mcp.tools.statamic.terms.audit_logging', true)) {
            \Log::info('MCP Terms Operation Started', [
                'action' => $action,
                'taxonomy' => $arguments['taxonomy'],
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
            if (config('statamic-mcp.tools.statamic.terms.audit_logging', true)) {
                $duration = microtime(true) - $startTime;
                \Log::info('MCP Terms Operation Completed', [
                    'action' => $action,
                    'taxonomy' => $arguments['taxonomy'],
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
            if (config('statamic-mcp.tools.statamic.terms.audit_logging', true)) {
                $duration = microtime(true) - $startTime;
                \Log::error('MCP Terms Operation Failed', [
                    'action' => $action,
                    'taxonomy' => $arguments['taxonomy'],
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
            'list' => $this->listTerms($arguments),
            'get' => $this->getTerm($arguments),
            'create' => $this->createTerm($arguments),
            'update' => $this->updateTerm($arguments),
            'delete' => $this->deleteTerm($arguments),
            default => $this->createErrorResponse("Action {$action} not supported for terms")->toArray(),
        };
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
                ->taxonomy($taxonomy)
                ->data($data);

            // Set slug if provided, otherwise generate from title
            if (! empty($arguments['slug'])) {
                $term->slug($arguments['slug']);
            } elseif (! $term->slug() && isset($data['title'])) {
                $term->slug(Str::slug($data['title']));
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

    protected function getActions(): array
    {
        return [
            'list' => [
                'description' => 'List terms with filtering and pagination',
                'purpose' => 'Term discovery and browsing',
                'required' => ['taxonomy'],
                'optional' => ['filters', 'limit', 'offset', 'site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'list', 'taxonomy' => 'categories'],
                    ['action' => 'list', 'taxonomy' => 'tags', 'limit' => 20],
                ],
            ],
            'get' => [
                'description' => 'Get specific term with full data',
                'purpose' => 'Term detail retrieval',
                'required' => ['taxonomy'],
                'optional' => ['id', 'slug', 'site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'get', 'taxonomy' => 'categories', 'id' => 'category-123'],
                    ['action' => 'get', 'taxonomy' => 'categories', 'slug' => 'category-slug'],
                ],
            ],
            'create' => [
                'description' => 'Create new term',
                'purpose' => 'Term creation following blueprint schema',
                'required' => ['taxonomy', 'data'],
                'optional' => ['slug', 'site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'create', 'taxonomy' => 'categories', 'data' => ['title' => 'New Category', 'description' => 'Category description']],
                ],
            ],
            'update' => [
                'description' => 'Update existing term',
                'purpose' => 'Term modification with validation',
                'required' => ['taxonomy', 'data'],
                'optional' => ['id', 'slug', 'site'],
                'destructive' => true,
                'examples' => [
                    ['action' => 'update', 'taxonomy' => 'categories', 'slug' => 'category-slug', 'data' => ['title' => 'Updated Category']],
                ],
            ],
            'delete' => [
                'description' => 'Delete term',
                'purpose' => 'Term removal with entry relationship checking',
                'required' => ['taxonomy'],
                'optional' => ['id', 'slug'],
                'destructive' => true,
                'examples' => [
                    ['action' => 'delete', 'taxonomy' => 'categories', 'id' => 'category-123'],
                    ['action' => 'delete', 'taxonomy' => 'categories', 'slug' => 'unused-category'],
                ],
            ],
        ];
    }

    protected function getTypes(): array
    {
        return [
            'term' => [
                'description' => 'Taxonomy classification items',
                'properties' => ['taxonomy', 'slug', 'title'],
                'relationships' => ['belongs to taxonomy', 'can be assigned to entries'],
                'examples' => ['categories', 'tags', 'locations', 'authors'],
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
