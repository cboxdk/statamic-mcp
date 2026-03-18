<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Statamic\Facades\Collection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations/tokens');

    // Enable web MCP endpoint for permission testing
    Config::set('statamic.mcp.web.enabled', true);

    // Force web context via X-MCP-Remote header
    request()->headers->set('X-MCP-Remote', 'true');
});

afterEach(function () {
    request()->headers->remove('X-MCP-Remote');
    request()->attributes->remove('mcp_token');
});

// ---------------------------------------------------------------------------
// Helper: create a mock Statamic user with real isSuper/hasPermission methods
// ---------------------------------------------------------------------------
function createMockUser(bool $isSuper = false, array $permissions = []): Authenticatable
{
    return new class($isSuper, $permissions) implements Authenticatable
    {
        /** @param array<string> $permissions */
        public function __construct(
            private readonly bool $isSuper,
            private readonly array $permissions,
        ) {}

        public function isSuper(): bool
        {
            return $this->isSuper;
        }

        public function hasPermission(string $permission): bool
        {
            return in_array($permission, $this->permissions, true);
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): string
        {
            return 'test-user-id';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return 'hashed-password';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

// ---------------------------------------------------------------------------
// Helper: create a bare Authenticatable without isSuper/hasPermission
// ---------------------------------------------------------------------------
function createBareUser(): Authenticatable
{
    return new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): string
        {
            return 'bare-user';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return 'pw';
        }

        public function getRememberToken(): ?string
        {
            return null;
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

// ---------------------------------------------------------------------------
// Helper: authenticate the mock user
// ---------------------------------------------------------------------------
function actAsUser(Authenticatable $user): void
{
    auth()->shouldUse('web');
    auth()->setUser($user);
}

// ---------------------------------------------------------------------------
// Helper: place an McpTokenData on the current request
// ---------------------------------------------------------------------------
function setMcpTokenOnRequest(McpTokenData $token): void
{
    request()->attributes->set('mcp_token', $token);
}

// ---------------------------------------------------------------------------
// Helper: create an McpTokenData with given scopes
// ---------------------------------------------------------------------------
function createToken(array $scopeValues): McpTokenData
{
    $model = McpToken::create([
        'user_id' => 'test-user-id',
        'name' => 'Test Token',
        'token' => hash('sha256', 'test-token-' . bin2hex(random_bytes(8))),
        'scopes' => $scopeValues,
    ]);

    return new McpTokenData(
        id: $model->id,
        userId: $model->user_id,
        name: $model->name,
        tokenHash: $model->token,
        scopes: $model->scopes,
        lastUsedAt: $model->last_used_at ? Carbon::instance($model->last_used_at) : null,
        expiresAt: $model->expires_at ? Carbon::instance($model->expires_at) : null,
        createdAt: Carbon::instance($model->created_at),
        updatedAt: $model->updated_at ? Carbon::instance($model->updated_at) : null,
    );
}

/*
|--------------------------------------------------------------------------
| 1. Token Scope Mapping (getRequiredTokenScope)
|--------------------------------------------------------------------------
|
| Tests the RouterHelpers::getRequiredTokenScope() method via reflection
| on concrete router instances to verify action-to-scope mapping.
|
*/

describe('Token Scope Mapping', function () {
    it('maps read actions to {domain}:read scope', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredTokenScope');

        $readScope = $method->invoke($router, 'list');
        expect($readScope)->toBe(TokenScope::EntriesRead);

        $getScope = $method->invoke($router, 'get');
        expect($getScope)->toBe(TokenScope::EntriesRead);
    });

    it('maps write actions to {domain}:write scope', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredTokenScope');

        foreach (['create', 'update', 'delete'] as $action) {
            $scope = $method->invoke($router, $action);
            expect($scope)->toBe(TokenScope::EntriesWrite, "Action '{$action}' should map to entries:write");
        }
    });

    it('maps publish and unpublish to write scope', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredTokenScope');

        expect($method->invoke($router, 'publish'))->toBe(TokenScope::EntriesWrite);
        expect($method->invoke($router, 'unpublish'))->toBe(TokenScope::EntriesWrite);
    });

    it('maps cache operations to write scope', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredTokenScope');

        expect($method->invoke($router, 'cache_clear'))->toBe(TokenScope::EntriesWrite);
        expect($method->invoke($router, 'cache_warm'))->toBe(TokenScope::EntriesWrite);
    });

    it('returns null for unknown domains via tryFrom', function () {
        // Create an anonymous concrete router with a non-existent domain
        $router = new class extends BaseRouter
        {
            protected function getDomain(): string
            {
                return 'nonexistent';
            }

            protected function getActions(): array
            {
                return ['list' => 'List'];
            }

            protected function getTypes(): array
            {
                return ['item' => 'Item'];
            }

            protected function executeAction(array $arguments): array
            {
                return [];
            }
        };

        $method = new ReflectionMethod($router, 'getRequiredTokenScope');
        $scope = $method->invoke($router, 'list');

        expect($scope)->toBeNull();
    });

    it('maps scopes correctly across different router domains', function () {
        $routersAndScopes = [
            [new GlobalsRouter, 'list', TokenScope::GlobalsRead],
            [new GlobalsRouter, 'update', TokenScope::GlobalsWrite],
            [new TermsRouter, 'get', TokenScope::TermsRead],
            [new TermsRouter, 'create', TokenScope::TermsWrite],
        ];

        foreach ($routersAndScopes as [$router, $action, $expectedScope]) {
            $method = new ReflectionMethod($router, 'getRequiredTokenScope');
            $scope = $method->invoke($router, $action);
            expect($scope)->toBe($expectedScope, get_class($router) . "::{$action} should map to {$expectedScope->value}");
        }
    });
});

/*
|--------------------------------------------------------------------------
| 2. Nested Permission Flow (checkWebPermissions)
|--------------------------------------------------------------------------
|
| Tests the two-layer permission model:
|   Layer 1: MCP token scope validation
|   Layer 2: Statamic user permission validation
|
*/

describe('Nested Permission Flow', function () {
    beforeEach(function () {
        $this->testId = bin2hex(random_bytes(8));
        $this->collectionHandle = "blog-{$this->testId}";

        Collection::make($this->collectionHandle)
            ->title('Blog')
            ->save();

        $this->entriesRouter = new EntriesRouter;
    });

    it('returns permission error when no user is authenticated', function () {
        auth()->logout();

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('Permission denied');
        expect($result['errors'][0])->toContain('Authentication required');
    });

    it('rejects token with wrong scope (Layer 1)', function () {
        $user = createMockUser(isSuper: true);
        actAsUser($user);

        $token = createToken([TokenScope::BlueprintsRead->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('Token missing required scope');
        expect($result['errors'][0])->toContain('entries:read');
    });

    it('passes Layer 1 with correct token scope', function () {
        $user = createMockUser(isSuper: true);
        actAsUser($user);

        $token = createToken([TokenScope::EntriesRead->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('entries');
    });

    it('passes Layer 1 with wildcard * scope', function () {
        $user = createMockUser(isSuper: true);
        actAsUser($user);

        $token = createToken([TokenScope::FullAccess->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('entries');
    });

    it('skips token scope check when no mcp_token on request (Basic Auth)', function () {
        $user = createMockUser(isSuper: true);
        actAsUser($user);

        // No mcp_token set on request attributes — simulates Basic Auth flow
        request()->attributes->remove('mcp_token');

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('entries');
    });

    it('allows super admin to bypass all Statamic permissions (Layer 2)', function () {
        $user = createMockUser(isSuper: true, permissions: []);
        actAsUser($user);

        $token = createToken([TokenScope::EntriesRead->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('rejects non-super user without required Statamic permission (Layer 2)', function () {
        $user = createMockUser(isSuper: false, permissions: []);
        actAsUser($user);

        $token = createToken([TokenScope::EntriesRead->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('Permission denied');
        expect($result['errors'][0])->toContain('Cannot list');
    });

    it('passes non-super user with required Statamic permission (Layer 2)', function () {
        $user = createMockUser(
            isSuper: false,
            permissions: ["view {$this->collectionHandle} entries"]
        );
        actAsUser($user);

        $token = createToken([TokenScope::EntriesRead->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('entries');
    });

    it('rejects when token scope is correct but Statamic permission missing (both layers must pass)', function () {
        $user = createMockUser(isSuper: false, permissions: []);
        actAsUser($user);

        // Token has the right scope
        $token = createToken([TokenScope::EntriesRead->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('Permission denied');
    });

    it('rejects when Statamic permission present but token scope wrong (both layers must pass)', function () {
        $user = createMockUser(
            isSuper: false,
            permissions: ["view {$this->collectionHandle} entries"]
        );
        actAsUser($user);

        // Token has wrong scope (blueprints instead of entries)
        $token = createToken([TokenScope::BlueprintsRead->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('Token missing required scope');
    });

    // Test removed: bare users without hasPermission() are no longer supported.
    // All users must implement Statamic\Contracts\Auth\User which has isSuper() and hasPermission().

    it('checks write scope for write actions end-to-end', function () {
        $user = createMockUser(isSuper: true);
        actAsUser($user);

        // Token only has read scope
        $token = createToken([TokenScope::EntriesRead->value]);
        setMcpTokenOnRequest($token);

        $result = $this->entriesRouter->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Test Entry'],
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['errors'][0])->toContain('Token missing required scope');
        expect($result['errors'][0])->toContain('entries:write');
    });
});

/*
|--------------------------------------------------------------------------
| 3. Per-Router Permission Definitions (getRequiredPermissions)
|--------------------------------------------------------------------------
|
| Tests that each router's getRequiredPermissions() override returns
| the correct Statamic permissions for each action.
|
*/

describe('EntriesRouter Permission Definitions', function () {
    it('requires collection-specific view permission for list and get', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $listPerms = $method->invoke($router, 'list', ['collection' => 'articles']);
        expect($listPerms)->toBe(['view articles entries']);

        $getPerms = $method->invoke($router, 'get', ['collection' => 'articles']);
        expect($getPerms)->toBe(['view articles entries']);
    });

    it('requires collection-specific create permission for create', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $perms = $method->invoke($router, 'create', ['collection' => 'posts']);
        expect($perms)->toBe(['create posts entries']);
    });

    it('requires collection-specific edit permission for update', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $perms = $method->invoke($router, 'update', ['collection' => 'pages']);
        expect($perms)->toBe(['edit pages entries']);
    });

    it('requires collection-specific delete permission for delete', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $perms = $method->invoke($router, 'delete', ['collection' => 'events']);
        expect($perms)->toBe(['delete events entries']);
    });

    it('requires collection-specific publish permission for publish and unpublish', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $publishPerms = $method->invoke($router, 'publish', ['collection' => 'news']);
        expect($publishPerms)->toBe(['publish news entries']);

        $unpublishPerms = $method->invoke($router, 'unpublish', ['collection' => 'news']);
        expect($unpublishPerms)->toBe(['publish news entries']);
    });

    it('returns empty permissions for unknown actions', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $perms = $method->invoke($router, 'unknown_action', ['collection' => 'blog']);
        expect($perms)->toBe([]);
    });

    it('handles missing collection gracefully', function () {
        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $perms = $method->invoke($router, 'list', []);
        expect($perms)->toBe(['view  entries']);
    });
});

describe('TermsRouter Permission Definitions', function () {
    it('requires taxonomy-specific view permission for list and get', function () {
        $router = new TermsRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $listPerms = $method->invoke($router, 'list', ['taxonomy' => 'tags']);
        expect($listPerms)->toBe(['view tags terms']);

        $getPerms = $method->invoke($router, 'get', ['taxonomy' => 'categories']);
        expect($getPerms)->toBe(['view categories terms']);
    });

    it('requires taxonomy-specific edit permission for create, update, delete', function () {
        $router = new TermsRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        foreach (['create', 'update', 'delete'] as $action) {
            $perms = $method->invoke($router, $action, ['taxonomy' => 'tags']);
            expect($perms)->toBe(['edit tags terms'], "Action '{$action}' should require 'edit tags terms'");
        }
    });

    it('returns empty permissions for unknown actions', function () {
        $router = new TermsRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $perms = $method->invoke($router, 'unknown_action', ['taxonomy' => 'tags']);
        expect($perms)->toBe([]);
    });
});

describe('GlobalsRouter Permission Definitions', function () {
    it('requires correct Statamic permissions for each action', function () {
        $router = new GlobalsRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        // list requires 'configure globals' (admin-level permission)
        $perms = $method->invoke($router, 'list', []);
        expect($perms)->toBe(['configure globals']);

        // get/update without handle falls back to 'configure globals'
        $perms = $method->invoke($router, 'get', []);
        expect($perms)->toBe(['configure globals']);

        // get/update with handle uses scoped 'edit {handle} globals'
        $perms = $method->invoke($router, 'get', ['handle' => 'settings']);
        expect($perms)->toBe(['edit settings globals']);

        $perms = $method->invoke($router, 'update', ['global_set' => 'footer']);
        expect($perms)->toBe(['edit footer globals']);
    });

    it('returns empty permissions for unknown actions', function () {
        $router = new GlobalsRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        $perms = $method->invoke($router, 'delete', []);
        expect($perms)->toBe([]);
    });
});

describe('ContentFacadeRouter Permission Definitions', function () {
    it('requires super permission for all workflows', function () {
        $router = new ContentFacadeRouter;
        $method = new ReflectionMethod($router, 'getRequiredPermissions');

        // All workflows require super since they span all content domains
        $perms = $method->invoke($router, 'execute', ['workflow' => 'content_audit']);
        expect($perms)->toBe(['super']);

        $perms = $method->invoke($router, 'execute', ['workflow' => 'cross_reference']);
        expect($perms)->toBe(['super']);

        // Even unknown workflows return super (caught elsewhere by action routing)
        $perms = $method->invoke($router, 'execute', ['workflow' => 'nonexistent']);
        expect($perms)->toBe(['super']);
    });
});

/*
|--------------------------------------------------------------------------
| 4. Context Detection (isCliContext / isWebContext)
|--------------------------------------------------------------------------
*/

describe('Context Detection', function () {
    it('treats request with X-MCP-Remote header as web context', function () {
        request()->headers->set('X-MCP-Remote', 'true');
        Config::set('statamic.mcp.security.force_web_mode', false);

        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'isCliContext');

        expect($method->invoke($router))->toBeFalse();
    });

    it('treats force_web_mode config as web context', function () {
        request()->headers->remove('X-MCP-Remote');
        Config::set('statamic.mcp.security.force_web_mode', true);

        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'isCliContext');

        expect($method->invoke($router))->toBeFalse();
    });

    it('treats console without remote header or force_web_mode as CLI context', function () {
        request()->headers->remove('X-MCP-Remote');
        Config::set('statamic.mcp.security.force_web_mode', false);

        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'isCliContext');

        // In Pest tests, app()->runningInConsole() is true
        expect($method->invoke($router))->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| 5. Web Tool Enablement (isWebToolEnabled)
|--------------------------------------------------------------------------
*/

describe('Web Tool Enablement', function () {
    it('returns false when web endpoint is disabled', function () {
        Config::set('statamic.mcp.web.enabled', false);
        Config::set('statamic.mcp.tools.entries.enabled', true);

        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'isWebToolEnabled');

        expect($method->invoke($router))->toBeFalse();
    });

    it('returns false when domain tool is disabled', function () {
        Config::set('statamic.mcp.web.enabled', true);
        Config::set('statamic.mcp.tools.entries.enabled', false);

        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'isWebToolEnabled');

        expect($method->invoke($router))->toBeFalse();
    });

    it('returns true when both web endpoint and domain tool are enabled', function () {
        Config::set('statamic.mcp.web.enabled', true);
        Config::set('statamic.mcp.tools.entries.enabled', true);

        $router = new EntriesRouter;
        $method = new ReflectionMethod($router, 'isWebToolEnabled');

        expect($method->invoke($router))->toBeTrue();
    });
});
