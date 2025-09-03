<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Roles;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Role;
use Statamic\Facades\User;

#[Title('List Roles')]
#[IsReadOnly]
class ListRolesTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.roles.list';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'List all roles with their permissions and user assignments';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_permissions')
            ->description('Include detailed permission information for each role')
            ->optional()
            ->boolean('include_user_counts')
            ->description('Include count of users assigned to each role')
            ->optional()
            ->boolean('include_user_list')
            ->description('Include list of users assigned to each role')
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
        $includePermissions = $arguments['include_permissions'] ?? false;
        $includeUserCounts = $arguments['include_user_counts'] ?? false;
        $includeUserList = $arguments['include_user_list'] ?? false;

        try {
            $roles = Role::all();
            $roleData = [];

            foreach ($roles as $role) {
                $data = [
                    'handle' => $role->handle(),
                    'title' => $role->title(),
                ];

                if ($includePermissions) {
                    $data['permissions'] = $role->permissions();
                }

                if ($includeUserCounts || $includeUserList) {
                    $usersWithRole = User::all()->filter(fn ($user) => $user->hasRole($role->handle()));

                    if ($includeUserCounts) {
                        $data['user_count'] = $usersWithRole->count();
                    }

                    if ($includeUserList) {
                        $data['users'] = $usersWithRole->map(function ($user) {
                            return [
                                'id' => $user->id(),
                                'email' => $user->email(),
                                'name' => $user->name(),
                            ];
                        })->all();
                    }
                }

                $roleData[] = $data;
            }

            return [
                'roles' => $roleData,
                'total_roles' => count($roleData),
                'meta' => [
                    'permissions_included' => $includePermissions,
                    'user_counts_included' => $includeUserCounts,
                    'user_lists_included' => $includeUserList,
                ],
                'available_permissions' => $this->getAvailablePermissions(),
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to list roles: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Get list of available permissions in the system.
     *
     * @return array<string>
     */
    private function getAvailablePermissions(): array
    {
        // Get all unique permissions from existing roles
        $permissions = [];

        try {
            foreach (Role::all() as $role) {
                $rolePermissions = $role->permissions();
                if (is_array($rolePermissions)) {
                    $permissions = array_merge($permissions, $rolePermissions);
                }
            }

            // Add common Statamic permissions that might not be in use
            $commonPermissions = [
                'access cp',
                'view users',
                'edit users',
                'create users',
                'delete users',
                'assign roles',
                'edit roles',
                'view entries',
                'edit entries',
                'create entries',
                'delete entries',
                'publish entries',
                'reorder entries',
                'view collections',
                'edit collections',
                'create collections',
                'delete collections',
                'view assets',
                'edit assets',
                'upload assets',
                'delete assets',
                'view blueprints',
                'edit blueprints',
                'create blueprints',
                'delete blueprints',
                'view forms',
                'edit forms',
                'create forms',
                'delete forms',
                'view form submissions',
                'delete form submissions',
                'view globals',
                'edit globals',
                'view taxonomies',
                'edit taxonomies',
                'create taxonomies',
                'delete taxonomies',
                'view terms',
                'edit terms',
                'create terms',
                'delete terms',
                'view nav',
                'edit nav',
                'create nav',
                'delete nav',
                'view sites',
                'edit sites',
                'create sites',
                'delete sites',
                'configure fields',
                'configure asset containers',
                'configure collections',
                'configure taxonomies',
                'configure globals',
                'configure navs',
                'configure forms',
                'configure sites',
                'manage preferences',
                'access updater',
                'view updates',
                'perform updates',
            ];

            $permissions = array_unique(array_merge($permissions, $commonPermissions));
            sort($permissions);
        } catch (\Exception $e) {
            // Return basic set if there's an error
            $permissions = ['access cp', 'view entries', 'edit entries'];
        }

        return $permissions;
    }
}
