<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

/**
 * Matches an authorization request's redirect_uri against a client's
 * registered redirect URIs.
 *
 * A registered URI matches exactly, with the one exception RFC 8252 §7.3
 * (OAuth 2.0 for Native Apps) requires and OAuth 2.1 keeps: for a loopback
 * redirect URI the port may differ. Native clients, such as MCP clients that
 * run on the user's computer, listen on a port the operating system assigns
 * when the request starts, so they register `http://localhost/callback` and
 * come back on `http://localhost:53682/callback`.
 *
 * Everything else must still be equal: scheme, host, path and query. Only the
 * plain-http loopback hosts that registration accepts (localhost and
 * 127.0.0.1) qualify, and a URI with user info or a fragment never does.
 * PKCE is required on every authorization request, so a code sent to another
 * local port cannot be redeemed without the client's code_verifier.
 */
final class RedirectUriMatcher
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * @param  array<int, string>  $registered
     */
    public static function matches(string $requested, array $registered): bool
    {
        if ($requested === '') {
            return false;
        }

        if (in_array($requested, $registered, true)) {
            return true;
        }

        $request = self::loopbackParts($requested);

        if ($request === null) {
            return false;
        }

        foreach ($registered as $uri) {
            if (self::loopbackParts($uri) === $request) {
                return true;
            }
        }

        return false;
    }

    /**
     * The parts of a loopback redirect URI that must match, without the port,
     * or null for a URI the port exception does not cover.
     *
     * @return array{host: string, path: string, query: string}|null
     */
    private static function loopbackParts(string $uri): ?array
    {
        $parts = parse_url($uri);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'http') {
            return null;
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || ! in_array($host, self::LOOPBACK_HOSTS, true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        return [
            'host' => $host,
            'path' => $parts['path'] ?? '',
            'query' => $parts['query'] ?? '',
        ];
    }
}
