<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

#[Title('Performance Analyzer')]
#[IsReadOnly]
class PerformanceMonitorTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.system.performance-monitor';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Analyze system performance metrics through sample operations, measuring query counts, memory usage, and identifying bottlenecks';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_database_metrics')
            ->description('Include database query analysis and performance metrics')
            ->optional()
            ->boolean('analyze_memory_usage')
            ->description('Analyze memory usage patterns and peak consumption')
            ->optional()
            ->boolean('check_slow_operations')
            ->description('Identify slow operations and potential bottlenecks')
            ->optional()
            ->boolean('monitor_cache_performance')
            ->description('Analyze cache hit rates and effectiveness')
            ->optional()
            ->integer('sample_duration')
            ->description('Duration in seconds to run performance analysis (1-60)')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeDatabaseMetrics = $arguments['include_database_metrics'] ?? true;
        $analyzeMemoryUsage = $arguments['analyze_memory_usage'] ?? true;
        $checkSlowOperations = $arguments['check_slow_operations'] ?? true;
        $monitorCachePerformance = $arguments['monitor_cache_performance'] ?? true;
        $sampleDuration = min(max($arguments['sample_duration'] ?? 5, 1), 60);

        try {
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $monitoring = [
                'monitoring_duration' => $sampleDuration,
                'system_metrics' => [],
                'database_metrics' => [],
                'memory_analysis' => [],
                'slow_operations' => [],
                'cache_performance' => [],
                'bottlenecks' => [],
                'recommendations' => [],
                'timestamp' => now()->toISOString(),
            ];

            // Baseline system metrics
            $monitoring['system_metrics'] = $this->captureSystemMetrics();

            // Enable query logging for database metrics
            if ($includeDatabaseMetrics) {
                \DB::enableQueryLog();
            }

            // Perform test operations to gather performance data
            $testOperations = $this->performTestOperations($sampleDuration);

            // Database metrics
            if ($includeDatabaseMetrics) {
                $monitoring['database_metrics'] = $this->analyzeDatabaseMetrics();
                \DB::disableQueryLog();
            }

            // Memory analysis
            if ($analyzeMemoryUsage) {
                $monitoring['memory_analysis'] = $this->analyzeMemoryUsage($startMemory);
            }

            // Slow operations detection
            if ($checkSlowOperations) {
                $monitoring['slow_operations'] = $this->detectSlowOperations($testOperations);
            }

            // Cache performance
            if ($monitorCachePerformance) {
                $monitoring['cache_performance'] = $this->analyzeCachePerformance();
            }

            // Calculate total execution time
            $totalExecutionTime = microtime(true) - $startTime;
            $monitoring['system_metrics']['total_execution_time'] = round($totalExecutionTime * 1000, 2);

            // Identify bottlenecks
            $monitoring['bottlenecks'] = $this->identifyBottlenecks($monitoring);

            // Generate recommendations
            $monitoring['recommendations'] = $this->generatePerformanceRecommendations($monitoring);

            return [
                'monitoring' => $monitoring,
                'summary' => [
                    'performance_score' => $this->calculatePerformanceScore($monitoring),
                    'execution_time' => $monitoring['system_metrics']['total_execution_time'] . 'ms',
                    'memory_peak' => $this->formatBytes($monitoring['memory_analysis']['peak_usage'] ?? 0),
                    'query_count' => $monitoring['database_metrics']['total_queries'] ?? 0,
                    'bottlenecks_detected' => count($monitoring['bottlenecks']),
                    'optimization_priority' => $this->getOptimizationPriority($monitoring),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to monitor performance: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Capture system metrics.
     *
     * @return array<string, mixed>
     */
    private function captureSystemMetrics(): array
    {
        $loadAverage = sys_getloadavg();

        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'current_memory_usage' => memory_get_usage(true),
            'current_memory_usage_formatted' => $this->formatBytes(memory_get_usage(true)),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'peak_memory_usage_formatted' => $this->formatBytes(memory_get_peak_usage(true)),
            'load_average' => $loadAverage[0] ?? null,
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false,
            'server_load' => $this->getServerLoad(),
        ];
    }

    /**
     * Perform test operations to measure performance.
     *
     *
     * @return array<string, mixed>
     */
    private function performTestOperations(int $duration): array
    {
        $operations = [];
        $startTime = microtime(true);

        // Test collection loading
        $collectionStart = microtime(true);
        try {
            $collections = Collection::all();
            $operations['collection_loading'] = [
                'time' => round((microtime(true) - $collectionStart) * 1000, 2),
                'count' => $collections->count(),
                'status' => 'success',
            ];
        } catch (\Exception $e) {
            $operations['collection_loading'] = [
                'time' => round((microtime(true) - $collectionStart) * 1000, 2),
                'error' => $e->getMessage(),
                'status' => 'error',
            ];
        }

        // Test entry querying (sample)
        $entryStart = microtime(true);
        try {
            $entries = Entry::query()->limit(10)->get();
            $operations['entry_querying'] = [
                'time' => round((microtime(true) - $entryStart) * 1000, 2),
                'count' => $entries->count(),
                'status' => 'success',
            ];
        } catch (\Exception $e) {
            $operations['entry_querying'] = [
                'time' => round((microtime(true) - $entryStart) * 1000, 2),
                'error' => $e->getMessage(),
                'status' => 'error',
            ];
        }

        // Test cache operations
        $cacheStart = microtime(true);
        try {
            $testKey = 'performance_monitor_test_' . time();
            cache()->put($testKey, 'test_value', 60);
            $retrieved = cache()->get($testKey);
            cache()->forget($testKey);

            $operations['cache_operations'] = [
                'time' => round((microtime(true) - $cacheStart) * 1000, 2),
                'operations' => 3, // put, get, forget
                'success' => $retrieved === 'test_value',
                'status' => 'success',
            ];
        } catch (\Exception $e) {
            $operations['cache_operations'] = [
                'time' => round((microtime(true) - $cacheStart) * 1000, 2),
                'error' => $e->getMessage(),
                'status' => 'error',
            ];
        }

        // Test file system operations
        $fileStart = microtime(true);
        try {
            $testFile = storage_path('logs/performance_test_' . time() . '.tmp');
            file_put_contents($testFile, 'performance test');
            $content = file_get_contents($testFile);
            unlink($testFile);

            $operations['filesystem_operations'] = [
                'time' => round((microtime(true) - $fileStart) * 1000, 2),
                'operations' => 3, // write, read, delete
                'success' => $content === 'performance test',
                'status' => 'success',
            ];
        } catch (\Exception $e) {
            $operations['filesystem_operations'] = [
                'time' => round((microtime(true) - $fileStart) * 1000, 2),
                'error' => $e->getMessage(),
                'status' => 'error',
            ];
        }

        $operations['total_test_time'] = round((microtime(true) - $startTime) * 1000, 2);

        return $operations;
    }

    /**
     * Analyze database metrics.
     *
     * @return array<string, mixed>
     */
    private function analyzeDatabaseMetrics(): array
    {
        $queries = \DB::getQueryLog();

        $metrics = [
            'total_queries' => count($queries),
            'query_times' => [],
            'slow_queries' => [],
            'duplicate_queries' => [],
            'query_types' => [],
            'total_time' => 0,
            'average_time' => 0,
            'slowest_query_time' => 0,
        ];

        $queryHashes = [];

        foreach ($queries as $query) {
            $time = $query['time'];
            $metrics['query_times'][] = $time;
            $metrics['total_time'] += $time;

            // Track slowest query
            if ($time > $metrics['slowest_query_time']) {
                $metrics['slowest_query_time'] = $time;
            }

            // Identify slow queries (> 100ms)
            if ($time > 100) {
                $metrics['slow_queries'][] = [
                    'sql' => $query['query'],
                    'time' => $time,
                    'bindings' => $query['bindings'],
                ];
            }

            // Detect duplicate queries
            $queryHash = md5($query['query']);
            if (isset($queryHashes[$queryHash])) {
                $queryHashes[$queryHash]['count']++;
            } else {
                $queryHashes[$queryHash] = [
                    'query' => $query['query'],
                    'count' => 1,
                    'time' => $time,
                ];
            }

            // Categorize query types
            $queryType = strtoupper(trim(explode(' ', trim($query['query']))[0]));
            $metrics['query_types'][$queryType] = ($metrics['query_types'][$queryType] ?? 0) + 1;
        }

        // Find duplicate queries
        foreach ($queryHashes as $hash => $data) {
            if ($data['count'] > 1) {
                $metrics['duplicate_queries'][] = [
                    'query' => substr($data['query'], 0, 100) . '...',
                    'count' => $data['count'],
                    'total_time' => $data['time'] * $data['count'],
                ];
            }
        }

        if (count($queries) > 0) {
            $metrics['average_time'] = round($metrics['total_time'] / count($queries), 2);
        }

        return $metrics;
    }

    /**
     * Analyze memory usage patterns.
     *
     *
     * @return array<string, mixed>
     */
    private function analyzeMemoryUsage(int $startMemory): array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));

        return [
            'start_memory' => $startMemory,
            'start_memory_formatted' => $this->formatBytes($startMemory),
            'current_memory' => $currentMemory,
            'current_memory_formatted' => $this->formatBytes($currentMemory),
            'peak_usage' => $peakMemory,
            'peak_usage_formatted' => $this->formatBytes($peakMemory),
            'memory_increase' => $currentMemory - $startMemory,
            'memory_increase_formatted' => $this->formatBytes($currentMemory - $startMemory),
            'memory_limit' => $memoryLimit,
            'memory_limit_formatted' => ini_get('memory_limit'),
            'memory_usage_percentage' => $memoryLimit > 0 ? round(($peakMemory / $memoryLimit) * 100, 2) : 0,
            'memory_efficiency' => $this->calculateMemoryEfficiency($startMemory, $peakMemory),
        ];
    }

    /**
     * Detect slow operations.
     *
     * @param  array<string, mixed>  $operations
     *
     * @return array<array<string, mixed>>
     */
    private function detectSlowOperations(array $operations): array
    {
        $slowOperations = [];
        $thresholds = [
            'collection_loading' => 500, // 500ms
            'entry_querying' => 200, // 200ms
            'cache_operations' => 50, // 50ms
            'filesystem_operations' => 100, // 100ms
        ];

        foreach ($operations as $operation => $data) {
            if (isset($data['time'], $thresholds[$operation])) {
                if ($data['time'] > $thresholds[$operation]) {
                    $slowOperations[] = [
                        'operation' => $operation,
                        'time' => $data['time'],
                        'threshold' => $thresholds[$operation],
                        'severity' => $this->getSeverity($data['time'], $thresholds[$operation]),
                        'impact' => 'Performance bottleneck detected',
                    ];
                }
            }
        }

        return $slowOperations;
    }

    /**
     * Analyze cache performance.
     *
     * @return array<string, mixed>
     */
    private function analyzeCachePerformance(): array
    {
        $cacheDriver = config('cache.default');

        $performance = [
            'driver' => $cacheDriver,
            'status' => 'unknown',
            'hit_rate' => null,
            'recommendations' => [],
        ];

        try {
            // Test cache performance
            $startTime = microtime(true);
            $testKey = 'performance_monitor_cache_test';
            $testValue = 'cache_performance_test_' . time();

            // Write test
            cache()->put($testKey, $testValue, 60);
            $writeTime = microtime(true) - $startTime;

            // Read test
            $readStart = microtime(true);
            $retrieved = cache()->get($testKey);
            $readTime = microtime(true) - $readStart;

            // Cleanup
            cache()->forget($testKey);

            $performance['write_time'] = round($writeTime * 1000, 2);
            $performance['read_time'] = round($readTime * 1000, 2);
            $performance['success'] = $retrieved === $testValue;
            $performance['status'] = $retrieved === $testValue ? 'working' : 'issues_detected';

            // Recommendations based on driver and performance
            if ($cacheDriver === 'file') {
                $performance['recommendations'][] = 'Consider using Redis or Memcached for better performance';
            }

            if ($performance['write_time'] > 10) {
                $performance['recommendations'][] = 'Cache write operations are slow';
            }

            if ($performance['read_time'] > 5) {
                $performance['recommendations'][] = 'Cache read operations are slow';
            }

        } catch (\Exception $e) {
            $performance['status'] = 'error';
            $performance['error'] = $e->getMessage();
        }

        return $performance;
    }

    /**
     * Identify system bottlenecks.
     *
     * @param  array<string, mixed>  $monitoring
     *
     * @return array<array<string, mixed>>
     */
    private function identifyBottlenecks(array $monitoring): array
    {
        $bottlenecks = [];

        // Memory bottleneck
        $memoryUsage = $monitoring['memory_analysis']['memory_usage_percentage'] ?? 0;
        if ($memoryUsage > 80) {
            $bottlenecks[] = [
                'type' => 'memory',
                'severity' => $memoryUsage > 90 ? 'critical' : 'warning',
                'description' => "High memory usage: {$memoryUsage}%",
                'recommendation' => 'Consider increasing memory limit or optimizing memory usage',
            ];
        }

        // Database bottleneck
        $slowQueries = count($monitoring['database_metrics']['slow_queries'] ?? []);
        if ($slowQueries > 0) {
            $bottlenecks[] = [
                'type' => 'database',
                'severity' => $slowQueries > 5 ? 'critical' : 'warning',
                'description' => "{$slowQueries} slow database queries detected",
                'recommendation' => 'Optimize slow queries and consider adding database indexes',
            ];
        }

        // Duplicate queries bottleneck
        $duplicateQueries = count($monitoring['database_metrics']['duplicate_queries'] ?? []);
        if ($duplicateQueries > 3) {
            $bottlenecks[] = [
                'type' => 'database',
                'severity' => 'warning',
                'description' => "{$duplicateQueries} duplicate queries detected",
                'recommendation' => 'Implement eager loading to reduce duplicate queries',
            ];
        }

        // Slow operations bottleneck
        foreach ($monitoring['slow_operations'] as $operation) {
            $bottlenecks[] = [
                'type' => 'performance',
                'severity' => $operation['severity'],
                'description' => "Slow {$operation['operation']}: {$operation['time']}ms",
                'recommendation' => $this->getOperationRecommendation($operation['operation']),
            ];
        }

        return $bottlenecks;
    }

    /**
     * Generate performance recommendations.
     *
     * @param  array<string, mixed>  $monitoring
     *
     * @return array<string>
     */
    private function generatePerformanceRecommendations(array $monitoring): array
    {
        $recommendations = [];

        // Memory recommendations
        $memoryUsage = $monitoring['memory_analysis']['memory_usage_percentage'] ?? 0;
        if ($memoryUsage > 70) {
            $recommendations[] = 'Monitor memory usage closely - consider memory optimization';
        }

        // Database recommendations
        $totalQueries = $monitoring['database_metrics']['total_queries'] ?? 0;
        if ($totalQueries > 50) {
            $recommendations[] = 'High query count detected - consider implementing query optimization';
        }

        $averageQueryTime = $monitoring['database_metrics']['average_time'] ?? 0;
        if ($averageQueryTime > 10) {
            $recommendations[] = 'Database queries are slower than optimal - review query performance';
        }

        // Cache recommendations
        $cacheDriver = $monitoring['cache_performance']['driver'] ?? null;
        if ($cacheDriver === 'file') {
            $recommendations[] = 'Consider upgrading from file cache to Redis or Memcached';
        }

        // General recommendations
        if (empty($recommendations)) {
            $recommendations[] = 'System performance appears optimal';
        }

        return $recommendations;
    }

    /**
     * Get server load information.
     *
     * @return array<string, mixed>|null
     */
    private function getServerLoad(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();

            if ($load === false) {
                return null;
            }

            return [
                '1_min' => $load[0],
                '5_min' => $load[1],
                '15_min' => $load[2],
            ];
        }

        return null;
    }

    /**
     * Parse memory limit string to bytes.
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0; // Unlimited
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        switch ($unit) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Calculate memory efficiency score.
     */
    private function calculateMemoryEfficiency(int $startMemory, int $peakMemory): string
    {
        $increase = $peakMemory - $startMemory;

        if ($increase < 1024 * 1024) { // < 1MB
            return 'excellent';
        } elseif ($increase < 5 * 1024 * 1024) { // < 5MB
            return 'good';
        } elseif ($increase < 10 * 1024 * 1024) { // < 10MB
            return 'moderate';
        } else {
            return 'poor';
        }
    }

    /**
     * Get severity level for slow operations.
     */
    private function getSeverity(float $time, int $threshold): string
    {
        $ratio = $time / $threshold;

        if ($ratio > 3) {
            return 'critical';
        } elseif ($ratio > 2) {
            return 'high';
        } else {
            return 'medium';
        }
    }

    /**
     * Get recommendation for slow operation.
     */
    private function getOperationRecommendation(string $operation): string
    {
        $recommendations = [
            'collection_loading' => 'Consider caching collection metadata or optimizing collection configuration',
            'entry_querying' => 'Optimize entry queries with proper indexing and eager loading',
            'cache_operations' => 'Check cache driver performance and connection',
            'filesystem_operations' => 'Verify disk performance and available space',
        ];

        return $recommendations[$operation] ?? 'Investigate and optimize this operation';
    }

    /**
     * Calculate overall performance score.
     *
     * @param  array<string, mixed>  $monitoring
     */
    private function calculatePerformanceScore(array $monitoring): int
    {
        $score = 100;

        // Deduct for slow operations
        $score -= count($monitoring['slow_operations']) * 10;

        // Deduct for bottlenecks
        foreach ($monitoring['bottlenecks'] as $bottleneck) {
            $deduction = match ($bottleneck['severity']) {
                'critical' => 20,
                'warning' => 10,
                default => 5,
            };
            $score -= $deduction;
        }

        // Deduct for high memory usage
        $memoryUsage = $monitoring['memory_analysis']['memory_usage_percentage'] ?? 0;
        if ($memoryUsage > 80) {
            $score -= 15;
        } elseif ($memoryUsage > 60) {
            $score -= 10;
        }

        // Deduct for slow queries
        $slowQueries = count($monitoring['database_metrics']['slow_queries'] ?? []);
        $score -= $slowQueries * 5;

        return max(0, $score);
    }

    /**
     * Get optimization priority based on monitoring results.
     *
     * @param  array<string, mixed>  $monitoring
     */
    private function getOptimizationPriority(array $monitoring): string
    {
        $criticalBottlenecks = count(array_filter(
            $monitoring['bottlenecks'],
            fn ($bottleneck) => $bottleneck['severity'] === 'critical'
        ));

        if ($criticalBottlenecks > 0) {
            return 'immediate';
        }

        $score = $this->calculatePerformanceScore($monitoring);

        if ($score < 70) {
            return 'high';
        } elseif ($score < 85) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
