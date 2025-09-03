<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Roles\CreateRoleTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Role;

class CreateRoleToolTest extends TestCase
{
    protected CreateRoleTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateRoleTool;
    }

    public function test_can_create_role()
    {
        $result = $this->tool->handle([
            'handle' => 'content_editor',
            'title' => 'Content Editor',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        if (! $response['success']) {
            dump('Role creation failed:', $response);
        }

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('role', $response['data']);
        $this->assertEquals('content_editor', $response['data']['role']['handle']);
        $this->assertEquals('Content Editor', $response['data']['role']['title']);

        // Verify role was actually created
        $role = Role::find('content_editor');
        $this->assertNotNull($role);
        $this->assertEquals('Content Editor', $role->title());
    }

    public function test_prevents_duplicate_role()
    {
        // Create first role
        $this->tool->handle([
            'handle' => 'duplicate_role',
            'title' => 'First Role',
        ]);

        // Try to create duplicate
        $result = $this->tool->handle([
            'handle' => 'duplicate_role',
            'title' => 'Second Role',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['errors'][0]);
    }

    public function test_creates_role_with_permissions()
    {
        $result = $this->tool->handle([
            'handle' => 'editor_with_perms',
            'title' => 'Editor with Permissions',
            'permissions' => ['access cp', 'edit entries'],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        if (! $response['success']) {
            dump('Role creation failed:', $response);
        }

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('permissions', $response['data']['role']);
        $this->assertContains('access cp', $response['data']['role']['permissions']);
        $this->assertContains('edit entries', $response['data']['role']['permissions']);

        // Verify role was created with permissions
        $role = Role::find('editor_with_perms');
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermission('access cp'));
        $this->assertTrue($role->hasPermission('edit entries'));
    }

    public function test_tool_has_correct_metadata()
    {
        $this->assertEquals('statamic.roles.create', $this->tool->name());
        $this->assertStringContainsString('Create a new role with', $this->tool->description());
    }

    protected function tearDown(): void
    {
        // Clean up test roles
        $testHandles = ['content_editor', 'duplicate_role', 'editor_with_perms'];

        foreach ($testHandles as $handle) {
            $role = Role::find($handle);
            if ($role) {
                $role->delete();
            }
        }

        parent::tearDown();
    }
}
