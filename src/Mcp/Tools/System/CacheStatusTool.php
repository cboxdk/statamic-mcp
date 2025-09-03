<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Stache;

#[Title('Get Cache Status')]
#[IsReadOnly]
class CacheStatusTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.system.cache.status';
    }

    protected function getToolDescription(): string
    {
        return 'Get status and information about Statamic caches';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_details')
            ->description('Include detailed cache information (default: false)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeDetails = $arguments['include_details'] ?? false;

        $status = [
            'stache' => $this->getStacheStatus($includeDetails),
            'static' => $this->getStaticCacheStatus($includeDetails),
            'application' => $this->getApplicationCacheStatus($includeDetails),
            'views' => $this->getViewCacheStatus($includeDetails),
            'images' => $this->getImageCacheStatus($includeDetails),
        ];

        return [
            'cache_status' => $status,
            'overall_health' => $this->calculateOverallHealth($status),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getStacheStatus(bool $includeDetails): array
    {
        $stache = Stache::instance();

        $status = [
            'enabled' => true,
            'stores' => count($stache->stores()),
        ];

        if ($includeDetails) {
            $stores = $stache->stores();
            $storeDetails = [];
            foreach ($stores as $key => $store) {
                $storeDetails[$key] = [
                    'class' => get_class($store),
                    'directory' => is_object($store) && method_exists($store, 'directory') ? $store->directory() : null,
                ];
            }
            $status['store_details'] = $storeDetails;
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function getStaticCacheStatus(bool $includeDetails): array
    {
        $enabled = config('statamic.static_caching.strategy') !== null;

        $status = [
            'enabled' => $enabled,
            'strategy' => config('statamic.static_caching.strategy'),
        ];

        if ($includeDetails && $enabled) {
            $status['config'] = [
                'base_url' => config('statamic.static_caching.base_url'),
                'exclude' => config('statamic.static_caching.exclude'),
                'ignore_query_strings' => config('statamic.static_caching.ignore_query_strings'),
            ];
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function getApplicationCacheStatus(bool $includeDetails): array
    {
        $status = [
            'enabled' => config('cache.default') !== 'array',
            'driver' => config('cache.default'),
        ];

        if ($includeDetails) {
            $status['stores'] = array_keys(config('cache.stores', []));
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function getViewCacheStatus(bool $includeDetails): array
    {
        $cachePath = config('view.compiled');
        $enabled = ! empty($cachePath);

        $status = [
            'enabled' => $enabled,
            'path' => $cachePath,
        ];

        if ($includeDetails && $enabled && is_dir($cachePath)) {
            $files = glob($cachePath . '/*');
            $status['cached_files'] = $files !== false ? count($files) : 0;
            $status['size'] = $this->getDirectorySize($cachePath);
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function getImageCacheStatus(bool $includeDetails): array
    {
        $glideEnabled = config('statamic.assets.image_manipulation.driver') === 'gd' || config('statamic.assets.image_manipulation.driver') === 'imagick';

        $status = [
            'glide_enabled' => $glideEnabled,
            'driver' => config('statamic.assets.image_manipulation.driver'),
        ];

        if ($includeDetails) {
            $status['cache_path'] = config('statamic.assets.image_manipulation.cache');
        }

        return $status;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cacheStatus
     */
    private function calculateOverallHealth(array $cacheStatus): string
    {
        $healthy = collect($cacheStatus)
            ->filter(fn ($status) => $status['enabled'] ?? false)
            ->count();

        $total = count($cacheStatus);
        $percentage = $total > 0 ? ($healthy / $total) * 100 : 0;

        return match (true) {
            $percentage >= 80 => 'excellent',
            $percentage >= 60 => 'good',
            $percentage >= 40 => 'fair',
            default => 'poor',
        };
    }

    private function getDirectorySize(string $directory): string
    {
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return $this->formatBytes($bytes);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.1f %s', $bytes / (1024 ** $factor), $units[$factor] ?? 'GB');
    }
}
