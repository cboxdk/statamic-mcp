<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Role operations for the UsersRouter.
 */
trait HandlesRoles
{
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
            default => $this->createErrorResponse("Unknown role action: {$action}")->toArray(),
        };
    }

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
                /** @var \Statamic\Contracts\Auth\Role $role */
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
                'roles' => $roles,
                'total' => count($roles),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list roles: {$e->getMessage()}")->toArray();
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;

            if (! $handle) {
                return $this->createErrorResponse('Role handle is required')->toArray();
            }

            /** @var \Statamic\Contracts\Auth\Role|null $role */
            $role = Role::find($handle);
            if (! $role) {
                return $this->createErrorResponse("Role not found: {$handle}")->toArray();
            }

            $roleHandle = $role->handle();
            $data = [
                'handle' => $roleHandle,
                'title' => $role->title(),
                'permissions' => $role->permissions(),
                'user_count' => User::all()->filter(function ($u) use ($roleHandle) {
                    /** @var \Statamic\Contracts\Auth\User $u */
                    return $u->hasRole($roleHandle);
                })->count(),
            ];

            return ['role' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get role: {$e->getMessage()}")->toArray();
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
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = is_string($data['handle'] ?? null) ? $data['handle'] : (is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null);

            if (! $handle) {
                return $this->createErrorResponse('Role handle is required')->toArray();
            }

            $existsError = $this->checkHandleNotExists(Role::find($handle), 'Role', $handle);
            if ($existsError !== null) {
                return $existsError;
            }

            $role = Role::make($handle);

            if (isset($data['title'])) {
                $role->title((string) $data['title']);
            }

            if (isset($data['permissions'])) {
                $role->permissions(is_array($data['permissions']) ? $data['permissions'] : []);
            }

            $role->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'role' => [
                    'handle' => $role->handle(),
                    'title' => $role->title(),
                    'created' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create role: {$e->getMessage()}")->toArray();
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            if (! $handle) {
                return $this->createErrorResponse('Role handle is required')->toArray();
            }

            $role = Role::find($handle);
            if (! $role) {
                return $this->createErrorResponse("Role not found: {$handle}")->toArray();
            }

            if (isset($data['title']) && is_string($data['title'])) {
                $role->title($data['title']);
            }

            if (isset($data['permissions'])) {
                $role->permissions(is_array($data['permissions']) ? $data['permissions'] : []);
            }

            $role->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'role' => [
                    'handle' => $role->handle(),
                    'title' => $role->title(),
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update role: {$e->getMessage()}")->toArray();
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;

            if (! $handle) {
                return $this->createErrorResponse('Role handle is required')->toArray();
            }

            $role = Role::find($handle);
            if (! $role) {
                return $this->createErrorResponse("Role not found: {$handle}")->toArray();
            }

            // Check if role is assigned to users
            $userCount = User::all()->filter(function ($u) use ($handle) {
                /** @var \Statamic\Contracts\Auth\User $u */
                return $u->hasRole($handle);
            })->count();
            if ($userCount > 0) {
                return $this->createErrorResponse("Cannot delete role '{$handle}' - it is assigned to {$userCount} users")->toArray();
            }

            $role->delete();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'role' => [
                    'handle' => $handle,
                    'deleted' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete role: {$e->getMessage()}")->toArray();
        }
    }
}
