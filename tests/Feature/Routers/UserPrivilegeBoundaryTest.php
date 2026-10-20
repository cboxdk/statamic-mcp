<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * statamic-users must not let a delegated, non-super caller escalate past its
 * own authority: granting 'super' through a role, attaching roles without
 * 'assign roles', or setting another user's password without 'change
 * passwords'. These guards only apply in web context (CLI is the trusted
 * operator), so the tests force web mode and act as a real non-super user.
 */
class UserPrivilegeBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        config(['statamic.mcp.security.force_web_mode' => true]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actAs(array $permissions, bool $super = false): UserContract
    {
        $handle = 'role-' . bin2hex(random_bytes(4));
        Role::make()->handle($handle)->title('Role')->permissions(['access cp', ...$permissions])->save();
        $user = User::make()->id('u-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        if ($super) {
            $user->makeSuper();
        } else {
            $user->assignRole($handle);
        }
        $user->save();
        $this->actingAs($user);

        return $user;
    }

    public function test_edit_roles_cannot_grant_super(): void
    {
        $role = 'mgr-' . bin2hex(random_bytes(4));
        Role::make()->handle($role)->title('Mgr')->permissions(['access cp', 'edit roles'])->save();
        $this->actAs(['edit roles']);

        $result = (new UsersRouter)->execute([
            'action' => 'update', 'resource_type' => 'role', 'handle' => $role,
            'data' => ['permissions' => ['access cp', 'edit roles', 'super']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('super', strtolower((string) ($result['errors'][0] ?? '')));
        $this->assertNotContains('super', Role::find($role)->permissions()->all());
    }

    public function test_super_admin_may_still_grant_super(): void
    {
        $role = 'mgr-' . bin2hex(random_bytes(4));
        Role::make()->handle($role)->title('Mgr')->permissions(['access cp'])->save();
        $this->actAs([], super: true);

        $result = (new UsersRouter)->execute([
            'action' => 'update', 'resource_type' => 'role', 'handle' => $role,
            'data' => ['permissions' => ['access cp', 'super']],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertContains('super', Role::find($role)->permissions()->all());
    }

    public function test_create_users_cannot_attach_roles_without_assign_roles(): void
    {
        Role::make()->handle('elevated')->title('Elevated')->permissions(['access cp', 'super'])->save();
        $this->actAs(['create users']);

        $result = (new UsersRouter)->execute([
            'action' => 'create', 'resource_type' => 'user',
            'data' => ['email' => 'new@example.test', 'password' => 'a-dummy-password', 'roles' => ['elevated']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('assign roles', strtolower((string) ($result['errors'][0] ?? '')));
        $this->assertNull(User::findByEmail('new@example.test'));
    }

    public function test_edit_users_cannot_set_another_users_password(): void
    {
        $victim = User::make()->id('victim-1')->email('victim@example.test')->makeSuper();
        $victim->password('original-secret-1');
        $victim->save();
        $this->actAs(['edit users']);

        $result = (new UsersRouter)->execute([
            'action' => 'update', 'resource_type' => 'user', 'id' => 'victim-1',
            'data' => ['password' => 'attacker-chosen-1'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('change passwords', strtolower((string) ($result['errors'][0] ?? '')));
    }

    public function test_edit_user_groups_cannot_attach_roles_without_assign_roles(): void
    {
        $role = 'elevated-' . bin2hex(random_bytes(4));
        $group = 'staff-' . bin2hex(random_bytes(4));
        Role::make()->handle($role)->title('Elevated')->permissions(['access cp', 'super'])->save();
        $this->actAs(['create user groups', 'edit user groups']);

        $result = (new UsersRouter)->execute([
            'action' => 'create', 'resource_type' => 'group',
            'data' => ['handle' => $group, 'title' => 'Staff', 'roles' => [$role]],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('assign roles', strtolower((string) ($result['errors'][0] ?? '')));
    }
}
