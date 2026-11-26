<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Cboxdk\StatamicMcp\Integration\IntegrationRegistry;

/**
 * Resolves whether a (domain, action) pair needs a person's approval in the
 * Control Panel, based on `statamic.mcp.confirmation.actions`.
 *
 * A listed domain uses its own list, so `[]` turns the gate off for it. A
 * domain not listed uses `default` (itself `['delete']` when missing) plus the
 * built-in floor below, so a config published before a domain got its floor
 * still gates what the floor covers. The `*` wildcard gates every action.
 */
class ConfirmationActionGate
{
    /**
     * Actions gated in a domain the config does not list. Each of these
     * users actions can hand an account to someone else: a new account with
     * roles, a changed email or password, roles added, an account switched
     * back on.
     */
    private const FLOOR = [
        'users' => ['create', 'update', 'activate', 'assign_role'],
        'forms' => ['delete_submission'],
        'system' => ['reindex'],
    ];

    public static function gates(string $domain, string $action): bool
    {
        /** @var array<string, array<int, string>> $map */
        $map = config('statamic.mcp.confirmation.actions', []);

        $gated = $map[$domain] ?? array_merge($map['default'] ?? ['delete'], self::FLOOR[$domain] ?? [],
            app(IntegrationRegistry::class)->approvals($domain));

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
