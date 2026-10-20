<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\CP;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * 'manage all mcp tokens' lets an admin list, rename, re-scope and revoke every
 * user's tokens. It must not become a way to obtain a working credential for
 * another user, or to raise a token above the admin's own authority.
 */
class TokenAdminBoundaryTest extends TestCase
{
    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        $this->tokens = $this->app->make(TokenService::class);

        Role::make()->handle('token-admin')->title('Token admin')->permissions([
            'access cp', 'view mcp dashboard', 'create mcp tokens', 'manage all mcp tokens',
        ])->save();
    }

    private function admin(string $id = 'admin-1'): \Statamic\Contracts\Auth\User
    {
        $u = User::make()->id($id)->email("{$id}@example.test");
        $u->assignRole('token-admin');
        $u->save();

        return $u;
    }

    private function superUser(string $id = 'super-1'): \Statamic\Contracts\Auth\User
    {
        $u = User::make()->id($id)->email("{$id}@example.test")->makeSuper();
        $u->save();

        return $u;
    }

    public function test_admin_cannot_regenerate_another_users_token(): void
    {
        $this->superUser();
        $token = $this->tokens->createToken('super-1', 'Super read', [TokenScope::ContentRead])['model'];

        $this->actingAs($this->admin())
            ->postJson(cp_route('statamic-mcp.tokens.regenerate', ['token' => $token->id]))
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'A token owned by another user cannot be regenerated — its secret would be disclosed to you. Revoke it instead and ask the owner to create a new one.']);
    }

    public function test_admin_can_regenerate_their_own_token(): void
    {
        $admin = $this->admin();
        $token = $this->tokens->createToken('admin-1', 'Mine', [TokenScope::EntriesRead])['model'];

        $response = $this->actingAs($admin)
            ->postJson(cp_route('statamic-mcp.tokens.regenerate', ['token' => $token->id]))
            ->assertOk();

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_non_super_admin_cannot_modify_a_super_admins_token(): void
    {
        $this->superUser();
        $token = $this->tokens->createToken('super-1', 'Super read', [TokenScope::ContentRead])['model'];

        $this->actingAs($this->admin())
            ->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $token->id]), ['scopes' => ['*']])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => "Only a super admin can modify a super admin's token."]);
    }

    public function test_widening_another_users_token_is_capped_by_the_editor(): void
    {
        // A non-super editor owns a token; the admin (also non-super, no users
        // rights) cannot add users:write it could not hold itself.
        Role::make()->handle('plain-editor')->title('Editor')->permissions(['access cp', 'view blog entries'])->save();
        $owner = User::make()->id('owner-1')->email('owner@example.test');
        $owner->assignRole('plain-editor');
        $owner->save();
        $token = $this->tokens->createToken('owner-1', 'Owned', [TokenScope::EntriesRead])['model'];

        $this->actingAs($this->admin())
            ->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $token->id]), ['scopes' => ['entries:read', 'users:write']])
            ->assertStatus(422);
    }
}
