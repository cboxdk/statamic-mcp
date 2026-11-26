<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Concerns;

trait ValidatesRedirectUris
{
    private function validateRedirectUri(string $uri): bool
    {
        // parse_url alone accepts HTML, whitespace and ambiguous authorities.
        if (filter_var($uri, FILTER_VALIDATE_URL) === false || str_contains($uri, '\\')) {
            return false;
        }
        $parsed = parse_url($uri);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Neither fragments nor credentials belong in an OAuth callback.
        if (isset($parsed['fragment']) || isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        $scheme = $parsed['scheme'];
        $host = $parsed['host'];

        // HTTPS is always allowed
        if ($scheme === 'https') {
            return true;
        }

        // HTTP is only allowed for localhost and 127.0.0.1
        if ($scheme === 'http' && ($host === 'localhost' || $host === '127.0.0.1')) {
            return true;
        }

        return false;
    }
}
