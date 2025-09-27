<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ExecutesWithAudit;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\JsonSchema\JsonSchema;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

class UsersRouter extends BaseRouter
{
    use ExecutesWithAudit;
    use RouterHelpers;

    protected function getToolName(): string
    {
        return 'statamic.users';
    }

    protected function getToolDescription(): string
    {
        return 'Manage Statamic users, roles, and user groups: list, get, create, update, delete, activate, deactivate operations';
    }

    protected function getDomain(): string
    {
        return 'users';
    }

    protected function getActions(): array
    {
        return [
            'list' => [
                'description' => 'List users, roles, or groups with filtering',
                'purpose' => 'User management overview and discovery',
                'destructive' => false,
                'required' => ['type'],
                'optional' => ['include_details', 'include_permissions'],
                'examples' => [
                    ['action' => 'list', 'type' => 'user'],
                    ['action' => 'list', 'type' => 'role', 'include_permissions' => true],
                    ['action' => 'list', 'type' => 'group'],
                ],
            ],
            'get' => [
                'description' => 'Get specific user, role, or group details',
                'purpose' => 'Detailed user information retrieval',
                'destructive' => false,
                'required' => ['type', 'handle'],
                'optional' => ['include_details', 'include_permissions'],
                'examples' => [
                    ['action' => 'get', 'type' => 'user', 'id' => 'user@example.com'],
                    ['action' => 'get', 'type' => 'role', 'handle' => 'editor'],
                ],
            ],
            'create' => [
                'description' => 'Create new user, role, or group',
                'purpose' => 'User management and access control setup',
                'destructive' => false,
                'required' => ['type', 'data'],
                'optional' => [],
                'examples' => [
                    ['action' => 'create', 'type' => 'user', 'data' => ['email' => 'user@example.com', 'name' => 'User Name']],
                    ['action' => 'create', 'type' => 'role', 'data' => ['handle' => 'editor', 'title' => 'Editor']],
                ],
            ],
            'update' => [
                'description' => 'Update existing user, role, or group',
                'purpose' => 'User information and permission management',
                'destructive' => false,
                'required' => ['type', 'handle', 'data'],
                'optional' => [],
                'examples' => [
                    ['action' => 'update', 'type' => 'user', 'id' => 'user@example.com', 'data' => ['name' => 'New Name']],
                ],
            ],
            'delete' => [
                'description' => 'Delete user, role, or group',
                'purpose' => 'User management cleanup',
                'destructive' => true,
                'required' => ['type', 'handle'],
                'optional' => [],
                'examples' => [
                    ['action' => 'delete', 'type' => 'user', 'id' => 'user@example.com'],
                ],
            ],
            'activate' => [
                'description' => 'Activate user account',
                'purpose' => 'User account management',
                'destructive' => false,
                'required' => ['type', 'id'],
                'optional' => [],
                'examples' => [
                    ['action' => 'activate', 'type' => 'user', 'id' => 'user@example.com'],
                ],
            ],
            'deactivate' => [
                'description' => 'Deactivate user account',
                'purpose' => 'User access control',
                'destructive' => true,
                'required' => ['type', 'id'],
                'optional' => [],
                'examples' => [
                    ['action' => 'deactivate', 'type' => 'user', 'id' => 'user@example.com'],
                ],
            ],
            'assign_role' => [
                'description' => 'Assign role to user',
                'purpose' => 'User permission management',
                'destructive' => false,
                'required' => ['type', 'id', 'role'],
                'optional' => [],
                'examples' => [
                    ['action' => 'assign_role', 'type' => 'user', 'id' => 'user@example.com', 'role' => 'editor'],
                ],
            ],
            'remove_role' => [
                'description' => 'Remove role from user',
                'purpose' => 'User permission management',
                'destructive' => true,
                'required' => ['type', 'id', 'role'],
                'optional' => [],
                'examples' => [
                    ['action' => 'remove_role', 'type' => 'user', 'id' => 'user@example.com', 'role' => 'editor'],
                ],
            ],
        ];
    }

    protected function getTypes(): array
    {
        return [
            'user' => [
                'description' => 'Statamic user accounts with authentication and authorization',
                'properties' => ['id', 'email', 'name', 'roles', 'groups', 'data', 'preferences'],
                'relationships' => ['roles', 'groups', 'entries', 'assets'],
                'examples' => [
                    'Basic user creation with email and name',
                    'User with custom fields and roles',
                    'Super user with all permissions',
                ],
            ],
            'role' => [
                'description' => 'Permission-based roles for access control',
                'properties' => ['handle', 'title', 'permissions'],
                'relationships' => ['users', 'groups'],
                'examples' => [
                    'Editor role with content management permissions',
                    'Admin role with system access',
                    'Custom role with specific permissions',
                ],
            ],
            'group' => [
                'description' => 'User groups for organizing users and role assignment',
                'properties' => ['handle', 'title', 'roles', 'users'],
                'relationships' => ['users', 'roles'],
                'examples' => [
                    'Content team group with editor roles',
                    'Admin group with system roles',
                    'Custom group with specific role combinations',
                ],
            ],
        ];
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'type' => JsonSchema::string()
                ->description('User type to operate on')
                ->enum(['user', 'role', 'group'])
                ->required(),
            'id' => JsonSchema::string()
                ->description('User ID, role handle, or group handle'),
            'email' => JsonSchema::string()
                ->description('User email address'),
            'handle' => JsonSchema::string()
                ->description('Role or group handle'),
            'data' => JsonSchema::object()
                ->description('User, role, or group data for create/update operations'),
            'role' => JsonSchema::string()
                ->description('Role handle for role assignment operations'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed information (default: true)'),
            'include_permissions' => JsonSchema::boolean()
                ->description('Include permission information for roles (default: false)'),
            'filters' => JsonSchema::object()
                ->description('Filtering options for list operations'),
        ]);
    }

    /**
     * Route user operations to appropriate handlers with security checks and audit logging.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = $arguments['action'];

        // Check if tool is enabled for current context
        if (! $this->isCliContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Users tool is disabled for web access')->toArray();
        }

        // Apply security checks for web context
        if ($this->isWebContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Execute action with audit logging
        return $this->executeWithAuditLog($action, $arguments);
    }

    /**
     * Perform the actual domain action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function performDomainAction(string $action, array $arguments): array
    {
        // Validate required parameters
        $type = $arguments['type'];

        // Route to type-specific handlers
        return match ($type) {
            'user' => $this->handleUserAction($action, $arguments),
            'role' => $this->handleRoleAction($action, $arguments),
            'group' => $this->handleGroupAction($action, $arguments),
            default => $this->createErrorResponse("Unknown user type: {$type}")->toArray(),
        };
    }

    /**
     * Handle user operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleUserAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listUsers($arguments),
            'get' => $this->getUser($arguments),
            'create' => $this->createUser($arguments),
            'update' => $this->updateUser($arguments),
            'delete' => $this->deleteUser($arguments),
            'activate' => $this->activateUser($arguments),
            'deactivate' => $this->deactivateUser($arguments),
            'assign_role' => $this->assignRole($arguments),
            'remove_role' => $this->removeRole($arguments),
            default => ['success' => false, 'errors' => ["Unknown user action: {$action}"]],
        };
    }

    /**
     * Handle role operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleRoleAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listRoles($arguments),
            'get' => $this->getRole($arguments),
            'create' => $this->createRole($arguments),
            'update' => $this->updateRole($arguments),
            'delete' => $this->deleteRole($arguments),
            default => ['success' => false, 'errors' => ["Unknown role action: {$action}"]],
        };
    }

    /**
     * Handle group operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleGroupAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listGroups($arguments),
            'get' => $this->getGroup($arguments),
            'create' => $this->createGroup($arguments),
            'update' => $this->updateGroup($arguments),
            'delete' => $this->deleteGroup($arguments),
            default => ['success' => false, 'errors' => ["Unknown group action: {$action}"]],
        };
    }

    // User Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listUsers(array $arguments): array
    {
        if (! $this->hasPermission('view', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot list users')->toArray();
        }

        try {
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $users = User::all()->map(function ($user) use ($includeDetails) {
                $data = [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->name(),
                    'super' => $user->isSuper(),
                ];

                if ($includeDetails) {
                    $data = array_merge($data, [
                        'roles' => $user->roles()->map->handle()->all(),
                        'groups' => $user->groups()->map->handle()->all(),
                        'preferences' => $user->preferences(),
                        'last_login' => $user->lastLogin()?->timestamp,
                        'avatar' => $user->avatar(),
                        'initials' => $user->initials(),
                        'data' => $user->data()->except(['password', 'remember_token'])->all(),
                    ]);
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'users' => $users,
                    'total' => count($users),
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to list users: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getUser(array $arguments): array
    {
        if (! $this->hasPermission('view', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot view users')->toArray();
        }

        try {
            $id = $arguments['id'] ?? null;
            $email = $arguments['email'] ?? null;

            if (! $id && ! $email) {
                return ['success' => false, 'errors' => ['Either ID or email is required']];
            }

            $user = $id ? User::find($id) : User::findByEmail($email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            $data = [
                'id' => $user->id(),
                'email' => $user->email(),
                'name' => $user->name(),
                'super' => $user->isSuper(),
                'roles' => $user->roles()->map->handle()->all(),
                'groups' => $user->groups()->map->handle()->all(),
                'preferences' => $user->preferences(),
                'last_login' => $user->lastLogin()?->timestamp,
                'avatar' => $user->avatar(),
                'initials' => $user->initials(),
                'permissions' => $user->permissions()->all(),
                'data' => $user->data()->except(['password', 'remember_token'])->all(),
            ];

            return [
                'success' => true,
                'data' => ['user' => $data],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to get user: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createUser(array $arguments): array
    {
        if (! $this->hasPermission('create', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot create users')->toArray();
        }

        try {
            $data = $arguments['data'] ?? [];
            $email = $data['email'] ?? null;

            if (! $email) {
                return ['success' => false, 'errors' => ['Email is required']];
            }

            if (User::findByEmail($email)) {
                return ['success' => false, 'errors' => ["User with email '{$email}' already exists"]];
            }

            $user = User::make()->email($email);

            // Set user data
            if (isset($data['name'])) {
                $user->set('name', $data['name']);
            }
            if (isset($data['password'])) {
                $user->password($data['password']);
            }
            if (isset($data['super']) && $data['super']) {
                $user->makeSuper();
            }

            $user->save();

            // Assign roles if provided
            if (isset($data['roles']) && is_array($data['roles'])) {
                foreach ($data['roles'] as $roleHandle) {
                    $role = Role::find($roleHandle);
                    if ($role) {
                        $user->assignRole($role);
                    }
                }
                $user->save();
            }

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                        'created' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to create user: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateUser(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot update users')->toArray();
        }

        try {
            $id = $arguments['id'] ?? null;
            $email = $arguments['email'] ?? null;
            $data = $arguments['data'] ?? [];

            if (! $id && ! $email) {
                return ['success' => false, 'errors' => ['Either ID or email is required']];
            }

            $user = $id ? User::find($id) : User::findByEmail($email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            // Update user data
            foreach ($data as $key => $value) {
                match ($key) {
                    'name' => $user->set('name', $value),
                    'email' => $user->email($value),
                    'password' => $user->password($value),
                    'super' => $value ? $user->makeSuper() : null,
                    default => $user->set($key, $value),
                };
            }

            $user->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                        'updated' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to update user: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteUser(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot delete users')->toArray();
        }

        try {
            $id = $arguments['id'] ?? null;
            $email = $arguments['email'] ?? null;

            if (! $id && ! $email) {
                return ['success' => false, 'errors' => ['Either ID or email is required']];
            }

            $user = $id ? User::find($id) : User::findByEmail($email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            // Prevent deletion of super user if it's the only one
            if ($user->isSuper()) {
                $superCount = User::all()->filter(function ($u) {
                    return $u->isSuper();
                })->count();
                if ($superCount <= 1) {
                    return [
                        'success' => false,
                        'errors' => ['Cannot delete the last super user'],
                    ];
                }
            }

            $userId = $user->id();
            $userEmail = $user->email();
            $user->delete();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $userId,
                        'email' => $userEmail,
                        'deleted' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to delete user: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function activateUser(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot activate users')->toArray();
        }

        try {
            $id = $arguments['id'] ?? null;
            $email = $arguments['email'] ?? null;

            if (! $id && ! $email) {
                return ['success' => false, 'errors' => ['Either ID or email is required']];
            }

            $user = $id ? User::find($id) : User::findByEmail($email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            // Activate user by ensuring they are not blocked/suspended
            // In Statamic, users can be activated by removing any 'blocked' status or similar
            $user->set('status', 'active');
            $user->save();

            // Clear caches
            \Statamic\Facades\Stache::clear();

            return [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->get('name'),
                        'status' => $user->get('status', 'active'),
                    ],
                    'activated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to activate user: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deactivateUser(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot deactivate users')->toArray();
        }

        try {
            $id = $arguments['id'] ?? null;
            $email = $arguments['email'] ?? null;
            $reason = $arguments['reason'] ?? 'deactivated';

            if (! $id && ! $email) {
                return ['success' => false, 'errors' => ['Either ID or email is required']];
            }

            $user = $id ? User::find($id) : User::findByEmail($email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            // Prevent deactivating super users as safety measure
            if ($user->isSuper()) {
                return $this->createErrorResponse('Cannot deactivate super users for security reasons')->toArray();
            }

            // Deactivate user by setting status
            $user->set('status', 'inactive');
            $user->set('deactivation_reason', $reason);
            $user->set('deactivated_at', now()->toISOString());
            $user->save();

            // Clear caches
            \Statamic\Facades\Stache::clear();

            return [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->get('name'),
                        'status' => $user->get('status'),
                        'deactivation_reason' => $reason,
                    ],
                    'deactivated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to deactivate user: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function assignRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot assign role to users')->toArray();
        }

        try {
            $id = $arguments['id'] ?? null;
            $email = $arguments['email'] ?? null;
            $roleHandle = $arguments['role'] ?? null;

            if ((! $id && ! $email) || ! $roleHandle) {
                return ['success' => false, 'errors' => ['User identifier and role handle are required']];
            }

            $user = $id ? User::find($id) : User::findByEmail($email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            $role = Role::find($roleHandle);
            if (! $role) {
                return $this->createErrorResponse("Role not found: {$roleHandle}")->toArray();
            }

            $user->assignRole($role);
            $user->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'role_assigned' => $roleHandle,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to assign role: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function removeRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot remove role from users')->toArray();
        }

        try {
            $id = $arguments['id'] ?? null;
            $email = $arguments['email'] ?? null;
            $roleHandle = $arguments['role'] ?? null;

            if ((! $id && ! $email) || ! $roleHandle) {
                return ['success' => false, 'errors' => ['User identifier and role handle are required']];
            }

            $user = $id ? User::find($id) : User::findByEmail($email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            $role = Role::find($roleHandle);
            if (! $role) {
                return $this->createErrorResponse("Role not found: {$roleHandle}")->toArray();
            }

            $user->removeRole($role);
            $user->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'role_removed' => $roleHandle,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to remove role: {$e->getMessage()}"]];
        }
    }

    // Role Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listRoles(array $arguments): array
    {
        if (! $this->hasPermission('view', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot list roles')->toArray();
        }

        try {
            $includePermissions = $this->getBooleanArgument($arguments, 'include_permissions', false);
            $roles = Role::all()->map(function ($role) use ($includePermissions) {
                $data = [
                    'handle' => $role->handle(),
                    'title' => $role->title(),
                ];

                if ($includePermissions) {
                    $data['permissions'] = $role->permissions();
                }

                return $data;
            })->all();

            return [
                'success' => true,
                'data' => [
                    'roles' => $roles,
                    'total' => count($roles),
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to list roles: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getRole(array $arguments): array
    {
        if (! $this->hasPermission('view', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot view roles')->toArray();
        }

        try {
            $handle = $arguments['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Role handle is required']];
            }

            $role = Role::find($handle);
            if (! $role) {
                return $this->createErrorResponse("Role not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $role->handle(),
                'title' => $role->title(),
                'permissions' => $role->permissions(),
                'user_count' => User::all()->filter(function ($u) use ($role) {
                    return $u->hasRole($role->handle());
                })->count(),
            ];

            return [
                'success' => true,
                'data' => ['role' => $data],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to get role: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createRole(array $arguments): array
    {
        if (! $this->hasPermission('create', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot create roles')->toArray();
        }

        try {
            $data = $arguments['data'] ?? [];
            $handle = $data['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Role handle is required']];
            }

            if (Role::find($handle)) {
                return ['success' => false, 'errors' => ["Role '{$handle}' already exists"]];
            }

            $role = Role::make($handle);

            if (isset($data['title'])) {
                $role->title($data['title']);
            }

            if (isset($data['permissions'])) {
                $role->permissions($data['permissions']);
            }

            $role->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'role' => [
                        'handle' => $role->handle(),
                        'title' => $role->title(),
                        'created' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to create role: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot update roles')->toArray();
        }

        try {
            $handle = $arguments['handle'] ?? null;
            $data = $arguments['data'] ?? [];

            if (! $handle) {
                return ['success' => false, 'errors' => ['Role handle is required']];
            }

            $role = Role::find($handle);
            if (! $role) {
                return $this->createErrorResponse("Role not found: {$handle}")->toArray();
            }

            if (isset($data['title'])) {
                $role->title($data['title']);
            }

            if (isset($data['permissions'])) {
                $role->permissions($data['permissions']);
            }

            $role->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'role' => [
                        'handle' => $role->handle(),
                        'title' => $role->title(),
                        'updated' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to update role: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteRole(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot delete roles')->toArray();
        }

        try {
            $handle = $arguments['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Role handle is required']];
            }

            $role = Role::find($handle);
            if (! $role) {
                return $this->createErrorResponse("Role not found: {$handle}")->toArray();
            }

            // Check if role is assigned to users
            $userCount = User::all()->filter(function ($u) use ($handle) {
                return $u->hasRole($handle);
            })->count();
            if ($userCount > 0) {
                return [
                    'success' => false,
                    'errors' => ["Cannot delete role '{$handle}' - it is assigned to {$userCount} users"],
                ];
            }

            $role->delete();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'role' => [
                        'handle' => $handle,
                        'deleted' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to delete role: {$e->getMessage()}"]];
        }
    }

    // Group Operations

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listGroups(array $arguments): array
    {
        if (! $this->hasPermission('view', 'user_groups')) {
            return $this->createErrorResponse('Permission denied: Cannot list user groups')->toArray();
        }

        try {
            $groups = UserGroup::all()->map(function ($group) {
                return [
                    'handle' => $group->handle(),
                    'title' => $group->title(),
                    'roles' => $group->roles()->map->handle()->all(),
                    'user_count' => $group->queryUsers()->count(),
                ];
            })->all();

            return [
                'success' => true,
                'data' => [
                    'groups' => $groups,
                    'total' => count($groups),
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to list groups: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getGroup(array $arguments): array
    {
        if (! $this->hasPermission('view', 'user_groups')) {
            return $this->createErrorResponse('Permission denied: Cannot view user groups')->toArray();
        }

        try {
            $handle = $arguments['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Group handle is required']];
            }

            $group = UserGroup::find($handle);
            if (! $group) {
                return $this->createErrorResponse("User group not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $group->handle(),
                'title' => $group->title(),
                'roles' => $group->roles()->map->handle()->all(),
                'users' => $group->queryUsers()->get()->map(function ($user) {
                    return [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                    ];
                })->all(),
            ];

            return [
                'success' => true,
                'data' => ['group' => $data],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to get group: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createGroup(array $arguments): array
    {
        if (! $this->hasPermission('create', 'user_groups')) {
            return $this->createErrorResponse('Permission denied: Cannot create user groups')->toArray();
        }

        try {
            $data = $arguments['data'] ?? [];
            $handle = $data['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Group handle is required']];
            }

            if (UserGroup::find($handle)) {
                return ['success' => false, 'errors' => ["Group '{$handle}' already exists"]];
            }

            $group = UserGroup::make()->handle($handle);

            if (isset($data['title'])) {
                $group->title($data['title']);
            }

            if (isset($data['roles']) && is_array($data['roles'])) {
                $roles = collect($data['roles'])->map(function ($roleHandle) {
                    return Role::find($roleHandle);
                })->filter();

                $group->roles($roles);
            }

            $group->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'group' => [
                        'handle' => $group->handle(),
                        'title' => $group->title(),
                        'created' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to create group: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateGroup(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'user_groups')) {
            return $this->createErrorResponse('Permission denied: Cannot update user groups')->toArray();
        }

        try {
            $handle = $arguments['handle'] ?? null;
            $data = $arguments['data'] ?? [];

            if (! $handle) {
                return ['success' => false, 'errors' => ['Group handle is required']];
            }

            $group = UserGroup::find($handle);
            if (! $group) {
                return $this->createErrorResponse("User group not found: {$handle}")->toArray();
            }

            if (isset($data['title'])) {
                $group->title($data['title']);
            }

            if (isset($data['roles']) && is_array($data['roles'])) {
                $roles = collect($data['roles'])->map(function ($roleHandle) {
                    return Role::find($roleHandle);
                })->filter();

                $group->roles($roles);
            }

            $group->save();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'group' => [
                        'handle' => $group->handle(),
                        'title' => $group->title(),
                        'updated' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to update group: {$e->getMessage()}"]];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteGroup(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'user_groups')) {
            return $this->createErrorResponse('Permission denied: Cannot delete user groups')->toArray();
        }

        try {
            $handle = $arguments['handle'] ?? null;

            if (! $handle) {
                return ['success' => false, 'errors' => ['Group handle is required']];
            }

            $group = UserGroup::find($handle);
            if (! $group) {
                return $this->createErrorResponse("User group not found: {$handle}")->toArray();
            }

            $group->delete();

            // Clear caches
            $this->clearCaches(['stache']);

            return [
                'success' => true,
                'data' => [
                    'group' => [
                        'handle' => $handle,
                        'deleted' => true,
                    ],
                ],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ["Failed to delete group: {$e->getMessage()}"]];
        }
    }

    // Helper methods now provided by RouterHelpers trait

    // BaseRouter abstract method implementations

    protected function getFeatures(): array
    {
        return [
            'user_management' => 'Complete user lifecycle management with authentication',
            'role_based_access' => 'Permission-based role system for access control',
            'user_groups' => 'Organize users with group-based role assignment',
            'activation_control' => 'User account activation and deactivation',
            'permission_assignment' => 'Dynamic role assignment and removal',
            'multi_type_operations' => 'Unified interface for users, roles, and groups',
            'security_controls' => 'Permission validation and secure operations',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'User management system for Statamic with comprehensive user, role, and group operations including authentication, authorization, and access control';
    }

    protected function getDecisionTree(): array
    {
        return [
            'user_operations' => [
                'question' => 'What user operation do you need?',
                'actions' => [
                    'list_users' => 'action=list&type=user for user overview',
                    'create_user' => 'action=create&type=user with user data',
                    'manage_access' => 'action=activate/deactivate&type=user for access control',
                ],
            ],
            'role_management' => [
                'question' => 'What role operation do you need?',
                'actions' => [
                    'list_roles' => 'action=list&type=role&include_permissions=true',
                    'create_role' => 'action=create&type=role with permissions',
                    'assign_roles' => 'action=assign_role&type=user with role',
                ],
            ],
            'group_organization' => [
                'question' => 'What group operation do you need?',
                'actions' => [
                    'list_groups' => 'action=list&type=group for group overview',
                    'create_group' => 'action=create&type=group with roles',
                    'manage_members' => 'Use group role assignment for member management',
                ],
            ],
            'permission_troubleshooting' => [
                'question' => 'What permission issue are you investigating?',
                'actions' => [
                    'user_permissions' => 'action=get&type=user&include_details=true',
                    'role_analysis' => 'action=get&type=role&include_permissions=true',
                    'group_roles' => 'action=get&type=group for role assignments',
                ],
            ],
        ];
    }

    protected function getContextAwareness(): array
    {
        return [
            'permission_levels' => [
                'cli_context' => 'Full access to all user operations',
                'web_context' => 'Permission-controlled based on user authentication',
                'required_permissions' => 'Varies by operation type (create/edit/delete users/roles/groups)',
            ],
            'operation_safety' => [
                'user_deletion' => 'Validate user relationships before deletion',
                'role_deletion' => 'Check role assignments before removal',
                'permission_changes' => 'Validate permission combinations',
            ],
            'data_relationships' => [
                'user_role_assignments' => 'Users can have multiple roles',
                'group_role_inheritance' => 'Groups assign roles to all members',
                'cascading_effects' => 'Role/group changes affect all assigned users',
            ],
        ];
    }

    protected function getWorkflowIntegration(): array
    {
        return [
            'user_onboarding' => [
                'new_user_setup' => 'Create user → assign roles → configure groups',
                'bulk_user_import' => 'Create multiple users with role assignments',
                'team_organization' => 'Setup groups and role hierarchies',
            ],
            'permission_management' => [
                'role_creation' => 'Define permissions → create role → assign to users',
                'access_review' => 'List users → check roles → validate permissions',
                'permission_updates' => 'Update roles → verify user access',
            ],
            'security_workflows' => [
                'user_deactivation' => 'Deactivate users → remove sensitive roles',
                'role_auditing' => 'List roles → check permissions → validate assignments',
                'group_management' => 'Review group roles → update memberships',
            ],
            'integration_patterns' => [
                'authentication_systems' => 'User creation with external auth integration',
                'authorization_services' => 'Role-based API access control',
                'content_workflows' => 'User roles for content creation and publishing',
            ],
        ];
    }

    protected function getCommonPatterns(): array
    {
        return [
            'complete_user_setup' => [
                'description' => 'Create user with full role and group configuration',
                'pattern' => [
                    'step_1' => 'action=create&type=user with basic user data',
                    'step_2' => 'action=assign_role&type=user with appropriate roles',
                    'step_3' => 'action=get&type=user&include_details=true to verify setup',
                ],
                'use_case' => 'New user onboarding with proper access setup',
            ],
            'role_management_workflow' => [
                'description' => 'Create and assign custom roles for specific permissions',
                'pattern' => [
                    'step_1' => 'action=create&type=role with permission definitions',
                    'step_2' => 'action=assign_role&type=user to assign to users',
                    'step_3' => 'action=list&type=user&include_details=true to verify assignments',
                ],
                'use_case' => 'Setting up custom permission structures',
            ],
            'group_organization' => [
                'description' => 'Organize users into groups with role inheritance',
                'pattern' => [
                    'step_1' => 'action=create&type=group with role assignments',
                    'step_2' => 'Add users to group through group role assignments',
                    'step_3' => 'action=get&type=group to verify membership and roles',
                ],
                'use_case' => 'Team-based permission management',
            ],
            'permission_audit' => [
                'description' => 'Comprehensive permission and access review',
                'pattern' => [
                    'step_1' => 'action=list&type=user&include_details=true',
                    'step_2' => 'action=list&type=role&include_permissions=true',
                    'step_3' => 'action=list&type=group for group-based assignments',
                ],
                'use_case' => 'Security audits and access reviews',
            ],
            'user_cleanup' => [
                'description' => 'Safe user deactivation and cleanup workflow',
                'pattern' => [
                    'step_1' => 'action=get&type=user&include_details=true to review assignments',
                    'step_2' => 'action=remove_role&type=user for sensitive roles',
                    'step_3' => 'action=deactivate&type=user to disable access',
                ],
                'use_case' => 'User offboarding and security cleanup',
            ],
        ];
    }

    /**
     * Get required permissions for action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        $type = $arguments['type'] ?? '';

        if ($type === 'user') {
            return match ($action) {
                'list', 'get' => ['view users'],
                'create' => ['create users'],
                'update', 'activate', 'deactivate', 'assign_role', 'remove_role' => ['edit users'],
                'delete' => ['delete users'],
                default => ['super'],
            };
        }

        if ($type === 'role') {
            return match ($action) {
                'list', 'get' => ['view roles'],
                'create' => ['create roles'],
                'update' => ['edit roles'],
                'delete' => ['delete roles'],
                default => ['super'],
            };
        }

        if ($type === 'group') {
            return match ($action) {
                'list', 'get' => ['view user_groups'],
                'create' => ['create user_groups'],
                'update' => ['edit user_groups'],
                'delete' => ['delete user_groups'],
                default => ['super'],
            };
        }

        return ['super'];
    }
}
