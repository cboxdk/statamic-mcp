<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\E2E;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesAuthenticatedUser;
use Cboxdk\StatamicMcp\Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use CreatesAuthenticatedUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Run token migrations for database-backed token operations
        $create = include __DIR__ . '/../../database/migrations/tokens/create_mcp_tokens_table.php';
        $create->up();

        $addIndex = include __DIR__ . '/../../database/migrations/tokens/add_unique_token_index_to_mcp_tokens_table.php';
        $addIndex->up();

        $oauthMeta = include __DIR__ . '/../../database/migrations/tokens/add_oauth_metadata_to_mcp_tokens_table.php';
        $oauthMeta->up();

        $this->app->singleton(TokenStore::class, DatabaseTokenStore::class);
    }

    public function test_create_token_returns_token_data(): void
    {
        $this->actingAsAdmin()
            ->postJson(cp_route('statamic-mcp.tokens.store'), [
                'name' => 'Test Token',
                'scopes' => ['content:read'],
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'token',
                'model' => ['id', 'name', 'scopes', 'created_at'],
                'message',
            ]);
    }

    public function test_update_token_succeeds(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $createResponse = $this->postJson(cp_route('statamic-mcp.tokens.store'), [
            'name' => 'Original Name',
            'scopes' => ['content:read'],
        ]);

        $tokenId = $createResponse->json('model.id');

        $this->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $tokenId]), [
            'name' => 'Updated Name',
        ])
            ->assertOk()
            ->assertJsonPath('model.name', 'Updated Name');
    }

    public function test_regenerate_token_returns_new_token(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $createResponse = $this->postJson(cp_route('statamic-mcp.tokens.store'), [
            'name' => 'Regenerate Me',
            'scopes' => ['content:read'],
        ]);

        $tokenId = $createResponse->json('model.id');
        $originalToken = $createResponse->json('token');

        $regenerateResponse = $this->postJson(cp_route('statamic-mcp.tokens.regenerate', ['token' => $tokenId]))
            ->assertOk()
            ->assertJsonStructure(['token', 'model', 'message']);

        $this->assertNotEquals($originalToken, $regenerateResponse->json('token'));
    }

    public function test_delete_token_removes_it(): void
    {
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        $createResponse = $this->postJson(cp_route('statamic-mcp.tokens.store'), [
            'name' => 'Delete Me',
            'scopes' => ['content:read'],
        ]);

        $tokenId = $createResponse->json('model.id');

        $this->deleteJson(cp_route('statamic-mcp.tokens.destroy', ['token' => $tokenId]))
            ->assertOk()
            ->assertJsonPath('message', 'Token revoked successfully.');
    }

    public function test_create_token_without_name_fails_validation(): void
    {
        $this->actingAsAdmin()
            ->postJson(cp_route('statamic-mcp.tokens.store'), [
                'scopes' => ['content:read'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
}
