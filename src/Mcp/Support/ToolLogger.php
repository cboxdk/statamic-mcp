<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Structured logging for MCP tools.
 */
class ToolLogger
{
    /**
     * Log tool execution start.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function toolStarted(string $toolName, array $arguments, ?string $correlationId = null): string
    {
        $correlationId = $correlationId ?: Str::uuid()->toString();

        Log::info('MCP Tool Started', [
            'tool' => $toolName,
            'correlation_id' => $correlationId,
            'arguments' => self::sanitizeArguments($arguments),
            'timestamp' => now()->toIso8601String(),
        ]);

        return $correlationId;
    }

    /**
     * Log tool execution success.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function toolSuccess(string $toolName, string $correlationId, ?float $duration = null, array $metadata = []): void
    {
        Log::info('MCP Tool Success', [
            'tool' => $toolName,
            'correlation_id' => $correlationId,
            'duration_ms' => $duration ? round($duration * 1000, 2) : null,
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log tool execution failure.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function toolFailed(string $toolName, string $correlationId, \Throwable $exception, ?float $duration = null, array $metadata = []): void
    {
        Log::error('MCP Tool Failed', [
            'tool' => $toolName,
            'correlation_id' => $correlationId,
            'duration_ms' => $duration ? round($duration * 1000, 2) : null,
            'error' => [
                'message' => $exception->getMessage(),
                'class' => get_class($exception),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
            'trace' => $exception->getTraceAsString(),
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log security warning.
     *
     * @param  array<string, mixed>  $details
     */
    public static function securityWarning(string $toolName, string $warning, array $details = []): void
    {
        Log::warning('Security Warning', [
            'tool' => $toolName,
            'warning' => $warning,
            'details' => $details,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log performance warning.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function performanceWarning(string $toolName, string $warning, float $duration, array $metadata = []): void
    {
        Log::warning('Performance Warning', [
            'tool' => $toolName,
            'warning' => $warning,
            'duration_ms' => round($duration * 1000, 2),
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log cache events.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function cacheEvent(string $toolName, string $event, string $key, array $metadata = []): void
    {
        Log::debug('Cache Event', [
            'tool' => $toolName,
            'event' => $event,
            'cache_key' => $key,
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Sanitize arguments by removing sensitive data.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private static function sanitizeArguments(array $arguments): array
    {
        $sensitiveKeys = [
            'password', 'token', 'secret', 'key', 'api_key',
            'access_token', 'refresh_token', 'private_key',
        ];

        $sanitized = [];

        foreach ($arguments as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeArguments($value);
            } else {
                // Truncate long values
                if (is_string($value) && strlen($value) > 1000) {
                    $sanitized[$key] = substr($value, 0, 1000) . '... [TRUNCATED]';
                } else {
                    $sanitized[$key] = $value;
                }
            }
        }

        return $sanitized;
    }
}
