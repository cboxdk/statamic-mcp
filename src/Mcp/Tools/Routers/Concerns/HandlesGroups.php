<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Statamic\Contracts\Auth\User;
use Statamic\Facades\Role;
use Statamic\Facades\UserGroup;

/**
 * User group operations for the UsersRouter.
 */
trait HandlesGroups
{
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
            default => $this->createErrorResponse("Unknown group action: {$action}")->toArray(),
        };
    }

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
                /** @var \Statamic\Contracts\Auth\UserGroup $group */
                return [
                    'handle' => $group->handle(),
                    'title' => $group->title(),
                    'roles' => $group->roles()->map->handle()->all(),
                    'user_count' => $group->queryUsers()->count(),
                ];
            })->all();

            return [
                'groups' => $groups,
                'total' => count($groups),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list groups: {$e->getMessage()}")->toArray();
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;

            if (! $handle) {
                return $this->createErrorResponse('Group handle is required')->toArray();
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
                    /** @var User $user */
                    return [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                    ];
                })->all(),
            ];

            return ['group' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get group: {$e->getMessage()}")->toArray();
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
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $handle = is_string($data['handle'] ?? null) ? $data['handle'] : (is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null);

            if (! $handle) {
                return $this->createErrorResponse('Group handle is required')->toArray();
            }

            if (UserGroup::find($handle)) {
                return $this->createErrorResponse("Group '{$handle}' already exists")->toArray();
            }

            $group = UserGroup::make()->handle($handle);

            if (isset($data['title']) && is_string($data['title'])) {
                $group->title($data['title']);
            }

            if (isset($data['roles']) && is_array($data['roles'])) {
                $roles = collect($data['roles'])->map(function ($roleHandle) {
                    return is_string($roleHandle) ? Role::find($roleHandle) : null;
                })->filter();

                $group->roles($roles);
            }

            $group->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'group' => [
                    'handle' => $group->handle(),
                    'title' => $group->title(),
                    'created' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create group: {$e->getMessage()}")->toArray();
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            if (! $handle) {
                return $this->createErrorResponse('Group handle is required')->toArray();
            }

            $group = UserGroup::find($handle);
            if (! $group) {
                return $this->createErrorResponse("User group not found: {$handle}")->toArray();
            }

            if (isset($data['title']) && is_string($data['title'])) {
                $group->title($data['title']);
            }

            if (isset($data['roles']) && is_array($data['roles'])) {
                $roles = collect($data['roles'])->map(function ($roleHandle) {
                    return is_string($roleHandle) ? Role::find($roleHandle) : null;
                })->filter();

                $group->roles($roles);
            }

            $group->save();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'group' => [
                    'handle' => $group->handle(),
                    'title' => $group->title(),
                    'updated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update group: {$e->getMessage()}")->toArray();
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
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : null;

            if (! $handle) {
                return $this->createErrorResponse('Group handle is required')->toArray();
            }

            $group = UserGroup::find($handle);
            if (! $group) {
                return $this->createErrorResponse("User group not found: {$handle}")->toArray();
            }

            $group->delete();

            // Clear caches
            $this->clearStatamicCaches(['stache']);

            return [
                'group' => [
                    'handle' => $handle,
                    'deleted' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete group: {$e->getMessage()}")->toArray();
        }
    }
}
