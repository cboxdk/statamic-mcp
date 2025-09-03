<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Roles;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

#[Title('Update Role')]
class UpdateRoleTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.roles.update';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Update an existing role with new permissions or title';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Handle of the role to update')
            ->required()
            ->string('title')
            ->description('New display title for the role')
            ->optional()
            ->raw('permissions', [
                'type' => 'array',
                'description' => 'Complete array of permissions to replace existing ones',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->raw('add_permissions', [
                'type' => 'array',
                'description' => 'Array of permissions to add to existing ones',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->raw('remove_permissions', [
                'type' => 'array',
                'description' => 'Array of permissions to remove from existing ones',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->boolean('validate_permissions')
            ->description('Validate that all permissions are valid Statamic permissions')
            ->optional()
            ->boolean('check_impact')
            ->description('Analyze impact on users with this role')
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
        $title = $arguments['title'] ?? null;
        $permissions = $arguments['permissions'] ?? null;
        $addPermissions = $arguments['add_permissions'] ?? [];
        $removePermissions = $arguments['remove_permissions'] ?? [];
        $validatePermissions = $arguments['validate_permissions'] ?? true;
        $checkImpact = $arguments['check_impact'] ?? true;

        try {
            $role = Role::find($handle);

            if (! $role) {
                return $this->createErrorResponse("Role '{$handle}' not found", [
                    'available_roles' => Role::all()->map(fn ($item) => $item->handle())->all(),
                ])->toArray();
            }

            $originalData = [
                'title' => $role->title(),
                'permissions' => $role->permissions(),
            ];

            $changes = [];
            $impactAnalysis = [];

            // Analyze impact before making changes
            if ($checkImpact) {
                $impactAnalysis = $this->analyzeRoleUpdateImpact($role, $permissions, $addPermissions, $removePermissions);
            }

            // Update title if provided
            if ($title !== null && $title !== $role->title()) {
                $role->title($title);
                $changes['title'] = ['from' => $originalData['title'], 'to' => $title];
            }

            // Handle permissions updates
            $finalPermissions = $originalData['permissions'];

            if ($permissions !== null) {
                // Replace all permissions
                if ($validatePermissions) {
                    $validationResult = $this->validatePermissions($permissions);
                    if ($validationResult['has_invalid']) {
                        return $this->createErrorResponse('Invalid permissions detected', [
                            'invalid_permissions' => $validationResult['invalid'],
                            'suggestions' => $validationResult['suggestions'],
                        ])->toArray();
                    }
                }

                $role->permissions($permissions);
                $finalPermissions = $permissions;
                $changes['permissions'] = ['from' => $originalData['permissions'], 'to' => $permissions];
            } else {
                // Add/remove individual permissions
                $currentPermissions = is_array($originalData['permissions']) ? $originalData['permissions'] : [];

                if (! empty($addPermissions)) {
                    if ($validatePermissions) {
                        $validationResult = $this->validatePermissions($addPermissions);
                        if ($validationResult['has_invalid']) {
                            return $this->createErrorResponse('Invalid permissions to add detected', [
                                'invalid_permissions' => $validationResult['invalid'],
                                'suggestions' => $validationResult['suggestions'],
                            ])->toArray();
                        }
                    }

                    $addedPermissions = [];
                    foreach ($addPermissions as $permission) {
                        if (! in_array($permission, $currentPermissions)) {
                            $role->addPermission($permission);
                            $addedPermissions[] = $permission;
                        }
                    }
                    if (! empty($addedPermissions)) {
                        $changes['permissions_added'] = $addedPermissions;
                    }
                }

                if (! empty($removePermissions)) {
                    $removedPermissions = [];
                    foreach ($removePermissions as $permission) {
                        if (in_array($permission, $currentPermissions)) {
                            $role->removePermission($permission);
                            $removedPermissions[] = $permission;
                        }
                    }
                    if (! empty($removedPermissions)) {
                        $changes['permissions_removed'] = $removedPermissions;
                    }
                }

                $finalPermissions = $role->permissions();
            }

            // Save the role
            $role->save();

            // Clear caches
            Stache::clear();

            return [
                'success' => true,
                'role' => [
                    'handle' => $handle,
                    'title' => $role->title(),
                    'permissions' => $finalPermissions,
                    'permission_count' => count($finalPermissions),
                ],
                'changes_applied' => $changes,
                'original_data' => $originalData,
                'impact_analysis' => $impactAnalysis,
                'security_notes' => $this->getSecurityNotes($finalPermissions, $changes),
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to update role: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Analyze the impact of updating a role.
     *
     * @param  \Statamic\Contracts\Auth\Role  $role
     * @param  array<string>|null  $newPermissions
     * @param  array<string>  $addPermissions
     * @param  array<string>  $removePermissions
     *
     * @return array<string, mixed>
     */
    private function analyzeRoleUpdateImpact($role, ?array $newPermissions, array $addPermissions, array $removePermissions): array
    {
        $analysis = [
            'affected_users' => 0,
            'permission_changes' => [],
            'potential_issues' => [],
            'recommendations' => [],
        ];

        try {
            // Count affected users
            $usersWithRole = User::all()->filter(fn ($user) => $user->hasRole($role->handle()));
            $analysis['affected_users'] = $usersWithRole->count();

            $currentPermissions = is_array($role->permissions()) ? $role->permissions() : [];

            // Calculate final permissions
            if ($newPermissions !== null) {
                $finalPermissions = $newPermissions;
                $analysis['permission_changes'] = [
                    'type' => 'complete_replacement',
                    'added' => array_diff($finalPermissions, $currentPermissions),
                    'removed' => array_diff($currentPermissions, $finalPermissions),
                ];
            } else {
                $finalPermissions = $currentPermissions;

                foreach ($addPermissions as $permission) {
                    if (! in_array($permission, $finalPermissions)) {
                        $finalPermissions[] = $permission;
                    }
                }

                $finalPermissions = array_diff($finalPermissions, $removePermissions);

                $analysis['permission_changes'] = [
                    'type' => 'incremental',
                    'added' => array_diff($addPermissions, $currentPermissions),
                    'removed' => array_intersect($removePermissions, $currentPermissions),
                ];
            }

            // Check for potential issues
            $issues = [];
            $recommendations = [];

            // Check for removal of critical permissions
            $criticalPermissions = ['access cp', 'view entries', 'view users'];
            $removedCritical = array_intersect($analysis['permission_changes']['removed'], $criticalPermissions);
            if (! empty($removedCritical)) {
                $issues[] = 'Removing critical permissions: ' . implode(', ', $removedCritical);
                $recommendations[] = 'Verify that affected users have alternative access methods';
            }

            // Check for addition of high-risk permissions
            $highRiskPermissions = ['delete users', 'delete collections', 'access updater'];
            $addedHighRisk = array_intersect($analysis['permission_changes']['added'], $highRiskPermissions);
            if (! empty($addedHighRisk)) {
                $issues[] = 'Adding high-risk permissions: ' . implode(', ', $addedHighRisk);
                $recommendations[] = 'Review users with this role for appropriate access level';
            }

            // Check for permission dependencies
            $dependencyIssues = $this->checkPermissionDependencies($finalPermissions);
            if ($dependencyIssues['has_missing']) {
                $issues[] = 'Missing permission dependencies detected';
                $recommendations[] = 'Consider adding: ' . implode(', ', $dependencyIssues['recommended_additions']);
            }

            $analysis['potential_issues'] = $issues;
            $analysis['recommendations'] = $recommendations;

            // Add user details if count is manageable
            if ($analysis['affected_users'] <= 10) {
                $roleHandle = $role->handle();
                $analysis['affected_user_details'] = $usersWithRole->map(function ($user) use ($roleHandle) {
                    return [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                        'is_super' => $user->isSuper(),
                        'other_roles' => $user->roles()
                            ->filter(fn ($r) => $r->handle() !== $roleHandle)
                            ->map(fn ($item) => $item->handle())
                            ->all(),
                    ];
                })->all();
            }
        } catch (\Exception $e) {
            $analysis['error'] = 'Could not analyze impact: ' . $e->getMessage();
        }

        return $analysis;
    }

    /**
     * Validate permission strings.
     *
     * @param  array<string>  $permissions
     *
     * @return array<string, mixed>
     */
    private function validatePermissions(array $permissions): array
    {
        $knownPermissions = [
            'access cp',
            'view users', 'edit users', 'create users', 'delete users',
            'assign roles', 'edit roles',
            'view entries', 'edit entries', 'create entries', 'delete entries',
            'publish entries', 'reorder entries',
            'view collections', 'edit collections', 'create collections', 'delete collections',
            'view assets', 'edit assets', 'upload assets', 'delete assets',
            'view blueprints', 'edit blueprints', 'create blueprints', 'delete blueprints',
            'view forms', 'edit forms', 'create forms', 'delete forms',
            'view form submissions', 'delete form submissions',
            'view globals', 'edit globals',
            'view taxonomies', 'edit taxonomies', 'create taxonomies', 'delete taxonomies',
            'view terms', 'edit terms', 'create terms', 'delete terms',
            'view nav', 'edit nav', 'create nav', 'delete nav',
            'view sites', 'edit sites', 'create sites', 'delete sites',
            'configure fields', 'configure asset containers',
            'configure collections', 'configure taxonomies',
            'configure globals', 'configure navs', 'configure forms', 'configure sites',
            'manage preferences', 'access updater', 'view updates', 'perform updates',
        ];

        $valid = [];
        $invalid = [];
        $suggestions = [];

        foreach ($permissions as $permission) {
            if (in_array($permission, $knownPermissions)) {
                $valid[] = $permission;
            } else {
                $invalid[] = $permission;

                // Find similar permissions
                $similar = array_filter($knownPermissions, function ($known) use ($permission) {
                    return levenshtein($permission, $known) <= 3;
                });

                if (! empty($similar)) {
                    $suggestions[$permission] = array_slice($similar, 0, 3);
                }
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'suggestions' => $suggestions,
            'has_invalid' => ! empty($invalid),
        ];
    }

    /**
     * Check for permission dependencies.
     *
     * @param  array<string>  $permissions
     *
     * @return array<string, mixed>
     */
    private function checkPermissionDependencies(array $permissions): array
    {
        $dependencies = [
            'edit entries' => ['view entries'],
            'create entries' => ['view entries', 'view collections'],
            'delete entries' => ['view entries', 'edit entries'],
            'edit users' => ['view users'],
            'create users' => ['view users'],
            'delete users' => ['view users', 'edit users'],
        ];

        $missing = [];
        $recommended = [];

        foreach ($permissions as $permission) {
            if (isset($dependencies[$permission])) {
                foreach ($dependencies[$permission] as $dependency) {
                    if (! in_array($dependency, $permissions)) {
                        $missing[$permission][] = $dependency;
                        if (! in_array($dependency, $recommended)) {
                            $recommended[] = $dependency;
                        }
                    }
                }
            }
        }

        return [
            'missing_dependencies' => $missing,
            'recommended_additions' => $recommended,
            'has_missing' => ! empty($missing),
        ];
    }

    /**
     * Get security notes for permission changes.
     *
     * @param  array<string>  $finalPermissions
     * @param  array<string, mixed>  $changes
     *
     * @return array<string>
     */
    private function getSecurityNotes(array $finalPermissions, array $changes): array
    {
        $notes = [];

        $highRiskPermissions = ['delete users', 'assign roles', 'delete collections', 'access updater'];
        $hasHighRisk = array_intersect($finalPermissions, $highRiskPermissions);

        if (! empty($hasHighRisk)) {
            $notes[] = 'Role has high-risk permissions: ' . implode(', ', $hasHighRisk);
        }

        if (isset($changes['permissions_added'])) {
            $addedHighRisk = array_intersect($changes['permissions_added'], $highRiskPermissions);
            if (! empty($addedHighRisk)) {
                $notes[] = 'Added high-risk permissions: ' . implode(', ', $addedHighRisk);
            }
        }

        return $notes;
    }
}
