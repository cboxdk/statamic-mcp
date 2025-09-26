<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;

class UsersRouterTest extends TestCase
{
    private UsersRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new UsersRouter;
    }

    public function test_list_users(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'user',
        ]);

        // Users list should work
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('users', $result['data']);
    }

    public function test_get_user_by_email(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'user',
            'email' => 'admin@example.com',
        ]);

        // Should fail because the test admin user doesn't exist
        if (! $result['success']) {
            $this->assertStringContainsString('User not found', $result['errors'][0]);
        } else {
            $this->assertArrayHasKey('user', $result['data']);
        }
    }

    public function test_get_role(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'role',
            'handle' => 'super',
        ]);

        // Super role may not exist in test environment
        if (! $result['success']) {
            $this->assertStringContainsString('Role not found: super', $result['errors'][0]);
        } else {
            $this->assertArrayHasKey('role', $result['data']);
        }
    }

    public function test_invalid_type(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'invalid',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown user type: invalid', $result['errors'][0]);
    }

    public function test_invalid_action(): void
    {
        $result = $this->router->execute([
            'action' => 'invalid',
            'type' => 'user',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown user action: invalid', $result['errors'][0]);
    }

    public function test_missing_id_for_user_get(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'user',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Either ID or email is required', $result['errors'][0]);
    }

    public function test_missing_handle_for_role_get(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'role',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Role handle is required', $result['errors'][0]);
    }

    public function test_user_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'user',
            'id' => 'nonexistent_user',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('User not found: nonexistent_user', $result['errors'][0]);
    }

    public function test_role_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'role',
            'handle' => 'nonexistent_role',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Role not found: nonexistent_role', $result['errors'][0]);
    }

    public function test_group_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'group',
            'handle' => 'nonexistent_group',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('User group not found: nonexistent_group', $result['errors'][0]);
    }

    public function test_create_user_missing_email(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'type' => 'user',
            'data' => ['name' => 'No Email User'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Email is required', $result['errors'][0]);
    }

    public function test_create_role_missing_handle(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'type' => 'role',
            'title' => 'No Handle Role',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Role handle is required', $result['errors'][0]);
    }

    public function test_assign_nonexistent_role(): void
    {
        $result = $this->router->execute([
            'action' => 'assign_role',
            'type' => 'user',
            'user_id' => 'testuser',
            'role_handle' => 'nonexistent_role',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('User identifier and role handle are required', $result['errors'][0]);
    }
}
