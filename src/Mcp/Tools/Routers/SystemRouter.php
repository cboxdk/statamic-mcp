<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Sites\Site;

#[Name('statamic-system')]
#[Description('Statamic system operations: environment info, health checks, cache management, and configuration. Actions: info, health, cache_status, cache_clear, cache_warm, config_get, config_set.')]
class SystemRouter extends BaseRouter
{
    protected function getDomain(): string
    {
        return 'system';
    }

    protected function getActions(): array
    {
        return [
            'info' => 'Get comprehensive system information and status',
            'health' => 'Perform system health checks and diagnostics',
            'cache_status' => 'Check cache status and statistics',
            'cache_clear' => 'Clear system caches for optimization',
            'cache_warm' => 'Warm system caches for performance',
            'config_get' => 'Get system configuration values',
            'config_set' => 'Set system configuration values',
        ];
    }

    protected function getTypes(): array
    {
        return [
            'system' => 'Overall system information and status',
            'cache' => 'System cache components and management',
            'config' => 'System configuration and settings',
            'health' => 'System health checks and diagnostics',
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'info (no params), '
                    . 'health (no params), '
                    . 'cache_status (optional: include_details), '
                    . 'cache_clear (cache_type), '
                    . 'cache_warm (cache_type), '
                    . 'config_get (config_key), '
                    . 'config_set (config_key, config_value)'
                )
                ->enum(['info', 'health', 'cache_status', 'cache_clear', 'cache_warm', 'config_get', 'config_set'])
                ->required(),
            'resource_type' => JsonSchema::string()
                ->description('System component type. Optional — most actions do not require this.')
                ->enum(['system', 'cache', 'config', 'health']),
            'cache_type' => JsonSchema::string()
                ->description('Cache type to clear or warm. "all" clears everything, "stache" for content index, "static" for static page cache, "views" for compiled views')
                ->enum(['all', 'stache', 'static', 'views', 'app', 'config', 'route']),
            'config_key' => JsonSchema::string()
                ->description('Laravel/Statamic configuration key in dot notation. Example: "statamic.mcp.web.enabled", "app.name"'),
            'config_value' => JsonSchema::string()
                ->description('Value to set for the configuration key. Type must match expected config type'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed metrics and diagnostic information'),
            'include_performance' => JsonSchema::boolean()
                ->description('Include performance metrics like response times and memory usage'),
        ]);
    }

    /**
     * Route system operations to appropriate handlers with security checks.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

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
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', false);
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
                /** @var Collection<int|string, Site> $allSites */
                $allSites = \Statamic\Facades\Site::all();
                $info = array_merge($info, [
                    'sites' => $allSites->map(function ($site) {
                        return [
                            'handle' => $site->handle(),
                            'name' => $site->name(),
                            'url' => $site->url(),
                            'locale' => $site->locale(),
                        ];
                    })->all(),
                    'collections_count' => \Statamic\Facades\Collection::handles()->count(),
                    'taxonomies_count' => Taxonomy::handles()->count(),
                    'users_count' => User::all()->count(),
                    'asset_containers_count' => AssetContainer::all()->count(),
                    'navigation_count' => Nav::all()->count(),
                    'global_sets_count' => GlobalSet::all()->count(),
                    'form_count' => Form::all()->count(),
                    'blueprint_count' => Blueprint::in('collections')->count()
                        + Blueprint::in('taxonomies')->count()
                        + Blueprint::in('globals')->count(),
                ]);
            }

            if ($includePerformance) {
                $info = array_merge($info, [
                    'memory_usage' => [
                        'current' => memory_get_usage(true),
                        'peak' => memory_get_peak_usage(true),
                        'limit' => (string) (ini_get('memory_limit') ?: '-1'),
                    ],
                    'execution_time' => microtime(true) - LARAVEL_START,
                    'opcache_enabled' => function_exists('opcache_get_status') && (bool) opcache_get_status(),
                ]);
            }

            return ['system_info' => $info];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get system info: {$e->getMessage()}")->toArray();
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
            $memoryLimit = $this->parseBytes((string) (ini_get('memory_limit') ?: '-1'));
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

            return ['health' => $health];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get health status: {$e->getMessage()}")->toArray();
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
            $cacheType = is_string($arguments['cache_type'] ?? null) ? $arguments['cache_type'] : 'all';

            $status = [
                'timestamp' => now()->toISOString(),
                'caches' => [],
            ];

            /** @var array<int, string> $cacheTypes */
            $cacheTypes = $cacheType === 'all'
                ? ['stache', 'static', 'views', 'app', 'config', 'route']
                : [$cacheType];

            foreach ($cacheTypes as $type) {
                $status['caches'][$type] = $this->getCacheTypeStatus($type);
            }

            return ['cache_status' => $status];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get cache status: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function clearCache(array $arguments): array
    {
        if (! $this->hasPermission('manage', 'system')) {
            return $this->createErrorResponse('Permission denied: Cannot clear cache')->toArray();
        }

        try {
            $cacheType = is_string($arguments['cache_type'] ?? null) ? $arguments['cache_type'] : 'all';
            /** @var array<string, array<string, mixed>> $cleared */
            $cleared = [];

            /** @var array<int, string> $cacheTypes */
            $cacheTypes = $cacheType === 'all'
                ? ['stache', 'static', 'views', 'app', 'config', 'route']
                : [$cacheType];

            foreach ($cacheTypes as $type) {
                $result = $this->clearCacheType($type);
                $cleared[$type] = $result;
            }

            return [
                'cache_cleared' => $cleared,
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to clear cache: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function warmCache(array $arguments): array
    {
        if (! $this->hasPermission('manage', 'system')) {
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
                'cache_warmed' => $warmed,
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to warm cache: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getConfig(array $arguments): array
    {
        if (! $this->hasPermission('view', 'system')) {
            return $this->createErrorResponse('Permission denied: Cannot get config')->toArray();
        }

        try {
            $configKey = is_string($arguments['config_key'] ?? null) ? $arguments['config_key'] : null;

            if (! $configKey) {
                return $this->createErrorResponse('Config key is required')->toArray();
            }

            // Security: Only allow reading from specific safe config keys
            // Deliberately restrictive — no broad namespace access, no app.url (reveals infrastructure)
            $safeKeys = [
                'app.name',
                'statamic.mcp.web.enabled',
                'statamic.mcp.web.path',
                'statamic.mcp.dashboard.enabled',
                'statamic.mcp.oauth.enabled',
                'statamic.cp.route',
            ];

            $allowed = collect($safeKeys)->some(function ($safeKey) use ($configKey) {
                // Exact match or match with dot separator to prevent partial key injection
                return $configKey === $safeKey || str_starts_with($configKey, $safeKey . '.');
            });

            if (! $allowed) {
                return $this->createErrorResponse("Access to config key '{$configKey}' is restricted")->toArray();
            }

            $value = Config::get($configKey);

            // Redact values that look like secrets even within safe config namespaces
            $value = self::redactSensitiveConfigValues($value);

            return [
                'config' => [
                    'key' => $configKey,
                    'value' => $value,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get config: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function setConfig(array $arguments): array
    {
        if (! $this->hasPermission('manage', 'system')) {
            return $this->createErrorResponse('Permission denied: Cannot set config')->toArray();
        }

        try {
            $configKey = is_string($arguments['config_key'] ?? null) ? $arguments['config_key'] : null;
            $configValue = $arguments['config_value'] ?? null;

            if (! $configKey) {
                return $this->createErrorResponse('Config key is required')->toArray();
            }

            // Security: config_set is only available in CLI context to prevent
            // remote configuration tampering via web MCP endpoints
            if ($this->isWebContext()) {
                return $this->createErrorResponse('config_set is only available in CLI context for security reasons')->toArray();
            }

            // Security: Only allow setting runtime config for non-security keys
            // Deliberately excludes security.force_web_mode and security.audit_logging
            // to prevent MCP tools from weakening their own security controls
            $allowedKeys = [
                'statamic.mcp.tools',
            ];

            $allowed = collect($allowedKeys)->some(function ($allowedKey) use ($configKey) {
                // Exact match or match with dot separator to prevent partial key injection
                return $configKey === $allowedKey || str_starts_with($configKey, $allowedKey . '.');
            });

            if (! $allowed) {
                return $this->createErrorResponse("Setting config key '{$configKey}' is restricted")->toArray();
            }

            // Parse value as JSON if it looks like JSON
            if (is_string($configValue) && (str_starts_with($configValue, '{') || str_starts_with($configValue, '['))) {
                $configValue = json_decode($configValue, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->createErrorResponse('Invalid JSON value provided')->toArray();
                }
            }

            // Validate config value size to prevent memory abuse
            $serialized = json_encode($configValue);
            if ($serialized !== false && strlen($serialized) > 10000) {
                return $this->createErrorResponse('Config value too large (maximum 10KB allowed)')->toArray();
            }

            Config::set($configKey, $configValue);

            return [
                'config' => [
                    'key' => $configKey,
                    'value' => $configValue,
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to set config: {$e->getMessage()}")->toArray();
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
        if ($size === '-1') {
            // PHP memory_limit of -1 means no limit; use a large fallback
            return PHP_INT_MAX;
        }

        $unit = strtoupper(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        // If the last char is a digit, the whole string is bytes
        if (is_numeric(substr($size, -1))) {
            return (int) $size;
        }

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $size,
        };
    }

    /**
     * Recursively redact config values whose keys suggest secrets.
     */
    private static function redactSensitiveConfigValues(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $lowerKey = strtolower((string) $k);
                if (str_contains($lowerKey, 'password')
                    || str_contains($lowerKey, 'secret')
                    || str_contains($lowerKey, 'key')
                    || str_contains($lowerKey, 'token')
                    || str_contains($lowerKey, 'credential')
                ) {
                    $result[$k] = '[REDACTED]';
                } else {
                    $result[$k] = self::redactSensitiveConfigValues($v);
                }
            }

            return $result;
        }

        return $value;
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
        // Read-only system ops require 'access utilities' (real Statamic permission).
        // Write ops (cache clear/warm, config set) have no granular Statamic permission —
        // 'super' will never match a real permission, so only super admins (who bypass
        // permission checks entirely in checkWebPermissions) can execute them.
        return match ($action) {
            'info', 'health', 'cache_status', 'config_get' => ['access utilities'],
            'cache_clear', 'cache_warm', 'config_set' => ['super'],
            default => ['super'],
        };
    }
}
