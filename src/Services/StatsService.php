<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Services;

use Statamic\Statamic;

/**
 * Statistics service for the MCP dashboard.
 *
 * Provides aggregated stats about tokens, system state,
 * and recent activity for display in the Control Panel.
 */
class StatsService
{
    /**
     * Get system-wide statistics.
     *
     * @return array<string, mixed>
     */
    public function getSystemStats(): array
    {
        return [
            'tool_count' => $this->getToolCount(),
            'statamic_version' => $this->getStatamicVersion(),
            'laravel_version' => app()->version(),
            'web_enabled' => $this->isWebEnabled(),
            'dashboard_enabled' => $this->isDashboardEnabled(),
            'audit_logging' => $this->isAuditLoggingEnabled(),
            'rate_limit_max' => $this->getRateLimitMax(),
        ];
    }

    /**
     * Get the number of enabled domain routers from configuration.
     *
     * Note: This counts tools enabled in config('statamic.mcp.tools'), which
     * tracks domain routers (e.g., entries, blueprints). It does not reflect
     * the actual tools registered on the MCP server (which also includes
     * system tools like discovery and schema).
     */
    private function getToolCount(): int
    {
        /** @var array<string, array<string, mixed>> $tools */
        $tools = config('statamic.mcp.tools', []);

        return count(array_filter($tools, fn (array $tool): bool => (bool) ($tool['enabled'] ?? false)));
    }

    /**
     * Check if web MCP endpoint is enabled.
     */
    private function isWebEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('statamic.mcp.web.enabled', false);

        return $enabled;
    }

    /**
     * Check if the dashboard is enabled.
     */
    private function isDashboardEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('statamic.mcp.dashboard.enabled', true);

        return $enabled;
    }

    /**
     * Check if audit logging is enabled.
     */
    private function isAuditLoggingEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('statamic.mcp.security.audit_logging', true);

        return $enabled;
    }

    /**
     * Get the configured rate limit max attempts.
     */
    private function getRateLimitMax(): int
    {
        /** @var int $max */
        $max = config('statamic.mcp.rate_limit.max_attempts', 60);

        return $max;
    }

    /**
     * Get the current Statamic version string.
     */
    private function getStatamicVersion(): string
    {
        return Statamic::version() ?? 'unknown';
    }
}
