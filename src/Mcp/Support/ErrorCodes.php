<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

/**
 * Standardized error codes for MCP tools.
 */
enum ErrorCodes: string
{
    // General errors
    case INVALID_INPUT = 'INVALID_INPUT';
    case VALIDATION_ERROR = 'VALIDATION_ERROR';
    case UNAUTHORIZED = 'UNAUTHORIZED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case CONFLICT = 'CONFLICT';
    case RATE_LIMITED = 'RATE_LIMITED';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    // Resource-specific errors
    case BLUEPRINT_NOT_FOUND = 'BLUEPRINT_NOT_FOUND';
    case BLUEPRINT_INVALID = 'BLUEPRINT_INVALID';
    case COLLECTION_NOT_FOUND = 'COLLECTION_NOT_FOUND';
    case ENTRY_NOT_FOUND = 'ENTRY_NOT_FOUND';
    case USER_NOT_FOUND = 'USER_NOT_FOUND';
    case ROLE_NOT_FOUND = 'ROLE_NOT_FOUND';
    case ASSET_NOT_FOUND = 'ASSET_NOT_FOUND';
    case FORM_NOT_FOUND = 'FORM_NOT_FOUND';
    case TAXONOMY_NOT_FOUND = 'TAXONOMY_NOT_FOUND';
    case TERM_NOT_FOUND = 'TERM_NOT_FOUND';
    case NAVIGATION_NOT_FOUND = 'NAVIGATION_NOT_FOUND';
    case GLOBAL_NOT_FOUND = 'GLOBAL_NOT_FOUND';

    // Operation-specific errors
    case CREATION_FAILED = 'CREATION_FAILED';
    case UPDATE_FAILED = 'UPDATE_FAILED';
    case DELETION_FAILED = 'DELETION_FAILED';
    case PERMISSION_DENIED = 'PERMISSION_DENIED';
    case DEPENDENCY_ERROR = 'DEPENDENCY_ERROR';
    case CACHE_ERROR = 'CACHE_ERROR';
    case FILE_SYSTEM_ERROR = 'FILE_SYSTEM_ERROR';
    case TEMPLATE_ERROR = 'TEMPLATE_ERROR';
    case SCHEMA_ERROR = 'SCHEMA_ERROR';

    // Security errors
    case PATH_TRAVERSAL = 'PATH_TRAVERSAL';
    case MALICIOUS_INPUT = 'MALICIOUS_INPUT';
    case UNSAFE_OPERATION = 'UNSAFE_OPERATION';

    /**
     * Get human-readable error message.
     */
    public function getMessage(): string
    {
        return match ($this) {
            self::INVALID_INPUT => 'The provided input is invalid',
            self::VALIDATION_ERROR => 'Input validation failed',
            self::UNAUTHORIZED => 'Authentication required',
            self::FORBIDDEN => 'Access denied',
            self::NOT_FOUND => 'Resource not found',
            self::CONFLICT => 'Resource already exists or conflicts with existing data',
            self::RATE_LIMITED => 'Too many requests - rate limit exceeded',
            self::INTERNAL_ERROR => 'An internal error occurred',

            self::BLUEPRINT_NOT_FOUND => 'Blueprint not found',
            self::BLUEPRINT_INVALID => 'Blueprint configuration is invalid',
            self::COLLECTION_NOT_FOUND => 'Collection not found',
            self::ENTRY_NOT_FOUND => 'Entry not found',
            self::USER_NOT_FOUND => 'User not found',
            self::ROLE_NOT_FOUND => 'Role not found',
            self::ASSET_NOT_FOUND => 'Asset not found',
            self::FORM_NOT_FOUND => 'Form not found',
            self::TAXONOMY_NOT_FOUND => 'Taxonomy not found',
            self::TERM_NOT_FOUND => 'Term not found',
            self::NAVIGATION_NOT_FOUND => 'Navigation not found',
            self::GLOBAL_NOT_FOUND => 'Global not found',

            self::CREATION_FAILED => 'Failed to create resource',
            self::UPDATE_FAILED => 'Failed to update resource',
            self::DELETION_FAILED => 'Failed to delete resource',
            self::PERMISSION_DENIED => 'Insufficient permissions for this operation',
            self::DEPENDENCY_ERROR => 'Operation blocked by dependent resources',
            self::CACHE_ERROR => 'Cache operation failed',
            self::FILE_SYSTEM_ERROR => 'File system operation failed',
            self::TEMPLATE_ERROR => 'Template processing error',
            self::SCHEMA_ERROR => 'Schema validation error',

            self::PATH_TRAVERSAL => 'Path traversal attempt detected',
            self::MALICIOUS_INPUT => 'Potentially malicious input detected',
            self::UNSAFE_OPERATION => 'Operation not permitted for security reasons',
        };
    }

    /**
     * Get suggested HTTP status code for this error.
     */
    public function getHttpStatus(): int
    {
        return match ($this) {
            self::INVALID_INPUT, self::VALIDATION_ERROR, self::BLUEPRINT_INVALID => 400,
            self::UNAUTHORIZED => 401,
            self::FORBIDDEN, self::PERMISSION_DENIED => 403,
            self::NOT_FOUND, self::BLUEPRINT_NOT_FOUND, self::COLLECTION_NOT_FOUND,
            self::ENTRY_NOT_FOUND, self::USER_NOT_FOUND, self::ROLE_NOT_FOUND,
            self::ASSET_NOT_FOUND, self::FORM_NOT_FOUND, self::TAXONOMY_NOT_FOUND,
            self::TERM_NOT_FOUND, self::NAVIGATION_NOT_FOUND, self::GLOBAL_NOT_FOUND => 404,
            self::CONFLICT => 409,
            self::RATE_LIMITED => 429,
            self::PATH_TRAVERSAL, self::MALICIOUS_INPUT, self::UNSAFE_OPERATION => 400,
            default => 500,
        };
    }
}
