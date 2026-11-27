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
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['statamic.mcp.web.basic_auth_enabled' => true]);
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
    $user->shouldReceive('get')->with('status')->andReturn($overrides['status'] ?? null)->byDefault();

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
            [TokenScope::EntriesRead],
        );
        $plainToken = $result['token'];

        User::shouldReceive('find')->with('user-1')->andReturn($mockUser);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $request = createBearerRequest($plainToken);

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
        expect($request->attributes->get('statamic_user'))->toBe($mockUser);
        expect($request->attributes->get('mcp_token'))->toBeInstanceOf(McpTokenData::class);
        expect($request->attributes->get('mcp_web_request'))->toBeTrue();
    });

    it('refuses a deactivated user holding a valid Bearer token', function () {
        $mockUser = createMockStatamicUser(overrides: ['status' => 'inactive']);

        $result = $this->tokenService->createToken('user-1', 'Test Token', [TokenScope::EntriesRead]);

        User::shouldReceive('find')->with('user-1')->andReturn($mockUser);

        $middleware = new AuthenticateForMcp($this->tokenService);
        $response = $middleware->handle(createBearerRequest($result['token']), $this->passThrough);

        expect($response->getStatusCode())->toBe(401);
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
            [TokenScope::EntriesRead],
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
            [TokenScope::EntriesRead],
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

    it('rejects Basic Auth even when legacy configuration enables it', function () {
        config(['statamic.mcp.web.basic_auth_enabled' => true]);
        User::shouldReceive('findByEmail')->never();
        $response = (new AuthenticateForMcp($this->tokenService))->handle(
            createBasicAuthRequest('admin@example.com', 'secret-password'), $this->passThrough
        );
        expect($response->getStatusCode())->toBe(401);
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
            [TokenScope::EntriesRead],
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
            [TokenScope::EntriesRead],
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

    it('answers 429 once the same failed credential is retried five times', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);

        for ($i = 0; $i < 5; $i++) {
            expect($middleware->handle(createBearerRequest('stale-token'), $this->passThrough)->getStatusCode())->toBe(401);
        }

        $response = $middleware->handle(createBearerRequest('stale-token'), $this->passThrough);
        expect($response->getStatusCode())->toBe(429);
        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
    });

    it('answers 429 once one address has failed thirty times with different credentials', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);

        for ($i = 0; $i < 30; $i++) {
            expect($middleware->handle(createBearerRequest("guess-{$i}"), $this->passThrough)->getStatusCode())->toBe(401);
        }

        expect($middleware->handle(createBearerRequest('guess-31'), $this->passThrough)->getStatusCode())->toBe(429);
    });

    it('lets a valid token through from an address that is over its failure limit', function () {
        $middleware = new AuthenticateForMcp($this->tokenService);
        for ($i = 0; $i < 31; $i++) {
            $middleware->handle(createBearerRequest("guess-{$i}"), $this->passThrough);
        }

        // Someone else behind the same address (an office, a cloud egress).
        $mockUser = createMockStatamicUser();
        $token = $this->tokenService->createToken('user-123', 'Colleague', [TokenScope::EntriesRead]);
        User::shouldReceive('find')->with('user-123')->andReturn($mockUser);

        expect($middleware->handle(createBearerRequest($token['token']), $this->passThrough)->getStatusCode())->toBe(200);
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
            [TokenScope::EntriesRead],
        );

        $mockUser = createMockStatamicUser();

        $middleware = new RequireMcpPermission;
        $request = Request::create('/mcp/test', 'GET');
        $request->attributes->set('statamic_user', $mockUser);
        $request->attributes->set('mcp_token', $result['model']);

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
    });

    it('aborts 403 when Basic Auth user lacks CP access', function () {
        $mockUser = createMockStatamicUser(canAccessCp: false);

        $middleware = new RequireMcpPermission;
        $request = Request::create('/mcp/test', 'GET');
        $request->attributes->set('statamic_user', $mockUser);
        // No mcp_token — simulates Basic Auth path

        $middleware->handle($request, $this->passThrough);
    })->throws(HttpException::class, 'A valid scoped MCP token is required.');

    it('rejects tokenless users even with CP access', function () {
        $mockUser = createMockStatamicUser(canAccessCp: true);

        $middleware = new RequireMcpPermission;
        $request = Request::create('/mcp/test', 'GET');
        $request->attributes->set('statamic_user', $mockUser);
        // No mcp_token — simulates Basic Auth path

        expect(fn () => $middleware->handle($request, $this->passThrough))
            ->toThrow(HttpException::class);
    });
});
