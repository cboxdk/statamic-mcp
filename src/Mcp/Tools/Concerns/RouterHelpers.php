<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\Support\Facades\Artisan;

/**
 * Router helper methods for common functionality across all routers.
 */
trait RouterHelpers
{
    /**
     * Check if CLI context (bypasses permissions).
     */
    protected function isCliContext(): bool
    {
        return app()->runningInConsole() &&
               ! request()->hasHeader('X-MCP-Remote') &&
               ! config('statamic.mcp.security.force_web_mode', false);
    }

    /**
     * Check if web tool is enabled for the current router.
     */
    protected function isWebToolEnabled(): bool
    {
        $domain = $this->getDomain();

        return config("statamic.mcp.tools.statamic.{$domain}.web_enabled", false);
    }

    /**
     * Check permissions for the given action and resource.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function hasPermissionForAction(array $arguments): bool
    {
        $action = $arguments['action'] ?? '';

        return $this->hasPermission($action, $this->getDomain());
    }

    /**
     * Check permissions for the given action and resource.
     */
    protected function hasPermission(string $action, string $resource): bool
    {
        // In CLI context, bypass all permissions
        if ($this->isCliContext()) {
            return true;
        }

        // Check if user is authenticated
        if (! auth()->check()) {
            return false;
        }

        // Check Statamic permissions based on resource type
        $user = auth()->user();

        return match ($resource) {
            'system' => $user->isSuper() || $user->can('access utilities'),
            'users' => $this->checkUserPermissions($user, $action, $resource),
            'assets' => $user->can("{$action} {$resource}"),
            'blueprints' => $user->can('configure collections') || $user->can('configure taxonomies'),
            'structures' => $user->can('configure collections') || $user->can('configure taxonomies'),
            'content' => $user->can("{$action} entries") || $user->can("{$action} terms"),
            default => $user->can("{$action} {$resource}"),
        };
    }

    /**
     * Check user-specific permissions with fallback handling.
     */
    private function checkUserPermissions(mixed $user, string $action, string $resource): bool
    {
        return match ($resource) {
            'users' => $user->can("{$action} {$resource}"),
            'roles' => $user->can("{$action} {$resource}"),
            'user_groups' => $user->can("{$action} user groups"),
            default => $user->can("{$action} {$resource}"),
        };
    }

    /**
     * Clear specified caches with comprehensive cache management.
     *
     * @param  array<int, string>  $caches
     */
    protected function clearCaches(array $caches = ['stache']): void
    {
        foreach ($caches as $cache) {
            match ($cache) {
                'stache' => Artisan::call('statamic:stache:clear'),
                'static' => Artisan::call('statamic:static:clear'),
                'views' => Artisan::call('view:clear'),
                'config' => Artisan::call('config:clear'),
                'route' => Artisan::call('route:clear'),
                'all' => $this->clearAllCaches(),
                default => null,
            };
        }
    }

    /**
     * Clear all available caches.
     */
    private function clearAllCaches(): void
    {
        $caches = ['stache', 'static', 'views', 'config', 'route'];

        foreach ($caches as $cache) {
            try {
                match ($cache) {
                    'stache' => Artisan::call('statamic:stache:clear'),
                    'static' => Artisan::call('statamic:static:clear'),
                    'views' => Artisan::call('view:clear'),
                    'config' => Artisan::call('config:clear'),
                    'route' => Artisan::call('route:clear'),
                };
            } catch (\Exception $e) {
                // Continue clearing other caches even if one fails
                continue;
            }
        }
    }

    /**
     * Create standardized permission denied response.
     *
     * @param  array<int, string>  $requiredPermissions
     *
     * @return array<string, mixed>
     */
    protected function createPermissionDeniedResponse(string $operation, ?string $resource = null, array $requiredPermissions = []): array
    {
        return $this->createErrorResponse(
            "Permission denied: Cannot {$operation}" .
            ($resource ? " {$resource}" : '') . '. ' .
            'Requires appropriate permissions or CLI context.'
        )->toArray();
    }

    /**
     * Get Statamic version for metadata.
     */
    protected function getStatamicVersion(): string
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

    /**
     * Get Laravel version for metadata.
     */
    protected function getLaravelVersion(): string
    {
        try {
            return app()->version();
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Add common metadata to responses.
     *
     * @param  array<string, mixed>  $response
     *
     * @return array<string, mixed>
     */
    protected function addResponseMetadata(array $response): array
    {
        $response['meta'] = array_merge($response['meta'] ?? [], [
            'tool' => $this->getToolName(),
            'timestamp' => now()->toISOString(),
            'statamic_version' => $this->getStatamicVersion(),
            'laravel_version' => $this->getLaravelVersion(),
        ]);

        return $response;
    }

    /**
     * Validate required fields for action.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<int, string>  $requiredFields
     *
     * @return array<string, mixed>|null Returns error response if validation fails, null if valid
     */
    protected function validateRequiredFields(array $arguments, array $requiredFields): ?array
    {
        $missing = [];

        foreach ($requiredFields as $field) {
            if (! isset($arguments[$field]) || $arguments[$field] === '') {
                $missing[] = $field;
            }
        }

        if (! empty($missing)) {
            return $this->createErrorResponse(
                'Missing required fields: ' . implode(', ', $missing)
            )->toArray();
        }

        return null;
    }

    /**
     * Sanitize handle for safe usage.
     */
    protected function sanitizeHandle(string $handle): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($handle)) ?: '';
    }

    /**
     * Get domain name - must be implemented by concrete router classes.
     */
    abstract protected function getDomain(): string;

    /**
     * Get tool name - must be implemented by concrete router classes.
     */
    abstract protected function getToolName(): string;
}
