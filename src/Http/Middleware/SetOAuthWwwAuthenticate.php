<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replacement for laravel/mcp's AddWwwAuthenticateHeader.
 *
 * The vendor middleware resolves the `resource_metadata` URL via
 * `route('mcp.oauth.protected-resource')`, which falls back to a generic
 * `Bearer realm="mcp", error="invalid_token"` header when that named
 * route isn't registered. That fallback happens in environments where
 * our OAuth route registration hasn't been observed (e.g. serving via
 * `php artisan serve` under some Statamic boot orderings), and it
 * breaks MCP clients that rely on the protected-resource discovery
 * pointer per RFC 9728.
 *
 * We always know the discovery endpoint is at
 * `/.well-known/oauth-protected-resource` in this addon, so we build
 * the URL directly via `url()` and skip the route-name dependency.
 */
class SetOAuthWwwAuthenticate extends AddWwwAuthenticateHeader
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        if (! (bool) config('statamic.mcp.oauth.enabled', true)) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');

            return $response;
        }

        $discoveryUrl = url('/.well-known/oauth-protected-resource/' . ltrim($request->path(), '/'));

        $response->headers->set(
            'WWW-Authenticate',
            'Bearer realm="mcp", resource_metadata="' . $discoveryUrl . '"'
        );

        return $response;
    }
}
