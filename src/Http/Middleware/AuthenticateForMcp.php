<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateForMcp
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = null;

        // Try Bearer token first (preferred for Claude Code)
        if ($token = $this->getBearerToken($request)) {
            $user = $this->authenticateWithToken($token);
        }

        // Try Basic Auth as fallback
        if (! $user && $credentials = $this->getBasicAuthCredentials($request)) {
            $user = $this->authenticateWithCredentials($credentials['email'], $credentials['password']);
        }

        // Fallback to session authentication (for browser access)
        if (! $user) {
            try {
                // Try to get current user from session/auth guard
                $user = auth()->user();

                // If Laravel auth user, try to find corresponding Statamic user
                if ($user && method_exists($user, 'email')) {
                    $statamicUser = User::findByEmail($user->email);
                    if ($statamicUser && $statamicUser->can('access cp')) {
                        $user = $statamicUser;
                    } else {
                        $user = null;
                    }
                }
            } catch (\Exception $e) {
                // Session authentication might not be available
                $user = null;
            }
        }

        if (! $user) {
            return response()->json([
                'error' => 'Authentication required',
                'message' => 'Please provide credentials via Basic Auth or log in to Statamic CP',
                'hint' => 'Use Basic Auth with your Statamic email/password',
            ], 401, [
                'WWW-Authenticate' => 'Basic realm="Statamic MCP"',
            ]);
        }

        // Store authenticated user in request for downstream middleware
        $request->attributes->set('statamic_user', $user);

        return $next($request);
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

        $encoded = substr($header, 6);
        $decoded = base64_decode($encoded);

        if (! $decoded || ! str_contains($decoded, ':')) {
            return null;
        }

        [$email, $password] = explode(':', $decoded, 2);

        return ['email' => $email, 'password' => $password];
    }

    /**
     * Get Bearer token from request.
     */
    private function getBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (! $header || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }

    /**
     * Authenticate user with Bearer token using Statamic's auth system.
     */
    private function authenticateWithToken(string $token): ?\Statamic\Contracts\Auth\User
    {
        try {
            // For simplicity, we'll use the token as email:password encoded with base64
            // Token format: base64(email:password)
            $decoded = base64_decode($token);

            if (! $decoded || ! str_contains($decoded, ':')) {
                return null;
            }

            [$email, $password] = explode(':', $decoded, 2);

            return $this->authenticateWithCredentials($email, $password);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Authenticate user with email and password using Statamic's auth system.
     */
    private function authenticateWithCredentials(string $email, string $password): ?\Statamic\Contracts\Auth\User
    {
        try {
            // Find user by email
            $user = User::findByEmail($email);

            if (! $user) {
                return null;
            }

            // Check if user can access CP
            if (! $user->can('access cp')) {
                return null;
            }

            // Verify password using PHP's password_verify (more reliable with Statamic)
            $userPassword = $user->password();
            if (! $userPassword || ! password_verify($password, $userPassword)) {
                return null;
            }

            return $user;
        } catch (\Exception $e) {
            return null;
        }
    }
}
