<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Cimd;

/**
 * Value object representing a validated CIMD client_id URL.
 *
 * Per the MCP CIMD spec, a CIMD client_id is an HTTPS URL that:
 * - Uses the https scheme
 * - Contains a meaningful path component (not just "/")
 * - Does not contain "." or ".." path segments
 * - Does not contain a fragment (#)
 * - Does not contain username or password (userinfo)
 */
final class CimdClientId
{
    private function __construct(
        private readonly string $url,
        private readonly string $host,
    ) {}

    /**
     * Attempt to create a CimdClientId from a string.
     *
     * Returns null if the string is not a valid CIMD client_id URL.
     */
    public static function tryFrom(string $value): ?self
    {
        if ($value === '') {
            return null;
        }

        $parsed = parse_url($value);

        if ($parsed === false) {
            return null;
        }

        // Must have scheme and host
        if (! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        // Must use https scheme
        if ($parsed['scheme'] !== 'https') {
            return null;
        }

        // Must not contain username or password
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        // Must not contain a fragment
        if (isset($parsed['fragment'])) {
            return null;
        }

        // Must contain a meaningful path (not just "/" or empty)
        $path = $parsed['path'] ?? '/';
        if ($path === '/' || $path === '') {
            return null;
        }

        // Must not contain "." or ".." path segments
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return new self($value, $parsed['host']);
    }

    public function toString(): string
    {
        return $this->url;
    }

    public function getHost(): string
    {
        return $this->host;
    }
}
