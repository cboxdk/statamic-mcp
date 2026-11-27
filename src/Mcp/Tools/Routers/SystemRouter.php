<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Mcp\Support\ContentSearch;
use Cboxdk\StatamicMcp\Mcp\Support\StaticCacheMaintenance;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Sites\Site;

#[Name('statamic-system')]
#[Title('Statamic System')]
#[Description('Statamic system: site information, health checks, cache state and cache maintenance, and MCP configuration.')]
class SystemRouter extends BaseRouter
{
    protected function getDomain(): string
    {
        return 'system';
    }

    protected function actions(): array
    {
        return [
            RouterAction::read('info', 'System information: sites, content counts and environment', optional: ['include_details', 'include_performance']),
            RouterAction::read('health', 'Run the health checks and report what they found', optional: []),
            RouterAction::read('cache_status', 'Report the state of each cache, or of one', optional: ['cache_type']),
            RouterAction::admin('cache_clear', 'Clear a cache; cache_type defaults to all', optional: ['cache_type']),
            RouterAction::admin('cache_warm', 'Warm stache, queue static pages, or both (all). Defaults to stache. Static warming requires an asynchronous queue.', optional: ['cache_type']),
            RouterAction::admin('invalidate_urls', 'Invalidate specific static-cache URLs within configured sites.', needs: ['urls']),
            RouterAction::read('config_get', 'Read an MCP configuration value', needs: ['config_key']),
            RouterAction::read('search', 'Search a configured index for readable entries, terms and assets. Domains with protected fields are omitted to prevent search-value leaks.', needs: ['handle', 'query'], optional: ['site', 'limit', 'cursor']),
            RouterAction::admin('reindex', 'Rebuild a configured search index; native queues may finish later. Requires approval by default.', needs: ['handle'], optional: ['site']),
        ];
    }

    public function getTypes(): array
    {
        return [];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'cache_type' => JsonSchema::string()
                ->description('Cache type: stache, static pages, glide images, views, app, config or route. cache_warm supports only stache, static and all.')
                ->enum(['all', 'stache', 'static', 'glide', 'views', 'app', 'config', 'route']),
            'urls' => JsonSchema::array()->items(JsonSchema::string())->description('Absolute site URLs to invalidate. No wildcards, credentials or dot segments. Maximum 500.'),
            'config_key' => JsonSchema::string()
                ->description('Laravel/Statamic configuration key in dot notation. Example: "statamic.mcp.web.enabled", "app.name"'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed metrics and diagnostic information'),
            'include_performance' => JsonSchema::boolean()
                ->description('Include performance metrics like response times and memory usage'),
            'handle' => JsonSchema::string()->description('Configured Statamic search index handle.'),
            'query' => JsonSchema::string()->description('Text to search for.')->min(1)->max(1000),
            'site' => JsonSchema::string()->description('Restrict search results or index rebuild to this site.'),
            'limit' => JsonSchema::integer()->description('Maximum search results per page, from 1 to 500.')->min(1)->max(500),
            'cursor' => JsonSchema::string()->description('Opaque next_cursor returned by the previous search page.'),
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

        return match ($action) {
            'info' => $this->getSystemInfo($arguments),
            'health' => $this->getHealthStatus($arguments),
            'cache_status' => $this->getCacheStatus($arguments),
            'cache_clear' => $this->clearCache($arguments),
            'cache_warm' => $this->warmCache($arguments),
            'invalidate_urls' => $this->invalidateUrls($arguments),
            'config_get' => $this->getConfig($arguments),
            'search' => $this->search($arguments),
            'reindex' => $this->reindex($arguments),
            default => $this->createErrorResponse("Unknown system action: {$action}")->toArray(),
        };
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function search(array $arguments): array
    {
        $items = app(ContentSearch::class)->search($this->getStringArgument($arguments, 'handle'),
            isset($arguments['site']) ? $this->getStringArgument($arguments, 'site') : null,
            $this->getStringArgument($arguments, 'query'));
        ['limit' => $limit, 'offset' => $offset] = $this->getPaginationArgs($arguments);

        return $this->buildPage(array_slice($items, $offset, $limit), count($items), $offset, $arguments);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function reindex(array $arguments): array
    {
        $indexes = app(ContentSearch::class)->indexes($this->getStringArgument($arguments, 'handle'),
            isset($arguments['site']) ? $this->getStringArgument($arguments, 'site') : null);
        $names = [];
        foreach ($indexes as $index) {
            $this->persistStatamic(fn () => $index->update(), []);
            $names[] = $index->name();
        }

        return ['indexes' => $names, 'status' => 'submitted'];
    }

    protected function writesReachOutside(): bool
    {
        // The operator may configure a hosted search driver such as Algolia.
        return true;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getSystemInfo(array $arguments): array
    {
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', false);
        $includePerformance = $this->getBooleanArgument($arguments, 'include_performance', false);
        $info = [
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
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getHealthStatus(array $arguments): array
    {
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
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getCacheStatus(array $arguments): array
    {
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
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function clearCache(array $arguments): array
    {
        if (! $this->hasPermission('manage', 'system')) {
            return $this->createErrorResponse('Permission denied: Cannot clear cache', code: ErrorCode::Forbidden)->toArray();
        }
        $cacheType = is_string($arguments['cache_type'] ?? null) ? $arguments['cache_type'] : 'all';
        /** @var array<string, array<string, mixed>> $cleared */
        $cleared = [];
        /** @var array<int, string> $cacheTypes */
        $cacheTypes = $cacheType === 'all'
            ? ['stache', 'static', 'glide', 'views', 'app', 'config', 'route']
            : [$cacheType];
        foreach ($cacheTypes as $type) {
            $result = $this->clearCacheType($type);
            $cleared[$type] = $result;
        }

        return [
            'cache_cleared' => $cleared,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function warmCache(array $arguments): array
    {
        if (! $this->hasPermission('manage', 'system')) {
            return $this->createErrorResponse('Permission denied: Cannot warm cache', code: ErrorCode::Forbidden)->toArray();
        }
        $cacheType = $arguments['cache_type'] ?? 'stache';
        if (! in_array($cacheType, ['stache', 'static', 'all'], true)) {
            throw new ToolFailure(ErrorCode::Validation, 'Warming supports stache, static or all.', 'cache_type');
        }
        $warmed = [];
        if ($cacheType === 'static' || $cacheType === 'all') {
            $maintenance = app(StaticCacheMaintenance::class);
            $urls = $maintenance->warmableUrls();
            $this->persistStatamic(fn () => $maintenance->queueWarm($urls), []);
            $warmed['static'] = ['status' => 'queued', 'urls' => count($urls)];
        }
        if ($cacheType === 'stache' || $cacheType === 'all') {
            $this->persistStatamic(fn () => Artisan::call('statamic:stache:warm'), []);
            $warmed['stache'] = ['status' => 'warmed', 'command' => 'statamic:stache:warm'];
        }

        return [
            'cache_warmed' => $warmed,
            'timestamp' => now()->toISOString(),
        ];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function invalidateUrls(array $arguments): array
    {
        Validator::make($arguments, ['urls' => ['required', 'array', 'list', 'min:1', 'max:500'], 'urls.*' => ['required', 'string']])->validate();
        $urls = is_array($arguments['urls']) ? array_values(array_filter($arguments['urls'], is_string(...))) : [];
        $this->persistStatamic(fn () => app(StaticCacheMaintenance::class)->invalidate($urls), []);

        return ['urls' => array_values(array_unique($urls)), 'status' => 'invalidated'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getConfig(array $arguments): array
    {
        if (! $this->hasPermission('view', 'system')) {
            return $this->createErrorResponse('Permission denied: Cannot get config', code: ErrorCode::Forbidden)->toArray();
        }
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
            return $this->createErrorResponse("Access to config key '{$configKey}' is restricted", code: ErrorCode::Forbidden, field: 'config_key')->toArray();
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
            'glide' => $this->runArtisanCommand('statamic:glide:clear'),
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
            return PHP_INT_MAX;
        }

        return (int) ini_parse_quantity($size);
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
            'info', 'health', 'cache_status', 'config_get', 'search' => ['access utilities'],
            'cache_clear', 'cache_warm', 'reindex' => ['super'],
            default => ['super'],
        };
    }
}
