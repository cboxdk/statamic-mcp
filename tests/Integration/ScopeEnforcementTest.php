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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Statamic\Facades\User;

class ScopeEnforcementTest extends TestCase
{
    use CreatesTestContent;
    use RefreshDatabase;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/tokens');

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
        $user = $this->createSuperUser();
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

    public function test_legacy_wildcard_token_is_refused(): void
    {
        $user = $this->createSuperUser();
        auth()->shouldUse('web');
        auth()->setUser($user);

        request()->attributes->set('mcp_token', $this->createTokenWithScopes([TokenScope::FullAccess->value]));

        $result = (new EntriesRouter)->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('must be approved again', $result['errors'][0]);
    }

    public function test_write_token_edits_but_needs_its_own_scopes_to_publish_and_delete(): void
    {
        $user = $this->createSuperUser();
        auth()->shouldUse('web');
        auth()->setUser($user);

        request()->attributes->set('mcp_token', $this->createTokenWithScopes([
            TokenScope::EntriesRead->value, TokenScope::EntriesWrite->value,
        ]));
        $router = new EntriesRouter;

        // Create succeeds, but as a draft: publishing is not this token's to do.
        $created = $router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Draft only'],
        ]);
        $this->assertTrue($created['success']);
        $this->assertFalse($created['data']['entry']['published']);
        $id = $created['data']['entry']['id'];

        $updated = $router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $id,
            'data' => ['title' => 'Edited'],
        ]);
        $this->assertTrue($updated['success']);

        // Asking to publish explicitly is refused, not silently downgraded.
        $explicit = $router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Wants live', 'published' => true],
        ]);
        $this->assertFalse($explicit['success']);
        $this->assertStringContainsString('Token missing required scope: entries:publish', $explicit['errors'][0]);

        foreach (['publish' => 'entries:publish', 'unpublish' => 'entries:publish', 'delete' => 'entries:delete'] as $action => $scope) {
            $refused = $router->execute(['action' => $action, 'collection' => $this->collectionHandle, 'id' => $id]);
            $this->assertFalse($refused['success'], $action);
            $this->assertStringContainsString("Token missing required scope: {$scope}", $refused['errors'][0]);
        }

        request()->attributes->set('mcp_token', $this->createTokenWithScopes([
            TokenScope::EntriesRead->value, TokenScope::EntriesWrite->value,
            TokenScope::EntriesPublish->value, TokenScope::EntriesDelete->value,
        ]));

        $published = $router->execute(['action' => 'publish', 'collection' => $this->collectionHandle, 'id' => $id]);
        $this->assertTrue($published['success']);
        $this->assertTrue($published['data']['entry']['published']);

        $deleted = $router->execute(['action' => 'delete', 'collection' => $this->collectionHandle, 'id' => $id]);
        $this->assertTrue($deleted['success']);
    }

    public function test_blueprints_read_token_allows_list_but_denies_create(): void
    {
        $user = $this->createSuperUser();
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

        // Create should be denied (needs blueprints:admin)
        $createResult = $router->execute([
            'action' => 'create',
            'handle' => 'denied_bp',
            'namespace' => 'collections',
            'collection' => $this->collectionHandle,
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
     * Create a real Statamic super admin so site and entry policies run in web context.
     */
    private function createSuperUser(): Authenticatable
    {
        $user = User::make()->id('scope-test-user')->email('scope-test@example.test')->makeSuper();
        $user->save();

        return $user;
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
