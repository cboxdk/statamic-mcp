<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMcpPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip permission check if not required
        if (! config('statamic.mcp.web.permissions.required', true)) {
            return $next($request);
        }

        // Get authenticated user from previous middleware
        $user = $request->attributes->get('statamic_user');

        // Check if user is authenticated
        if (! $user) {
            abort(401, 'Authentication required for MCP access');
        }

        // Check if user has required permission
        $requiredPermission = config('statamic.mcp.web.permissions.permission', 'access mcp');

        if (! $user->can($requiredPermission)) {
            abort(403, "Permission '{$requiredPermission}' required for MCP access");
        }

        return $next($request);
    }
}
