<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\McpTokenGuard;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Statamic\Contracts\Auth\User as StatamicUserContract;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations/tokens');
});

// ---------------------------------------------------------------------------
// Helper to create a guard with mocked dependencies
// ---------------------------------------------------------------------------

function createGuard(?string $authorizationHeader = null, ?TokenService $tokenService = null): McpTokenGuard
{
    $request = new Request;

    if ($authorizationHeader !== null) {
        $request->headers->set('Authorization', $authorizationHeader);
    }

    return new McpTokenGuard(
        $tokenService ?? Mockery::mock(TokenService::class),
        $request,
    );
}

function createMockGuardUser(string $id = 'user-1'): StatamicUserContract
{
    $user = Mockery::mock(StatamicUserContract::class, Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn($id);

    return $user;
}

// ---------------------------------------------------------------------------
// extractBearerToken (tested via user/check/guest)
// ---------------------------------------------------------------------------

describe('extractBearerToken', function () {
    it('returns guest when no Authorization header is present', function () {
        $guard = createGuard();

        expect($guard->user())->toBeNull();
        expect($guard->guest())->toBeTrue();
        expect($guard->check())->toBeFalse();
    });

    it('returns guest when Authorization header is not Bearer type', function () {
        $guard = createGuard('Basic dXNlcjpwYXNz');

        expect($guard->user())->toBeNull();
        expect($guard->guest())->toBeTrue();
    });

    it('returns guest when Bearer token is empty', function () {
        $guard = createGuard('Bearer ');

        expect($guard->user())->toBeNull();
        expect($guard->guest())->toBeTrue();
    });

    it('extracts token correctly from valid Bearer header', function () {
        $mcpToken = new McpTokenData(
            id: 'token-1', userId: 'user-1', name: 'Test',
            tokenHash: 'hash', scopes: ['*'], lastUsedAt: null,
            expiresAt: null, createdAt: Carbon::now(),
        );

        $mockUser = createMockGuardUser('user-1');

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('validateToken')
            ->with('abc123')
            ->once()
            ->andReturn($mcpToken);

        User::shouldReceive('find')
            ->with('user-1')
            ->once()
            ->andReturn($mockUser);

        $guard = createGuard('Bearer abc123', $tokenService);

        expect($guard->user())->toBe($mockUser);
        expect($guard->check())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// user()
// ---------------------------------------------------------------------------

describe('user', function () {
    it('returns Authenticatable user for a valid token', function () {
        $mcpToken = new McpTokenData(
            id: 'token-1', userId: 'user-1', name: 'Test',
            tokenHash: 'hash', scopes: ['*'], lastUsedAt: null,
            expiresAt: null, createdAt: Carbon::now(),
        );

        $mockUser = createMockGuardUser('user-1');

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('validateToken')
            ->with('valid-token')
            ->once()
            ->andReturn($mcpToken);

        User::shouldReceive('find')
            ->with('user-1')
            ->once()
            ->andReturn($mockUser);

        $guard = createGuard('Bearer valid-token', $tokenService);

        expect($guard->user())->toBe($mockUser);
    });

    it('caches user on subsequent calls and only validates once', function () {
        $mcpToken = new McpTokenData(
            id: 'token-1', userId: 'user-1', name: 'Test',
            tokenHash: 'hash', scopes: ['*'], lastUsedAt: null,
            expiresAt: null, createdAt: Carbon::now(),
        );

        $mockUser = createMockGuardUser('user-1');

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('validateToken')
            ->with('valid-token')
            ->once()
            ->andReturn($mcpToken);

        User::shouldReceive('find')
            ->with('user-1')
            ->once()
            ->andReturn($mockUser);

        $guard = createGuard('Bearer valid-token', $tokenService);

        // Call user() multiple times — tokenService->validateToken should only be called once
        $firstCall = $guard->user();
        $secondCall = $guard->user();
        $thirdCall = $guard->user();

        expect($firstCall)->toBe($mockUser);
        expect($secondCall)->toBe($mockUser);
        expect($thirdCall)->toBe($mockUser);
    });

    it('returns null for an invalid token', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('validateToken')
            ->with('invalid-token')
            ->once()
            ->andReturn(null);

        $guard = createGuard('Bearer invalid-token', $tokenService);

        expect($guard->user())->toBeNull();
    });

    it('returns null when token is valid but user does not exist', function () {
        $mcpToken = new McpTokenData(
            id: 'token-1', userId: 'deleted-user', name: 'Test',
            tokenHash: 'hash', scopes: ['*'], lastUsedAt: null,
            expiresAt: null, createdAt: Carbon::now(),
        );

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('validateToken')
            ->with('orphan-token')
            ->once()
            ->andReturn($mcpToken);

        User::shouldReceive('find')
            ->with('deleted-user')
            ->once()
            ->andReturn(null);

        $guard = createGuard('Bearer orphan-token', $tokenService);

        expect($guard->user())->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// check() / guest()
// ---------------------------------------------------------------------------

describe('check and guest', function () {
    it('check returns true when authenticated', function () {
        $mcpToken = new McpTokenData(
            id: 'token-1', userId: 'user-1', name: 'Test',
            tokenHash: 'hash', scopes: ['*'], lastUsedAt: null,
            expiresAt: null, createdAt: Carbon::now(),
        );

        $mockUser = createMockGuardUser('user-1');

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('validateToken')->andReturn($mcpToken);

        User::shouldReceive('find')->andReturn($mockUser);

        $guard = createGuard('Bearer valid-token', $tokenService);

        expect($guard->check())->toBeTrue();
    });

    it('check returns false when no token is provided', function () {
        $guard = createGuard();

        expect($guard->check())->toBeFalse();
    });

    it('guest is inverse of check', function () {
        $guard = createGuard();

        expect($guard->check())->toBeFalse();
        expect($guard->guest())->toBeTrue();

        // Now set a user to make check() return true
        $mockUser = createMockGuardUser('user-1');
        $guard->setUser($mockUser);

        expect($guard->check())->toBeTrue();
        expect($guard->guest())->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// setUser()
// ---------------------------------------------------------------------------

describe('setUser', function () {
    it('sets user directly bypassing token validation', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldNotReceive('validateToken');

        $guard = createGuard(null, $tokenService);
        $mockUser = createMockGuardUser('user-1');

        $result = $guard->setUser($mockUser);

        expect($guard->user())->toBe($mockUser);
        expect($guard->check())->toBeTrue();
        expect($result)->toBe($guard);
    });

    it('hasUser returns true after setUser', function () {
        $guard = createGuard();

        expect($guard->hasUser())->toBeFalse();

        $mockUser = createMockGuardUser('user-1');
        $guard->setUser($mockUser);

        expect($guard->hasUser())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// validate()
// ---------------------------------------------------------------------------

describe('validate', function () {
    it('returns true for valid token credentials', function () {
        $mcpToken = new McpTokenData(
            id: 'token-1', userId: 'user-1', name: 'Test',
            tokenHash: 'hash', scopes: ['*'], lastUsedAt: null,
            expiresAt: null, createdAt: Carbon::now(),
        );

        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('validateToken')
            ->with('valid-token')
            ->once()
            ->andReturn($mcpToken);

        $guard = createGuard(null, $tokenService);

        expect($guard->validate(['token' => 'valid-token']))->toBeTrue();
    });

    it('returns false for invalid token credentials', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldReceive('validateToken')
            ->with('invalid-token')
            ->once()
            ->andReturn(null);

        $guard = createGuard(null, $tokenService);

        expect($guard->validate(['token' => 'invalid-token']))->toBeFalse();
    });

    it('returns false when token key is missing from credentials', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldNotReceive('validateToken');

        $guard = createGuard(null, $tokenService);

        expect($guard->validate([]))->toBeFalse();
    });

    it('returns false when token value is not a string', function () {
        $tokenService = Mockery::mock(TokenService::class);
        $tokenService->shouldNotReceive('validateToken');

        $guard = createGuard(null, $tokenService);

        expect($guard->validate(['token' => 12345]))->toBeFalse();
        expect($guard->validate(['token' => null]))->toBeFalse();
        expect($guard->validate(['token' => true]))->toBeFalse();
        expect($guard->validate(['token' => ['array']]))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// id()
// ---------------------------------------------------------------------------

describe('id', function () {
    it('returns user identifier when authenticated', function () {
        $guard = createGuard();
        $mockUser = createMockGuardUser('user-42');
        $guard->setUser($mockUser);

        expect($guard->id())->toBe('user-42');
    });

    it('returns null when guest', function () {
        $guard = createGuard();

        expect($guard->id())->toBeNull();
    });
});
