<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Concerns;

trait ValidatesRedirectUris
{
    private function validateRedirectUri(string $uri): bool
    {
        $parsed = parse_url($uri);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // No fragments allowed
        if (isset($parsed['fragment'])) {
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
