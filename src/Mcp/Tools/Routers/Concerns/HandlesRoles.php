<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
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
        if (! $this->hasPermission('edit', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot list roles', code: ErrorCode::Forbidden)->toArray();
        }
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

        return $this->paginateItems($roles, $arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot view roles', code: ErrorCode::Forbidden)->toArray();
        }
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
        if (! $handle) {
            return $this->createErrorResponse('Role handle is required')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\Role|null $role */
        $role = Role::find($handle);
        if (! $role) {
            return $this->missingResource('Role', $handle, candidates: Role::all()->map->handle()->all(), arguments: $arguments)->toArray();
        }
        $roleHandle = $role->handle();
        $data = [
            ...$this->roleRecord($role),
            'user_count' => User::all()->filter(function ($u) use ($roleHandle) {
                /** @var \Statamic\Contracts\Auth\User $u */
                return $u->hasRole($roleHandle);
            })->count(),
        ];

        return ['role' => $data];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot create roles', code: ErrorCode::Forbidden)->toArray();
        }
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
        if (! $handle) {
            return $this->createErrorResponse('Role handle is required')->toArray();
        }
        if (array_key_exists('handle', $data) && $data['handle'] !== $handle) {
            return $this->createErrorResponse('The new role\'s handle is the handle argument; data.handle may only repeat it.')->toArray();
        }
        $existsError = $this->checkHandleNotExists(Role::find($handle), 'Role', $handle);
        if ($existsError !== null) {
            return $existsError;
        }
        $role = Role::make($handle);
        if (is_scalar($data['title'] ?? null)) {
            $role->title((string) $data['title']);
        }
        if (isset($data['permissions'])) {
            $permissions = is_array($data['permissions']) ? array_values(array_filter($data['permissions'], 'is_string')) : [];
            // Only a super admin may grant the 'super' permission, the same
            // rule Statamic's RolesController enforces. Otherwise a non-super
            // holder of 'edit roles' could make its own role super.
            /** @var \Statamic\Contracts\Auth\User|null $caller */
            $caller = auth()->user();
            if (in_array('super', $permissions, true) && ! $this->isCliContext() && (! $caller || ! $caller->isSuper())) {
                return $this->createErrorResponse('Permission denied: Only super admins can grant the super permission to a role', code: ErrorCode::Forbidden)->toArray();
            }
            $role->permissions($permissions);
        }
        $this->persistStatamic(fn () => $role->save(), ['stache']);
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'role' => [
                ...$this->roleRecord($role),
                'created' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot update roles', code: ErrorCode::Forbidden)->toArray();
        }
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        if (! $handle) {
            return $this->createErrorResponse('Role handle is required')->toArray();
        }
        $role = Role::find($handle);
        if (! $role) {
            return $this->missingResource('Role', $handle, candidates: Role::all()->map->handle()->all(), arguments: $arguments)->toArray();
        }
        if (isset($data['title']) && is_string($data['title'])) {
            $role->title($data['title']);
        }
        if (isset($data['permissions'])) {
            $permissions = is_array($data['permissions']) ? array_values(array_filter($data['permissions'], 'is_string')) : [];
            // Only a super admin may grant the 'super' permission, the same
            // rule Statamic's RolesController enforces. Otherwise a non-super
            // holder of 'edit roles' could make its own role super.
            /** @var \Statamic\Contracts\Auth\User|null $caller */
            $caller = auth()->user();
            if (in_array('super', $permissions, true) && ! $this->isCliContext() && (! $caller || ! $caller->isSuper())) {
                return $this->createErrorResponse('Permission denied: Only super admins can grant the super permission to a role', code: ErrorCode::Forbidden)->toArray();
            }
            $role->permissions($permissions);
        }
        $this->persistStatamic(fn () => $role->save(), ['stache']);
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'role' => [
                ...$this->roleRecord($role),
                'updated' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'roles')) {
            return $this->createErrorResponse('Permission denied: Cannot delete roles', code: ErrorCode::Forbidden)->toArray();
        }
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
        if (! $handle) {
            return $this->createErrorResponse('Role handle is required')->toArray();
        }
        $role = Role::find($handle);
        if (! $role) {
            return $this->missingResource('Role', $handle, candidates: Role::all()->map->handle()->all(), arguments: $arguments)->toArray();
        }
        // Check if role is assigned to users
        $userCount = User::all()->filter(function ($u) use ($handle) {
            /** @var \Statamic\Contracts\Auth\User $u */
            return $u->hasRole($handle);
        })->count();
        if ($userCount > 0) {
            return $this->createErrorResponse("Cannot delete role '{$handle}' - it is assigned to {$userCount} users")->toArray();
        }
        $snapshot = $this->roleRecord($role);
        $this->persistStatamic(fn () => $role->delete(), ['stache']);
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'role' => [
                ...$snapshot,
                'deleted' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function roleRecord(\Statamic\Contracts\Auth\Role $role): array
    {
        $data = [
            'handle' => $role->handle(),
            'title' => $role->title(),
            'permissions' => $role->permissions(),
        ];

        return [...$data, 'version' => self::resourceVersion($data)];
    }
}
