<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects MCP web requests over plain HTTP when HTTPS is required.
 *
 * Enable via config: statamic.mcp.web.require_https = true
 * Automatically skipped in local/testing environments.
 */
class EnsureSecureTransport
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var bool $requireHttps */
        $requireHttps = config('statamic.mcp.web.require_https', false);

        if (! $requireHttps) {
            return $next($request);
        }

        // Skip HTTPS check in local and testing environments
        if (app()->environment('local', 'testing')) {
            return $next($request);
        }

        if (! $request->secure()) {
            return response()->json([
                'error' => 'HTTPS required',
                'message' => 'MCP web endpoint requires a secure (HTTPS) connection.',
            ], 403);
        }

        return $next($request);
    }
}
