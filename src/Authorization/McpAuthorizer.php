<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Cboxdk\StatamicMcp\Actions\Transport\ContextFactory;
use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Support\McpContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Auth\User;

/**
 * The one decision every built-in MCP surface asks before it acts: tools
 * (BaseRouter) and resources (AuthorizesResourceAccess) describe the call as
 * an McpCall, and this runs the same gates in the same order for both.
 *
 * 1. HTTP only: an authenticated actor.
 * 2. HTTP only: the token's scopes. A v3 `*` is refused outright; a scope v4
 *    replaced is named in the refusal.
 * 3. HTTP only: the actor's Statamic permissions (super admins pass).
 * 4. Every transport, stdio included: the site-wide resource policy.
 *
 * The installation profile runs before this (it also covers tools that are not
 * routers), and object-level checks — site, author, the resolved record — run
 * after it in the handler, where the object exists. Neither replaces this.
 */
final class McpAuthorizer
{
    /**
     * The first gate on its own: over HTTP, nobody is acting. Asked before a
     * call's arguments are checked, so an anonymous caller learns nothing
     * about the call but that it must sign in.
     *
     * @return array{code: ErrorCode, message: string}|null
     */
    public function authenticationDenial(): ?array
    {
        return ! McpContext::isCli() && auth()->user() === null
            ? ['code' => ErrorCode::Forbidden, 'message' => 'Permission denied: Authentication required']
            : null;
    }

    /** @return array{code: ErrorCode, message: string}|null */
    public function denial(McpCall $call): ?array
    {
        if (($denial = $this->authenticationDenial()) !== null) {
            return $denial;
        }

        if ($call->capability !== null) {
            try {
                app(GrantAuthorizer::class)->authorizeCapability($call->capability['id'], $call->capability['fingerprint'],
                    array_map(fn (TokenScope|string $scope): string => $scope instanceof TokenScope ? $scope->value : $scope, $call->scopes),
                    app(ContextFactory::class)->create($call->site));
            } catch (AuthorizationException) {
                return ['code' => ErrorCode::Forbidden, 'message' => 'This integration action requires a current grant, permitted site and current Statamic permissions. Approve access again if the integration changed.'];
            }
        }

        if (! McpContext::isCli()) {
            /** @var User $user */
            $user = auth()->user();

            $token = request()->attributes->get('mcp_token');
            if ($token instanceof McpTokenData && ($denial = $this->tokenDenial($call, $token)) !== null) {
                return $denial;
            }

            if (($denial = $this->permissionDenial($call, $user)) !== null) {
                return $denial;
            }
        }

        if ($call->handle !== null && ! app(ResourcePolicy::class)->canAccess($call->domain, $call->handle, $call->mode)) {
            return ['code' => ErrorCode::Forbidden, 'message' => ucfirst($call->mode) . " access to '{$call->handle}' is not permitted by resource policy"];
        }

        return null;
    }

    /** @return array{code: ErrorCode, message: string}|null */
    private function tokenDenial(McpCall $call, McpTokenData $token): ?array
    {
        if (in_array(TokenScope::FullAccess->value, $token->scopes, true)) {
            // A v3 token carrying `*` would grow to cover every scope added
            // later. v4 fixes full access to an explicit list when a token is
            // issued, so this one must be approved again.
            return ['code' => ErrorCode::ScopeMissing, 'message' => 'This token was issued with full access (*) before v4. Full access must be approved again: '
                . 'create a new token, or authorize the client again.'];
        }

        // A tool another package added, in a domain this package has no scope
        // for, would otherwise need none. Fail closed: no token carries a scope
        // for it. Such a tool must become a registered action with its own scope.
        if ($call->scopes === [] && $call->extraTool) {
            return ['code' => ErrorCode::ScopeMissing, 'message' => 'This tool declares no token scope. Tools from other packages must be registered actions with their own scope.'];
        }

        $tokens = app(TokenService::class);
        foreach ($call->scopes as $scope) {
            if ($tokens->hasScope($token, $scope)) {
                continue;
            }

            $value = $scope instanceof TokenScope ? $scope->value : $scope;
            Log::warning('MCP permission denied: token missing scope', [
                'domain' => $call->domain,
                'action' => $call->action,
                'required_scope' => $value,
                'token_id' => $token->id,
                'ip' => request()->ip(),
            ]);

            return ['code' => ErrorCode::ScopeMissing, 'message' => "Token missing required scope: {$value}" . ($scope instanceof TokenScope ? $this->replacedScopeHint($token->scopes, $scope) : '')];
        }

        return null;
    }

    /** @return array{code: ErrorCode, message: string}|null */
    private function permissionDenial(McpCall $call, User $user): ?array
    {
        if ($user->isSuper()) {
            return null;
        }

        // A tool another package added that declares no Statamic permission
        // would otherwise be open to every Control Panel user. Fail closed:
        // only a super admin may reach it until it names the permission.
        $permissions = $call->permissions === [] && $call->extraTool && $call->capability === null ? ['super'] : $call->permissions;

        foreach ($permissions as $permission) {
            // A nested list is satisfied by any one of its permissions.
            $granted = is_array($permission)
                ? array_filter($permission, fn (string $alternative): bool => $user->hasPermission($alternative)) !== []
                : $user->hasPermission($permission);

            if (! $granted) {
                Log::warning('MCP permission denied: missing Statamic permission', [
                    'domain' => $call->domain,
                    'action' => $call->action,
                    'missing_permission' => is_array($permission) ? implode(' | ', $permission) : $permission,
                    'user_id' => $user->id(),
                    'ip' => request()->ip(),
                ]);

                return ['code' => ErrorCode::Forbidden, 'message' => "Permission denied: Cannot {$call->action} {$call->domain}"];
            }
        }

        return null;
    }

    /**
     * Tells the holder of a v3 scope that v4 renamed it, so the refusal
     * explains itself instead of reading like a token that never had access.
     *
     * @param  array<int, string>  $held
     */
    private function replacedScopeHint(array $held, TokenScope $missing): string
    {
        foreach ($held as $value) {
            if (TokenScope::tryFrom($value)?->replacedBy() === $missing) {
                return " ({$value} was replaced by {$missing->value} in v4; approve the token again)";
            }
        }

        return '';
    }
}
