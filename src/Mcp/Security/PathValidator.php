<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Security;

use InvalidArgumentException;

/**
 * Validates file paths to prevent path traversal attacks.
 */
class PathValidator
{
    /**
     * Validate that a path is within allowed directories.
     *
     * @param  string  $path  The path to validate
     * @param  array<string>  $allowedBasePaths  Array of allowed base paths
     *
     * @throws InvalidArgumentException If path is invalid or outside allowed directories
     */
    public static function validatePath(string $path, array $allowedBasePaths): string
    {
        // Resolve the path to prevent directory traversal
        $realPath = realpath($path);

        if ($realPath === false) {
            throw new InvalidArgumentException("Path does not exist: {$path}");
        }

        // Check if the resolved path is within any of the allowed base paths
        foreach ($allowedBasePaths as $basePath) {
            $realBasePath = realpath($basePath);

            if ($realBasePath !== false && str_starts_with($realPath, $realBasePath . DIRECTORY_SEPARATOR)) {
                return $realPath;
            }
        }

        throw new InvalidArgumentException("Path traversal attempt detected: {$path}");
    }

    /**
     * Get default allowed template base paths for Statamic.
     *
     * @return array<string>
     */
    public static function getAllowedTemplatePaths(): array
    {
        return [
            resource_path('views'),
            resource_path('blueprints'),
            resource_path('fieldsets'),
        ];
    }

    /**
     * Get default allowed asset paths for Statamic.
     *
     * @return array<string>
     */
    public static function getAllowedAssetPaths(): array
    {
        return [
            public_path(),
            storage_path('app/public'),
        ];
    }

    /**
     * Sanitize a filename by removing dangerous characters.
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove path separators and null bytes
        $cleaned = str_replace(['/', '\\', "\0"], '', $filename);

        // Remove relative path components
        $cleaned = str_replace(['..', '.'], '', $cleaned);

        // Remove leading/trailing whitespace and dots
        $cleaned = trim($cleaned, " \t\n\r\0\x0B.");

        if (empty($cleaned)) {
            throw new InvalidArgumentException('Invalid filename after sanitization');
        }

        return $cleaned;
    }

    /**
     * Validate file extension against allowed extensions.
     *
     * @param  array<string>  $allowedExtensions
     *
     * @throws InvalidArgumentException
     */
    public static function validateFileExtension(string $filename, array $allowedExtensions): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException("File extension '{$extension}' not allowed");
        }
    }

    /**
     * Check if a path contains suspicious patterns.
     */
    public static function containsSuspiciousPatterns(string $path): bool
    {
        $suspiciousPatterns = [
            '../',
            '..\\',
            '%2e%2e%2f',
            '%2e%2e\\',
            '..%2f',
            '..%5c',
            '%2e%2e/',
            '..\/',
        ];

        $lowercasePath = strtolower($path);

        foreach ($suspiciousPatterns as $pattern) {
            if (str_contains($lowercasePath, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
