<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Caching utilities for MCP tools.
 */
class ToolCache
{
    /**
     * Default cache TTL in seconds (5 minutes).
     */
    private const DEFAULT_TTL = 300;

    /**
     * Cache prefix for tool operations.
     */
    private const CACHE_PREFIX = 'mcp_tool:';

    /**
     * Cache expensive tool discovery operations.
     */
    public static function remember(string $toolName, string $operation, callable $callback, ?int $ttl = null): mixed
    {
        $key = self::generateKey($toolName, $operation);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        ToolLogger::cacheEvent($toolName, 'cache_lookup', $key);

        return Cache::remember($key, $ttl, function () use ($toolName, $callback, $key) {
            ToolLogger::cacheEvent($toolName, 'cache_miss', $key);

            return $callback();
        });
    }

    /**
     * Cache tool discovery results with dependency tracking.
     *
     * @param  array<int, array<string, mixed>>  $result
     * @param  array<string>  $dependencies
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cacheDiscovery(string $toolName, array $result, array $dependencies = [], ?int $ttl = null): array
    {
        $key = self::generateKey($toolName, 'discovery');
        $ttl = $ttl ?? 3600; // 1 hour for discovery operations

        $cachedData = [
            'result' => $result,
            'cached_at' => Carbon::now()->toIso8601String(),
            'dependencies' => $dependencies,
            'statamic_version' => \Statamic\Statamic::version(),
        ];

        Cache::put($key, $cachedData, $ttl);
        ToolLogger::cacheEvent($toolName, 'cache_store', $key, ['ttl' => $ttl]);

        return $result;
    }

    /**
     * Get cached discovery result if valid.
     *
     * @param  array<string>  $dependencies
     *
     * @return array<string, mixed>|null
     */
    public static function getCachedDiscovery(string $toolName, array $dependencies = []): ?array
    {
        $key = self::generateKey($toolName, 'discovery');
        $cached = Cache::get($key);

        if (! $cached || ! is_array($cached)) {
            ToolLogger::cacheEvent($toolName, 'cache_miss', $key);

            return null;
        }

        // Check if dependencies have changed
        if (self::dependenciesChanged($cached['dependencies'] ?? [], $dependencies)) {
            ToolLogger::cacheEvent($toolName, 'cache_invalidated', $key, ['reason' => 'dependencies_changed']);
            self::forget($toolName, 'discovery');

            return null;
        }

        // Check if Statamic version changed
        if (($cached['statamic_version'] ?? '') !== \Statamic\Statamic::version()) {
            ToolLogger::cacheEvent($toolName, 'cache_invalidated', $key, ['reason' => 'version_changed']);
            self::forget($toolName, 'discovery');

            return null;
        }

        ToolLogger::cacheEvent($toolName, 'cache_hit', $key);

        return $cached['result'];
    }

    /**
     * Cache blueprint scanning results with file modification tracking.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string>  $scannedFiles
     *
     * @return array<string, mixed>
     */
    public static function cacheBlueprintScan(string $toolName, array $result, array $scannedFiles, ?int $ttl = null): array
    {
        $key = self::generateKey($toolName, 'blueprint_scan');
        $ttl = $ttl ?? 1800; // 30 minutes

        $fileHashes = [];
        foreach ($scannedFiles as $file) {
            if (file_exists($file)) {
                $fileHashes[$file] = filemtime($file);
            }
        }

        $cachedData = [
            'result' => $result,
            'cached_at' => Carbon::now()->toIso8601String(),
            'file_hashes' => $fileHashes,
        ];

        Cache::put($key, $cachedData, $ttl);
        ToolLogger::cacheEvent($toolName, 'cache_store', $key, ['files_count' => count($scannedFiles)]);

        return $result;
    }

    /**
     * Get cached blueprint scan if files haven't changed.
     *
     * @param  array<string>  $scannedFiles
     *
     * @return array<string, mixed>|null
     */
    public static function getCachedBlueprintScan(string $toolName, array $scannedFiles): ?array
    {
        $key = self::generateKey($toolName, 'blueprint_scan');
        $cached = Cache::get($key);

        if (! $cached || ! is_array($cached)) {
            ToolLogger::cacheEvent($toolName, 'cache_miss', $key);

            return null;
        }

        $cachedHashes = $cached['file_hashes'] ?? [];

        // Check if any files have been modified
        foreach ($scannedFiles as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $currentMtime = filemtime($file);
            $cachedMtime = $cachedHashes[$file] ?? null;

            if ($cachedMtime === null || $currentMtime > $cachedMtime) {
                ToolLogger::cacheEvent($toolName, 'cache_invalidated', $key, [
                    'reason' => 'file_modified',
                    'file' => $file,
                ]);
                self::forget($toolName, 'blueprint_scan');

                return null;
            }
        }

        ToolLogger::cacheEvent($toolName, 'cache_hit', $key);

        return $cached['result'];
    }

    /**
     * Forget cached data for a specific tool and operation.
     */
    public static function forget(string $toolName, string $operation): bool
    {
        $key = self::generateKey($toolName, $operation);
        ToolLogger::cacheEvent($toolName, 'cache_forget', $key);

        return Cache::forget($key);
    }

    /**
     * Clear all cache for a specific tool.
     */
    public static function clearTool(string $toolName): void
    {
        $pattern = self::CACHE_PREFIX . $toolName . ':*';
        ToolLogger::cacheEvent($toolName, 'cache_clear_all', $pattern);

        // This is a simplified approach - in production you'd want a more sophisticated cache tagging system
        $operations = ['discovery', 'blueprint_scan', 'template_scan', 'general'];
        foreach ($operations as $operation) {
            self::forget($toolName, $operation);
        }
    }

    /**
     * Clear all MCP tool caches.
     */
    public static function clearAll(): void
    {
        // In production, implement proper cache tagging
        Cache::flush();
        ToolLogger::cacheEvent('system', 'cache_flush_all', 'all');
    }

    /**
     * Generate cache key for tool operation.
     */
    private static function generateKey(string $toolName, string $operation): string
    {
        return self::CACHE_PREFIX . $toolName . ':' . $operation;
    }

    /**
     * Check if dependencies have changed.
     *
     * @param  array<string>  $cachedDependencies
     * @param  array<string>  $currentDependencies
     */
    private static function dependenciesChanged(array $cachedDependencies, array $currentDependencies): bool
    {
        // Simple comparison - could be enhanced with more sophisticated dependency tracking
        sort($cachedDependencies);
        sort($currentDependencies);

        return $cachedDependencies !== $currentDependencies;
    }
}
