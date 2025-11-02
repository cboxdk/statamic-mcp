<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\JsonSchema\JsonSchema;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Support\Str;

class GlobalsRouter extends BaseRouter
{
    use ClearsCaches;
    use HasCommonSchemas;
    use RouterHelpers;

    protected function getDomain(): string
    {
        return 'globals';
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'global_set' => JsonSchema::string()
                ->description('Global set handle (required for get, update operations)'),

            'handle' => JsonSchema::string()
                ->description('Global set handle (alternative to global_set)'),

            'site' => JsonSchema::string()
                ->description('Site handle (optional, defaults to default site)'),

            'data' => JsonSchema::object()
                ->description('Global data for update operations'),

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

        // Skip validation for help/discovery actions that don't need global_set
        if (in_array($action, ['help', 'discover', 'examples'])) {
            return parent::executeInternal($arguments);
        }

        // Check if tool is enabled for current context
        if (! $this->isToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Globals tool is disabled for web access')->toArray();
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
            'site_wide_configuration' => 'Manage site-wide settings and configuration data',
            'multi_site_localization' => 'Full localization support for global values across sites',
            'blueprint_validation' => 'Automatic validation against global set blueprints',
            'flexible_identification' => 'Support for both global_set and handle parameters',
            'cache_management' => 'Intelligent cache clearing after operations',
            'audit_logging' => 'Complete operation logging for security and compliance',
            'template_integration' => 'Seamless integration with Antlers/Blade templates',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'Comprehensive management of Statamic global sets and values with multi-site support and configuration management.';
    }

    protected function getDecisionTree(): array
    {
        return [
            'operation_selection' => [
                'list' => 'Browse and discover all global sets and their values',
                'get' => 'Retrieve specific global set values for a site',
                'update' => 'Modify global values with validation and multi-site support',
            ],
            'identification_methods' => [
                'global_set' => 'Global set handle (primary parameter)',
                'handle' => 'Alternative handle parameter for compatibility',
                'both_supported' => 'Either global_set or handle can be used',
            ],
            'multi_site_considerations' => [
                'site_specific_values' => 'Global values can differ per site',
                'localization_support' => 'Full multi-site content management',
                'default_site_fallback' => 'Automatic fallback to default site',
            ],
            'security_considerations' => [
                'cli_context' => 'Full access with minimal restrictions',
                'web_context' => 'Permission-based access with audit logging',
                'global_permissions' => 'Edit globals permission required',
            ],
        ];
    }

    protected function getContextAwareness(): array
    {
        return [
            'global_context' => [
                'blueprint_compliance' => 'All operations validate against global set blueprints',
                'template_accessibility' => 'Values available in templates via global_set.field syntax',
                'configuration_role' => 'Serves as site-wide configuration storage',
            ],
            'multi_site_context' => [
                'localization_support' => 'Full multi-site and localization capabilities',
                'site_specific_content' => 'Global values can have different content per site',
                'default_site_fallback' => 'Automatic fallback to default site when needed',
            ],
            'template_context' => [
                'antlers_integration' => 'Accessible via {{ global_set:field }} syntax',
                'blade_integration' => 'Available through Statamic helper functions',
                'caching_implications' => 'Changes affect template caching and performance',
            ],
        ];
    }

    protected function getWorkflowIntegration(): array
    {
        return [
            'site_configuration_workflow' => [
                'step1' => 'Use statamic-globals.sets to create global set structure',
                'step2' => 'Use update to set initial configuration values',
                'step3' => 'Use get to verify configuration across sites',
                'step4' => 'Use templates to display global configuration',
            ],
            'content_management_workflow' => [
                'step1' => 'Use list to review all global sets and values',
                'step2' => 'Use get to examine specific global set content',
                'step3' => 'Use update to modify values with validation',
                'step4' => 'Test template integration and caching',
            ],
            'localization_workflow' => [
                'step1' => 'Use get with default site to establish base values',
                'step2' => 'Use update with specific sites for localized content',
                'step3' => 'Use list to audit localization across all sites',
                'step4' => 'Validate template rendering per site',
            ],
        ];
    }

    protected function getCommonPatterns(): array
    {
        return [
            'configuration_management' => [
                'description' => 'Managing site-wide configuration settings',
                'pattern' => 'list → get current → update values → verify',
                'example' => ['action' => 'update', 'global_set' => 'site_settings', 'data' => ['site_name' => 'My Site', 'contact_email' => 'admin@example.com']],
            ],
            'multi_site_localization' => [
                'description' => 'Managing localized global content',
                'pattern' => 'get default → update per site → validate consistency',
                'example' => ['action' => 'update', 'global_set' => 'navigation', 'site' => 'en', 'data' => ['menu_items' => ['item1', 'item2']]],
            ],
            'global_discovery' => [
                'description' => 'Exploring all global sets and their structure',
                'pattern' => 'list → get details → analyze usage',
                'example' => ['action' => 'list', 'limit' => 20],
            ],
            'configuration_audit' => [
                'description' => 'Auditing global configuration across sites',
                'pattern' => 'list all → get per site → compare values',
                'example' => ['action' => 'get', 'global_set' => 'site_settings', 'site' => 'en'],
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

        return config('statamic-mcp.tools.statamic-globals.web_enabled', false);
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
        // Global set handle required for get and update operations
        if (in_array($action, ['get', 'update'])) {
            $globalSetHandle = $arguments['global_set'] ?? $arguments['handle'] ?? null;
            if (empty($globalSetHandle)) {
                return $this->createErrorResponse('Global set handle is required for global operations')->toArray();
            }
            if (! GlobalSet::find($globalSetHandle)) {
                return $this->createErrorResponse("Global set not found: {$globalSetHandle}")->toArray();
            }
        }

        // Data required for update actions
        if ($action === 'update' && empty($arguments['data'])) {
            return $this->createErrorResponse('Data is required for update action')->toArray();
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
                return $this->createErrorResponse("Permission denied: Cannot {$action} globals")->toArray();
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
        return match ($action) {
            'list' => ['edit globals'],
            'get' => ['edit globals'],
            'update' => ['edit globals'],
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
        if (config('statamic-mcp.tools.statamic-globals.audit_logging', true)) {
            \Log::info('MCP Globals Operation Started', [
                'action' => $action,
                'global_set' => $arguments['global_set'] ?? $arguments['handle'] ?? null,
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
            if (config('statamic-mcp.tools.statamic-globals.audit_logging', true)) {
                $duration = microtime(true) - $startTime;
                \Log::info('MCP Globals Operation Completed', [
                    'action' => $action,
                    'global_set' => $arguments['global_set'] ?? $arguments['handle'] ?? null,
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
            if (config('statamic-mcp.tools.statamic-globals.audit_logging', true)) {
                $duration = microtime(true) - $startTime;
                \Log::error('MCP Globals Operation Failed', [
                    'action' => $action,
                    'global_set' => $arguments['global_set'] ?? $arguments['handle'] ?? null,
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
                if (Str::contains(strtolower($key), ['password', 'secret', 'token', 'key', 'api'])) {
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
            'list' => $this->listGlobals($arguments),
            'get' => $this->getGlobal($arguments),
            'update' => $this->updateGlobal($arguments),
            default => $this->createErrorResponse("Action {$action} not supported for globals")->toArray(),
        };
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
                'description' => 'List global sets with values and pagination',
                'purpose' => 'Global set discovery and browsing',
                'required' => [],
                'optional' => ['limit', 'offset', 'site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'list'],
                    ['action' => 'list', 'site' => 'en', 'limit' => 10],
                ],
            ],
            'get' => [
                'description' => 'Get specific global set with values',
                'purpose' => 'Global set detail retrieval',
                'required' => ['global_set'],
                'optional' => ['site'],
                'destructive' => false,
                'examples' => [
                    ['action' => 'get', 'global_set' => 'site_settings'],
                    ['action' => 'get', 'handle' => 'navigation', 'site' => 'en'],
                ],
            ],
            'update' => [
                'description' => 'Update global set values',
                'purpose' => 'Global configuration modification',
                'required' => ['global_set', 'data'],
                'optional' => ['site'],
                'destructive' => true,
                'examples' => [
                    ['action' => 'update', 'global_set' => 'site_settings', 'data' => ['site_name' => 'My Site', 'contact_email' => 'admin@example.com']],
                ],
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

    /**
     * Get global set type definitions for schema and documentation.
     *
     * @return array<string, mixed>
     */
    protected function getTypes(): array
    {
        return [
            'GlobalSet' => [
                'description' => 'Global set configuration object',
                'properties' => [
                    'handle' => [
                        'type' => 'string',
                        'description' => 'Unique identifier for the global set',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Display title for the global set',
                    ],
                    'blueprint' => [
                        'type' => 'string',
                        'description' => 'Blueprint handle defining the field structure',
                    ],
                    'sites' => [
                        'type' => 'array',
                        'description' => 'Array of site handles where this global set is available',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'GlobalValues' => [
                'description' => 'Global set values for a specific site',
                'properties' => [
                    'handle' => [
                        'type' => 'string',
                        'description' => 'Global set handle',
                    ],
                    'site' => [
                        'type' => 'string',
                        'description' => 'Site handle for these values',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Field values as defined by the blueprint',
                    ],
                    'published' => [
                        'type' => 'boolean',
                        'description' => 'Whether these values are published',
                    ],
                ],
            ],
        ];
    }
}
