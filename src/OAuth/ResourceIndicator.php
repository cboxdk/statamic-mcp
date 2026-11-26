<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Illuminate\Http\Request;

/** The audience advertised by this endpoint, including a non-default port. */
final class ResourceIndicator
{
    public static function identifier(Request $request): string
    {
        return $request->getSchemeAndHttpHost() . config()->string('statamic.mcp.web.path', '/mcp/statamic');
    }

    public static function fromRequest(Request $request): string
    {
        $value = $request->input('resource');
        $parts = is_string($value) ? parse_url($value) : false;
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            throw new OAuthException('invalid_target', 'The resource parameter must identify this MCP endpoint.');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $canonical = $scheme . '://' . strtolower($parts['host']) . ($defaultPort ? '' : ':' . $port) . ($parts['path'] ?? '');
        if ($canonical !== self::identifier($request)) {
            throw new OAuthException('invalid_target', 'The resource parameter must identify this MCP endpoint.');
        }

        return $canonical;
    }

    public static function requireAudience(?CapabilitySnapshot $grant, ?string $resource): void
    {
        // Local driver callers may use non-OAuth grants. HTTP always supplies
        // a resource; an OAuth-bound grant cannot be exchanged without one.
        if ($grant?->resource !== $resource) {
            throw new OAuthException('invalid_target', 'The resource does not match the authorized grant. Authorize this endpoint again.');
        }
    }
}
