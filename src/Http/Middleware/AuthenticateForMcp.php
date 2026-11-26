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

        // Mark this as a web request authoritatively, so context detection does
        // not depend on the process SAPI (Octane/Swoole workers report console).
        $request->attributes->set(McpContext::WEB_ATTRIBUTE, true);

        // Try Bearer token first (scoped MCP API tokens). A valid token always
        // gets through: only failed attempts are counted, so a scanner or a
        // stale client behind a shared address (an office, a cloud egress)
        // cannot lock out everyone else there. Per-request throttling is the
        // separate throttle:mcp middleware.
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
        }

        // Password-only access was removed in v4, even with legacy config.
        if ($plainToken || $request->hasHeader('Authorization')) {
            if ($limited = $this->countFailure($request, (string) $request->header('Authorization'))) {
                return $limited;
            }
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
     * Counts a failed credential against the credential itself (a stale
     * token retried in a loop) and against the address (many guesses), and
     * answers 429 once either is over its limit.
     */
    private function countFailure(Request $request, string $credential): ?Response
    {
        $keys = [
            'mcp_auth:credential:' . substr(hash('sha256', $credential), 0, 32) => 5,
            'mcp_auth:ip:' . ($request->ip() ?? 'unknown') => 30,
        ];

        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return response()->json([
                    'error' => 'Too many authentication attempts',
                    'message' => 'Please wait before trying again',
                ], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }
        }
        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 60);
        }

        return null;
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
