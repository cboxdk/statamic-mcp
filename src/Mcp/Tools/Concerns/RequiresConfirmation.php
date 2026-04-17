<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Auth\ConfirmationTokenManager;

/**
 * Provides confirmation token flow for destructive router actions.
 *
 * Routers using this trait call handleConfirmation() early in action methods.
 * Returns a confirmation request array if no valid token is provided, or null
 * if confirmation was successful (proceed with execution).
 */
trait RequiresConfirmation
{
    /**
     * Check if the given action requires confirmation for this router.
     */
    protected function requiresConfirmation(string $action): bool
    {
        // All routers: delete requires confirmation
        if ($action === 'delete') {
            return true;
        }

        // BlueprintsRouter: create and update also require confirmation
        if ($this->getDomain() === 'blueprints' && in_array($action, ['create', 'update'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Handle the confirmation flow for a destructive action.
     *
     * Returns a confirmation request array if the agent needs to confirm,
     * or null if confirmation is valid or not required (proceed with action).
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    protected function handleConfirmation(string $action, array $arguments): ?array
    {
        // Skip if confirmation is not required for this action
        if (! $this->requiresConfirmation($action)) {
            return null;
        }

        /** @var ConfirmationTokenManager $manager */
        $manager = app(ConfirmationTokenManager::class);

        // Skip if confirmation is disabled (env-aware)
        if (! $manager->isEnabled()) {
            return null;
        }

        // Skip in CLI context
        if ($this->isCliContext()) {
            return null;
        }

        // Check if a valid confirmation token was provided
        $token = $arguments['confirmation_token'] ?? null;
        if (is_string($token) && $token !== '') {
            $toolName = $this->name();
            if ($manager->validate($token, $toolName, $arguments)) {
                return null; // Token valid, proceed
            }

            // Invalid or expired token
            return $this->createErrorResponse(
                'Invalid or expired confirmation token. Request a new one by calling without confirmation_token.',
            )->toArray();
        }

        // No token provided — generate one and return confirmation request
        $toolName = $this->name();
        $confirmationToken = $manager->generate($toolName, $arguments);

        /** @var int $ttl */
        $ttl = config('statamic.mcp.confirmation.ttl', 300);

        return $this->createErrorResponse(
            'This operation requires confirmation. Resubmit with the provided confirmation_token to proceed.',
            [
                'requires_confirmation' => true,
                'confirmation_token' => $confirmationToken,
                'description' => $this->buildConfirmationDescription($action, $arguments),
                'expires_in' => $ttl,
            ],
        )->toArray();
    }

    /**
     * Build a human-readable description of what the confirmed action will do.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function buildConfirmationDescription(string $action, array $arguments): string
    {
        $domain = $this->getDomain();
        $handle = $arguments['handle'] ?? $arguments['slug'] ?? $arguments['id'] ?? 'unknown';
        $handle = is_string($handle) ? $handle : 'unknown';

        $resourceContext = '';
        if (isset($arguments['collection']) && is_string($arguments['collection'])) {
            $resourceContext = " from collection '{$arguments['collection']}'";
        } elseif (isset($arguments['taxonomy']) && is_string($arguments['taxonomy'])) {
            $resourceContext = " from taxonomy '{$arguments['taxonomy']}'";
        } elseif (isset($arguments['namespace']) && is_string($arguments['namespace'])) {
            $resourceContext = " in namespace '{$arguments['namespace']}'";
        } elseif (isset($arguments['container']) && is_string($arguments['container'])) {
            $resourceContext = " from container '{$arguments['container']}'";
        }

        return match ($action) {
            'delete' => "Delete {$domain} '{$handle}'{$resourceContext}. This action cannot be undone.",
            'create' => "Create {$domain} '{$handle}'{$resourceContext}.",
            'update' => "Update {$domain} '{$handle}'{$resourceContext}.",
            default => ucfirst($action) . " {$domain} '{$handle}'{$resourceContext}.",
        };
    }
}
