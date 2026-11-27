<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\OAuth\ScopeChallenge;
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

        $challenge = app(ScopeChallenge::class);
        $scopes = $challenge->forRequest($request);
        foreach ($scopes as $scope) {
            $core = TokenScope::tryFrom($scope);
            $allowed = $core !== null ? app(TokenService::class)->hasScope($mcpToken, $core) : in_array($scope, $mcpToken->scopes, true);
            if (! $allowed) {
                return response()->json(['error' => 'insufficient_scope', 'scope' => implode(' ', $scopes)], 403, [
                    'WWW-Authenticate' => $challenge->header($request, $scopes, insufficient: true),
                ]);
            }
        }

        return $next($request);
    }
}
