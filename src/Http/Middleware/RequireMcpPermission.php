<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Closure;
use Illuminate\Http\Request;
use Statamic\Contracts\Auth\User;
use Symfony\Component\HttpFoundation\Response;

class RequireMcpPermission
{
    /**
     * Handle an incoming request.
     *
     * Checks that the authenticated user/token has appropriate MCP permissions.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('statamic_user');

        if (! $user) {
            abort(401, 'Authentication required for MCP access');
        }

        // If authenticated via MCP token, scopes are checked at the tool level
        // Just verify the token is still valid
        /** @var McpTokenData|null $mcpToken */
        $mcpToken = $request->attributes->get('mcp_token');

        if ($mcpToken && $mcpToken->expiresAt !== null && now()->greaterThan($mcpToken->expiresAt)) {
            abort(401, 'MCP token has expired');
        }

        // For Basic Auth users (no token), require CP access
        if (! $mcpToken) {
            /** @var User|null $statamicUser */
            $statamicUser = $user;
            if (! $statamicUser || ! $statamicUser->hasPermission('access cp')) {
                abort(403, 'CP access required for MCP operations');
            }
        }

        return $next($request);
    }
}
