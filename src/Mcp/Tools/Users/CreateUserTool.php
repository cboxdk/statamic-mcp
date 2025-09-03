<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Users;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

#[Title('Create User')]
class CreateUserTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.users.create';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Create a new user with roles and custom field data';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('email')
            ->description('User email address (serves as unique identifier)')
            ->required()
            ->string('name')
            ->description('User display name')
            ->optional()
            ->string('password')
            ->description('User password (will be hashed)')
            ->optional()
            ->raw('roles', [
                'type' => 'array',
                'description' => 'Array of role handles to assign to the user',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->raw('groups', [
                'type' => 'array',
                'description' => 'Array of group handles to assign to the user',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->boolean('super')
            ->description('Whether the user should be a super user')
            ->optional()
            ->raw('data', [
                'type' => 'object',
                'description' => 'Additional user field data based on user blueprint',
                'additionalProperties' => true,
            ])
            ->optional()
            ->boolean('send_activation_email')
            ->description('Send activation email to the user')
            ->optional()
            ->boolean('dry_run')
            ->description('Simulate the user creation without actually creating the user')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $email = $arguments['email'];
        $name = $arguments['name'] ?? '';
        $password = $arguments['password'] ?? null;
        $roles = $arguments['roles'] ?? [];
        $groups = $arguments['groups'] ?? [];
        $super = $arguments['super'] ?? false;
        $data = $arguments['data'] ?? [];
        $sendActivationEmail = $arguments['send_activation_email'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            // Check if user already exists
            if (User::findByEmail($email)) {
                return $this->createErrorResponse("User with email '{$email}' already exists")->toArray();
            }

            // Validate email format
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->createErrorResponse('Invalid email address format')->toArray();
            }

            // Validate roles
            $validRoles = [];
            foreach ($roles as $roleHandle) {
                $role = Role::find($roleHandle);
                if (! $role) {
                    return $this->createErrorResponse("Role '{$roleHandle}' not found", [
                        'available_roles' => Role::all()->map(fn ($item) => $item->handle())->all(),
                    ])->toArray();
                }
                $validRoles[] = $roleHandle;
            }

            // Validate groups (if groups are configured)
            $validGroups = [];
            foreach ($groups as $groupHandle) {
                // Groups validation would depend on your specific group implementation
                $validGroups[] = $groupHandle;
            }

            // Get user blueprint for field validation
            $blueprint = Blueprint::find('user');
            if ($blueprint) {
                $fields = $blueprint->fields();
                $validatedData = [];

                foreach ($data as $fieldHandle => $value) {
                    if ($fields->has($fieldHandle)) {
                        $field = $fields->get($fieldHandle);
                        // Basic validation - you could extend this
                        $validatedData[$fieldHandle] = $value;
                    }
                }
                $data = $validatedData;
            }

            if ($dryRun) {
                return [
                    'success' => true,
                    'dry_run' => true,
                    'would_create' => [
                        'email' => $email,
                        'name' => $name,
                        'roles' => $validRoles,
                        'groups' => $validGroups,
                        'is_super' => $super,
                        'has_password' => ! empty($password),
                        'data_fields' => array_keys($data),
                        'send_activation_email' => $sendActivationEmail,
                    ],
                ];
            }

            // Create the user
            $userData = array_merge([
                'email' => $email,
                'name' => $name,
            ], $data);

            $user = User::make()
                ->email($email)
                ->data($userData);

            // Set password if provided
            if ($password) {
                $user->password($password);
            }

            // Set super user status
            if ($super) {
                $user->makeSuper();
            }

            // Assign roles
            if (! empty($validRoles)) {
                foreach ($validRoles as $roleHandle) {
                    $role = Role::find($roleHandle);
                    if ($role) {
                        $user->assignRole($role);
                    }
                }
            }

            // Assign groups
            if (! empty($validGroups)) {
                foreach ($validGroups as $group) {
                    $user->addToGroup($group);
                }
            }

            // Save the user
            $user->save();

            // Clear caches
            Stache::clear();

            // Send activation email if requested
            if ($sendActivationEmail) {
                try {
                    // Use reflection to call the method if it exists
                    $reflection = new \ReflectionClass($user);
                    if ($reflection->hasMethod('sendActivationNotification')) {
                        $method = $reflection->getMethod('sendActivationNotification');
                        $method->invoke($user);
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail user creation
                    $emailError = 'Failed to send activation email: ' . $e->getMessage();
                }
            }

            return [
                'success' => true,
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->name(),
                    'roles' => $user->roles()->map(fn ($item) => $item->handle())->all(),
                    'groups' => $user->groups()->map(fn ($item) => $item->handle())->all() ?? [],
                    'is_super' => $user->isSuper(),
                    'has_password' => ! empty($user->password()),
                    'is_activated' => true, // Always activated in Statamic v5
                ],
                'data_fields' => array_keys($data),
                'activation_email_sent' => $sendActivationEmail && ! isset($emailError),
                'email_error' => $emailError ?? null,
                'next_steps' => [
                    'set_password' => ! $password ? 'User needs to set a password' : null,
                    'activate_account' => $sendActivationEmail ? 'User will receive activation email' : 'Manual activation may be required',
                    'assign_permissions' => 'Review user roles and permissions',
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to create user: ' . $e->getMessage())->toArray();
        }
    }
}
