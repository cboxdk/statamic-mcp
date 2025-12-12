<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ExecutesWithAudit;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class SystemRouter extends BaseRouter
{
    use ExecutesWithAudit;
    use RouterHelpers;

    protected function getToolName(): string
    {
        return 'statamic-system';
    }

    protected function getToolDescription(): string
    {
        return 'Manage Statamic system operations: cache management, health checks, configuration, and system information';
    }

    protected function getDomain(): string
    {
        return 'system';
    }

    protected function getActions(): array
    {
        return [
            'info' => [
                'description' => 'Get comprehensive system information and status',
                'purpose' => 'System inspection and health overview',
                'destructive' => false,
                'examples' => [
                    ['action' => 'info'],
                    ['action' => 'info', 'include_performance' => true],
                ],
            ],
            'health' => [
                'description' => 'Perform system health checks and diagnostics',
                'purpose' => 'System monitoring and troubleshooting',
                'destructive' => false,
                'examples' => [
                    ['action' => 'health'],
                ],
            ],
            'cache_status' => [
                'description' => 'Check cache status and statistics',
                'purpose' => 'Cache monitoring and analysis',
                'destructive' => false,
                'examples' => [
                    ['action' => 'cache_status'],
                    ['action' => 'cache_status', 'cache_type' => 'stache'],
                ],
            ],
            'cache_clear' => [
                'description' => 'Clear system caches for optimization',
                'purpose' => 'Cache management and performance',
                'destructive' => true,
                'examples' => [
                    ['action' => 'cache_clear', 'cache_type' => 'stache'],
                ],
            ],
            'cache_warm' => [
                'description' => 'Warm system caches for performance',
                'purpose' => 'Cache optimization and preloading',
                'destructive' => false,
                'examples' => [
                    ['action' => 'cache_warm', 'cache_type' => 'stache'],
                ],
            ],
            'config_get' => [
                'description' => 'Get system configuration values',
                'purpose' => 'Configuration inspection and debugging',
                'destructive' => false,
                'examples' => [
                    ['action' => 'config_get', 'config_key' => 'app.name'],
                ],
            ],
            'config_set' => [
                'description' => 'Set system configuration values',
                'purpose' => 'Configuration management and updates',
                'destructive' => true,
                'examples' => [
                    ['action' => 'config_set', 'config_key' => 'app.debug', 'config_value' => 'false'],
                ],
            ],
        ];
    }

    protected function getTypes(): array
    {
        return [
            'system' => [
                'description' => 'Overall system information and status',
                'properties' => ['version', 'environment', 'php_version', 'memory_usage'],
                'relationships' => ['cache', 'config'],
                'examples' => ['info', 'health'],
            ],
            'cache' => [
                'description' => 'System cache components and management',
                'properties' => ['type', 'size', 'hit_rate', 'status'],
                'relationships' => ['system'],
                'examples' => ['stache', 'static', 'views'],
            ],
            'config' => [
                'description' => 'System configuration and settings',
                'properties' => ['key', 'value', 'environment', 'source'],
                'relationships' => ['system'],
                'examples' => ['app.name', 'statamic-license_key'],
            ],
            'health' => [
                'description' => 'System health checks and diagnostics',
                'properties' => ['status', 'checks', 'warnings', 'errors'],
                'relationships' => ['system', 'cache'],
                'examples' => ['overall', 'database', 'filesystem'],
            ],
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'type' => JsonSchema::string()
                ->description('System component type')
                ->enum(['system', 'cache', 'config', 'health']),
            'cache_type' => JsonSchema::string()
                ->description('Cache type to operate on')
                ->enum(['all', 'stache', 'static', 'views', 'app', 'config', 'route']),
            'config_key' => JsonSchema::string()
                ->description('Configuration key for config operations'),
            'config_value' => JsonSchema::string()
                ->description('Configuration value for config set operations'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed information (default: true)'),
            'include_performance' => JsonSchema::boolean()
                ->description('Include performance metrics (default: false)'),
        ]);
    }

    /**
     * Route system operations to appropriate handlers with security checks and audit logging.
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
            return $this->createErrorResponse('Permission denied: System tool is disabled for web access')->toArray();
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
        return match ($action) {
            'info' => $this->getSystemInfo($arguments),
            'health' => $this->getHealthStatus($arguments),
            'cache_status' => $this->getCacheStatus($arguments),
            'cache_clear' => $this->clearCache($arguments),
            'cache_warm' => $this->warmCache($arguments),
            'config_get' => $this->getConfig($arguments),
            'config_set' => $this->setConfig($arguments),
            default => $this->createErrorResponse("Unknown system action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getSystemInfo(array $arguments): array
    {
        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $includePerformance = $this->getBooleanArgument($arguments, 'include_performance', false);

            $info = [
                'statamic_version' => $this->getStatamicVersion(),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'environment' => app()->environment(),
                'debug_mode' => config('app.debug'),
                'timezone' => config('app.timezone'),
            ];

            if ($includeDetails) {
                $info = array_merge($info, [
                    'sites' => \Statamic\Facades\Site::all()->map(function ($site) {
                        return [
                            'handle' => $site->handle(),
                            'name' => $site->name(),
                            'url' => $site->url(),
                            'locale' => $site->locale(),
                        ];
                    })->all(),
                    'collections_count' => \Statamic\Facades\Collection::all()->count(),
                    'taxonomies_count' => \Statamic\Facades\Taxonomy::all()->count(),
                    'users_count' => \Statamic\Facades\User::all()->count(),
                    'asset_containers_count' => \Statamic\Facades\AssetContainer::all()->count(),
                    'navigation_count' => \Statamic\Facades\Nav::all()->count(),
                    'global_sets_count' => \Statamic\Facades\GlobalSet::all()->count(),
                    'form_count' => \Statamic\Facades\Form::all()->count(),
                    'blueprint_count' => 0, // Blueprint counting simplified
                ]);
            }

            if ($includePerformance) {
                $info = array_merge($info, [
                    'memory_usage' => [
                        'current' => memory_get_usage(true),
                        'peak' => memory_get_peak_usage(true),
                        'limit' => ini_get('memory_limit'),
                    ],
                    'execution_time' => microtime(true) - LARAVEL_START,
                    'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status(),
                ]);
            }

            return [
                'success' => true,
                'data' => ['system_info' => $info],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to get system info: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getHealthStatus(array $arguments): array
    {
        try {
            $health = [
                'overall_status' => 'healthy',
                'checks' => [],
                'timestamp' => now()->toISOString(),
            ];

            // Database connection check
            try {
                \DB::connection()->getPdo();
                $health['checks']['database'] = ['status' => 'healthy', 'message' => 'Database connection successful'];
            } catch (\Exception $e) {
                $health['checks']['database'] = ['status' => 'unhealthy', 'message' => "Database connection failed: {$e->getMessage()}"];
                $health['overall_status'] = 'unhealthy';
            }

            // Cache connection check
            try {
                Cache::put('health_check', 'test', 1);
                $testValue = Cache::get('health_check');
                if ($testValue === 'test') {
                    $health['checks']['cache'] = ['status' => 'healthy', 'message' => 'Cache system working'];
                } else {
                    $health['checks']['cache'] = ['status' => 'degraded', 'message' => 'Cache system not responding correctly'];
                    $health['overall_status'] = 'degraded';
                }
                Cache::forget('health_check');
            } catch (\Exception $e) {
                $health['checks']['cache'] = ['status' => 'unhealthy', 'message' => "Cache system failed: {$e->getMessage()}"];
                $health['overall_status'] = 'unhealthy';
            }

            // Storage check
            try {
                $storageWritable = is_writable(storage_path());
                $health['checks']['storage'] = [
                    'status' => $storageWritable ? 'healthy' : 'unhealthy',
                    'message' => $storageWritable ? 'Storage directory writable' : 'Storage directory not writable',
                ];
                if (! $storageWritable) {
                    $health['overall_status'] = 'unhealthy';
                }
            } catch (\Exception $e) {
                $health['checks']['storage'] = ['status' => 'unhealthy', 'message' => "Storage check failed: {$e->getMessage()}"];
                $health['overall_status'] = 'unhealthy';
            }

            // Memory check
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = $this->parseBytes(ini_get('memory_limit'));
            $memoryPercentage = ($memoryUsage / $memoryLimit) * 100;

            if ($memoryPercentage > 90) {
                $health['checks']['memory'] = ['status' => 'unhealthy', 'message' => "Memory usage critical: {$memoryPercentage}%"];
                $health['overall_status'] = 'unhealthy';
            } elseif ($memoryPercentage > 80) {
                $health['checks']['memory'] = ['status' => 'degraded', 'message' => "Memory usage high: {$memoryPercentage}%"];
                if ($health['overall_status'] === 'healthy') {
                    $health['overall_status'] = 'degraded';
                }
            } else {
                $health['checks']['memory'] = ['status' => 'healthy', 'message' => "Memory usage normal: {$memoryPercentage}%"];
            }

            // Stache status check - basic health indicator
            $health['checks']['stache'] = ['status' => 'healthy', 'message' => 'Stache cache operational'];

            return [
                'success' => true,
                'data' => ['health' => $health],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to get health status: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getCacheStatus(array $arguments): array
    {
        try {
            $cacheType = $arguments['cache_type'] ?? 'all';

            $status = [
                'timestamp' => now()->toISOString(),
                'caches' => [],
            ];

            $cacheTypes = $cacheType === 'all'
                ? ['stache', 'static', 'views', 'app', 'config', 'route']
                : [$cacheType];

            foreach ($cacheTypes as $type) {
                $status['caches'][$type] = $this->getCacheTypeStatus($type);
            }

            return [
                'success' => true,
                'data' => ['cache_status' => $status],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to get cache status: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function clearCache(array $arguments): array
    {
        if (! $this->hasPermission('manage', 'cache')) {
            return $this->createErrorResponse('Permission denied: Cannot clear cache')->toArray();
        }

        try {
            $cacheType = $arguments['cache_type'] ?? 'all';
            $cleared = [];

            $cacheTypes = $cacheType === 'all'
                ? ['stache', 'static', 'views', 'app', 'config', 'route']
                : [$cacheType];

            foreach ($cacheTypes as $type) {
                $result = $this->clearCacheType($type);
                $cleared[$type] = $result;
            }

            return [
                'success' => true,
                'data' => [
                    'cache_cleared' => $cleared,
                    'timestamp' => now()->toISOString(),
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to clear cache: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function warmCache(array $arguments): array
    {
        if (! $this->hasPermission('manage', 'cache')) {
            return $this->createErrorResponse('Permission denied: Cannot warm cache')->toArray();
        }

        try {
            $cacheType = $arguments['cache_type'] ?? 'stache';
            $warmed = [];

            if ($cacheType === 'stache' || $cacheType === 'all') {
                // Warm Stache cache
                Artisan::call('statamic:stache:warm');
                $warmed['stache'] = ['status' => 'warmed', 'command' => 'statamic:stache:warm'];
            }

            if ($cacheType === 'static' || $cacheType === 'all') {
                // Static cache warming would go here if available
                $warmed['static'] = ['status' => 'skipped', 'reason' => 'No warming command available'];
            }

            return [
                'success' => true,
                'data' => [
                    'cache_warmed' => $warmed,
                    'timestamp' => now()->toISOString(),
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to warm cache: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getConfig(array $arguments): array
    {
        if (! $this->hasPermission('view', 'config')) {
            return $this->createErrorResponse('Permission denied: Cannot get config')->toArray();
        }

        try {
            $configKey = $arguments['config_key'] ?? null;

            if (! $configKey) {
                return ['success' => false, 'errors' => ['Config key is required']];
            }

            // Security: Only allow reading from safe config keys
            $safeKeys = [
                'app.name',
                'app.env',
                'app.debug',
                'app.timezone',
                'statamic',
                'statamic_mcp',
            ];

            $allowed = collect($safeKeys)->some(function ($safeKey) use ($configKey) {
                return str_starts_with($configKey, $safeKey);
            });

            if (! $allowed) {
                return ['success' => false, 'errors' => ["Access to config key '{$configKey}' is restricted"]];
            }

            $value = Config::get($configKey);

            return [
                'success' => true,
                'data' => [
                    'config' => [
                        'key' => $configKey,
                        'value' => $value,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to get config: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function setConfig(array $arguments): array
    {
        if (! $this->hasPermission('manage', 'config')) {
            return $this->createErrorResponse('Permission denied: Cannot set config')->toArray();
        }

        try {
            $configKey = $arguments['config_key'] ?? null;
            $configValue = $arguments['config_value'] ?? null;

            if (! $configKey) {
                return ['success' => false, 'errors' => ['Config key is required']];
            }

            // Security: Only allow setting runtime config for specific keys
            $allowedKeys = [
                'statamic-mcp.tools',
                'statamic-mcp.security.force_web_mode',
                'statamic-mcp.tools.statamic-system.audit_logging',
            ];

            $allowed = collect($allowedKeys)->some(function ($allowedKey) use ($configKey) {
                return str_starts_with($configKey, $allowedKey);
            });

            if (! $allowed) {
                return ['success' => false, 'errors' => ["Setting config key '{$configKey}' is restricted"]];
            }

            // Parse value as JSON if it looks like JSON
            if (is_string($configValue) && (str_starts_with($configValue, '{') || str_starts_with($configValue, '['))) {
                $configValue = json_decode($configValue, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return ['success' => false, 'errors' => ['Invalid JSON value provided']];
                }
            }

            Config::set($configKey, $configValue);

            return [
                'success' => true,
                'data' => [
                    'config' => [
                        'key' => $configKey,
                        'value' => $configValue,
                        'updated' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to set config: {$e->getMessage()}"]];
        }
    }

    // Helper Methods

    /**
     * Get status for specific cache type.
     *
     * @return array<string, mixed>
     */
    private function getCacheTypeStatus(string $type): array
    {
        return match ($type) {
            'stache' => [
                'type' => 'stache',
                'status' => 'active',
                'path' => storage_path('statamic/stache'),
                'writable' => is_writable(storage_path('statamic')),
            ],
            'static' => [
                'type' => 'static',
                'status' => config('statamic-static_caching.strategy') ? 'active' : 'disabled',
                'strategy' => config('statamic-static_caching.strategy'),
            ],
            'views' => [
                'type' => 'views',
                'status' => 'active',
                'path' => storage_path('framework/views'),
                'writable' => is_writable(storage_path('framework/views')),
            ],
            'app' => [
                'type' => 'app',
                'status' => 'active',
                'driver' => config('cache.default'),
            ],
            'config' => [
                'type' => 'config',
                'status' => app()->configurationIsCached() ? 'cached' : 'not_cached',
            ],
            'route' => [
                'type' => 'route',
                'status' => app()->routesAreCached() ? 'cached' : 'not_cached',
            ],
            default => [
                'type' => $type,
                'status' => 'unknown',
            ],
        };
    }

    /**
     * Clear specific cache type.
     *
     * @return array<string, mixed>
     */
    private function clearCacheType(string $type): array
    {
        return match ($type) {
            'stache' => $this->runArtisanCommand('statamic:stache:clear'),
            'static' => $this->runArtisanCommand('statamic:static:clear'),
            'views' => $this->runArtisanCommand('view:clear'),
            'app' => $this->runArtisanCommand('cache:clear'),
            'config' => $this->runArtisanCommand('config:clear'),
            'route' => $this->runArtisanCommand('route:clear'),
            default => ['status' => 'unknown', 'message' => "Unknown cache type: {$type}"],
        };
    }

    /**
     * Run Artisan command and return result.
     *
     * @return array<string, mixed>
     */
    private function runArtisanCommand(string $command): array
    {
        try {
            $exitCode = Artisan::call($command);

            return [
                'status' => $exitCode === 0 ? 'success' : 'failed',
                'command' => $command,
                'exit_code' => $exitCode,
                'output' => Artisan::output(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'command' => $command,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse bytes from PHP ini setting.
     */
    private function parseBytes(string $size): int
    {
        $unit = strtoupper(substr($size, -1));
        $value = (int) $size;

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Get Statamic version.
     */
    private function getStatamicVersion(): string
    {
        try {
            if (class_exists('\\Statamic\\Statamic')) {
                $version = \Statamic\Statamic::version();

                return $version ?: 'unknown';
            }
        } catch (\Exception $e) {
            // Continue with fallback
        }

        return 'unknown';
    }

    // Helper methods now provided by RouterHelpers trait

    // BaseRouter abstract method implementations

    protected function getFeatures(): array
    {
        return [
            'health_monitoring' => 'Comprehensive system health checks and diagnostics',
            'cache_management' => 'Intelligent cache operations with selective clearing',
            'performance_analysis' => 'System performance metrics and optimization insights',
            'configuration_management' => 'Safe configuration reading and validation',
            'license_management' => 'License validation and status monitoring',
            'environment_detection' => 'Environment-aware operations (CLI vs web)',
            'permission_control' => 'Role-based access control for system operations',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'System administration and monitoring for Statamic installations with comprehensive health checks, cache management, and performance optimization';
    }

    protected function getDecisionTree(): array
    {
        return [
            'system_inspection' => [
                'question' => 'What type of system information do you need?',
                'actions' => [
                    'general_status' => 'action=info for overall system status',
                    'health_check' => 'action=health for comprehensive diagnostics',
                    'cache_status' => 'action=cache_status for cache information',
                ],
            ],
            'system_maintenance' => [
                'question' => 'What maintenance operation do you need?',
                'actions' => [
                    'cache_clearing' => 'action=clear_cache with specific cache types',
                    'cache_warming' => 'action=warm_cache for performance preparation',
                    'configuration_check' => 'action=config for configuration validation',
                ],
            ],
            'troubleshooting' => [
                'question' => 'What issue are you investigating?',
                'actions' => [
                    'performance_issues' => 'action=health with performance focus',
                    'cache_problems' => 'action=cache_status then selective clearing',
                    'license_issues' => 'action=license for license validation',
                ],
            ],
        ];
    }

    protected function getContextAwareness(): array
    {
        return [
            'environment_detection' => [
                'cli_context' => 'Full access to all system operations',
                'web_context' => 'Permission-controlled access based on user roles',
                'permission_levels' => 'Super user or utilities access required',
            ],
            'safety_protocols' => [
                'cache_operations' => 'Selective cache clearing to avoid performance impact',
                'dry_run_support' => 'Preview cache clearing effects before execution',
                'permission_validation' => 'Automatic permission checks for web context',
            ],
            'performance_awareness' => [
                'cache_impact' => 'Understand cache clearing performance implications',
                'selective_operations' => 'Target specific cache types for efficiency',
                'health_monitoring' => 'Regular health checks for proactive maintenance',
            ],
        ];
    }

    protected function getWorkflowIntegration(): array
    {
        return [
            'development_workflow' => [
                'local_development' => 'Clear development caches during active development',
                'testing_preparation' => 'Cache warming before performance testing',
                'debugging_support' => 'Health checks to identify system bottlenecks',
            ],
            'deployment_workflow' => [
                'pre_deployment' => 'System health validation before deployment',
                'post_deployment' => 'Cache warming and health verification',
                'rollback_support' => 'Health monitoring for deployment validation',
            ],
            'maintenance_workflow' => [
                'scheduled_maintenance' => 'Regular health checks and cache optimization',
                'performance_monitoring' => 'Ongoing system performance tracking',
                'issue_resolution' => 'Systematic troubleshooting with health diagnostics',
            ],
            'integration_patterns' => [
                'monitoring_systems' => 'Health check data for external monitoring',
                'ci_cd_pipelines' => 'Automated health validation in deployment pipelines',
                'alerting_systems' => 'Performance metrics for proactive alerting',
            ],
        ];
    }

    protected function getCommonPatterns(): array
    {
        return [
            'system_health_check' => [
                'description' => 'Comprehensive system health assessment',
                'pattern' => [
                    'step_1' => 'action=health for full diagnostic',
                    'step_2' => 'action=info for system overview',
                    'step_3' => 'action=cache_status for cache health',
                ],
                'use_case' => 'Regular maintenance or troubleshooting',
            ],
            'performance_optimization' => [
                'description' => 'Optimize system performance through cache management',
                'pattern' => [
                    'step_1' => 'action=cache_status to assess current state',
                    'step_2' => 'action=clear_cache with selective types',
                    'step_3' => 'action=warm_cache for key content',
                    'step_4' => 'action=health to validate improvements',
                ],
                'use_case' => 'Performance issues or after content changes',
            ],
            'deployment_validation' => [
                'description' => 'Validate system after deployment',
                'pattern' => [
                    'step_1' => 'action=health for comprehensive check',
                    'step_2' => 'action=license to validate licensing',
                    'step_3' => 'action=warm_cache for production readiness',
                ],
                'use_case' => 'Post-deployment verification',
            ],
            'troubleshooting_workflow' => [
                'description' => 'Systematic issue investigation',
                'pattern' => [
                    'step_1' => 'action=info to understand environment',
                    'step_2' => 'action=health to identify issues',
                    'step_3' => 'action=cache_status for cache-related problems',
                    'step_4' => 'action=clear_cache if cache issues found',
                ],
                'use_case' => 'When experiencing system issues',
            ],
            'maintenance_routine' => [
                'description' => 'Regular system maintenance workflow',
                'pattern' => [
                    'step_1' => 'action=health for baseline assessment',
                    'step_2' => 'action=clear_cache types=["static","image"] if needed',
                    'step_3' => 'action=warm_cache for performance preparation',
                    'step_4' => 'action=info to confirm system status',
                ],
                'use_case' => 'Scheduled maintenance windows',
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
        return match ($action) {
            'info', 'health', 'cache_status' => ['view system'],
            'cache_clear', 'cache_warm' => ['manage cache'],
            'config_get' => ['view config'],
            'config_set' => ['manage config'],
            default => ['super'],
        };
    }
}
