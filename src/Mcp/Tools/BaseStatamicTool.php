<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorResponse;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ResponseMeta;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\SuccessResponse;
use Cboxdk\StatamicMcp\Mcp\Support\ToolLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Statamic\Statamic;

abstract class BaseStatamicTool extends Tool
{
    /**
     * Define the tool's input schema.
     *
     * @return array<string, mixed>
     */
    abstract protected function defineSchema(JsonSchemaContract $schema): array;

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    abstract protected function executeInternal(array $arguments): array;

    /**
     * Define the tool's input schema (v0.6 convention).
     * Delegates to defineSchema() for backward compatibility with existing routers.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchemaContract $schema): array
    {
        return $this->defineSchema($schema);
    }

    /**
     * Define the output schema for this tool's results.
     *
     * Returns the standard MCP response envelope used by all tools.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchemaContract $schema): array
    {
        return [
            'success' => JsonSchema::boolean()->description('Whether the operation succeeded'),
            'data' => JsonSchema::object()->description('Operation result data'),
            'meta' => JsonSchema::object()->description('Response metadata (tool, timestamp, versions)'),
            'errors' => JsonSchema::array()->description('Error messages if operation failed'),
            'warnings' => JsonSchema::array()->description('Warning messages'),
        ];
    }

    /**
     * Handle the tool invocation (Laravel MCP v0.6).
     *
     * Called via container DI: Container::getInstance()->call([$tool, 'handle'])
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = $request->all();
        $result = $this->execute($arguments);

        if ($result['success'] ?? false) {
            return Response::structured($result);
        }

        $errors = $result['errors'] ?? null;
        $firstError = is_array($errors) ? ($errors[0] ?? null) : null;
        $errorRaw = $firstError ?? $result['error'] ?? 'Unknown error occurred';
        $errorMessage = is_string($errorRaw) ? $errorRaw : 'Unknown error occurred';

        $correlationId = $result['correlation_id'] ?? null;
        if (is_string($correlationId)) {
            $errorMessage .= " [correlation_id: {$correlationId}]";
        }

        return Response::error($errorMessage);
    }

    /**
     * Override execute method to add consistent error handling and MCP compliance.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    final public function execute(array $arguments): array
    {
        $toolName = $this->name();
        $startTime = microtime(true);

        // Propagate correlation ID from middleware if available
        $requestCorrelationId = null;
        if (app()->bound('request')) {
            $raw = request()->attributes->get('mcp_correlation_id');
            $requestCorrelationId = is_string($raw) ? $raw : null;
        }
        $correlationId = $requestCorrelationId ?: Str::uuid()->toString();

        try {
            $arguments = $this->validateAndSanitizeArguments($arguments);

            $result = $this->executeInternal($arguments);
            $standardized = $this->wrapInStandardFormat($result);

            $duration = microtime(true) - $startTime;

            // Enforce configurable timeout
            /** @var int|float $timeout */
            $timeout = config('statamic.mcp.security.tool_timeout_seconds', 30);
            if ($duration > (float) $timeout) {
                ToolLogger::logToolCall(
                    $toolName, $arguments, 'timeout', $duration * 1000,
                    action: $this->extractAction($arguments),
                    result: $standardized,
                    correlationId: $correlationId,
                );
                ToolLogger::performanceWarning($toolName, "Tool execution exceeded {$timeout}s timeout", $duration);

                return $this->createSafeErrorResponse(
                    "Tool '{$toolName}' timed out after " . round($duration, 1) . "s (limit: {$timeout}s). Use pagination or filters to reduce scope.",
                    $correlationId
                );
            }

            ToolLogger::logToolCall(
                $toolName, $arguments, 'success', $duration * 1000,
                action: $this->extractAction($arguments),
                result: $standardized,
                correlationId: $correlationId,
            );

            if ($duration > 5.0) {
                ToolLogger::performanceWarning($toolName, 'Tool execution exceeded 5 seconds', $duration);
            }

            return $standardized;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            $status = ($e instanceof \InvalidArgumentException) ? 'validation_error' : 'error';

            ToolLogger::logToolCall(
                $toolName, $arguments, $status, $duration * 1000,
                action: $this->extractAction($arguments),
                error: $e,
                correlationId: $correlationId,
            );

            $prefix = match (true) {
                $e instanceof \TypeError => 'Type error',
                $e instanceof \InvalidArgumentException => 'Invalid argument',
                $e instanceof \Error => 'Fatal error',
                default => 'Exception',
            };

            // Always log the full error server-side
            Log::error("MCP tool error in {$toolName}", [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $rawMessage = $this->sanitizeErrorMessage($e->getMessage());
            $errorMessage = app()->environment('local', 'testing')
                ? "{$prefix} in {$toolName}: {$rawMessage}"
                : "{$prefix} in {$toolName}: An error occurred. Check server logs for details.";

            return $this->createSafeErrorResponse($errorMessage, $correlationId, $e);
        }
    }

    /**
     * Extract the action parameter from arguments (used by routers).
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function extractAction(array $arguments): ?string
    {
        $action = $arguments['action'] ?? null;

        return is_string($action) ? $action : null;
    }

    /**
     * Wrap result in standardized format if needed.
     *
     * Also validates response size to prevent LLM token overflow.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function wrapInStandardFormat(array $result): array
    {
        if (isset($result['success']) && isset($result['meta'])) {
            $wrapped = $result;
        } else {
            $wrapped = $this->createSuccessResponse($result)->toArray();
        }

        // Guard against oversized responses that would overflow LLM token budgets
        $encoded = json_encode($wrapped);
        if ($encoded !== false && strlen($encoded) > 100000) {
            return $this->createErrorResponse(
                'Response too large (' . round(strlen($encoded) / 1024) . 'KB). Use pagination or filters to reduce the result set.',
                ['response_size_bytes' => strlen($encoded)],
            )->toArray();
        }

        return $wrapped;
    }

    /**
     * Get boolean argument with default value.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function getBooleanArgument(array $arguments, string $key, bool $default = false): bool
    {
        return (bool) ($arguments[$key] ?? $default);
    }

    /**
     * Get integer argument with validation.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function getIntegerArgument(array $arguments, string $key, int $default = 0, int $min = 0, ?int $max = null): int
    {
        $raw = $arguments[$key] ?? $default;
        $value = is_scalar($raw) ? (int) $raw : $default;

        if ($value < $min) {
            $value = $min;
        }

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    /** @var array{statamic: string, laravel: string}|null */
    private static ?array $versionCache = null;

    /**
     * @return array{statamic: string, laravel: string}
     */
    private static function getCachedVersions(): array
    {
        if (self::$versionCache === null) {
            self::$versionCache = [
                'statamic' => Statamic::version() ?? 'unknown',
                'laravel' => app()->version(),
            ];
        }

        return self::$versionCache;
    }

    /**
     * Clear version cache (for testing only).
     *
     * @internal For testing only
     */
    public static function clearVersionCache(): void
    {
        self::$versionCache = null;
    }

    /**
     * Determine if this tool should be registered.
     *
     * Checks the per-domain tool configuration. Tools whose domain
     * is disabled in config will not be exposed to MCP clients.
     */
    public function shouldRegister(): bool
    {
        $domain = $this->getToolDomain();

        if ($domain === null) {
            return true;
        }

        /** @var bool $enabled */
        $enabled = config("statamic.mcp.tools.{$domain}.enabled", true);

        return $enabled;
    }

    /**
     * Get the domain key for config lookup.
     *
     * Override in subclasses to map to config keys.
     * Returns null if tool has no domain toggle.
     */
    protected function getToolDomain(): ?string
    {
        return null;
    }

    /**
     * Create response metadata.
     */
    private function createResponseMeta(): ResponseMeta
    {
        $exposeVersions = (bool) config('statamic.mcp.security.expose_versions', false);

        if ($exposeVersions) {
            $versions = self::getCachedVersions();

            return new ResponseMeta(
                tool: $this->name(),
                timestamp: now()->toISOString() ?? date('c'),
                statamic_version: $versions['statamic'],
                laravel_version: $versions['laravel'],
            );
        }

        return new ResponseMeta(
            tool: $this->name(),
            timestamp: now()->toISOString() ?? date('c'),
        );
    }

    /**
     * Get Statamic version.
     */
    protected function getStatamicVersion(): string
    {
        return self::getCachedVersions()['statamic'];
    }

    /**
     * Create success response.
     *
     * @param  array<int, string>  $warnings
     */
    protected function createSuccessResponse(mixed $data, array $warnings = []): SuccessResponse
    {
        return new SuccessResponse(
            data: $data,
            meta: $this->createResponseMeta(),
            warnings: $warnings
        );
    }

    /**
     * Create error response.
     *
     * @param  string|array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    protected function createErrorResponse(string|array $errors, mixed $data = null, array $warnings = []): ErrorResponse
    {
        return new ErrorResponse(
            errors: $errors,
            meta: $this->createResponseMeta(),
            data: $data,
            warnings: $warnings
        );
    }

    /**
     * Basic argument validation (permissive for Claude compatibility).
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function validateAndSanitizeArguments(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if ($this->containsNullByte($value)) {
                throw new \InvalidArgumentException("Argument '{$key}' contains invalid null bytes");
            }
        }

        return $arguments;
    }

    /**
     * Recursively check if a value contains null bytes.
     */
    private function containsNullByte(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, "\x00");
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($this->containsNullByte($v)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sanitize error messages (minimal sanitization for Claude compatibility).
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        $message = str_replace("\x00", '', $message);

        if (strlen($message) > 1000) {
            $message = substr($message, 0, 997) . '...';
        }

        return $message;
    }

    /**
     * Create safe error response that prevents information disclosure.
     *
     * @return array<string, mixed>
     */
    protected function createSafeErrorResponse(string $message, string $correlationId, ?\Throwable $exception = null): array
    {
        $response = [
            'success' => false,
            'error' => $message,
            'correlation_id' => $correlationId,
            'timestamp' => now()->toISOString() ?? date('c'),
            'tool' => $this->name(),
        ];

        if (app()->bound('env') && app('env') === 'local' && $exception) {
            $response['debug'] = [
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
                'type' => get_class($exception),
            ];
        }

        return $response;
    }
}
