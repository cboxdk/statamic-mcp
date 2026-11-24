<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

/**
 * Resolves whether a (domain, action) pair needs a person's approval in the
 * Control Panel, based on `statamic.mcp.confirmation.actions`.
 *
 * Domains not listed fall back to `default`, which itself defaults to
 * `['delete']`. The `*` wildcard gates every action in the domain.
 */
class ConfirmationActionGate
{
    public static function gates(string $domain, string $action): bool
    {
        /** @var array<string, array<int, string>> $map */
        $map = config('statamic.mcp.confirmation.actions', []);

        $gated = $map[$domain] ?? $map['default'] ?? ['delete'];

        return in_array('*', $gated, true) || in_array($action, $gated, true);
    }

    /** Explicit config wins; otherwise approvals are on in production only. */
    public static function enabled(): bool
    {
        $enabled = config('statamic.mcp.confirmation.enabled');
        if ($enabled !== null) {
            return (bool) $enabled;
        }

        return app()->environment('production');
    }
}
