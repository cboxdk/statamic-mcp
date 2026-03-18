<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Statamic\Contracts\Auth\User;

/**
 * Structured logging for MCP tools.
 *
 * Logs ONE entry per tool call with rich context.
 * Delegates to the bound AuditStore implementation for persistence.
 */
class ToolLogger
{
    /**
     * Sensitive keys that should be redacted from log output.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'key',
        'api_key',
        'access_token',
        'refresh_token',
        'private_key',
        'credential',
        'authorization',
        'email',
        'phone',
        'ssn',
        'credit_card',
        'card_number',
    ];

    /**
     * Patterns that indicate a string value contains PII, regardless of key name.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_VALUE_PATTERNS = [
        // Email addresses
        '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        // Bearer/Basic auth tokens in values
        '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i',
        // Credit card numbers (13-19 digits, optionally separated)
        '/\b(?:\d[ \-]*?){13,19}\b/',
    ];

    /**
     * Log a complete tool call (one entry per execution).
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $result
     */
    public static function logToolCall(
        string $toolName,
        array $arguments,
        string $status,
        ?float $durationMs = null,
        ?string $action = null,
        ?array $result = null,
        ?\Throwable $error = null,
        ?string $correlationId = null,
    ): void {
        if (! self::isEnabled()) {
            return;
        }

        $entry = [
            'level' => in_array($status, ['success', 'warning'], true) ? 'info' : 'error',
            'message' => self::buildMessage($toolName, $action, $status),
            'tool' => $toolName,
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($action !== null) {
            $entry['action'] = $action;
        }

        if ($durationMs !== null) {
            $entry['duration_ms'] = round($durationMs, 2);
        }

        if ($correlationId !== null) {
            $entry['correlation_id'] = $correlationId;
        }

        // User context from request
        $entry = array_merge($entry, self::getUserContext());

        // Arguments (sanitized)
        $entry['arguments'] = self::sanitizeArguments($arguments);

        // Mutation metadata for write operations
        $mutation = self::extractMutation($toolName, $action, $arguments);
        if ($mutation !== null) {
            $entry['mutation'] = $mutation;
        }

        // Error details
        if ($error !== null) {
            $entry['error'] = [
                'message' => $error->getMessage(),
                'class' => get_class($error),
            ];
        }

        // Response summary
        if ($result !== null) {
            $entry['response_summary'] = self::summarizeResponse($result);
        }

        /** @var array{level: string, message: string, tool?: string, action?: string, status?: string, correlation_id?: string, duration_ms?: float, timestamp: string, metadata?: array<string, mixed>} $entry */
        /** @var AuditStore $store */
        $store = app(AuditStore::class);
        $store->write($entry);
    }

    /**
     * Log performance warning.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function performanceWarning(string $toolName, string $warning, float $duration, array $metadata = []): void
    {
        if (! self::isEnabled()) {
            return;
        }

        /** @var AuditStore $store */
        $store = app(AuditStore::class);
        $store->write([
            'level' => 'warning',
            'message' => 'Performance Warning',
            'tool' => $toolName,
            'status' => 'warning',
            'warning' => $warning,
            'duration_ms' => round($duration * 1000, 2),
            'timestamp' => now()->toIso8601String(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Check if audit logging is enabled.
     */
    public static function isEnabled(): bool
    {
        /** @var bool $enabled */
        $enabled = config('statamic.mcp.security.audit_logging', true);

        return $enabled;
    }

    /**
     * Build a concise log message.
     */
    private static function buildMessage(string $toolName, ?string $action, string $status): string
    {
        if ($action !== null) {
            return "{$toolName}.{$action}: {$status}";
        }

        return "{$toolName}: {$status}";
    }

    /**
     * Extract structured mutation metadata from write operations.
     *
     * Returns null for read-only operations (list, get, scan, etc.).
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    private static function extractMutation(string $toolName, ?string $action, array $arguments): ?array
    {
        // Only track write operations
        $writeActions = ['create', 'update', 'delete', 'publish', 'unpublish', 'activate', 'deactivate', 'assign_role', 'remove_role'];
        if ($action === null || ! in_array($action, $writeActions, true)) {
            return null;
        }

        // Map tool names to resource types
        $resourceType = match (true) {
            str_contains($toolName, 'entries') => 'entry',
            str_contains($toolName, 'blueprints') => 'blueprint',
            str_contains($toolName, 'terms') => 'term',
            str_contains($toolName, 'globals') => 'global',
            str_contains($toolName, 'assets') => 'asset',
            str_contains($toolName, 'users') => 'user',
            str_contains($toolName, 'structures') => 'structure',
            default => str_replace('statamic-', '', $toolName),
        };

        $mutation = [
            'type' => $resourceType,
            'operation' => $action,
        ];

        // Extract resource identifier from arguments
        $id = $arguments['id'] ?? $arguments['handle'] ?? $arguments['slug'] ?? null;
        if (is_string($id) && $id !== '') {
            $mutation['resource_id'] = $id;
        }

        // Add collection/taxonomy context if present
        $collection = $arguments['collection'] ?? null;
        if (is_string($collection) && $collection !== '') {
            $mutation['resource_id'] = $collection . '::' . ($mutation['resource_id'] ?? '*');
        }

        $taxonomy = $arguments['taxonomy'] ?? null;
        if (is_string($taxonomy) && $taxonomy !== '') {
            $mutation['resource_id'] = $taxonomy . '::' . ($mutation['resource_id'] ?? '*');
        }

        // Extract changed field names from data argument (for updates)
        $data = $arguments['data'] ?? $arguments['fields'] ?? null;
        if (is_array($data) && $action === 'update') {
            $mutation['changed_fields'] = array_keys($data);
        }

        return $mutation;
    }

    /**
     * Extract user context from the current request.
     *
     * @return array<string, mixed>
     */
    private static function getUserContext(): array
    {
        $context = [];

        if (! app()->bound('request')) {
            $context['context'] = 'cli';

            return $context;
        }

        try {
            $request = request();

            // statamic_user is a Statamic User object set by AuthenticateForMcp middleware
            $user = $request->attributes->get('statamic_user');
            if ($user instanceof User) {
                $context['user_id'] = (string) $user->id();
                $context['user'] = $user->name() ?? (string) $user->id();
            }

            // mcp_token is a McpTokenData object set by AuthenticateForMcp middleware
            $token = $request->attributes->get('mcp_token');
            if ($token instanceof McpTokenData) {
                $context['token_name'] = $token->name;
            }

            $ip = $request->ip();
            if (is_string($ip) && $ip !== '') {
                $context['ip'] = $ip;
            }

            // Determine context: if request has mcp_token or correlation_id, it's a web request
            $hasMcpContext = $request->attributes->has('mcp_token') || $request->attributes->has('mcp_correlation_id');
            $context['context'] = $hasMcpContext ? 'web' : (app()->runningInConsole() ? 'cli' : 'web');
        } catch (\Throwable) {
            $context['context'] = 'unknown';
        }

        return $context;
    }

    /**
     * Summarize a tool response for the audit log.
     *
     * @param  array<string, mixed>  $result
     */
    private static function summarizeResponse(array $result): string
    {
        // Error in result
        if (isset($result['error']) && is_string($result['error'])) {
            return mb_substr($result['error'], 0, 200);
        }

        if (isset($result['errors']) && is_array($result['errors'])) {
            $first = $result['errors'][0] ?? null;

            return is_string($first) ? mb_substr($first, 0, 200) : 'Error occurred';
        }

        // Success with data
        if (isset($result['success']) && $result['success'] === true && isset($result['data'])) {
            /** @var mixed $data */
            $data = $result['data'];

            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                return self::summarizeData($data);
            }

            return 'Completed successfully';
        }

        // Created/updated flags
        if (isset($result['created']) && $result['created'] === true) {
            return 'Created successfully';
        }

        if (isset($result['updated']) && $result['updated'] === true) {
            return 'Updated successfully';
        }

        if (isset($result['deleted']) && $result['deleted'] === true) {
            return 'Deleted successfully';
        }

        return 'Completed';
    }

    /**
     * Summarize a data array from a successful response.
     *
     * @param  array<string, mixed>  $data
     */
    private static function summarizeData(array $data): string
    {
        // Look for common collection keys and count items
        $collectionKeys = ['entries', 'blueprints', 'terms', 'globals', 'assets', 'users', 'collections', 'taxonomies', 'navigations', 'sites', 'results'];

        foreach ($collectionKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $count = count($data[$key]);

                return "Listed {$count} {$key}";
            }
        }

        // Look for a single item with handle/title
        if (isset($data['handle']) && is_string($data['handle'])) {
            return "Retrieved '{$data['handle']}'";
        }

        if (isset($data['title']) && is_string($data['title'])) {
            return "Retrieved '{$data['title']}'";
        }

        return 'Completed successfully';
    }

    /**
     * Sanitize arguments by removing sensitive data.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    public static function sanitizeArguments(array $arguments): array
    {
        $sanitized = [];

        foreach ($arguments as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (self::isSensitiveKey($lowerKey)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $sanitized[$key] = self::sanitizeArguments($value);
            } elseif (is_string($value) && self::containsSensitiveValue($value)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_string($value) && strlen($value) > 1000) {
                $sanitized[$key] = substr($value, 0, 1000) . '... [TRUNCATED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if a key name indicates sensitive data.
     */
    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a string value matches known PII patterns (email, auth tokens, etc.).
     */
    private static function containsSensitiveValue(string $value): bool
    {
        foreach (self::SENSITIVE_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
