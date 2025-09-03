<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\Users\CreateUserTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\User;

class CreateUserToolTest extends TestCase
{
    protected CreateUserTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CreateUserTool;

        // Clear any existing state for clean test execution
        // Note: Don't clear Stache here to avoid conflicts with other tests in parallel execution
    }

    public function test_can_create_user()
    {
        $uniqueEmail = 'test-' . uniqid() . '@example.com';

        $result = $this->tool->handle([
            'email' => $uniqueEmail,
            'name' => 'Test User',
            'password' => 'secure-password-123',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        if (! $response['success']) {
            dump('User creation failed:', $response);
        }

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('user', $response['data']);
        $this->assertEquals($uniqueEmail, $response['data']['user']['email']);
        $this->assertEquals('Test User', $response['data']['user']['name']);

        // Verify user was actually created
        $user = User::findByEmail($uniqueEmail);
        $this->assertNotNull($user);
        $this->assertEquals('Test User', $user->name());
    }

    public function test_prevents_duplicate_user()
    {
        $duplicateEmail = 'duplicate-' . uniqid() . '@example.com';

        // Create first user
        $this->tool->handle([
            'email' => $duplicateEmail,
            'name' => 'First User',
            'password' => 'password123',
        ]);

        // Try to create duplicate
        $result = $this->tool->handle([
            'email' => $duplicateEmail,
            'name' => 'Second User',
            'password' => 'password456',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('already exists', $response['errors'][0]);
    }

    public function test_validates_invalid_email()
    {
        $result = $this->tool->handle([
            'email' => 'invalid-email-' . uniqid(),
            'name' => 'Test User',
            'password' => 'password123',
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('email', strtolower($response['errors'][0]));
    }

    public function test_creates_user_with_roles()
    {
        $uniqueEmail = 'editor-' . uniqid() . '@example.com';

        // Create roles first
        $editorRole = \Statamic\Facades\Role::make('editor')->title('Editor')->save();
        $authorRole = \Statamic\Facades\Role::make('author')->title('Author')->save();

        $result = $this->tool->handle([
            'email' => $uniqueEmail,
            'name' => 'Editor User',
            'password' => 'secure-password-123',
            'roles' => ['editor', 'author'],
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        if (! $response['success']) {
            dump('User creation failed:', $response);
        }

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('roles', $response['data']['user']);
        $this->assertContains('editor', $response['data']['user']['roles']);
        $this->assertContains('author', $response['data']['user']['roles']);

        // Verify user was created with roles
        $user = User::findByEmail($uniqueEmail);
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('editor'));
        $this->assertTrue($user->hasRole('author'));
    }

    public function test_dry_run_mode()
    {
        $dryRunEmail = 'dryrun-' . uniqid() . '@example.com';

        $result = $this->tool->handle([
            'email' => $dryRunEmail,
            'name' => 'Dry Run User',
            'password' => 'password123',
            'dry_run' => true,
        ]);

        $resultData = $result->toArray();
        $response = json_decode($resultData['content'][0]['text'], true);

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);

        // Verify user was NOT actually created
        $user = User::findByEmail($dryRunEmail);
        $this->assertNull($user);
    }

    public function test_tool_has_correct_metadata()
    {
        $this->assertEquals('statamic.users.create', $this->tool->name());
        $this->assertStringContainsString('Create a new user with', $this->tool->description());
    }

    protected function tearDown(): void
    {
        try {
            // Clean up any test users (emails ending with @example.com)
            $allUsers = User::all();
            foreach ($allUsers as $user) {
                if (str_ends_with($user->email(), '@example.com')) {
                    $user->delete();
                }
            }

            // Clean up test roles
            $testRoles = ['editor', 'author'];
            foreach ($testRoles as $roleHandle) {
                $role = \Statamic\Facades\Role::find($roleHandle);
                if ($role) {
                    $role->delete();
                }
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors in parallel test execution
        }

        parent::tearDown();
    }
}
