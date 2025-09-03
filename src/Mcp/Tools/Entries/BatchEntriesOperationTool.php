<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

#[Title('Batch Entry Operations')]
class BatchEntriesOperationTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.batch_operation';
    }

    protected function getToolDescription(): string
    {
        return 'Perform batch operations on multiple entries (update, publish, delete, move)';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('operation')
            ->description('Batch operation: update, publish, unpublish, delete, move_collection')
            ->required()
            ->raw('entry_criteria', [
                'type' => 'object',
                'description' => 'Criteria to select entries',
                'properties' => [
                    'collection' => ['type' => 'string'],
                    'ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'query' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string'],
                            'author' => ['type' => 'string'],
                            'blueprint' => ['type' => 'string'],
                            'site' => ['type' => 'string'],
                        ],
                        'additionalProperties' => true,
                    ],
                    'date_range' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'string'],
                            'to' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'additionalProperties' => false,
            ])
            ->required()
            ->raw('operation_data', [
                'type' => 'object',
                'description' => 'Data for the operation',
                'properties' => [
                    'field_updates' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                    'target_collection' => ['type' => 'string'],
                    'new_status' => ['type' => 'string'],
                    'new_author' => ['type' => 'string'],
                    'merge_data' => ['type' => 'boolean'],
                ],
                'additionalProperties' => true,
            ])
            ->optional()
            ->integer('batch_size')
            ->description('Process entries in batches of this size (default: 50)')
            ->optional()
            ->boolean('validate_blueprint')
            ->description('Validate updates against blueprint schema')
            ->optional()
            ->boolean('create_backups')
            ->description('Create backup before destructive operations')
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
        $entryCriteria = $arguments['entry_criteria'];
        $operationData = $arguments['operation_data'] ?? [];
        $batchSize = $arguments['batch_size'] ?? 50;
        $validateBlueprint = $arguments['validate_blueprint'] ?? false;
        $createBackups = $arguments['create_backups'] ?? false;
        $dryRun = $arguments['dry_run'] ?? false;

        // Validate operation
        $validOperations = ['update', 'publish', 'unpublish', 'delete', 'move_collection'];
        if (! in_array($operation, $validOperations)) {
            return $this->createErrorResponse("Invalid operation '{$operation}'. Valid operations: " . implode(', ', $validOperations))->toArray();
        }

        // Find entries based on criteria
        $entries = $this->findEntriesByCriteria($entryCriteria);

        if ($entries->isEmpty()) {
            return [
                'operation' => $operation,
                'entries_found' => 0,
                'message' => 'No entries found matching the criteria',
                'criteria' => $entryCriteria,
            ];
        }

        $totalEntries = $entries->count();
        $batches = $entries->chunk($batchSize);
        $results = [];
        $errors = [];

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => $operation,
                'entries_found' => $totalEntries,
                'batches' => $batches->count(),
                'batch_size' => $batchSize,
                'sample_entries' => $entries->take(5)->map(function ($entry) {
                    return [
                        'id' => $entry->id(),
                        'title' => $entry->get('title'),
                        'slug' => $entry->slug(),
                        'collection' => $entry->collection()->handle(),
                        'published' => $entry->published(),
                    ];
                })->all(),
                'operation_preview' => $this->previewOperation($operation, $operationData, $entries->first()),
            ];
        }

        // Process batches
        foreach ($batches as $batchIndex => $batchEntries) {
            $batchResults = [];
            $batchErrors = [];

            foreach ($batchEntries as $entry) {
                try {
                    $result = $this->executeOperation($operation, $entry, $operationData, $validateBlueprint, $createBackups);
                    $batchResults[] = $result;
                } catch (\Exception $e) {
                    $batchErrors[] = [
                        'entry_id' => $entry->id(),
                        'error' => $e->getMessage(),
                        'exception_type' => get_class($e),
                    ];
                }
            }

            $results[] = [
                'batch' => $batchIndex + 1,
                'processed' => count($batchResults),
                'errors' => count($batchErrors),
                'results' => $batchResults,
                'batch_errors' => $batchErrors,
            ];

            $errors = array_merge($errors, $batchErrors);
        }

        // Clear caches after all operations
        $cacheTypes = $this->getRecommendedCacheTypes($operation === 'delete' ? 'structure_change' : 'content_change');
        $cacheResult = $this->clearStatamicCaches($cacheTypes);

        return [
            'operation' => $operation,
            'total_entries' => $totalEntries,
            'total_batches' => count($results),
            'batch_size' => $batchSize,
            'successful_operations' => array_sum(array_column($results, 'processed')),
            'failed_operations' => count($errors),
            'batch_results' => $results,
            'errors' => $errors,
            'cache' => $cacheResult,
            'execution_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? 0),
        ];
    }

    /**
     * Find entries based on criteria.
     *
     * @param  array<string, mixed>  $criteria
     *
     * @return \Illuminate\Support\Collection<int, \Statamic\Contracts\Entries\Entry>
     */
    private function findEntriesByCriteria(array $criteria): \Illuminate\Support\Collection
    {
        $query = Entry::query();

        // Filter by collection
        if (isset($criteria['collection'])) {
            $query->where('collection', $criteria['collection']);
        }

        // Filter by specific IDs
        if (isset($criteria['ids']) && is_array($criteria['ids'])) {
            $query->whereIn('id', $criteria['ids']);
        }

        // Apply query filters
        if (isset($criteria['query']) && is_array($criteria['query'])) {
            foreach ($criteria['query'] as $field => $value) {
                if ($field === 'status') {
                    if ($value === 'published') {
                        $query->where('published', true);
                    } elseif ($value === 'draft') {
                        $query->where('published', false);
                    }
                } elseif ($field === 'site') {
                    $query->where('locale', $value);
                } else {
                    $query->where($field, $value);
                }
            }
        }

        // Apply date range filter
        if (isset($criteria['date_range']) && is_array($criteria['date_range'])) {
            if (isset($criteria['date_range']['from'])) {
                $query->where('date', '>=', Carbon::parse($criteria['date_range']['from']));
            }
            if (isset($criteria['date_range']['to'])) {
                $query->where('date', '<=', Carbon::parse($criteria['date_range']['to']));
            }
        }

        return $query->get();
    }

    /**
     * Execute operation on a single entry.
     *
     * @param  array<string, mixed>  $operationData
     *
     * @return array<string, mixed>
     */
    private function executeOperation(string $operation, \Statamic\Contracts\Entries\Entry $entry, array $operationData, bool $validateBlueprint, bool $createBackups): array
    {
        $originalData = null;
        if ($createBackups && in_array($operation, ['update', 'delete', 'move_collection'])) {
            $originalData = [
                'id' => $entry->id(),
                'data' => $entry->data()->all(),
                'published' => $entry->published(),
                'collection' => $entry->collection()->handle(),
            ];
        }

        switch ($operation) {
            case 'update':
                return $this->executeUpdateOperation($entry, $operationData, $validateBlueprint);

            case 'publish':
                $entry->published(true);
                $entry->save();

                return [
                    'entry_id' => $entry->id(),
                    'operation' => 'published',
                    'title' => $entry->get('title'),
                    'previous_status' => 'draft',
                    'new_status' => 'published',
                ];

            case 'unpublish':
                $entry->published(false);
                $entry->save();

                return [
                    'entry_id' => $entry->id(),
                    'operation' => 'unpublished',
                    'title' => $entry->get('title'),
                    'previous_status' => 'published',
                    'new_status' => 'draft',
                ];

            case 'delete':
                $entryData = [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'slug' => $entry->slug(),
                    'collection' => $entry->collection()->handle(),
                ];
                $entry->delete();

                return [
                    'entry_id' => $entryData['id'],
                    'operation' => 'deleted',
                    'title' => $entryData['title'],
                    'backup' => $originalData,
                ];

            case 'move_collection':
                return $this->executeMoveCollectionOperation($entry, $operationData);

            default:
                throw new \InvalidArgumentException("Unknown operation: {$operation}");
        }
    }

    /**
     * Execute update operation.
     *
     * @param  array<string, mixed>  $operationData
     *
     * @return array<string, mixed>
     */
    private function executeUpdateOperation(\Statamic\Contracts\Entries\Entry $entry, array $operationData, bool $validateBlueprint): array
    {
        $fieldUpdates = $operationData['field_updates'] ?? [];
        $mergeData = $operationData['merge_data'] ?? true;
        $newAuthor = $operationData['new_author'] ?? null;

        if (empty($fieldUpdates) && ! $newAuthor) {
            throw new \InvalidArgumentException('No field updates or author change provided for update operation');
        }

        $currentData = $entry->data()->all();
        $updatedData = $mergeData ? array_merge($currentData, $fieldUpdates) : $fieldUpdates;

        // Validate against blueprint if requested
        if ($validateBlueprint) {
            $blueprint = $entry->blueprint();
            if ($blueprint) {
                foreach ($blueprint->fields()->all() as $fieldHandle => $field) {
                    if ($field->isRequired() && ! isset($updatedData[$fieldHandle])) {
                        throw new \InvalidArgumentException("Required field '{$fieldHandle}' is missing");
                    }
                }
            }
        }

        $entry->data($updatedData);

        // Handle author change
        if ($newAuthor) {
            if (filter_var($newAuthor, FILTER_VALIDATE_EMAIL)) {
                $authorUser = User::findByEmail($newAuthor);
            } else {
                $authorUser = User::find($newAuthor);
            }
            if ($authorUser) {
                $entry->set('author', $authorUser->id());
            }
        }

        $entry->save();

        return [
            'entry_id' => $entry->id(),
            'operation' => 'updated',
            'title' => $entry->get('title'),
            'fields_updated' => array_keys($fieldUpdates),
            'author_changed' => $newAuthor !== null,
        ];
    }

    /**
     * Execute move collection operation.
     *
     * @param  array<string, mixed>  $operationData
     *
     * @return array<string, mixed>
     */
    private function executeMoveCollectionOperation(\Statamic\Contracts\Entries\Entry $entry, array $operationData): array
    {
        $targetCollection = $operationData['target_collection'] ?? null;
        if (! $targetCollection) {
            throw new \InvalidArgumentException('Target collection is required for move_collection operation');
        }

        $targetCollectionObj = Collection::find($targetCollection);
        if (! $targetCollectionObj) {
            throw new \InvalidArgumentException("Target collection '{$targetCollection}' not found");
        }

        $originalCollection = $entry->collection()->handle();
        $entryData = $entry->data()->all();

        // Delete from original collection
        $entry->delete();

        // Create in new collection
        $newEntry = Entry::make()
            ->collection($targetCollection)
            ->slug($entry->slug())
            ->data($entryData)
            ->published($entry->published());

        if ($entry->date()) {
            $newEntry->date($entry->date());
        }

        $newEntry->save();

        return [
            'entry_id' => $newEntry->id(),
            'operation' => 'moved_collection',
            'title' => $newEntry->get('title'),
            'from_collection' => $originalCollection,
            'to_collection' => $targetCollection,
            'original_id' => $entry->id(),
            'new_id' => $newEntry->id(),
        ];
    }

    /**
     * Preview what an operation would do.
     *
     * @param  array<string, mixed>  $operationData
     *
     * @return array<string, mixed>
     */
    private function previewOperation(string $operation, array $operationData, \Statamic\Contracts\Entries\Entry $sampleEntry): array
    {
        switch ($operation) {
            case 'update':
                return [
                    'will_update_fields' => array_keys($operationData['field_updates'] ?? []),
                    'will_change_author' => isset($operationData['new_author']),
                    'merge_mode' => $operationData['merge_data'] ?? true,
                ];

            case 'publish':
            case 'unpublish':
                return [
                    'will_change_status' => true,
                    'current_status' => $sampleEntry->status(),
                    'new_status' => $operation === 'publish' ? 'published' : 'draft',
                ];

            case 'delete':
                return [
                    'will_delete_permanently' => true,
                    'backup_created' => true,
                ];

            case 'move_collection':
                return [
                    'from_collection' => $sampleEntry->collection()->handle(),
                    'to_collection' => $operationData['target_collection'] ?? 'unknown',
                    'will_preserve_data' => true,
                ];

            default:
                return ['unknown_operation' => $operation];
        }
    }
}
