<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorResponse;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ResponseMeta;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\SuccessResponse;
use Cboxdk\StatamicMcp\Mcp\Support\ErrorCodes;
use Cboxdk\StatamicMcp\Mcp\Support\ToolLogger;
use Cboxdk\StatamicMcp\Mcp\Support\ToolResponse;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tool;

abstract class BaseStatamicTool extends Tool
{
    /**
     * Get the tool name.
     */
    abstract protected function getToolName(): string;

    /**
     * Get the tool description.
     */
    abstract protected function getToolDescription(): string;

    /**
     * Define the tool's input schema.
     *
     * @return array<string, mixed>
     */
    abstract protected function defineSchema(JsonSchema $schema): array;

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    abstract protected function executeInternal(array $arguments): array;

    /**
     * The tool name.
     */
    final public function name(): string
    {
        return $this->getToolName();
    }

    /**
     * The tool description.
     */
    final public function description(): string
    {
        return $this->getToolDescription();
    }

    /**
     * Define the tool's input schema.
     *
     * @return array<string, mixed>
     */
    final public function schema(JsonSchema $schema): array
    {
        return $this->defineSchema($schema);
    }

    /**
     * Handle the tool invocation (required by Laravel MCP v0.2.0).
     */
    final public function handle(\Laravel\Mcp\Request $request): \Laravel\Mcp\Response
    {
        $arguments = $request->all();
        $result = $this->execute($arguments);

        // Create appropriate Response based on success/failure
        if ($result['success'] ?? false) {
            $jsonData = json_encode($result['data'] ?? $result);

            return \Laravel\Mcp\Response::text($jsonData !== false ? $jsonData : '{}');
        }

        // Extract error message and create error response
        $errorMessage = $result['errors'][0] ?? $result['error'] ?? 'Unknown error occurred';

        return \Laravel\Mcp\Response::error($errorMessage);
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
        $toolName = $this->getToolName();
        $startTime = microtime(true);
        $correlationId = ToolLogger::toolStarted($toolName, $arguments);

        try {
            // Global defensive validation
            $arguments = $this->validateAndSanitizeArguments($arguments);

            $result = $this->executeInternal($arguments);
            $standardized = $this->wrapInStandardFormat($result);

            $duration = microtime(true) - $startTime;
            ToolLogger::toolSuccess($toolName, $correlationId, $duration);

            // Log performance warning if tool takes too long
            if ($duration > 5.0) {
                ToolLogger::performanceWarning($toolName, 'Tool execution exceeded 5 seconds', $duration);
            }

            return $standardized;
        } catch (\TypeError $e) {
            $duration = microtime(true) - $startTime;
            ToolLogger::toolFailed($toolName, $correlationId, $e, $duration);

            $errorMessage = "Type error in {$toolName}: " . $this->sanitizeErrorMessage($e->getMessage());
            $errorResponse = $this->createSafeErrorResponse($errorMessage, $correlationId);

            return $errorResponse;
        } catch (\Error $e) {
            $duration = microtime(true) - $startTime;
            ToolLogger::toolFailed($toolName, $correlationId, $e, $duration);

            $errorMessage = "Fatal error in {$toolName}: " . $this->sanitizeErrorMessage($e->getMessage());
            $errorResponse = $this->createSafeErrorResponse($errorMessage, $correlationId);

            return $errorResponse;
        } catch (\InvalidArgumentException $e) {
            $duration = microtime(true) - $startTime;
            ToolLogger::toolFailed($toolName, $correlationId, $e, $duration);

            $errorMessage = "Invalid argument in {$toolName}: " . $this->sanitizeErrorMessage($e->getMessage());
            $errorResponse = $this->createSafeErrorResponse($errorMessage, $correlationId);

            return $errorResponse;
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            ToolLogger::toolFailed($toolName, $correlationId, $e, $duration);

            $errorMessage = "Exception in {$toolName}: " . $this->sanitizeErrorMessage($e->getMessage());
            $errorResponse = $this->createSafeErrorResponse($errorMessage, $correlationId, $e);

            return $errorResponse;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            ToolLogger::toolFailed($toolName, $correlationId, $e, $duration);

            $errorMessage = "Critical error in {$toolName}: Tool execution failed";
            $errorResponse = $this->createSafeErrorResponse($errorMessage, $correlationId);

            return $errorResponse;
        }
    }

    /**
     * Wrap result in standardized format if needed.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function wrapInStandardFormat(array $result): array
    {
        // If already standardized, return as is
        if (isset($result['success']) && isset($result['meta'])) {
            return $result;
        }

        // Otherwise wrap in success response
        $response = $this->createSuccessResponse($result);

        return $response->toArray();
    }

    /**
     * Validate required arguments.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<int, string>  $required
     */
    protected function validateRequiredArguments(array $arguments, array $required): void
    {
        foreach ($required as $field) {
            if (! isset($arguments[$field]) || empty($arguments[$field])) {
                throw new \InvalidArgumentException("Missing required parameter: {$field}");
            }
        }
    }

    /**
     * Get argument with default value.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function getArgument(array $arguments, string $key, mixed $default = null): mixed
    {
        return $arguments[$key] ?? $default;
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
        $value = (int) ($arguments[$key] ?? $default);

        if ($value < $min) {
            $value = $min;
        }

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    /**
     * Create response metadata.
     */
    private function createResponseMeta(): ResponseMeta
    {
        return new ResponseMeta(
            tool: $this->name(),
            timestamp: now()->toISOString() ?? date('c'),
            statamic_version: $this->getStatamicVersion(),
            laravel_version: app()->version(),
        );
    }

    /**
     * Get Statamic version.
     */
    private function getStatamicVersion(): string
    {
        try {
            if (class_exists('\Statamic\Statamic')) {
                $version = \Statamic\Statamic::version();

                return $version ?: 'unknown';
            }
        } catch (\Exception $e) {
            // Continue with fallback
        }

        return 'unknown';
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
     * Check if this is a dry-run operation.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function isDryRun(array $arguments): bool
    {
        return $this->getBooleanArgument($arguments, 'dry_run', false);
    }

    /**
     * Add dry-run schema field to tools that support it.
     *
     * @return array<string, mixed>
     */
    protected function addDryRunSchema(): array
    {
        return [
            'dry_run' => JsonSchema::boolean()->description('Preview changes without executing them (default: false)'),
        ];
    }

    /**
     * Simulate dry-run operation result.
     *
     * @param  array<int, string>  $targets
     * @param  array<string, mixed>  $changes
     *
     * @return array<string, mixed>
     */
    protected function simulateOperation(string $operation, array $targets, array $changes = []): array
    {
        return [
            'dry_run' => true,
            'operation' => $operation,
            'targets' => $targets,
            'changes_preview' => $changes,
            'would_affect' => count($targets),
            'timestamp' => now()->toISOString(),
            'note' => 'This is a preview - no changes have been made',
        ];
    }

    /**
     * Validate operation safety before execution.
     *
     * @param  array<int, string>  $targets
     *
     * @return array<string, mixed>
     */
    protected function validateOperationSafety(string $operation, array $targets): array
    {
        $warnings = [];
        $risks = [];

        // Check for destructive operations
        if (in_array($operation, ['delete', 'remove', 'clear', 'purge'])) {
            $risks[] = "Destructive operation: {$operation}";  // Will be converted to associative
            $warnings[] = 'This operation cannot be undone without a backup';  // Will be converted to associative
        }

        // Check target count
        if (count($targets) > 50) {
            $warnings[] = 'Large operation affecting ' . count($targets) . ' items';
        }

        // Check for critical system targets
        foreach ($targets as $target) {
            if (in_array($target, ['pages', 'home', 'default', 'user'])) {
                $risks[] = "Critical system target: {$target}";  // Will be converted to associative
            }
        }

        return [
            'safe' => empty($risks),
            'warnings' => $warnings,
            'risks' => $risks,
        ];
    }

    /**
     * Legacy compatibility method for error responses.
     *
     * @param  string|array<int, string>  $message
     *
     * @deprecated Use createErrorResponse() instead
     *
     * @return array<string, mixed>
     */
    protected function errorResponse(string|array $message): array
    {
        return [
            'success' => false,
            'error' => is_array($message) ? $message[0] ?? 'Unknown error' : $message,
            'message' => is_array($message) ? $message : [$message],
        ];
    }

    /**
     * Create a standardized success response.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     *
     * @return array<string, mixed>
     */
    protected function createStandardSuccessResponse(array $data, array $meta = []): array
    {
        return ToolResponse::success($data, array_merge([
            'tool' => $this->getToolName(),
        ], $meta));
    }

    /**
     * Create a standardized error response with error code.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     *
     * @return array<string, mixed>
     */
    protected function createStandardErrorResponse(ErrorCodes $errorCode, array $data = [], array $meta = []): array
    {
        return ToolResponse::error($errorCode, $data, array_merge([
            'tool' => $this->getToolName(),
        ], $meta));
    }

    /**
     * Create a not found error response.
     *
     * @param  array<string, mixed>  $suggestions
     *
     * @return array<string, mixed>
     */
    protected function createNotFoundResponse(string $resource, ?string $identifier = null, array $suggestions = []): array
    {
        return ToolResponse::notFound($resource, $identifier, $suggestions);
    }

    /**
     * Create a validation error response.
     *
     * @param  array<string, string|array<string>>  $errors
     *
     * @return array<string, mixed>
     */
    protected function createValidationErrorResponse(array $errors): array
    {
        return ToolResponse::validationError($errors, [
            'tool' => $this->getToolName(),
        ]);
    }

    /**
     * Create a permission denied error response.
     *
     * @param  array<string>  $requiredPermissions
     *
     * @return array<string, mixed>
     */
    protected function createPermissionDeniedResponse(string $operation, ?string $resource = null, array $requiredPermissions = []): array
    {
        return ToolResponse::permissionDenied($operation, $resource, $requiredPermissions);
    }

    /**
     * Create a security error response.
     *
     *
     * @return array<string, mixed>
     */
    protected function createSecurityErrorResponse(ErrorCodes $securityError, ?string $details = null): array
    {
        return ToolResponse::securityError($securityError, $details);
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
        // Only perform basic validation - don't modify arguments that Claude sends
        // Only basic null byte protection - don't validate keys or content to avoid breaking Claude
        foreach ($arguments as $value) {
            if (is_string($value) && str_contains($value, "\x00")) {
                throw new \InvalidArgumentException('Null bytes not allowed in string arguments');
            }
        }

        // Return arguments unchanged to preserve Claude's data
        return $arguments;
    }

    /**
     * Recursively sanitize array values with depth protection.
     *
     * @param  array<mixed>  $array
     *
     * @return array<mixed>
     */
    protected function sanitizeArrayRecursively(array $array, int $depth = 0, int $maxDepth = 5): array
    {
        if ($depth >= $maxDepth) {
            return ['_truncated' => 'Array too deep'];
        }

        $sanitized = [];
        $itemCount = 0;
        $maxItems = 1000; // Prevent memory exhaustion

        foreach ($array as $key => $value) {
            if (++$itemCount > $maxItems) {
                $sanitized['_truncated'] = 'Array too large';
                break;
            }

            $cleanKey = is_string($key) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $key) : $key;

            if (is_string($value)) {
                $sanitized[$cleanKey] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
            } elseif (is_array($value)) {
                $sanitized[$cleanKey] = $this->sanitizeArrayRecursively($value, $depth + 1, $maxDepth);
            } else {
                $sanitized[$cleanKey] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize error messages (minimal sanitization for Claude compatibility).
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        // Only perform minimal sanitization to avoid breaking Claude

        // Remove null bytes if present
        $message = str_replace("\x00", '', $message);

        // Limit message length to prevent excessive output
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
            'tool' => $this->getToolName(),
        ];

        // Only add debug info in local environment
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
