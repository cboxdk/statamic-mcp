<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Cboxdk\StatamicMcp\Auth\TokenService;
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
        $correlationId = $request->header('X-Correlation-ID') ?? Str::uuid()->toString();
        $request->attributes->set('mcp_correlation_id', $correlationId);

        /** @var int $rateLimitMax */
        $rateLimitMax = config('statamic.mcp.rate_limit.max_attempts', 60);
        /** @var int $rateLimitDecay */
        $rateLimitDecay = config('statamic.mcp.rate_limit.decay_minutes', 1);

        // Check if IP is locked out from failed auth attempts (atomic via RateLimiter)
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

        // Try Bearer token first (scoped MCP API tokens)
        $plainToken = $request->bearerToken();
        if ($plainToken) {
            $mcpToken = $this->tokenService->validateToken($plainToken);

            if ($mcpToken) {
                $statamicUser = User::find($mcpToken->userId);

                if ($statamicUser) {
                    // Per-token rate limiting
                    $tokenRateKey = "mcp_token:{$mcpToken->id}";
                    if (RateLimiter::tooManyAttempts($tokenRateKey, $rateLimitMax)) {
                        return response()->json([
                            'error' => 'Rate limit exceeded for this token',
                            'message' => 'Too many requests. Please slow down.',
                        ], 429, [
                            'Retry-After' => (string) RateLimiter::availableIn($tokenRateKey),
                        ]);
                    }
                    RateLimiter::hit($tokenRateKey, $rateLimitDecay * 60);

                    $request->attributes->set('statamic_user', $statamicUser);
                    $request->attributes->set('mcp_token', $mcpToken);
                    Auth::setUser($statamicUser);

                    return $next($request);
                }
            }

            // Bearer token was provided but invalid — rate limit
            RateLimiter::hit($rateLimitKey, 60);
        }

        // Try Basic Auth as fallback
        $credentials = $this->getBasicAuthCredentials($request);
        if ($credentials) {
            $user = $this->authenticateWithCredentials($credentials['email'], $credentials['password']);

            if ($user) {
                // Per-user rate limiting for Basic Auth
                $userRateKey = "mcp_user:{$user->id()}";
                if (RateLimiter::tooManyAttempts($userRateKey, $rateLimitMax)) {
                    return response()->json([
                        'error' => 'Rate limit exceeded for this user',
                        'message' => 'Too many requests. Please slow down.',
                    ], 429, [
                        'Retry-After' => (string) RateLimiter::availableIn($userRateKey),
                    ]);
                }
                RateLimiter::hit($userRateKey, $rateLimitDecay * 60);

                $request->attributes->set('statamic_user', $user);
                Auth::setUser($user);

                return $next($request);
            }

            // Basic Auth credentials were provided but invalid — rate limit
            RateLimiter::hit($rateLimitKey, 60);
        }

        $wwwAuth = 'Bearer realm="Statamic MCP"';
        if (config('statamic.mcp.oauth.enabled', true)) {
            $wwwAuth = 'Bearer resource_metadata="' . url('/.well-known/oauth-protected-resource') . '"';
        }

        return response()->json([
            'error' => 'Authentication required',
            'message' => 'Provide a Bearer token or Basic Auth credentials',
            'hint' => 'Create an API token in the Statamic MCP dashboard',
        ], 401, [
            'WWW-Authenticate' => $wwwAuth,
        ]);
    }

    /**
     * Get Basic Auth credentials from request.
     *
     * @return array{email: string, password: string}|null
     */
    private function getBasicAuthCredentials(Request $request): ?array
    {
        $header = $request->header('Authorization');

        if (! $header || ! str_starts_with($header, 'Basic ')) {
            return null;
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        // Reject credentials containing null bytes
        if (str_contains($decoded, "\x00")) {
            return null;
        }

        [$email, $password] = explode(':', $decoded, 2);

        return ['email' => $email, 'password' => $password];
    }

    /**
     * Authenticate user with email and password using Statamic's auth system.
     */
    private function authenticateWithCredentials(string $email, string $password): ?\Statamic\Contracts\Auth\User
    {
        // Dummy hash used to ensure constant-time response when user doesn't exist,
        // preventing account enumeration via timing analysis.
        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

        try {
            $user = User::findByEmail($email);

            $userPassword = $user?->password() ?? $dummyHash;

            // Always run password_verify to prevent timing-based user enumeration
            $passwordValid = password_verify($password, $userPassword);

            if (! $user || ! $passwordValid || ! $user->hasPermission('access cp')) {
                return null;
            }

            return $user;
        } catch (\Exception $e) {
            // Constant-time path on exception
            password_verify($password, $dummyHash);

            return null;
        }
    }
}
