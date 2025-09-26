<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Provides rate limiting capabilities for MCP tools.
 */
trait HasRateLimiting
{
    /**
     * Check if the current operation should be rate limited.
     *
     * @param  string  $key  The rate limit key
     * @param  int  $maxAttempts  Maximum attempts allowed
     * @param  int  $decayMinutes  Time window in minutes
     *
     * @return bool True if rate limited, false otherwise
     */
    protected function isRateLimited(string $key, int $maxAttempts = 60, int $decayMinutes = 1): bool
    {
        $cacheKey = "mcp_rate_limit:{$key}";
        $attempts = Cache::get($cacheKey, 0);

        if ($attempts >= $maxAttempts) {
            return true;
        }

        Cache::put($cacheKey, $attempts + 1, now()->addMinutes($decayMinutes));

        return false;
    }

    /**
     * Get the rate limit key for the current operation.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function getRateLimitKey(string $action, array $arguments): string
    {
        $user = auth()->user();
        $userId = $user ? $user->getAuthIdentifier() : 'anonymous';
        $context = $this->isCliContext() ? 'cli' : 'web';

        return implode(':', [
            $this->getToolName(),
            $action,
            $context,
            $userId,
        ]);
    }

    /**
     * Clear rate limit for a specific key.
     */
    protected function clearRateLimit(string $key): void
    {
        Cache::forget("mcp_rate_limit:{$key}");
    }

    /**
     * Get remaining attempts before rate limit.
     */
    protected function getRemainingAttempts(string $key, int $maxAttempts = 60): int
    {
        $cacheKey = "mcp_rate_limit:{$key}";
        $attempts = Cache::get($cacheKey, 0);

        return (int) max(0, $maxAttempts - $attempts);
    }

    /**
     * Check if running in CLI context.
     */
    abstract protected function isCliContext(): bool;

    /**
     * Get the tool name.
     */
    abstract protected function getToolName(): string;
}
