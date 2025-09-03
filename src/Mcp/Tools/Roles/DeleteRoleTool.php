<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Roles;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

#[Title('Delete Role')]
class DeleteRoleTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.roles.delete';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Delete a role with safety checks and user reassignment options';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Handle of the role to delete')
            ->required()
            ->boolean('force')
            ->description('Force deletion even if role is assigned to users')
            ->optional()
            ->string('reassign_to_role')
            ->description('Role handle to reassign users to before deletion')
            ->optional()
            ->boolean('remove_from_users')
            ->description('Simply remove this role from all users without reassignment')
            ->optional()
            ->boolean('create_backup')
            ->description('Create backup of role data before deletion')
            ->optional()
            ->boolean('dry_run')
            ->description('Show what would be deleted without actually deleting')
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
        $force = $arguments['force'] ?? false;
        $reassignToRole = $arguments['reassign_to_role'] ?? null;
        $removeFromUsers = $arguments['remove_from_users'] ?? false;
        $createBackup = $arguments['create_backup'] ?? true;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            // Check if role exists
            $role = Role::find($handle);
            if (! $role) {
                return $this->createErrorResponse("Role '{$handle}' not found", [
                    'available_roles' => Role::all()->map->handle()->all(),
                ])->toArray();
            }

            // Find users with this role
            $usersWithRole = User::all()->filter(fn ($user) => $user->hasRole($handle));
            $userCount = $usersWithRole->count();

            // Validate reassignment target if specified
            $reassignmentTarget = null;
            if ($reassignToRole) {
                $reassignmentTarget = Role::find($reassignToRole);
                if (! $reassignmentTarget) {
                    return $this->createErrorResponse("Reassignment target role '{$reassignToRole}' not found", [
                        'available_roles' => Role::all()->map->handle()->all(),
                    ])->toArray();
                }

                if ($reassignToRole === $handle) {
                    return $this->createErrorResponse('Cannot reassign to the same role being deleted')->toArray();
                }
            }

            // Check for conflicts in user handling
            $userHandlingConflicts = [];
            if ($userCount > 0 && ! $force && ! $reassignToRole && ! $removeFromUsers) {
                $userHandlingConflicts[] = 'Role has assigned users and no user handling method specified';
            }

            if ($reassignToRole && $removeFromUsers) {
                $userHandlingConflicts[] = 'Cannot both reassign and remove role from users - choose one option';
            }

            if (! empty($userHandlingConflicts)) {
                return $this->createErrorResponse('User handling conflicts detected', [
                    'conflicts' => $userHandlingConflicts,
                    'assigned_users' => $userCount,
                    'solutions' => [
                        'use_force' => 'Set force=true to delete anyway',
                        'reassign_users' => 'Specify reassign_to_role with another role handle',
                        'remove_from_users' => 'Set remove_from_users=true to simply remove this role',
                    ],
                ])->toArray();
            }

            if ($dryRun) {
                $userDetails = $usersWithRole->map(function ($user) use ($handle) {
                    return [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                        'other_roles' => $user->roles()
                            ->filter(fn ($r) => $r->handle() !== $handle)
                            ->map->handle()
                            ->all(),
                    ];
                })->all();

                return [
                    'dry_run' => true,
                    'role' => [
                        'handle' => $handle,
                        'title' => $role->title(),
                        'permissions' => $role->permissions(),
                        'would_be_deleted' => true,
                    ],
                    'affected_users' => $userCount,
                    'user_details' => $userDetails,
                    'actions' => [
                        'role_backup' => $createBackup ? 'Would create role data backup' : 'No backup',
                        'user_reassignment' => $reassignToRole ? "Would reassign to {$reassignToRole}" : 'No reassignment',
                        'role_removal' => $removeFromUsers ? 'Would remove role from all users' : 'Role preserved on users',
                    ],
                ];
            }

            $backupData = null;
            if ($createBackup) {
                $backupData = $this->createRoleBackup($role, $usersWithRole);
            }

            // Handle users before deleting role
            $userHandling = [];
            if ($userCount > 0) {
                if ($reassignmentTarget) {
                    $userHandling = $this->reassignUsersToRole($usersWithRole, $handle, $reassignmentTarget);
                } elseif ($removeFromUsers) {
                    $userHandling = $this->removeRoleFromUsers($usersWithRole, $handle);
                }
            }

            // Delete the role
            $roleData = [
                'handle' => $handle,
                'title' => $role->title(),
                'permissions' => $role->permissions(),
            ];

            $role->delete();

            // Clear caches
            Stache::clear();

            return [
                'success' => true,
                'deleted_role' => $roleData,
                'affected_users' => $userCount,
                'user_handling' => $userHandling,
                'backup_created' => $backupData !== null,
                'backup_data' => $createBackup ? $backupData : null,
                'remaining_roles' => Role::all()->map->handle()->all(),
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to delete role: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Create backup of role data.
     *
     * @param  \Statamic\Contracts\Auth\Role  $role
     * @param  \Illuminate\Support\Collection<int, mixed>  $usersWithRole
     *
     * @return array<string, mixed>
     */
    private function createRoleBackup($role, $usersWithRole): array
    {
        return [
            'role' => [
                'handle' => $role->handle(),
                'title' => $role->title(),
                'permissions' => $role->permissions(),
            ],
            'assigned_users' => $usersWithRole->map(function ($user) {
                return [
                    'id' => $user->id(),
                    'email' => $user->email(),
                    'name' => $user->name(),
                    'all_roles' => $user->roles()->map->handle()->all(),
                ];
            })->all(),
            'backup_timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Reassign users from one role to another.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $users
     * @param  \Statamic\Contracts\Auth\Role  $toRole
     *
     * @return array<string, mixed>
     */
    private function reassignUsersToRole($users, string $fromRole, $toRole): array
    {
        $reassigned = ['users' => 0, 'errors' => []];

        try {
            foreach ($users as $user) {
                // Remove old role and add new role
                $user->removeRole($fromRole);
                $user->assignRole($toRole->handle());
                $user->save();
                $reassigned['users']++;
            }

            $reassigned['reassigned_to'] = [
                'handle' => $toRole->handle(),
                'title' => $toRole->title(),
            ];
        } catch (\Exception $e) {
            $reassigned['errors'][] = 'User reassignment error: ' . $e->getMessage();
        }

        return $reassigned;
    }

    /**
     * Remove role from all users.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $users
     *
     * @return array<string, mixed>
     */
    private function removeRoleFromUsers($users, string $roleHandle): array
    {
        $removed = ['users' => 0, 'warnings' => [], 'errors' => []];

        try {
            foreach ($users as $user) {
                // Check if user will have any roles left
                $remainingRoles = $user->roles()
                    ->filter(fn ($r) => $r->handle() !== $roleHandle)
                    ->count();

                if ($remainingRoles === 0 && ! $user->isSuper()) {
                    $removed['warnings'][] = "User {$user->email()} will have no roles after removal";
                }

                $user->removeRole($roleHandle);
                $user->save();
                $removed['users']++;
            }
        } catch (\Exception $e) {
            $removed['errors'][] = 'Role removal error: ' . $e->getMessage();
        }

        return $removed;
    }
}
