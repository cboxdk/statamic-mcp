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

#[Title('Update User')]
class UpdateUserTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.users.update';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Update an existing user with new data, roles, and permissions';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('user_id')
            ->description('User ID or email to update')
            ->required()
            ->string('name')
            ->description('User display name')
            ->optional()
            ->string('email')
            ->description('New email address')
            ->optional()
            ->string('password')
            ->description('New password (will be hashed)')
            ->optional()
            ->raw('roles', [
                'type' => 'array',
                'description' => 'Array of role handles to assign (replaces existing roles)',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->raw('add_roles', [
                'type' => 'array',
                'description' => 'Array of role handles to add to existing roles',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->raw('remove_roles', [
                'type' => 'array',
                'description' => 'Array of role handles to remove from user',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->boolean('super')
            ->description('Whether the user should be a super user')
            ->optional()
            ->raw('data', [
                'type' => 'object',
                'description' => 'User field data to update (merges with existing data)',
                'additionalProperties' => true,
            ])
            ->optional()
            ->boolean('activated')
            ->description('User activation status')
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
        $userId = $arguments['user_id'];
        $name = $arguments['name'] ?? null;
        $email = $arguments['email'] ?? null;
        $password = $arguments['password'] ?? null;
        $roles = $arguments['roles'] ?? null;
        $addRoles = $arguments['add_roles'] ?? [];
        $removeRoles = $arguments['remove_roles'] ?? [];
        $super = $arguments['super'] ?? null;
        $data = $arguments['data'] ?? [];
        $activated = $arguments['activated'] ?? null;

        try {
            // Find the user
            $user = User::find($userId) ?? User::findByEmail($userId);

            if (! $user) {
                return $this->createErrorResponse("User '{$userId}' not found")->toArray();
            }

            $changes = [];
            $originalData = [
                'email' => $user->email(),
                'name' => $user->name(),
                'roles' => $user->roles()->map->handle()->all(),
                'is_super' => $user->isSuper(),
            ];

            // Update name
            if ($name !== null && $name !== $user->name()) {
                $user->set('name', $name);
                $changes['name'] = ['from' => $user->name(), 'to' => $name];
            }

            // Update email
            if ($email !== null && $email !== $user->email()) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $this->createErrorResponse('Invalid email address format')->toArray();
                }

                // Check if new email already exists
                $existingUser = User::findByEmail($email);
                if ($existingUser && $existingUser->id() !== $user->id()) {
                    return $this->createErrorResponse("Email '{$email}' is already in use by another user")->toArray();
                }

                $user->email($email);
                $changes['email'] = ['from' => $user->email(), 'to' => $email];
            }

            // Update password
            if ($password !== null) {
                $user->password($password);
                $changes['password'] = 'updated';
            }

            // Handle super user status
            if ($super !== null) {
                if ($super && ! $user->isSuper()) {
                    $user->makeSuper();
                    $changes['super'] = ['from' => false, 'to' => true];
                } elseif (! $super && $user->isSuper()) {
                    $user->removeSuper();
                    $changes['super'] = ['from' => true, 'to' => false];
                }
            }

            // Handle roles
            if ($roles !== null) {
                // Replace all roles
                $validRoles = $this->validateRoles($roles);
                if (isset($validRoles['error'])) {
                    return $validRoles;
                }

                $user->assignRole(collect($validRoles));
                $changes['roles'] = ['from' => $originalData['roles'], 'to' => $validRoles];
            } else {
                // Add/remove individual roles
                $currentRoles = $user->roles()->map->handle()->all();

                if (! empty($addRoles)) {
                    $validAddRoles = $this->validateRoles($addRoles);
                    if (isset($validAddRoles['error'])) {
                        return $validAddRoles;
                    }

                    foreach ($validAddRoles as $roleHandle) {
                        if (! in_array($roleHandle, $currentRoles)) {
                            $user->assignRole($roleHandle);
                            $changes['roles_added'][] = $roleHandle;
                        }
                    }
                }

                if (! empty($removeRoles)) {
                    foreach ($removeRoles as $roleHandle) {
                        if (in_array($roleHandle, $currentRoles)) {
                            $user->removeRole($roleHandle);
                            $changes['roles_removed'][] = $roleHandle;
                        }
                    }
                }
            }

            // Update custom field data
            if (! empty($data)) {
                $blueprint = Blueprint::find('user');
                $validatedData = [];

                if ($blueprint) {
                    $fields = $blueprint->fields();
                    foreach ($data as $fieldHandle => $value) {
                        if ($fields->has($fieldHandle)) {
                            $validatedData[$fieldHandle] = $value;
                        }
                    }
                } else {
                    $validatedData = $data;
                }

                if (! empty($validatedData)) {
                    $currentData = $user->data()->all();
                    $user->data(array_merge($currentData, $validatedData));
                    $changes['data_updated'] = array_keys($validatedData);
                }
            }

            // Handle activation status
            if ($activated !== null) {
                if (method_exists($user, 'activate') && method_exists($user, 'deactivate')) {
                    if ($activated && ! $user->isActivated()) {
                        $user->activate();
                        $changes['activated'] = ['from' => false, 'to' => true];
                    } elseif (! $activated && $user->isActivated()) {
                        $user->deactivate();
                        $changes['activated'] = ['from' => true, 'to' => false];
                    }
                }
            }

            // Save the user
            $user->save();

            // Clear caches
            Stache::clear();

            return [
                'success' => true,
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->name(),
                    'roles' => $user->roles()->map->handle()->all(),
                    'is_super' => $user->isSuper(),
                    'is_activated' => $user->isActivated() ?? true,
                ],
                'changes_applied' => $changes,
                'original_data' => $originalData,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to update user: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Validate role handles.
     *
     * @param  array<string>  $roles
     *
     * @return array<string>|array<string, mixed>
     */
    private function validateRoles(array $roles): array
    {
        $validRoles = [];
        foreach ($roles as $roleHandle) {
            $role = Role::find($roleHandle);
            if (! $role) {
                return $this->createErrorResponse("Role '{$roleHandle}' not found", [
                    'available_roles' => Role::all()->map->handle()->all(),
                ])->toArray();
            }
            $validRoles[] = $roleHandle;
        }

        return $validRoles;
    }
}
