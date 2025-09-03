<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Carbon\Carbon;

/**
 * Standardized response builder for MCP tools.
 */
class ToolResponse
{
    /**
     * Create a successful response.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     *
     * @return array<string, mixed>
     */
    public static function success(array $data, array $meta = []): array
    {
        return [
            'success' => true,
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => Carbon::now()->toIso8601String(),
                'statamic_version' => app('statamic.version'),
                'laravel_version' => app()->version(),
            ], $meta),
        ];
    }

    /**
     * Create an error response.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     *
     * @return array<string, mixed>
     */
    public static function error(string|ErrorCodes $error, array $data = [], array $meta = []): array
    {
        if (is_string($error)) {
            $code = ErrorCodes::INTERNAL_ERROR;
            $message = $error;
        } else {
            $code = $error;
            $message = $error->getMessage();
        }

        return [
            'success' => false,
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'data' => $data,
            ],
            'meta' => array_merge([
                'timestamp' => Carbon::now()->toIso8601String(),
                'statamic_version' => app('statamic.version'),
                'laravel_version' => app()->version(),
            ], $meta),
        ];
    }

    /**
     * Create a validation error response.
     *
     * @param  array<string, string|array<string>>  $errors
     * @param  array<string, mixed>  $meta
     *
     * @return array<string, mixed>
     */
    public static function validationError(array $errors, array $meta = []): array
    {
        return self::error(ErrorCodes::VALIDATION_ERROR, [
            'validation_errors' => $errors,
        ], $meta);
    }

    /**
     * Create a not found error response.
     *
     * @param  array<string, mixed>  $suggestions
     *
     * @return array<string, mixed>
     */
    public static function notFound(string $resource, ?string $identifier = null, array $suggestions = []): array
    {
        $data = ['resource' => $resource];

        if ($identifier) {
            $data['identifier'] = $identifier;
        }

        if (! empty($suggestions)) {
            $data['suggestions'] = $suggestions;
        }

        return self::error(ErrorCodes::NOT_FOUND, $data);
    }

    /**
     * Create a permission denied error response.
     *
     * @param  array<string>  $requiredPermissions
     *
     * @return array<string, mixed>
     */
    public static function permissionDenied(string $operation, ?string $resource = null, array $requiredPermissions = []): array
    {
        $data = ['operation' => $operation];

        if ($resource) {
            $data['resource'] = $resource;
        }

        if (! empty($requiredPermissions)) {
            $data['required_permissions'] = $requiredPermissions;
        }

        return self::error(ErrorCodes::PERMISSION_DENIED, $data);
    }

    /**
     * Create a security error response.
     *
     *
     * @return array<string, mixed>
     */
    public static function securityError(ErrorCodes $securityError, ?string $details = null): array
    {
        $data = [];

        if ($details) {
            $data['details'] = $details;
        }

        return self::error($securityError, $data, [
            'security_incident' => true,
            'logged' => true,
        ]);
    }
}
