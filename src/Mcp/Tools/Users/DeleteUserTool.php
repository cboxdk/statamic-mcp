<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Users;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

#[Title('Delete User')]
class DeleteUserTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.users.delete';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Delete a user with safety checks and content handling options';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('user_id')
            ->description('User ID or email to delete')
            ->required()
            ->boolean('force')
            ->description('Force deletion even if user has authored content')
            ->optional()
            ->string('reassign_content_to')
            ->description('User ID/email to reassign authored content to')
            ->optional()
            ->boolean('delete_user_content')
            ->description('Delete all content authored by this user')
            ->optional()
            ->boolean('create_backup')
            ->description('Create backup of user data before deletion')
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
        $userId = $arguments['user_id'];
        $force = $arguments['force'] ?? false;
        $reassignContentTo = $arguments['reassign_content_to'] ?? null;
        $deleteUserContent = $arguments['delete_user_content'] ?? false;
        $createBackup = $arguments['create_backup'] ?? true;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            // Find the user
            $user = User::find($userId) ?? User::findByEmail($userId);

            if (! $user) {
                return $this->createErrorResponse("User '{$userId}' not found")->toArray();
            }

            // Prevent deleting super users without force
            if ($user->isSuper() && ! $force) {
                return $this->createErrorResponse('Cannot delete super user without force=true', [
                    'user_id' => $user->id(),
                    'is_super' => true,
                ])->toArray();
            }

            // Analyze user's authored content
            $contentAnalysis = $this->analyzeUserContent($user);

            // Check content conflicts
            $contentConflicts = [];
            if ($contentAnalysis['has_content'] && ! $force && ! $reassignContentTo && ! $deleteUserContent) {
                $contentConflicts[] = 'User has authored content and no content handling method specified';
            }

            if ($reassignContentTo && $deleteUserContent) {
                $contentConflicts[] = 'Cannot both reassign and delete content - choose one option';
            }

            if (! empty($contentConflicts)) {
                return $this->createErrorResponse('Content handling conflicts detected', [
                    'conflicts' => $contentConflicts,
                    'content_analysis' => $contentAnalysis,
                    'solutions' => [
                        'use_force' => 'Set force=true to delete anyway',
                        'reassign_content' => 'Specify reassign_content_to with another user ID',
                        'delete_content' => 'Set delete_user_content=true to delete all authored content',
                    ],
                ])->toArray();
            }

            // Validate reassignment target if specified
            $reassignToUser = null;
            if ($reassignContentTo) {
                $reassignToUser = User::find($reassignContentTo) ?? User::findByEmail($reassignContentTo);
                if (! $reassignToUser) {
                    return $this->createErrorResponse("Reassignment target user '{$reassignContentTo}' not found")->toArray();
                }

                if ($reassignToUser->id() === $user->id()) {
                    return $this->createErrorResponse('Cannot reassign content to the same user being deleted')->toArray();
                }
            }

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'user' => [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                        'is_super' => $user->isSuper(),
                        'would_be_deleted' => true,
                    ],
                    'content_analysis' => $contentAnalysis,
                    'actions' => [
                        'user_backup' => $createBackup ? 'Would create user data backup' : 'No backup',
                        'content_reassignment' => $reassignContentTo ? "Would reassign to {$reassignToUser->email()}" : 'No reassignment',
                        'content_deletion' => $deleteUserContent ? 'Would delete all authored content' : 'Content preserved',
                    ],
                ];
            }

            $backupData = null;
            if ($createBackup) {
                $backupData = $this->createUserBackup($user);
            }

            // Handle content before deleting user
            $contentHandling = [];
            if ($contentAnalysis['has_content']) {
                if ($reassignToUser) {
                    $contentHandling = $this->reassignUserContent($user, $reassignToUser);
                } elseif ($deleteUserContent) {
                    $contentHandling = $this->deleteUserContent($user);
                }
            }

            // Delete the user
            $userData = [
                'id' => $user->id(),
                'email' => $user->email(),
                'name' => $user->name(),
                'roles' => $user->roles()->map(fn ($item) => $item->handle())->all(),
                'is_super' => $user->isSuper(),
            ];

            $user->delete();

            // Clear caches
            Stache::clear();

            return [
                'success' => true,
                'deleted_user' => $userData,
                'content_analysis' => $contentAnalysis,
                'content_handling' => $contentHandling,
                'backup_created' => $backupData !== null,
                'backup_data' => $createBackup ? $backupData : null,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to delete user: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Analyze content authored by a user.
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     *
     * @return array<string, mixed>
     */
    private function analyzeUserContent($user): array
    {
        $analysis = [
            'has_content' => false,
            'entries' => 0,
            'collections' => [],
        ];

        try {
            // Find entries authored by this user
            $entries = Entry::query()->where('author', $user->id())->get();
            $analysis['entries'] = $entries->count();

            if ($analysis['entries'] > 0) {
                $analysis['has_content'] = true;
                $collectionCounts = [];

                foreach ($entries as $entry) {
                    $collection = $entry->collection()->handle();
                    $collectionCounts[$collection] = ($collectionCounts[$collection] ?? 0) + 1;
                }

                $analysis['collections'] = $collectionCounts;
            }
        } catch (\Exception $e) {
            $analysis['error'] = 'Could not analyze user content: ' . $e->getMessage();
        }

        return $analysis;
    }

    /**
     * Create backup of user data.
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     *
     * @return array<string, mixed>
     */
    private function createUserBackup($user): array
    {
        return [
            'id' => $user->id(),
            'email' => $user->email(),
            'name' => $user->name(),
            'data' => $user->data()->all(),
            'roles' => $user->roles()->map(fn ($item) => $item->handle())->all(),
            'groups' => $user->groups()->map(fn ($item) => $item->handle())->all() ?? [],
            'is_super' => $user->isSuper(),
            'backup_timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Reassign user content to another user.
     *
     * @param  \Statamic\Contracts\Auth\User  $fromUser
     * @param  \Statamic\Contracts\Auth\User  $toUser
     *
     * @return array<string, mixed>
     */
    private function reassignUserContent($fromUser, $toUser): array
    {
        $reassigned = ['entries' => 0, 'errors' => []];

        try {
            $entries = Entry::query()->where('author', $fromUser->id())->get();

            foreach ($entries as $entry) {
                $entry->set('author', $toUser->id());
                $entry->save();
                $reassigned['entries']++;
            }

            $reassigned['reassigned_to'] = [
                'id' => $toUser->id(),
                'email' => $toUser->email(),
                'name' => $toUser->name(),
            ];
        } catch (\Exception $e) {
            $reassigned['errors'][] = 'Content reassignment error: ' . $e->getMessage();
        }

        return $reassigned;
    }

    /**
     * Delete all content authored by a user.
     *
     * @param  \Statamic\Contracts\Auth\User  $user
     *
     * @return array<string, mixed>
     */
    private function deleteUserContent($user): array
    {
        $deleted = ['entries' => 0, 'errors' => []];

        try {
            $entries = Entry::query()->where('author', $user->id())->get();

            foreach ($entries as $entry) {
                $entry->delete();
                $deleted['entries']++;
            }
        } catch (\Exception $e) {
            $deleted['errors'][] = 'Content deletion error: ' . $e->getMessage();
        }

        return $deleted;
    }
}
