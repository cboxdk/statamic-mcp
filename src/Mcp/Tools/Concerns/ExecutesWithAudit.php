<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Statamic\Support\Str;

/**
 * Provides standardized execution with audit logging for all routers.
 */
trait ExecutesWithAudit
{
    use HasRateLimiting;

    /**
     * Execute action with comprehensive audit logging and security checks.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeWithAuditLog(string $action, array $arguments): array
    {
        $startTime = microtime(true);
        $user = auth()->user();
        $domain = $this->getDomain();

        // Check rate limiting (skip for CLI context)
        if (! $this->isCliContext()) {
            $rateLimitKey = $this->getRateLimitKey($action, $arguments);
            $maxAttempts = config("statamic.mcp.tools.statamic.{$domain}.rate_limit.max_attempts", 60);
            $decayMinutes = config("statamic.mcp.tools.statamic.{$domain}.rate_limit.decay_minutes", 1);

            if ($this->isRateLimited($rateLimitKey, $maxAttempts, $decayMinutes)) {
                return $this->createErrorResponse(
                    'Rate limit exceeded. Please wait before trying again.'
                )->toArray();
            }
        }

        // Log the operation start if audit logging is enabled
        if (config("statamic.mcp.tools.statamic.{$domain}.audit_logging", true)) {
            \Log::info('MCP Operation Started', [
                'tool' => $this->getToolName(),
                'action' => $action,
                'domain' => $domain,
                'user' => $user?->getAttribute('email'),
                'context' => $this->isCliContext() ? 'cli' : 'web',
                'arguments' => $this->sanitizeArgumentsForLogging($arguments),
                'timestamp' => now()->toISOString(),
            ]);
        }

        try {
            // Execute the actual action
            $result = $this->performDomainAction($action, $arguments);

            // Log successful operation
            if (config("statamic.mcp.tools.statamic.{$domain}.audit_logging", true)) {
                $duration = microtime(true) - $startTime;
                \Log::info('MCP Operation Completed', [
                    'tool' => $this->getToolName(),
                    'action' => $action,
                    'domain' => $domain,
                    'user' => $user?->getAttribute('email'),
                    'context' => $this->isCliContext() ? 'cli' : 'web',
                    'duration' => round($duration, 4),
                    'success' => true,
                    'timestamp' => now()->toISOString(),
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            // Log failed operation
            if (config("statamic.mcp.tools.statamic.{$domain}.audit_logging", true)) {
                $duration = microtime(true) - $startTime;
                \Log::error('MCP Operation Failed', [
                    'tool' => $this->getToolName(),
                    'action' => $action,
                    'domain' => $domain,
                    'user' => $user?->getAttribute('email'),
                    'context' => $this->isCliContext() ? 'cli' : 'web',
                    'duration' => round($duration, 4),
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toISOString(),
                ]);
            }

            return $this->createErrorResponse("Operation failed: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Determine if we're in web context.
     */
    protected function isWebContext(): bool
    {
        return ! $this->isCliContext();
    }

    /**
     * Check permissions for web context operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    protected function checkWebPermissions(string $action, array $arguments): ?array
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
                return $this->createErrorResponse("Permission denied: Cannot {$action} " . $this->getDomain())->toArray();
            }
        }

        return null;
    }

    /**
     * Sanitize arguments for logging (remove sensitive data).
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function sanitizeArgumentsForLogging(array $arguments): array
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
     * Get required permissions for action - should be implemented by each router.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        // Default to super admin permission - individual routers should override
        return ['super'];
    }

    /**
     * Perform the actual domain action - must be implemented by each router.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    abstract protected function performDomainAction(string $action, array $arguments): array;

    /**
     * Get domain name - must be implemented by each router.
     */
    abstract protected function getDomain(): string;

    /**
     * Get tool name - must be implemented by each router.
     */
    abstract protected function getToolName(): string;
}
