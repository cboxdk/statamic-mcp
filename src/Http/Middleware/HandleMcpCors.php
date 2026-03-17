<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles CORS for browser-based MCP clients.
 *
 * Only active when statamic.mcp.web.allowed_origins is non-empty.
 * Desktop MCP clients (Claude Desktop, Cursor, etc.) don't need CORS.
 */
class HandleMcpCors
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<int, string> $allowedOrigins */
        $allowedOrigins = config('statamic.mcp.web.allowed_origins', []);

        // No CORS config = no CORS headers (desktop clients don't need them)
        if (empty($allowedOrigins)) {
            $response = $next($request);

            return $this->addSecurityHeaders($response);
        }

        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            return $this->addSecurityHeaders(
                $this->buildPreflightResponse($request, $allowedOrigins)
            );
        }

        $response = $next($request);

        return $this->addSecurityHeaders(
            $this->addCorsHeaders($response, $request, $allowedOrigins)
        );
    }

    /**
     * Add security headers to prevent clickjacking and MIME sniffing.
     */
    private function addSecurityHeaders(Response $response): Response
    {
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * Build a response for CORS preflight (OPTIONS) requests.
     *
     * @param  array<int, string>  $allowedOrigins
     */
    private function buildPreflightResponse(Request $request, array $allowedOrigins): Response
    {
        $response = response('', 204);

        /** @var string $origin */
        $origin = $request->header('Origin', '');
        if ($origin !== '' && $this->isOriginAllowed($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }

    /**
     * Add CORS headers to the response.
     *
     * @param  array<int, string>  $allowedOrigins
     */
    private function addCorsHeaders(Response $response, Request $request, array $allowedOrigins): Response
    {
        /** @var string $origin */
        $origin = $request->header('Origin', '');

        if ($origin !== '' && $this->isOriginAllowed($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }

    /**
     * Check if the given origin is in the allowed list.
     *
     * @param  array<int, string>  $allowedOrigins
     */
    private function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        if (in_array('*', $allowedOrigins, true)) {
            if (app()->environment('production')) {
                Log::error('MCP CORS wildcard (*) rejected in production. Configure specific origins instead.');

                return false;
            }

            return true;
        }

        return in_array($origin, $allowedOrigins, true);
    }
}
