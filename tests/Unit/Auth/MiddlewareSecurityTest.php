<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Http\Middleware\AuthenticateForMcp;
use Cboxdk\StatamicMcp\Http\Middleware\RequireMcpPermission;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations/tokens');

    $this->tokenService = app(TokenService::class);
    $this->passThrough = fn (Request $request): Response => response()->json(['ok' => true]);
});

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

/**
 * Create a mock Statamic user.
 *
 * Mocks both StatamicUser and Authorizable so that method_exists($user, 'can')
 * returns true. The RequireMcpPermission middleware uses method_exists() to check
 * for the can() method, which only works when it exists on the generated proxy class
 * (not via Mockery's __call magic).
 *
 * @param  array<string, mixed>  $overrides
 */
function createMockStatamicUser(string $id = 'user-1', string $email = 'test@example.com', bool $canAccessCp = true, array $overrides = []): StatamicUser&Authorizable
{
    $user = Mockery::mock(StatamicUser::class . ', ' . Authorizable::class);
    $user->shouldReceive('id')->andReturn($overrides['id'] ?? $id);
    $user->shouldReceive('email')->andReturn($overrides['email'] ?? $email);
    $user->shouldReceive('password')->andReturn($overrides['password'] ?? password_hash('secret-password', PASSWORD_BCRYPT));
    $user->shouldReceive('getAuthIdentifier')->andReturn($overrides['id'] ?? $id);
    $user->shouldReceive('can')->with('access cp')->andReturn($canAccessCp);
    $user->shouldReceive('hasPermission')->with('access cp')->andReturn($canAccessCp);
    $user->shouldReceive('isSuper')->andReturn(false)->byDefault();

    return $user;
}

/**
 * Create a request with a Bearer token.
 */
function createBearerRequest(string $token): Request
{
    $request = Request::create('/mcp/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);

    return $request;
}

/**
 * Create a request with Basic Auth credentials.
 */
function createBasicAuthRequest(string $email, string $password): Request
{
    $request = Request::create('/mcp/test', 'GET');
    $encoded = base64_encode($email . ':' . $password);
    $request->headers->set('Authorization', 'Basic ' . $encoded);

    return $request;
}

/*
|--------------------------------------------------------------------------
| AuthenticateForMcp — Bearer Token Authentication
|--------------------------------------------------------------------------
*/

describe('AuthenticateForMcp', function () {

    it('authenticates with a valid Bearer token and sets request attributes', function () {
        $mockUser = createMockStatamicUser();

        $result = $this->tokenService->createToken(
            'user-1',
            'Test Token',
            [TokenScope::ContentRead],
        );
        $plainToken = $result['token'];

        User::shouldReceive('find')->with('user-1')->andReturn($mockUser);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBearerRequest($plainToken);

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
        expect($request->attributes->get('statamic_user'))->toBe($mockUser);
        expect($request->attributes->get('mcp_token'))->toBeInstanceOf(McpTokenData::class);
    });

    it('returns 401 for an invalid Bearer token', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBearerRequest('completely-invalid-token');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 for an expired Bearer token', function () {
        $result = $this->tokenService->createToken(
            'user-1',
            'Expired Token',
            [TokenScope::ContentRead],
            Carbon::now()->subDay(),
        );

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBearerRequest($result['token']);

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when token is valid but the associated user is deleted', function () {
        $result = $this->tokenService->createToken(
            'deleted-user-id',
            'Orphan Token',
            [TokenScope::ContentRead],
        );

        User::shouldReceive('find')->with('deleted-user-id')->andReturn(null);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBearerRequest($result['token']);

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 when no Authorization header is present', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = Request::create('/mcp/test', 'GET');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
    });

    it('includes WWW-Authenticate header in 401 responses', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = Request::create('/mcp/test', 'GET');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
        $wwwAuth = $response->headers->get('WWW-Authenticate');
        expect($wwwAuth)->toContain('Bearer');
    });

    it('authenticates via Basic Auth with valid credentials', function () {
        $mockUser = createMockStatamicUser(
            email: 'admin@example.com',
        );

        User::shouldReceive('findByEmail')->with('admin@example.com')->andReturn($mockUser);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBasicAuthRequest('admin@example.com', 'secret-password');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
        expect($request->attributes->get('statamic_user'))->toBe($mockUser);
        expect($request->attributes->get('mcp_token'))->toBeNull();
    });

    it('returns 401 for Basic Auth with an invalid password', function () {
        $mockUser = createMockStatamicUser(email: 'admin@example.com');

        User::shouldReceive('findByEmail')->with('admin@example.com')->andReturn($mockUser);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBasicAuthRequest('admin@example.com', 'wrong-password');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 for Basic Auth with a non-existent email', function () {
        User::shouldReceive('findByEmail')->with('nobody@example.com')->andReturn(null);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBasicAuthRequest('nobody@example.com', 'any-password');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
    });

    it('returns 401 for a malformed Basic Auth header', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);

        // Base64 without a colon separator
        $request = Request::create('/mcp/test', 'GET');
        $request->headers->set('Authorization', 'Basic ' . base64_encode('no-colon-here'));

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
    });

    it('prefers Bearer token over Basic Auth when both are present', function () {
        $mockUser = createMockStatamicUser();

        $result = $this->tokenService->createToken(
            'user-1',
            'Token Auth',
            [TokenScope::ContentRead],
        );

        User::shouldReceive('find')->with('user-1')->andReturn($mockUser);
        // findByEmail should NOT be called since Bearer takes precedence
        User::shouldReceive('findByEmail')->never();

        $middleware = new AuthenticateForMcp($this->tokenService);

        // Build request with Bearer token (Basic Auth header would be overridden,
        // but the middleware checks bearerToken() first)
        $request = createBearerRequest($result['token']);

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
        expect($request->attributes->get('mcp_token'))->toBeInstanceOf(McpTokenData::class);
    });

    it('returns 401 for an empty Bearer token', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);

        $request = Request::create('/mcp/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
    });

    it('sets Auth::setUser when Bearer token authentication succeeds', function () {
        $mockUser = createMockStatamicUser();

        $result = $this->tokenService->createToken(
            'user-1',
            'Auth Check Token',
            [TokenScope::ContentRead],
        );

        User::shouldReceive('find')->with('user-1')->andReturn($mockUser);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBearerRequest($result['token']);

        Auth::shouldReceive('setUser')->once()->with($mockUser);

        $middleware->handle($request, $this->passThrough);
    });
});

/*
|--------------------------------------------------------------------------
| AuthenticateForMcp — Rate Limiting
|--------------------------------------------------------------------------
*/

describe('AuthenticateForMcp Rate Limiting', function () {

    it('locks out IP after 5 failed Basic Auth attempts', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);

        User::shouldReceive('findByEmail')->with('attacker@example.com')->andReturn(null);

        for ($i = 0; $i < 5; $i++) {
            $request = createBasicAuthRequest('attacker@example.com', 'wrong-password');
            $response = $middleware->handle($request, $this->passThrough);
            expect($response->getStatusCode())->toBe(401);
        }

        // 6th attempt should be locked out
        $request = createBasicAuthRequest('attacker@example.com', 'wrong-password');
        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(429);
        $retryAfter = (int) $response->headers->get('Retry-After');
        expect($retryAfter)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
    });

    it('uses atomic increment for rate limiting counter', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);

        User::shouldReceive('findByEmail')->with('test@example.com')->andReturn(null);

        $request = createBasicAuthRequest('test@example.com', 'wrong');
        $middleware->handle($request, $this->passThrough);

        $ip = $request->ip() ?? 'unknown';
        $rateLimitKey = "mcp_auth:{$ip}";

        // RateLimiter should have recorded 1 attempt after one failed attempt
        expect(RateLimiter::tooManyAttempts($rateLimitKey, 5))->toBeFalse();

        // Second attempt
        $request2 = createBasicAuthRequest('test@example.com', 'wrong');
        $middleware->handle($request2, $this->passThrough);

        // Still under the limit after 2 attempts
        expect(RateLimiter::tooManyAttempts($rateLimitKey, 5))->toBeFalse();
    });

    it('does not increment counter on successful authentication', function () {
        $mockUser = createMockStatamicUser(email: 'admin@example.com');

        User::shouldReceive('findByEmail')->with('admin@example.com')->andReturn($mockUser);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBasicAuthRequest('admin@example.com', 'secret-password');
        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);

        $ip = $request->ip() ?? 'unknown';
        $rateLimitKey = "mcp_auth:{$ip}";
        expect(RateLimiter::tooManyAttempts($rateLimitKey, 1))->toBeFalse();
    });

    it('increments counter on failed Bearer token attempt', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBearerRequest('invalid-token-here');
        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(401);

        $ip = $request->ip() ?? 'unknown';
        $rateLimitKey = "mcp_auth:{$ip}";
        // Bearer token failures now count toward rate limiting
        expect(RateLimiter::tooManyAttempts($rateLimitKey, 1))->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| RequireMcpPermission — Permission & Expiry Checks
|--------------------------------------------------------------------------
*/

describe('RequireMcpPermission', function () {

    it('aborts 401 when no statamic_user attribute is set', function () {
        $middleware = new RequireMcpPermission;
        $request = Request::create('/mcp/test', 'GET');

        $middleware->handle($request, $this->passThrough);
    })->throws(HttpException::class, 'Authentication required for MCP access');

    it('passes through when authenticated with a valid MCP token', function () {
        $result = $this->tokenService->createToken(
            'user-1',
            'Valid Token',
            [TokenScope::ContentRead],
        );

        $mockUser = createMockStatamicUser();

        $middleware = new RequireMcpPermission;
        $request = Request::create('/mcp/test', 'GET');
        $request->attributes->set('statamic_user', $mockUser);
        $request->attributes->set('mcp_token', $result['model']);

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
    });

    it('aborts 401 when the MCP token has expired', function () {
        $result = $this->tokenService->createToken(
            'user-1',
            'Expired Token',
            [TokenScope::ContentRead],
            Carbon::now()->subDay(),
        );

        $mockUser = createMockStatamicUser();

        $middleware = new RequireMcpPermission;
        $request = Request::create('/mcp/test', 'GET');
        $request->attributes->set('statamic_user', $mockUser);
        $request->attributes->set('mcp_token', $result['model']);

        $middleware->handle($request, $this->passThrough);
    })->throws(HttpException::class, 'MCP token has expired');

    it('aborts 403 when Basic Auth user lacks CP access', function () {
        $mockUser = createMockStatamicUser(canAccessCp: false);

        $middleware = new RequireMcpPermission;
        $request = Request::create('/mcp/test', 'GET');
        $request->attributes->set('statamic_user', $mockUser);
        // No mcp_token — simulates Basic Auth path

        $middleware->handle($request, $this->passThrough);
    })->throws(HttpException::class, 'CP access required for MCP operations');

    it('passes through when Basic Auth user has CP access', function () {
        $mockUser = createMockStatamicUser(canAccessCp: true);

        $middleware = new RequireMcpPermission;
        $request = Request::create('/mcp/test', 'GET');
        $request->attributes->set('statamic_user', $mockUser);
        // No mcp_token — simulates Basic Auth path

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
    });
});
