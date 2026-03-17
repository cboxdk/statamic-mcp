<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;

class ScopeEnforcementTest extends TestCase
{
    use CreatesTestContent;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        // Run token migration
        $migration = include __DIR__ . '/../../database/migrations/tokens/create_mcp_tokens_table.php';
        $migration->up();

        $oauthMeta = include __DIR__ . '/../../database/migrations/tokens/add_oauth_metadata_to_mcp_tokens_table.php';
        $oauthMeta->up();

        // Enable web context
        Config::set('statamic.mcp.web.enabled', true);
        request()->headers->set('X-MCP-Remote', 'true');

        $this->collectionHandle = 'scope_test_blog';
        $this->createTestCollection($this->collectionHandle);
        $this->createTestBlueprint($this->collectionHandle);
    }

    protected function tearDown(): void
    {
        request()->headers->remove('X-MCP-Remote');
        request()->attributes->remove('mcp_token');

        parent::tearDown();
    }

    public function test_content_read_token_allows_list_but_denies_create(): void
    {
        $user = $this->createMockSuperUser();
        auth()->shouldUse('web');
        auth()->setUser($user);

        $token = $this->createTokenWithScopes([TokenScope::EntriesRead->value]);
        request()->attributes->set('mcp_token', $token);

        $router = new EntriesRouter;

        // List should succeed
        $listResult = $router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);
        $this->assertTrue($listResult['success']);

        // Create should be denied (needs entries:write)
        $createResult = $router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Should Fail'],
        ]);
        $this->assertFalse($createResult['success']);
        $this->assertStringContainsString('Token missing required scope', $createResult['errors'][0]);
    }

    public function test_wildcard_token_allows_create(): void
    {
        $user = $this->createMockSuperUser();
        auth()->shouldUse('web');
        auth()->setUser($user);

        $token = $this->createTokenWithScopes([TokenScope::FullAccess->value]);
        request()->attributes->set('mcp_token', $token);

        $router = new EntriesRouter;

        $result = $router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Wildcard Entry'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['created']);
    }

    public function test_blueprints_read_token_allows_list_but_denies_create(): void
    {
        $user = $this->createMockSuperUser();
        auth()->shouldUse('web');
        auth()->setUser($user);

        $token = $this->createTokenWithScopes([TokenScope::BlueprintsRead->value]);
        request()->attributes->set('mcp_token', $token);

        $router = new BlueprintsRouter;

        // List should succeed
        $listResult = $router->execute([
            'action' => 'list',
            'namespace' => 'collections',
            'include_fields' => false,
        ]);
        $this->assertTrue($listResult['success']);

        // Create should be denied (needs blueprints:write)
        $createResult = $router->execute([
            'action' => 'create',
            'handle' => 'denied_bp',
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ],
        ]);
        $this->assertFalse($createResult['success']);
        $this->assertStringContainsString('Token missing required scope', $createResult['errors'][0]);
    }

    public function test_no_token_in_cli_context_allows_all_operations(): void
    {
        // Remove web context markers to simulate CLI
        request()->headers->remove('X-MCP-Remote');
        Config::set('statamic.mcp.security.force_web_mode', false);

        // No mcp_token set, no auth needed
        request()->attributes->remove('mcp_token');

        $router = new EntriesRouter;

        $result = $router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('entries', $result['data']);
    }

    /**
     * Create a mock super admin user for web context testing.
     */
    private function createMockSuperUser(): Authenticatable
    {
        return new class implements Authenticatable
        {
            public function isSuper(): bool
            {
                return true;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): string
            {
                return 'scope-test-user';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return 'hashed';
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

    /**
     * Create an McpTokenData with the given scopes.
     *
     * @param  array<int, string>  $scopeValues
     */
    private function createTokenWithScopes(array $scopeValues): McpTokenData
    {
        $model = McpToken::create([
            'user_id' => 'scope-test-user',
            'name' => 'Test Token',
            'token' => hash('sha256', 'test-token-' . bin2hex(random_bytes(8))),
            'scopes' => $scopeValues,
        ]);

        /** @var \DateTimeInterface $createdAt */
        $createdAt = $model->created_at;

        return new McpTokenData(
            id: $model->id,
            userId: $model->user_id,
            name: $model->name,
            tokenHash: $model->token,
            scopes: $model->scopes,
            lastUsedAt: $model->last_used_at instanceof \DateTimeInterface ? Carbon::instance($model->last_used_at) : null,
            expiresAt: $model->expires_at instanceof \DateTimeInterface ? Carbon::instance($model->expires_at) : null,
            createdAt: Carbon::instance($createdAt),
            updatedAt: $model->updated_at instanceof \DateTimeInterface ? Carbon::instance($model->updated_at) : null,
        );
    }
}
