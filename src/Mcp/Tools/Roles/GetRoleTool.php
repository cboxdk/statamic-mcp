<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Roles;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Role;
use Statamic\Facades\User;

#[Title('Get Role')]
#[IsReadOnly]
class GetRoleTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.roles.get';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific role';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Role handle to retrieve')
            ->required()
            ->boolean('include_users')
            ->description('Include list of users with this role')
            ->optional()
            ->boolean('include_permission_analysis')
            ->description('Include analysis of permission coverage and conflicts')
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
        $handle = $arguments['handle'];
        $includeUsers = $arguments['include_users'] ?? false;
        $includePermissionAnalysis = $arguments['include_permission_analysis'] ?? false;

        try {
            $role = Role::find($handle);

            if (! $role) {
                return $this->createErrorResponse("Role '{$handle}' not found", [
                    'available_roles' => Role::all()->map(fn ($item) => $item->handle())->all(),
                ])->toArray();
            }

            $roleData = [
                'handle' => $role->handle(),
                'title' => $role->title(),
                'permissions' => $role->permissions(),
            ];

            if ($includeUsers) {
                $usersWithRole = User::all()->filter(fn ($user) => $user->hasRole($handle));
                $roleData['users'] = $usersWithRole->map(function ($user) use ($handle) {
                    return [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                        'is_super' => $user->isSuper(),
                        'additional_roles' => $user->roles()
                            ->filter(fn ($r) => $r->handle() !== $handle)
                            ->map(fn ($item) => $item->handle())
                            ->all(),
                    ];
                })->all();

                $roleData['user_count'] = count($roleData['users']);
            }

            if ($includePermissionAnalysis) {
                $roleData['permission_analysis'] = $this->analyzePermissions($role);
            }

            return [
                'role' => $roleData,
                'related_data' => [
                    'other_roles' => Role::all()
                        ->filter(fn ($r) => $r->handle() !== $handle)
                        ->map(fn ($item) => $item->handle())
                        ->all(),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to get role: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Analyze role permissions.
     *
     * @param  \Statamic\Contracts\Auth\Role  $role
     *
     * @return array<string, mixed>
     */
    private function analyzePermissions($role): array
    {
        $analysis = [
            'total_permissions' => 0,
            'permission_categories' => [],
            'potential_conflicts' => [],
            'missing_dependencies' => [],
            'security_level' => 'unknown',
        ];

        try {
            $permissions = $role->permissions();

            if (! is_array($permissions)) {
                $permissions = [];
            }

            $analysis['total_permissions'] = count($permissions);

            // Categorize permissions
            $categories = [];
            foreach ($permissions as $permission) {
                $category = $this->categorizePermission($permission);
                $categories[$category][] = $permission;
            }
            $analysis['permission_categories'] = $categories;

            // Check for potential conflicts or dangerous combinations
            $conflicts = [];
            if (in_array('delete users', $permissions) && in_array('create users', $permissions)) {
                $conflicts[] = 'Has both user creation and deletion permissions';
            }

            if (in_array('delete entries', $permissions) && ! in_array('edit entries', $permissions)) {
                $conflicts[] = 'Can delete entries but cannot edit them';
            }

            if (in_array('configure collections', $permissions) && ! in_array('view collections', $permissions)) {
                $conflicts[] = 'Can configure collections but cannot view them';
            }

            $analysis['potential_conflicts'] = $conflicts;

            // Check for missing permission dependencies
            $dependencies = [];
            if (in_array('edit entries', $permissions) && ! in_array('view entries', $permissions)) {
                $dependencies[] = 'Should have "view entries" to edit entries effectively';
            }

            if (in_array('create entries', $permissions) && ! in_array('view collections', $permissions)) {
                $dependencies[] = 'Should have "view collections" to create entries effectively';
            }

            if (in_array('upload assets', $permissions) && ! in_array('view assets', $permissions)) {
                $dependencies[] = 'Should have "view assets" to upload assets effectively';
            }

            $analysis['missing_dependencies'] = $dependencies;

            // Determine security level
            $dangerousPermissions = [
                'delete users', 'assign roles', 'edit roles', 'delete collections',
                'delete blueprints', 'delete forms', 'configure sites', 'access updater',
            ];

            $hasDangerous = array_intersect($permissions, $dangerousPermissions);

            if (! empty($hasDangerous)) {
                $analysis['security_level'] = 'high';
                $analysis['dangerous_permissions'] = $hasDangerous;
            } elseif (in_array('access cp', $permissions)) {
                $analysis['security_level'] = 'medium';
            } else {
                $analysis['security_level'] = 'low';
            }
        } catch (\Exception $e) {
            $analysis['error'] = 'Could not analyze permissions: ' . $e->getMessage();
        }

        return $analysis;
    }

    /**
     * Categorize a permission by its prefix or context.
     */
    private function categorizePermission(string $permission): string
    {
        if (str_contains($permission, 'user')) {
            return 'users';
        } elseif (str_contains($permission, 'role')) {
            return 'roles';
        } elseif (str_contains($permission, 'entries') || str_contains($permission, 'collection')) {
            return 'content';
        } elseif (str_contains($permission, 'asset')) {
            return 'assets';
        } elseif (str_contains($permission, 'blueprint') || str_contains($permission, 'field')) {
            return 'blueprints';
        } elseif (str_contains($permission, 'form')) {
            return 'forms';
        } elseif (str_contains($permission, 'global')) {
            return 'globals';
        } elseif (str_contains($permission, 'taxonomies') || str_contains($permission, 'term')) {
            return 'taxonomies';
        } elseif (str_contains($permission, 'nav')) {
            return 'navigation';
        } elseif (str_contains($permission, 'site')) {
            return 'sites';
        } elseif (str_contains($permission, 'configure') || str_contains($permission, 'manage')) {
            return 'administration';
        } elseif (str_contains($permission, 'update')) {
            return 'system';
        } elseif ($permission === 'access cp') {
            return 'access';
        } else {
            return 'other';
        }
    }
}
