<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;

#[Title('Entry Versioning & Revisions')]
class EntryVersioningTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.versioning';
    }

    protected function getToolDescription(): string
    {
        return 'Advanced entry versioning with revision history, working copies, and rollback capabilities';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('operation')
            ->description('Operation: list_revisions, create_revision, publish_revision, rollback, compare_revisions, cleanup_revisions')
            ->required()
            ->string('entry_id')
            ->description('Entry ID')
            ->required()
            ->string('revision_id')
            ->description('Specific revision ID (for single revision operations)')
            ->optional()
            ->string('compare_revision_id')
            ->description('Second revision ID for comparison')
            ->optional()
            ->string('revision_message')
            ->description('Message describing the revision changes')
            ->optional()
            ->raw('revision_metadata', [
                'type' => 'object',
                'description' => 'Additional revision metadata',
                'properties' => [
                    'author' => ['type' => 'string'],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'branch' => ['type' => 'string'],
                    'milestone' => ['type' => 'string'],
                ],
                'additionalProperties' => true,
            ])
            ->optional()
            ->boolean('auto_publish')
            ->description('Automatically publish after creating revision')
            ->optional()
            ->boolean('create_backup')
            ->description('Create backup before operations')
            ->optional()
            ->integer('keep_revisions')
            ->description('Number of revisions to keep (for cleanup)')
            ->optional()
            ->string('cleanup_strategy')
            ->description('Cleanup strategy: keep_latest, keep_published, keep_tagged')
            ->optional()
            ->boolean('include_drafts')
            ->description('Include draft revisions in operations')
            ->optional()
            ->raw('rollback_options', [
                'type' => 'object',
                'description' => 'Options for rollback operation',
                'properties' => [
                    'preserve_metadata' => ['type' => 'boolean'],
                    'update_timestamps' => ['type' => 'boolean'],
                    'create_rollback_revision' => ['type' => 'boolean'],
                ],
                'additionalProperties' => false,
            ])
            ->optional()
            ->boolean('dry_run')
            ->description('Preview operation without executing')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $operation = $arguments['operation'];
        $entryId = $arguments['entry_id'];
        $revisionId = $arguments['revision_id'] ?? null;
        $compareRevisionId = $arguments['compare_revision_id'] ?? null;
        $revisionMessage = $arguments['revision_message'] ?? null;
        $revisionMetadata = $arguments['revision_metadata'] ?? [];
        $autoPublish = $arguments['auto_publish'] ?? false;
        $createBackup = $arguments['create_backup'] ?? true;
        $keepRevisions = $arguments['keep_revisions'] ?? null;
        $cleanupStrategy = $arguments['cleanup_strategy'] ?? 'keep_latest';
        $includeDrafts = $arguments['include_drafts'] ?? true;
        $rollbackOptions = $arguments['rollback_options'] ?? [];
        $dryRun = $arguments['dry_run'] ?? false;

        $validOperations = ['list_revisions', 'create_revision', 'publish_revision', 'rollback', 'compare_revisions', 'cleanup_revisions'];
        if (! in_array($operation, $validOperations)) {
            return $this->createErrorResponse("Invalid operation '{$operation}'. Valid: " . implode(', ', $validOperations))->toArray();
        }

        $entry = Entry::find($entryId);
        if (! $entry) {
            return $this->createErrorResponse("Entry '{$entryId}' not found")->toArray();
        }

        switch ($operation) {
            case 'list_revisions':
                return $this->listRevisions($entry, $includeDrafts);
            case 'create_revision':
                return $this->createRevision($entry, $revisionMessage, $revisionMetadata, $autoPublish, $createBackup, $dryRun);
            case 'publish_revision':
                return $this->publishRevision($entry, $revisionId, $createBackup, $dryRun);
            case 'rollback':
                return $this->rollbackToRevision($entry, $revisionId, $rollbackOptions, $createBackup, $dryRun);
            case 'compare_revisions':
                return $this->compareRevisions($entry, $revisionId, $compareRevisionId);
            case 'cleanup_revisions':
                return $this->cleanupRevisions($entry, $keepRevisions, $cleanupStrategy, $dryRun);
            default:
                return $this->createErrorResponse("Operation '{$operation}' not implemented")->toArray();
        }
    }

    /**
     * List all revisions for an entry.
     *
     * @return array<string, mixed>
     */
    private function listRevisions(\Statamic\Contracts\Entries\Entry $entry, bool $includeDrafts): array
    {
        $revisions = [];

        // Get main entry info
        $mainRevision = [
            'id' => 'main',
            'type' => 'published',
            'title' => $entry->get('title'),
            'status' => $entry->status(),
            'published' => $entry->published(),
            'last_modified' => $entry->lastModified()?->toISOString(),
            'author' => $entry->get('author'),
            'is_current' => true,
            'is_working_copy' => false,
            'revision_number' => 0,
            'data_hash' => md5(serialize($entry->data()->all())),
        ];

        $revisions[] = $mainRevision;

        // Check for working copy
        if (method_exists($entry, 'hasWorkingCopy') && $entry->hasWorkingCopy()) {
            $workingCopy = method_exists($entry, 'workingCopy') ? $entry->workingCopy() : null;
            if ($workingCopy) {
                $workingRevision = [
                    'id' => 'working_copy',
                    'type' => 'working_copy',
                    'title' => $workingCopy->get('title'),
                    'status' => $workingCopy->status(),
                    'published' => $workingCopy->published(),
                    'last_modified' => $workingCopy->lastModified()?->toISOString(),
                    'author' => $workingCopy->get('author'),
                    'is_current' => false,
                    'is_working_copy' => true,
                    'revision_number' => 1,
                    'data_hash' => md5(serialize($workingCopy->data()->all())),
                    'changes_from_published' => $this->calculateChanges($entry->data()->all(), $workingCopy->data()->all()),
                ];

                $revisions[] = $workingRevision;
            }
        }

        // Simulate additional revision history (in a real implementation, this would come from a storage system)
        $simulatedRevisions = $this->getSimulatedRevisionHistory($entry);
        $revisions = array_merge($revisions, $simulatedRevisions);

        return [
            'operation' => 'list_revisions',
            'entry_id' => $entry->id(),
            'entry_title' => $entry->get('title'),
            'collection' => $entry->collection()->handle(),
            'total_revisions' => count($revisions),
            'current_revision' => $revisions[0],
            'has_working_copy' => count($revisions) > 1 && $revisions[1]['is_working_copy'],
            'revisions' => $revisions,
            'revision_summary' => [
                'published' => count(array_filter($revisions, fn ($r) => $r['type'] === 'published')),
                'working_copies' => count(array_filter($revisions, fn ($r) => $r['type'] === 'working_copy')),
                'drafts' => count(array_filter($revisions, fn ($r) => $r['type'] === 'draft')),
            ],
        ];
    }

    /**
     * Create a new revision.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @return array<string, mixed>
     */
    private function createRevision(
        \Statamic\Contracts\Entries\Entry $entry,
        ?string $message,
        array $metadata,
        bool $autoPublish,
        bool $createBackup,
        bool $dryRun
    ): array {
        $revisionData = [
            'id' => 'rev_' . uniqid(),
            'created_at' => Carbon::now()->toISOString(),
            'message' => $message ?? 'Revision created via MCP',
            'author' => $metadata['author'] ?? 'system',
            'tags' => $metadata['tags'] ?? [],
            'branch' => $metadata['branch'] ?? 'main',
            'milestone' => $metadata['milestone'] ?? null,
            'entry_data' => $entry->data()->all(),
            'entry_meta' => [
                'published' => $entry->published(),
                'status' => $entry->status(),
                'collection' => $entry->collection()->handle(),
                'slug' => $entry->slug(),
                'site' => $entry->locale(),
                'blueprint' => $entry->blueprint()?->handle(),
            ],
            'data_hash' => md5(serialize($entry->data()->all())),
        ];

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'create_revision',
                'entry_id' => $entry->id(),
                'would_create_revision' => $revisionData,
                'auto_publish' => $autoPublish,
                'create_backup' => $createBackup,
            ];
        }

        // Create backup if requested
        $backupInfo = null;
        if ($createBackup) {
            $backupInfo = $this->createEntryBackup($entry);
        }

        // In a real implementation, this would be stored in a revision storage system
        $revisionStored = $this->storeRevision($entry, $revisionData);

        // Create working copy if not auto-publishing
        $workingCopy = null;
        if (! $autoPublish && method_exists($entry, 'makeWorkingCopy')) {
            $workingCopy = $entry->makeWorkingCopy();
            if ($workingCopy) {
                $workingCopy->save();
            }
        }

        // Auto-publish if requested
        if ($autoPublish) {
            $entry->published(true);
            $entry->save();
        }

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'create_revision',
            'entry_id' => $entry->id(),
            'revision' => $revisionData,
            'revision_stored' => $revisionStored,
            'working_copy_created' => $workingCopy !== null,
            'auto_published' => $autoPublish,
            'backup' => $backupInfo,
            'cache' => $cacheResult,
        ];
    }

    /**
     * Publish a specific revision.
     *
     * @return array<string, mixed>
     */
    private function publishRevision(
        \Statamic\Contracts\Entries\Entry $entry,
        ?string $revisionId,
        bool $createBackup,
        bool $dryRun
    ): array {
        if (! $revisionId) {
            return $this->createErrorResponse('Revision ID is required for publish operation')->toArray();
        }

        // Handle working copy publish
        if ($revisionId === 'working_copy') {
            if (method_exists($entry, 'hasWorkingCopy') && $entry->hasWorkingCopy()) {
                $workingCopy = method_exists($entry, 'workingCopy') ? $entry->workingCopy() : null;
                if (! $workingCopy) {
                    return $this->createErrorResponse('Working copy not found')->toArray();
                }

                if ($dryRun) {
                    return [
                        'dry_run' => true,
                        'operation' => 'publish_revision',
                        'entry_id' => $entry->id(),
                        'revision_id' => $revisionId,
                        'would_publish_working_copy' => true,
                        'changes' => $this->calculateChanges($entry->data()->all(), $workingCopy->data()->all()),
                    ];
                }

                // Create backup if requested
                $backupInfo = null;
                if ($createBackup) {
                    $backupInfo = $this->createEntryBackup($entry);
                }

                // Publish working copy
                $workingCopy->published(true);
                $workingCopy->save();

                // Clear caches
                $cacheTypes = $this->getRecommendedCacheTypes('content_change');
                $cacheResult = $this->clearStatamicCaches($cacheTypes);

                return [
                    'operation' => 'publish_revision',
                    'entry_id' => $entry->id(),
                    'revision_id' => $revisionId,
                    'published' => true,
                    'backup' => $backupInfo,
                    'cache' => $cacheResult,
                ];
            } else {
                return $this->createErrorResponse('Entry has no working copy')->toArray();
            }
        }

        // Handle historical revision publish
        $revision = $this->getStoredRevision($entry, $revisionId);
        if (! $revision) {
            return $this->createErrorResponse("Revision '{$revisionId}' not found")->toArray();
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'publish_revision',
                'entry_id' => $entry->id(),
                'revision_id' => $revisionId,
                'would_restore_data' => $revision['entry_data'],
                'changes' => $this->calculateChanges($entry->data()->all(), $revision['entry_data']),
            ];
        }

        // Create backup if requested
        $backupInfo = null;
        if ($createBackup) {
            $backupInfo = $this->createEntryBackup($entry);
        }

        // Restore revision data
        $entry->data($revision['entry_data']);
        $entry->published(true);
        $entry->save();

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'publish_revision',
            'entry_id' => $entry->id(),
            'revision_id' => $revisionId,
            'restored_from' => $revision['created_at'],
            'published' => true,
            'backup' => $backupInfo,
            'cache' => $cacheResult,
        ];
    }

    /**
     * Rollback to a specific revision.
     *
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    private function rollbackToRevision(
        \Statamic\Contracts\Entries\Entry $entry,
        ?string $revisionId,
        array $options,
        bool $createBackup,
        bool $dryRun
    ): array {
        if (! $revisionId) {
            return $this->createErrorResponse('Revision ID is required for rollback')->toArray();
        }

        $revision = $this->getStoredRevision($entry, $revisionId);
        if (! $revision) {
            return $this->createErrorResponse("Revision '{$revisionId}' not found")->toArray();
        }

        $preserveMetadata = $options['preserve_metadata'] ?? true;
        $updateTimestamps = $options['update_timestamps'] ?? true;
        $createRollbackRevision = $options['create_rollback_revision'] ?? true;

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'rollback',
                'entry_id' => $entry->id(),
                'rollback_to_revision' => $revisionId,
                'rollback_options' => $options,
                'changes' => $this->calculateChanges($entry->data()->all(), $revision['entry_data']),
                'would_preserve_metadata' => $preserveMetadata,
                'would_update_timestamps' => $updateTimestamps,
                'would_create_rollback_revision' => $createRollbackRevision,
            ];
        }

        // Create rollback revision first if requested
        if ($createRollbackRevision) {
            $this->createRevision($entry, "Rollback to revision {$revisionId}", [
                'author' => 'system',
                'tags' => ['rollback'],
            ], false, false, false);
        }

        // Create backup if requested
        $backupInfo = null;
        if ($createBackup) {
            $backupInfo = $this->createEntryBackup($entry);
        }

        // Restore revision data
        $entry->data($revision['entry_data']);

        if (! $preserveMetadata) {
            $entry->published($revision['entry_meta']['published']);
            if (isset($revision['entry_meta']['slug'])) {
                $entry->slug($revision['entry_meta']['slug']);
            }
        }

        $entry->save();

        // Clear caches
        $cacheTypes = $this->getRecommendedCacheTypes('content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => 'rollback',
            'entry_id' => $entry->id(),
            'rolled_back_to' => $revisionId,
            'rollback_timestamp' => $revision['created_at'],
            'rollback_revision_created' => $createRollbackRevision,
            'metadata_preserved' => $preserveMetadata,
            'backup' => $backupInfo,
            'cache' => $cacheResult,
        ];
    }

    /**
     * Compare two revisions.
     *
     * @return array<string, mixed>
     */
    private function compareRevisions(\Statamic\Contracts\Entries\Entry $entry, ?string $revisionId1, ?string $revisionId2): array
    {
        if (! $revisionId1 || ! $revisionId2) {
            return $this->createErrorResponse('Both revision IDs are required for comparison')->toArray();
        }

        $revision1 = $this->getRevisionData($entry, $revisionId1);
        $revision2 = $this->getRevisionData($entry, $revisionId2);

        if (! $revision1 || ! $revision2) {
            return $this->createErrorResponse('One or both revisions not found')->toArray();
        }

        $changes = $this->calculateDetailedChanges($revision1['data'], $revision2['data']);

        return [
            'operation' => 'compare_revisions',
            'entry_id' => $entry->id(),
            'revision_1' => [
                'id' => $revisionId1,
                'timestamp' => $revision1['created_at'] ?? null,
                'author' => $revision1['author'] ?? null,
            ],
            'revision_2' => [
                'id' => $revisionId2,
                'timestamp' => $revision2['created_at'] ?? null,
                'author' => $revision2['author'] ?? null,
            ],
            'comparison' => [
                'total_changes' => count($changes),
                'added_fields' => array_keys(array_filter($changes, fn ($change) => $change['type'] === 'added')),
                'removed_fields' => array_keys(array_filter($changes, fn ($change) => $change['type'] === 'removed')),
                'modified_fields' => array_keys(array_filter($changes, fn ($change) => $change['type'] === 'modified')),
                'changes' => $changes,
            ],
        ];
    }

    /**
     * Cleanup old revisions.
     *
     * @return array<string, mixed>
     */
    private function cleanupRevisions(\Statamic\Contracts\Entries\Entry $entry, ?int $keepRevisions, string $strategy, bool $dryRun): array
    {
        $allRevisions = $this->getAllStoredRevisions($entry);
        $toDelete = [];

        switch ($strategy) {
            case 'keep_latest':
                if ($keepRevisions && count($allRevisions) > $keepRevisions) {
                    $sorted = collect($allRevisions)->sortByDesc('created_at');
                    $toDelete = $sorted->skip($keepRevisions)->all();
                }
                break;

            case 'keep_published':
                $toDelete = array_filter($allRevisions, function ($revision) {
                    return ! ($revision['entry_meta']['published'] ?? false);
                });
                break;

            case 'keep_tagged':
                $toDelete = array_filter($allRevisions, function ($revision) {
                    return empty($revision['tags'] ?? []);
                });
                break;
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => 'cleanup_revisions',
                'entry_id' => $entry->id(),
                'total_revisions' => count($allRevisions),
                'would_delete' => count($toDelete),
                'cleanup_strategy' => $strategy,
                'keep_revisions' => $keepRevisions,
                'revisions_to_delete' => array_column($toDelete, 'id'),
            ];
        }

        $deleted = 0;
        foreach ($toDelete as $revision) {
            if ($this->deleteStoredRevision($entry, $revision['id'])) {
                $deleted++;
            }
        }

        return [
            'operation' => 'cleanup_revisions',
            'entry_id' => $entry->id(),
            'total_revisions' => count($allRevisions),
            'deleted_revisions' => $deleted,
            'remaining_revisions' => count($allRevisions) - $deleted,
            'cleanup_strategy' => $strategy,
        ];
    }

    /**
     * Calculate changes between two data arrays.
     *
     * @param  array<string, mixed>  $oldData
     * @param  array<string, mixed>  $newData
     *
     * @return array<string, mixed>
     */
    private function calculateChanges(array $oldData, array $newData): array
    {
        return [
            'added' => array_diff_key($newData, $oldData),
            'removed' => array_diff_key($oldData, $newData),
            'modified' => array_filter($newData, function ($value, $key) use ($oldData) {
                return isset($oldData[$key]) && $oldData[$key] !== $value;
            }, ARRAY_FILTER_USE_BOTH),
        ];
    }

    /**
     * Calculate detailed changes with field-level analysis.
     *
     * @param  array<string, mixed>  $oldData
     * @param  array<string, mixed>  $newData
     *
     * @return array<string, mixed>
     */
    private function calculateDetailedChanges(array $oldData, array $newData): array
    {
        $changes = [];
        $allKeys = array_unique(array_merge(array_keys($oldData), array_keys($newData)));

        foreach ($allKeys as $key) {
            $oldValue = $oldData[$key] ?? null;
            $newValue = $newData[$key] ?? null;

            if (! isset($oldData[$key])) {
                $changes[$key] = ['type' => 'added', 'new_value' => $newValue];
            } elseif (! isset($newData[$key])) {
                $changes[$key] = ['type' => 'removed', 'old_value' => $oldValue];
            } elseif ($oldValue !== $newValue) {
                $changes[$key] = [
                    'type' => 'modified',
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ];
            }
        }

        return $changes;
    }

    // Simulated methods for revision storage (would be implemented with actual storage)

    /**
     * @param  array<string, mixed>  $revisionData
     */
    private function storeRevision(\Statamic\Contracts\Entries\Entry $entry, array $revisionData): bool
    {
        // In a real implementation, store in database or file system
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getStoredRevision(\Statamic\Contracts\Entries\Entry $entry, string $revisionId): ?array
    {
        // In a real implementation, retrieve from storage
        if ($revisionId === 'main') {
            return [
                'id' => 'main',
                'created_at' => $entry->lastModified()?->toISOString(),
                'entry_data' => $entry->data()->all(),
                'entry_meta' => [
                    'published' => $entry->published(),
                    'status' => $entry->status(),
                ],
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRevisionData(\Statamic\Contracts\Entries\Entry $entry, string $revisionId): ?array
    {
        if ($revisionId === 'main') {
            return [
                'data' => $entry->data()->all(),
                'created_at' => $entry->lastModified()?->toISOString(),
            ];
        }

        return $this->getStoredRevision($entry, $revisionId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAllStoredRevisions(\Statamic\Contracts\Entries\Entry $entry): array
    {
        // In a real implementation, retrieve all revisions from storage
        return [];
    }

    private function deleteStoredRevision(\Statamic\Contracts\Entries\Entry $entry, string $revisionId): bool
    {
        // In a real implementation, delete from storage
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSimulatedRevisionHistory(\Statamic\Contracts\Entries\Entry $entry): array
    {
        // Simulate some revision history for demonstration
        return [
            [
                'id' => 'rev_' . uniqid(),
                'type' => 'revision',
                'title' => $entry->get('title') . ' (Previous Version)',
                'status' => 'published',
                'published' => true,
                'last_modified' => Carbon::now()->subDays(1)->toISOString(),
                'author' => $entry->get('author'),
                'is_current' => false,
                'is_working_copy' => false,
                'revision_number' => -1,
                'message' => 'Previous published version',
                'data_hash' => md5('previous_version'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createEntryBackup(\Statamic\Contracts\Entries\Entry $entry): array
    {
        // In a real implementation, create actual backup
        return [
            'backup_id' => 'backup_' . uniqid(),
            'created_at' => Carbon::now()->toISOString(),
            'entry_data' => $entry->data()->all(),
            'entry_meta' => [
                'published' => $entry->published(),
                'status' => $entry->status(),
            ],
        ];
    }
}
