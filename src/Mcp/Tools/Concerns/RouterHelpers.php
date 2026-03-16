<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Auth\User;

/**
 * Router helper methods for common functionality across all routers.
 */
trait RouterHelpers
{
    /**
     * Check if CLI context (bypasses permissions).
     */
    protected function isCliContext(): bool
    {
        return app()->runningInConsole() &&
               ! request()->hasHeader('X-MCP-Remote') &&
               ! config('statamic.mcp.security.force_web_mode', false);
    }

    /**
     * Check if web tool is enabled for the current router.
     */
    protected function isWebToolEnabled(): bool
    {
        $domain = $this->getDomain();

        return config("statamic.mcp.tools.{$domain}.enabled", true) && config('statamic.mcp.web.enabled', false);
    }

    /**
     * Check permissions for the given action and resource.
     */
    protected function hasPermission(string $action, string $resource): bool
    {
        // In CLI context, bypass all permissions
        if ($this->isCliContext()) {
            return true;
        }

        // Check if user is authenticated
        if (! auth()->check()) {
            return false;
        }

        // Check Statamic permissions based on resource type
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Super admins bypass all permission checks
        if ($user->isSuper()) {
            return true;
        }

        return match ($resource) {
            'system' => $user->hasPermission('access utilities'),
            'blueprints', 'structures', 'collections' => $user->hasPermission('configure collections') || $user->hasPermission('configure taxonomies'),
            'content' => $user->hasPermission("{$action} entries") || $user->hasPermission("{$action} terms"),
            'user_groups' => $user->hasPermission("{$action} user groups"),
            'taxonomies' => $user->hasPermission('configure taxonomies'),
            'navigations' => $user->hasPermission('configure navigations'),
            default => $user->hasPermission("{$action} {$resource}"),
        };
    }

    /**
     * Determine if we're in web context.
     */
    protected function isWebContext(): bool
    {
        return ! $this->isCliContext();
    }

    /**
     * Check permissions for web context operations.
     *
     * Nested permission model:
     * 1. MCP token scopes (does the token allow this domain/action?)
     * 2. Statamic user permissions (does the underlying user have CMS rights?)
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    protected function checkWebPermissions(string $action, array $arguments): ?array
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return $this->createErrorResponse('Permission denied: Authentication required')->toArray();
        }

        // Layer 1: Check MCP token scopes
        /** @var McpTokenData|null $mcpToken */
        $mcpToken = request()->attributes->get('mcp_token');

        if ($mcpToken) {
            $requiredScope = $this->getRequiredTokenScope($action);
            /** @var TokenService $tokenService */
            $tokenService = app(TokenService::class);
            if ($requiredScope && ! $tokenService->hasScope($mcpToken, $requiredScope)) {
                Log::warning('MCP permission denied: token missing scope', [
                    'domain' => $this->getDomain(),
                    'action' => $action,
                    'required_scope' => $requiredScope->value,
                    'token_id' => $mcpToken->id,
                    'ip' => request()->ip(),
                ]);

                return $this->createErrorResponse(
                    "Token missing required scope: {$requiredScope->value}"
                )->toArray();
            }
        }

        // Layer 2: Check Statamic user permissions
        // Super admins pass all Statamic permission checks
        if ($user->isSuper()) {
            return null;
        }

        $requiredPermissions = $this->getRequiredPermissions($action, $arguments);

        foreach ($requiredPermissions as $permission) {
            if (! $user->hasPermission($permission)) {
                Log::warning('MCP permission denied: missing Statamic permission', [
                    'domain' => $this->getDomain(),
                    'action' => $action,
                    'missing_permission' => $permission,
                    'user_id' => method_exists($user, 'id') ? $user->id() : $user->getAuthIdentifier(),
                    'ip' => request()->ip(),
                ]);

                return $this->createErrorResponse("Permission denied: Cannot {$action} " . $this->getDomain())->toArray();
            }
        }

        return null;
    }

    /**
     * Get required token scope for action. Maps actions to TokenScope enum.
     * Override in routers for domain-specific scope mapping.
     */
    protected function getRequiredTokenScope(string $action): ?TokenScope
    {
        $domain = $this->getDomain();
        $isWrite = in_array($action, [
            'create', 'update', 'delete', 'publish', 'unpublish',
            'activate', 'deactivate', 'assign_role', 'remove_role',
            'move', 'copy', 'upload', 'configure',
            'cache_clear', 'cache_warm', 'config_set',
        ]);

        return TokenScope::tryFrom("{$domain}:" . ($isWrite ? 'write' : 'read'));
    }

    /**
     * Get required Statamic permissions for action - can be overridden by each router.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        return [];
    }

    /**
     * Common parameter name corrections for LLM mistakes.
     *
     * @var array<string, string>
     */
    private const PARAM_CORRECTIONS = [
        'taxonomy' => 'taxonomies',
        'collection' => 'collections',
        'field_type' => 'type',
        'fieldtype' => 'type',
        'name' => 'handle',
    ];

    /**
     * Suggest a correction for a mistyped parameter name.
     */
    protected function suggestParamCorrection(string $key): ?string
    {
        return self::PARAM_CORRECTIONS[$key] ?? null;
    }

    /**
     * Get domain name - must be implemented by concrete router classes.
     */
    abstract protected function getDomain(): string;
}
