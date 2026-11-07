<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Closure;
use Illuminate\Http\Request;
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

        // If authenticated via MCP token, scopes are checked at the tool level.
        // Expiry is already validated by AuthenticateForMcp upstream.
        /** @var McpTokenData|null $mcpToken */
        $mcpToken = $request->attributes->get('mcp_token');

        if (! $mcpToken instanceof McpTokenData || $mcpToken->isExpired()) {
            abort(403, 'A valid scoped MCP token is required.');
        }

        return $next($request);
    }
}
