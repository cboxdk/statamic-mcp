<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Stache;

#[Title('System Health Check')]
#[IsReadOnly]
class SystemHealthCheckTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.system.health-check';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Comprehensive system health check including performance, security, and configuration analysis';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_performance_metrics')
            ->description('Include detailed performance and memory usage metrics')
            ->optional()
            ->boolean('check_security_configuration')
            ->description('Analyze security-related configuration and settings')
            ->optional()
            ->boolean('validate_file_permissions')
            ->description('Check file and directory permissions')
            ->optional()
            ->boolean('analyze_cache_status')
            ->description('Analyze cache configuration and health')
            ->optional()
            ->boolean('check_dependencies')
            ->description('Verify required PHP extensions and Composer dependencies')
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
        $includePerformance = $arguments['include_performance_metrics'] ?? true;
        $checkSecurity = $arguments['check_security_configuration'] ?? true;
        $validatePermissions = $arguments['validate_file_permissions'] ?? true;
        $analyzeCache = $arguments['analyze_cache_status'] ?? true;
        $checkDependencies = $arguments['check_dependencies'] ?? true;

        try {
            $healthCheck = [
                'overall_status' => 'healthy',
                'checks' => [],
                'warnings' => [],
                'errors' => [],
                'recommendations' => [],
                'system_info' => $this->getSystemInfo(),
                'timestamp' => now()->toISOString(),
            ];

            // Core system checks
            $healthCheck['checks']['core'] = $this->performCoreChecks();

            // Performance metrics
            if ($includePerformance) {
                $healthCheck['checks']['performance'] = $this->checkPerformanceMetrics();
            }

            // Security configuration
            if ($checkSecurity) {
                $healthCheck['checks']['security'] = $this->checkSecurityConfiguration();
            }

            // File permissions
            if ($validatePermissions) {
                $healthCheck['checks']['permissions'] = $this->checkFilePermissions();
            }

            // Cache status
            if ($analyzeCache) {
                $healthCheck['checks']['cache'] = $this->analyzeCacheStatus();
            }

            // Dependencies
            if ($checkDependencies) {
                $healthCheck['checks']['dependencies'] = $this->checkDependencies();
            }

            // Compile warnings and errors
            foreach ($healthCheck['checks'] as $category => $checks) {
                if (isset($checks['warnings'])) {
                    $healthCheck['warnings'] = array_merge($healthCheck['warnings'], $checks['warnings']);
                }
                if (isset($checks['errors'])) {
                    $healthCheck['errors'] = array_merge($healthCheck['errors'], $checks['errors']);
                }
            }

            // Determine overall status
            if (! empty($healthCheck['errors'])) {
                $healthCheck['overall_status'] = 'critical';
            } elseif (count($healthCheck['warnings']) > 5) {
                $healthCheck['overall_status'] = 'warning';
            } elseif (count($healthCheck['warnings']) > 0) {
                $healthCheck['overall_status'] = 'needs_attention';
            }

            // Generate recommendations
            $healthCheck['recommendations'] = $this->generateRecommendations($healthCheck);

            return [
                'health_check' => $healthCheck,
                'summary' => [
                    'status' => $healthCheck['overall_status'],
                    'error_count' => count($healthCheck['errors']),
                    'warning_count' => count($healthCheck['warnings']),
                    'checks_performed' => count($healthCheck['checks']),
                    'overall_score' => $this->calculateHealthScore($healthCheck),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to perform health check: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Get basic system information.
     *
     * @return array<string, mixed>
     */
    private function getSystemInfo(): array
    {
        return [
            'laravel_version' => app()->version(),
            'statamic_version' => \Statamic\Statamic::version(),
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'timezone' => config('app.timezone'),
            'disk_space' => $this->getDiskSpace(),
        ];
    }

    /**
     * Perform core system checks.
     *
     * @return array<string, mixed>
     */
    private function performCoreChecks(): array
    {
        $checks = [
            'status' => 'healthy',
            'results' => [],
            'warnings' => [],
            'errors' => [],
        ];

        // Check if Statamic is properly installed
        try {
            \Statamic\Statamic::version();
            $checks['results']['statamic_installation'] = 'OK';
        } catch (\Exception $e) {
            $checks['errors'][] = 'Statamic installation issue: ' . $e->getMessage();
            $checks['status'] = 'critical';
        }

        // Check database connection
        try {
            \DB::connection()->getPdo();
            $checks['results']['database_connection'] = 'OK';
        } catch (\Exception $e) {
            $checks['errors'][] = 'Database connection failed: ' . $e->getMessage();
            $checks['status'] = 'critical';
        }

        // Check storage directories
        $storageDirectories = [
            'logs' => storage_path('logs'),
            'cache' => storage_path('framework/cache'),
            'sessions' => storage_path('framework/sessions'),
            'views' => storage_path('framework/views'),
        ];

        foreach ($storageDirectories as $name => $path) {
            if (! is_dir($path)) {
                $checks['errors'][] = "Storage directory missing: {$name} ({$path})";
                $checks['status'] = 'critical';
            } elseif (! is_writable($path)) {
                $checks['errors'][] = "Storage directory not writable: {$name} ({$path})";
                $checks['status'] = 'critical';
            } else {
                $checks['results']['storage_' . $name] = 'OK';
            }
        }

        // Check queue configuration
        $queueDriver = config('queue.default');
        if ($queueDriver === 'sync' && app()->environment('production')) {
            $checks['warnings'][] = 'Queue driver is set to sync in production - consider using a proper queue driver';
        }
        $checks['results']['queue_driver'] = $queueDriver;

        return $checks;
    }

    /**
     * Check performance metrics.
     *
     * @return array<string, mixed>
     */
    private function checkPerformanceMetrics(): array
    {
        $checks = [
            'status' => 'healthy',
            'metrics' => [],
            'warnings' => [],
            'errors' => [],
        ];

        // Memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryPercentage = $memoryLimit > 0 ? ($memoryUsage / $memoryLimit) * 100 : 0;

        $checks['metrics']['memory_usage'] = [
            'current' => $this->formatBytes($memoryUsage),
            'limit' => ini_get('memory_limit'),
            'percentage' => round($memoryPercentage, 2),
        ];

        if ($memoryPercentage > 80) {
            $checks['warnings'][] = 'High memory usage: ' . round($memoryPercentage, 1) . '%';
        }

        // Execution time
        $executionTime = microtime(true) - LARAVEL_START;
        $checks['metrics']['execution_time'] = round($executionTime * 1000, 2) . 'ms';

        if ($executionTime > 5) {
            $checks['warnings'][] = 'Slow execution time: ' . round($executionTime, 2) . 's';
        }

        // Opcache status
        if (function_exists('opcache_get_status')) {
            $opcacheStatus = opcache_get_status();
            $checks['metrics']['opcache'] = [
                'enabled' => $opcacheStatus !== false,
                'hit_rate' => $opcacheStatus ? round($opcacheStatus['opcache_statistics']['opcache_hit_rate'], 2) : 0,
            ];

            if (! $opcacheStatus) {
                $checks['warnings'][] = 'OPcache is not enabled - consider enabling for better performance';
            } elseif ($opcacheStatus['opcache_statistics']['opcache_hit_rate'] < 90) {
                $checks['warnings'][] = 'Low OPcache hit rate: ' . round($opcacheStatus['opcache_statistics']['opcache_hit_rate'], 1) . '%';
            }
        } else {
            $checks['warnings'][] = 'OPcache extension not available';
        }

        return $checks;
    }

    /**
     * Check security configuration.
     *
     * @return array<string, mixed>
     */
    private function checkSecurityConfiguration(): array
    {
        $checks = [
            'status' => 'secure',
            'results' => [],
            'warnings' => [],
            'errors' => [],
        ];

        // Debug mode in production
        if (config('app.debug') && app()->environment('production')) {
            $checks['errors'][] = 'Debug mode is enabled in production - this exposes sensitive information';
            $checks['status'] = 'critical';
        }
        $checks['results']['debug_mode'] = config('app.debug') ? 'enabled' : 'disabled';

        // APP_KEY
        if (empty(config('app.key'))) {
            $checks['errors'][] = 'Application key is not set - run php artisan key:generate';
            $checks['status'] = 'critical';
        } else {
            $checks['results']['app_key'] = 'configured';
        }

        // HTTPS configuration
        $isHttps = request()->isSecure() || config('app.url', '')->startsWith('https://');
        $checks['results']['https'] = $isHttps ? 'enabled' : 'disabled';

        if (! $isHttps && app()->environment('production')) {
            $checks['warnings'][] = 'HTTPS is not configured - consider enabling SSL/TLS for security';
        }

        // Session security
        $sessionDriver = config('session.driver');
        $checks['results']['session_driver'] = $sessionDriver;

        if ($sessionDriver === 'file' && app()->environment('production')) {
            $checks['warnings'][] = 'File session driver in production - consider using database or Redis';
        }

        // CSRF protection (skip in testing environment)
        $checks['results']['csrf_protection'] = app()->environment('testing') ? 'skipped' : 'enabled';

        if (! app()->environment('testing')) {
            $checks['results']['csrf_protection'] = 'enabled';
        }

        return $checks;
    }

    /**
     * Check file permissions.
     *
     * @return array<string, mixed>
     */
    private function checkFilePermissions(): array
    {
        $checks = [
            'status' => 'secure',
            'results' => [],
            'warnings' => [],
            'errors' => [],
        ];

        $criticalPaths = [
            'storage' => storage_path(),
            'bootstrap_cache' => function_exists('bootstrap_path') ? bootstrap_path('cache') : base_path('bootstrap/cache'),
            'config_cache' => config_path(),
            'public' => public_path(),
        ];

        foreach ($criticalPaths as $name => $path) {
            if (! file_exists($path)) {
                $checks['errors'][] = "Critical path does not exist: {$name} ({$path})";
                $checks['status'] = 'critical';
                continue;
            }

            $perms = fileperms($path);
            $octalPerms = substr(sprintf('%o', $perms), -4);

            $checks['results'][$name] = [
                'path' => $path,
                'permissions' => $octalPerms,
                'writable' => is_writable($path),
                'readable' => is_readable($path),
            ];

            // Check for overly permissive permissions
            if ($name !== 'public' && ($perms & 0x0004) || ($perms & 0x0002)) {
                $checks['warnings'][] = "Potentially insecure permissions on {$name}: {$octalPerms}";
            }

            // Check if writable when it should be
            if (in_array($name, ['storage', 'bootstrap_cache']) && ! is_writable($path)) {
                $checks['errors'][] = "Path should be writable but isn't: {$name} ({$path})";
                $checks['status'] = 'critical';
            }
        }

        return $checks;
    }

    /**
     * Analyze cache status.
     *
     * @return array<string, mixed>
     */
    private function analyzeCacheStatus(): array
    {
        $checks = [
            'status' => 'optimal',
            'caches' => [],
            'warnings' => [],
            'errors' => [],
        ];

        // Config cache
        $configCachePath = function_exists('bootstrap_path') ? bootstrap_path('cache/config.php') : base_path('bootstrap/cache/config.php');
        $configCached = file_exists($configCachePath);
        $checks['caches']['config'] = $configCached ? 'cached' : 'not_cached';

        if (! $configCached && app()->environment('production')) {
            $checks['warnings'][] = 'Configuration not cached in production - run php artisan config:cache';
        }

        // Route cache
        $routesCachePath = function_exists('bootstrap_path') ? bootstrap_path('cache/routes-v7.php') : base_path('bootstrap/cache/routes-v7.php');
        $routesCached = file_exists($routesCachePath);
        $checks['caches']['routes'] = $routesCached ? 'cached' : 'not_cached';

        if (! $routesCached && app()->environment('production')) {
            $checks['warnings'][] = 'Routes not cached in production - run php artisan route:cache';
        }

        // View cache
        $viewCacheDir = storage_path('framework/views');
        $viewFiles = glob($viewCacheDir . '/*.php');
        $viewsCached = is_dir($viewCacheDir) && is_array($viewFiles) && count($viewFiles) > 0;
        $checks['caches']['views'] = $viewsCached ? 'cached' : 'not_cached';

        // Stache status (skip if method doesn't exist)
        try {
            if (method_exists(Stache::class, 'isWarmedUp')) {
                $stacheStatus = Stache::isWarmedUp();
                $checks['caches']['stache'] = $stacheStatus ? 'warmed' : 'cold';

                if (! $stacheStatus) {
                    $checks['warnings'][] = 'Stache is not warmed up - consider running php artisan statamic:stache:warm';
                }
            } else {
                $checks['caches']['stache'] = 'unknown';
                $checks['info'][] = 'Stache status check not available';
            }
        } catch (\Exception $e) {
            $checks['errors'][] = 'Could not check Stache status: ' . $e->getMessage();
        }

        // Cache driver
        $cacheDriver = config('cache.default');
        $checks['caches']['driver'] = $cacheDriver;

        if ($cacheDriver === 'file' && app()->environment('production')) {
            $checks['warnings'][] = 'Using file cache driver in production - consider Redis or Memcached for better performance';
        }

        return $checks;
    }

    /**
     * Check dependencies.
     *
     * @return array<string, mixed>
     */
    private function checkDependencies(): array
    {
        $checks = [
            'status' => 'satisfied',
            'php_extensions' => [],
            'composer_packages' => [],
            'warnings' => [],
            'errors' => [],
        ];

        // Required PHP extensions
        $requiredExtensions = [
            'bcmath' => 'BCMath',
            'ctype' => 'Ctype',
            'fileinfo' => 'Fileinfo',
            'json' => 'JSON',
            'mbstring' => 'Mbstring',
            'openssl' => 'OpenSSL',
            'pdo' => 'PDO',
            'tokenizer' => 'Tokenizer',
            'xml' => 'XML',
            'gd' => 'GD',
            'curl' => 'cURL',
        ];

        foreach ($requiredExtensions as $extension => $name) {
            $loaded = extension_loaded($extension);
            $checks['php_extensions'][$extension] = $loaded ? 'loaded' : 'missing';

            if (! $loaded) {
                $checks['errors'][] = "Required PHP extension missing: {$name}";
                $checks['status'] = 'critical';
            }
        }

        // Optional but recommended extensions
        $recommendedExtensions = [
            'imagick' => 'ImageMagick',
            'redis' => 'Redis',
            'memcached' => 'Memcached',
            'zip' => 'Zip',
        ];

        foreach ($recommendedExtensions as $extension => $name) {
            $loaded = extension_loaded($extension);
            $checks['php_extensions'][$extension] = $loaded ? 'loaded' : 'not_loaded';

            if (! $loaded) {
                $checks['warnings'][] = "Recommended PHP extension not loaded: {$name}";
            }
        }

        // Check Composer packages
        if (file_exists(base_path('composer.lock'))) {
            $composerContent = file_get_contents(base_path('composer.lock'));
            if ($composerContent !== false) {
                $composerLock = json_decode($composerContent, true);
                $checks['composer_packages']['lock_file'] = 'present';
                $checks['composer_packages']['packages_count'] = count($composerLock['packages'] ?? []);
            } else {
                $checks['composer_packages']['lock_file'] = 'unreadable';
            }
        } else {
            $checks['warnings'][] = 'Composer lock file missing - run composer install';
            $checks['composer_packages']['lock_file'] = 'missing';
        }

        return $checks;
    }

    /**
     * Get disk space information.
     *
     * @return array<string, mixed>
     */
    private function getDiskSpace(): array
    {
        $path = base_path();

        $freeSpace = disk_free_space($path);
        $totalSpace = disk_total_space($path);

        return [
            'free_bytes' => $freeSpace,
            'total_bytes' => $totalSpace,
            'free_formatted' => $freeSpace !== false ? $this->formatBytes((int) $freeSpace) : 'unknown',
            'total_formatted' => $totalSpace !== false ? $this->formatBytes((int) $totalSpace) : 'unknown',
            'used_percentage' => ($freeSpace !== false && $totalSpace !== false && $totalSpace > 0)
                ? round((1 - ($freeSpace / $totalSpace)) * 100, 2)
                : null,
        ];
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

    /**
     * Generate recommendations based on health check results.
     *
     * @param  array<string, mixed>  $healthCheck
     *
     * @return array<string>
     */
    private function generateRecommendations(array $healthCheck): array
    {
        $recommendations = [];

        // Critical errors
        if (! empty($healthCheck['errors'])) {
            $recommendations[] = 'Address critical errors immediately to ensure system stability';
        }

        // Performance recommendations
        if (count($healthCheck['warnings']) > 5) {
            $recommendations[] = 'Multiple warnings detected - consider a comprehensive system review';
        }

        // Cache recommendations
        if (isset($healthCheck['checks']['cache'])) {
            $cacheIssues = count($healthCheck['checks']['cache']['warnings'] ?? []);
            if ($cacheIssues > 0) {
                $recommendations[] = 'Optimize caching configuration for better performance';
            }
        }

        // Security recommendations
        if (isset($healthCheck['checks']['security'])) {
            $securityIssues = count(array_merge(
                $healthCheck['checks']['security']['warnings'] ?? [],
                $healthCheck['checks']['security']['errors'] ?? []
            ));

            if ($securityIssues > 0) {
                $recommendations[] = 'Review and strengthen security configuration';
            }
        }

        return $recommendations;
    }

    /**
     * Calculate overall health score.
     *
     * @param  array<string, mixed>  $healthCheck
     */
    private function calculateHealthScore(array $healthCheck): int
    {
        $score = 100;

        // Deduct for errors and warnings
        $score -= count($healthCheck['errors']) * 20;
        $score -= count($healthCheck['warnings']) * 5;

        return max(0, $score);
    }
}
