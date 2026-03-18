<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * User operations for the UsersRouter.
 */
trait HandlesUsers
{
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
            'search' => $this->searchUsers($arguments),
            'get' => $this->getUser($arguments),
            'create' => $this->createUser($arguments),
            'update' => $this->updateUser($arguments),
            'delete' => $this->deleteUser($arguments),
            'activate' => $this->activateUser($arguments),
            'deactivate' => $this->deactivateUser($arguments),
            'assign_role' => $this->assignRole($arguments),
            'remove_role' => $this->removeRole($arguments),
            default => $this->createErrorResponse("Unknown user action: {$action}")->toArray(),
        };
    }

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
            $pagination = $this->getPaginationArgs($arguments, defaultLimit: 100);
            $limit = $pagination['limit'];
            $offset = $pagination['offset'];

            $allUsers = User::all();
            $total = $allUsers->count();

            $users = $allUsers->slice($offset, $limit)->map(function ($user) use ($includeDetails) {
                /** @var \Statamic\Contracts\Auth\User $user */
                return $this->serializeUser($user, $includeDetails);
            })->values()->all();

            return [
                'users' => $users,
                'pagination' => $this->buildPaginationMeta($total, $limit, $offset),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list users: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Search and filter users by name, email, role, group, or status.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function searchUsers(array $arguments): array
    {
        if (! $this->hasPermission('view', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot search users')->toArray();
        }

        try {
            $query = is_string($arguments['query'] ?? null) ? strtolower($arguments['query']) : null;
            $roleFilter = is_string($arguments['role'] ?? null) ? $arguments['role'] : null;
            $groupFilter = is_string($arguments['group'] ?? null) ? $arguments['group'] : null;
            $superFilter = isset($arguments['super']) ? (bool) $arguments['super'] : null;
            $statusFilter = is_string($arguments['status'] ?? null) ? $arguments['status'] : null;
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
            $pagination = $this->getPaginationArgs($arguments, defaultLimit: 100);
            $limit = $pagination['limit'];
            $offset = $pagination['offset'];

            $users = User::all()->filter(function ($user) use ($query, $roleFilter, $groupFilter, $superFilter, $statusFilter) {
                /** @var \Statamic\Contracts\Auth\User $user */

                // Query filter - match name or email
                if ($query !== null) {
                    $name = strtolower((string) $user->name());
                    $emailValue = $user->email();
                    $email = strtolower(is_string($emailValue) ? $emailValue : '');
                    if (! str_contains($name, $query) && ! str_contains($email, $query)) {
                        return false;
                    }
                }

                // Role filter
                if ($roleFilter !== null && ! $user->hasRole($roleFilter)) {
                    return false;
                }

                // Group filter
                if ($groupFilter !== null) {
                    /** @var array<int, string> $groupHandles */
                    $groupHandles = $user->groups()->map->handle()->all();
                    if (! in_array($groupFilter, $groupHandles, true)) {
                        return false;
                    }
                }

                // Super admin filter
                if ($superFilter !== null && $user->isSuper() !== $superFilter) {
                    return false;
                }

                // Status filter
                if ($statusFilter !== null) {
                    /** @var string $userStatus */
                    $userStatus = $user->get('status', 'active');
                    if ($userStatus !== $statusFilter) {
                        return false;
                    }
                }

                return true;
            });

            $total = $users->count();

            $results = $users->slice($offset, $limit)->map(function ($user) use ($includeDetails) {
                /** @var \Statamic\Contracts\Auth\User $user */
                return $this->serializeUser($user, $includeDetails);
            })->values()->all();

            // Build applied filters summary
            $appliedFilters = array_filter([
                'query' => $query,
                'role' => $roleFilter,
                'group' => $groupFilter,
                'super' => $superFilter,
                'status' => $statusFilter,
            ], fn ($v) => $v !== null);

            return [
                'users' => $results,
                'pagination' => $this->buildPaginationMeta($total, $limit, $offset),
                'filters_applied' => $appliedFilters,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to search users: {$e->getMessage()}")->toArray();
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
            $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
            $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;

            if (! $id && ! $email) {
                return $this->createErrorResponse('Either ID or email is required')->toArray();
            }

            $user = $id ? User::find($id) : User::findByEmail((string) $email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            /** @var \Statamic\Contracts\Auth\User $user */
            $data = array_merge($this->serializeUser($user, true), [
                'permissions' => $user->permissions()->all(),
            ]);

            return ['user' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get user: {$e->getMessage()}")->toArray();
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
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $email = is_string($data['email'] ?? null) ? $data['email'] : null;

            if (! $email) {
                return $this->createErrorResponse('Email is required')->toArray();
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return $this->createErrorResponse("Invalid email format: {$email}")->toArray();
            }

            $existsError = $this->checkHandleNotExists(User::findByEmail($email), 'User with email', $email);
            if ($existsError !== null) {
                return $existsError;
            }

            /** @var \Statamic\Contracts\Auth\User $user */
            $user = User::make()->email($email);

            // Set user data
            if (isset($data['name'])) {
                $user->set('name', $data['name']);
            }
            if (isset($data['password']) && is_string($data['password'])) {
                if (strlen($data['password']) < 8) {
                    return $this->createErrorResponse('Password must be at least 8 characters long')->toArray();
                }
                $user->password($data['password']);
            }
            // Super admin promotion requires the caller to already be a super admin
            if (isset($data['super']) && $data['super']) {
                /** @var \Statamic\Contracts\Auth\User|null $authenticatedUser */
                $authenticatedUser = auth()->user();
                if (! $this->isCliContext() && (! $authenticatedUser || ! $authenticatedUser->isSuper())) {
                    return $this->createErrorResponse('Permission denied: Only super admins can grant super admin status')->toArray();
                }
                $user->makeSuper();
            }

            $user->save();

            // Assign roles if provided
            if (isset($data['roles']) && is_array($data['roles'])) {
                foreach ($data['roles'] as $roleHandle) {
                    $role = is_string($roleHandle) ? Role::find($roleHandle) : null;
                    if ($role) {
                        $user->assignRole($role);
                    }
                }
                $user->save();
            }

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->name(),
                    'created' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create user: {$e->getMessage()}")->toArray();
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
            $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
            $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            if (! $id && ! $email) {
                return $this->createErrorResponse('Either ID or email is required')->toArray();
            }

            /** @var \Statamic\Contracts\Auth\User|null $user */
            $user = $id ? User::find($id) : User::findByEmail((string) $email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            // Update user data — only whitelisted fields allowed
            if (isset($data['name'])) {
                $user->set('name', $data['name']);
            }
            if (isset($data['email']) && is_string($data['email'])) {
                if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                    return $this->createErrorResponse("Invalid email format: {$data['email']}")->toArray();
                }
                $user->email($data['email']);
            }
            if (isset($data['password']) && is_string($data['password'])) {
                if (strlen($data['password']) < 8) {
                    return $this->createErrorResponse('Password must be at least 8 characters long')->toArray();
                }
                $user->password($data['password']);
            }
            if (! empty($data['super'])) {
                /** @var \Statamic\Contracts\Auth\User|null $authenticatedUser */
                $authenticatedUser = auth()->user();
                if (! $this->isCliContext() && (! $authenticatedUser || ! $authenticatedUser->isSuper())) {
                    return $this->createErrorResponse('Permission denied: Only super admins can grant super admin status')->toArray();
                }
                $user->makeSuper();
            }

            $user->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->name(),
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update user: {$e->getMessage()}")->toArray();
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
            $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
            $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;

            if (! $id && ! $email) {
                return $this->createErrorResponse('Either ID or email is required')->toArray();
            }

            /** @var \Statamic\Contracts\Auth\User|null $user */
            $user = $id ? User::find($id) : User::findByEmail((string) $email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            // Prevent deletion of super user if it's the only one
            if ($user->isSuper()) {
                $superCount = User::all()->filter(function ($u) {
                    /** @var \Statamic\Contracts\Auth\User $u */
                    return $u->isSuper();
                })->count();
                if ($superCount <= 1) {
                    return $this->createErrorResponse('Cannot delete the last super user')->toArray();
                }
            }

            $userId = $user->id();
            $userEmail = $user->email();
            $user->delete();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'user' => [
                    'id' => $userId,
                    'email' => $userEmail,
                    'deleted' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete user: {$e->getMessage()}")->toArray();
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
            $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
            $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;

            if (! $id && ! $email) {
                return $this->createErrorResponse('Either ID or email is required')->toArray();
            }

            /** @var \Statamic\Contracts\Auth\User|null $user */
            $user = $id ? User::find($id) : User::findByEmail((string) $email);
            if (! $user) {
                return $this->createErrorResponse('User not found: ' . ($id ?? $email))->toArray();
            }

            // Activate user by ensuring they are not blocked/suspended
            // In Statamic, users can be activated by removing any 'blocked' status or similar
            $user->set('status', 'active');
            $user->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->get('name'),
                    'status' => $user->get('status', 'active'),
                ],
                'activated' => true,
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
            $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
            $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
            $reason = is_string($arguments['reason'] ?? null) ? $arguments['reason'] : 'deactivated';

            if (! $id && ! $email) {
                return $this->createErrorResponse('Either ID or email is required')->toArray();
            }

            /** @var \Statamic\Contracts\Auth\User|null $user */
            $user = $id ? User::find($id) : User::findByEmail((string) $email);
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
            $this->clearStatamicCaches(['stache']);

            return [
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->get('name'),
                    'status' => $user->get('status'),
                    'deactivation_reason' => $reason,
                ],
                'deactivated' => true,
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
            $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
            $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
            $roleHandle = is_string($arguments['role'] ?? null) ? $arguments['role'] : null;

            if ((! $id && ! $email) || ! $roleHandle) {
                return $this->createErrorResponse('User identifier and role handle are required')->toArray();
            }

            /** @var \Statamic\Contracts\Auth\User|null $user */
            $user = $id ? User::find($id) : User::findByEmail((string) $email);
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
            $this->clearStatamicCaches(['stache']);

            return [
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'role_assigned' => $roleHandle,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to assign role: {$e->getMessage()}")->toArray();
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
            $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
            $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
            $roleHandle = is_string($arguments['role'] ?? null) ? $arguments['role'] : null;

            if ((! $id && ! $email) || ! $roleHandle) {
                return $this->createErrorResponse('User identifier and role handle are required')->toArray();
            }

            /** @var \Statamic\Contracts\Auth\User|null $user */
            $user = $id ? User::find($id) : User::findByEmail((string) $email);
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
            $this->clearStatamicCaches(['stache']);

            return [
                'user' => [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'role_removed' => $roleHandle,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to remove role: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Serialize a Statamic user to an array.
     *
     * @return array<string, mixed>
     */
    private function serializeUser(\Statamic\Contracts\Auth\User $user, bool $includeDetails = false): array
    {
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
    }
}
