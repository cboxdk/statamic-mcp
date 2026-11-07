<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Support\McpContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateForMcp
{
    public function __construct(
        protected TokenService $tokenService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate correlation ID for request tracing across middleware → tools → audit logs
        $clientCorrelationId = $request->header('X-Correlation-ID');
        $correlationId = (is_string($clientCorrelationId) && preg_match('/^[\w\-]{1,128}$/', $clientCorrelationId))
            ? $clientCorrelationId
            : Str::uuid()->toString();
        $request->attributes->set('mcp_correlation_id', $correlationId);

        // IP-level rate limiting for brute-force auth protection
        // Per-request throttling is handled by the separate throttle:mcp middleware
        $ip = $request->ip() ?? 'unknown';
        $rateLimitKey = "mcp_auth:{$ip}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json([
                'error' => 'Too many authentication attempts',
                'message' => 'Please wait before trying again',
            ], 429, [
                'Retry-After' => (string) RateLimiter::availableIn($rateLimitKey),
            ]);
        }

        // Mark this as a web request authoritatively, so context detection does
        // not depend on the process SAPI (Octane/Swoole workers report console).
        $request->attributes->set(McpContext::WEB_ATTRIBUTE, true);

        // Try Bearer token first (scoped MCP API tokens)
        $plainToken = $request->bearerToken();
        if ($plainToken) {
            $mcpToken = $this->tokenService->validateToken($plainToken);

            if ($mcpToken) {
                $statamicUser = User::find($mcpToken->userId);

                if ($statamicUser && ! $this->isDeactivated($statamicUser)) {
                    $request->attributes->set('statamic_user', $statamicUser);
                    $request->attributes->set('mcp_token', $mcpToken);
                    Auth::setUser($statamicUser);

                    return $next($request);
                }
            }

            // Bearer token was provided but invalid — rate limit
            RateLimiter::hit($rateLimitKey, 60);
        }

        // Password-only access was removed in v4, even with legacy config.
        if (! $plainToken && $request->hasHeader('Authorization')) {
            RateLimiter::hit($rateLimitKey, 60);
        }

        $wwwAuth = 'Bearer realm="Statamic MCP"';
        if (config('statamic.mcp.oauth.enabled', true)) {
            $wwwAuth = 'Bearer resource_metadata="' . url('/.well-known/oauth-protected-resource') . '"';
        }

        return response()->json([
            'error' => 'Authentication required',
            'message' => 'Provide a Bearer token or authenticate through OAuth',
            'hint' => 'Create an API token in the Statamic MCP dashboard',
        ], 401, [
            'WWW-Authenticate' => $wwwAuth,
        ]);
    }

    /**
     * Whether the account was deactivated through statamic-users. That action
     * sets status=inactive; without this check neither authentication path
     * would honour it, so a deactivated user kept full access.
     */
    private function isDeactivated(\Statamic\Contracts\Auth\User $user): bool
    {
        return $user->get('status') === 'inactive';
    }
}
