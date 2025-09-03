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

#[Title('Import/Export Entries')]
class ImportExportEntriesTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.import_export';
    }

    protected function getToolDescription(): string
    {
        return 'Import and export entries with data transformation, validation, and conflict resolution';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('operation')
            ->description('Operation: import, export, validate_import')
            ->required()
            ->string('collection')
            ->description('Target collection handle')
            ->required()
            ->raw('data', [
                'type' => 'array',
                'description' => 'Entry data for import (array of entry objects)',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ])
            ->optional()
            ->raw('export_criteria', [
                'type' => 'object',
                'description' => 'Criteria for export selection',
                'properties' => [
                    'status' => ['type' => 'string'],
                    'author' => ['type' => 'string'],
                    'date_range' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'string'],
                            'to' => ['type' => 'string'],
                        ],
                    ],
                    'limit' => ['type' => 'integer'],
                ],
                'additionalProperties' => true,
            ])
            ->optional()
            ->raw('field_mapping', [
                'type' => 'object',
                'description' => 'Map source fields to target fields',
                'additionalProperties' => ['type' => 'string'],
            ])
            ->optional()
            ->raw('transformation_rules', [
                'type' => 'array',
                'description' => 'Rules for transforming data during import',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string'],
                        'transformation' => ['type' => 'string'],
                        'parameters' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ])
            ->optional()
            ->string('conflict_resolution')
            ->description('How to handle existing entries: skip, update, replace, duplicate')
            ->optional()
            ->string('id_field')
            ->description('Field to use for identifying existing entries (default: slug)')
            ->optional()
            ->boolean('validate_blueprint')
            ->description('Validate imported data against blueprint')
            ->optional()
            ->boolean('create_missing_users')
            ->description('Create user accounts for missing authors')
            ->optional()
            ->string('default_status')
            ->description('Default status for imported entries')
            ->optional()
            ->boolean('preserve_dates')
            ->description('Preserve original dates from import data')
            ->optional()
            ->integer('batch_size')
            ->description('Process imports in batches (default: 50)')
            ->optional()
            ->boolean('dry_run')
            ->description('Validate and preview without importing')
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
        $collection = $arguments['collection'];
        $dryRun = $arguments['dry_run'] ?? false;

        switch ($operation) {
            case 'export':
                return $this->executeExport($collection, $arguments);
            case 'import':
                return $this->executeImport($collection, $arguments, $dryRun);
            case 'validate_import':
                return $this->validateImport($collection, $arguments);
            default:
                return $this->createErrorResponse("Invalid operation '{$operation}'. Valid operations: export, import, validate_import")->toArray();
        }
    }

    /**
     * Execute export operation.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function executeExport(string $collectionHandle, array $arguments): array
    {
        $criteria = $arguments['export_criteria'] ?? [];
        $fieldMapping = $arguments['field_mapping'] ?? [];

        $collectionObj = Collection::find($collectionHandle);
        if (! $collectionObj) {
            return $this->createErrorResponse("Collection '{$collectionHandle}' not found")->toArray();
        }

        $query = Entry::query()->where('collection', $collectionHandle);

        // Apply export criteria
        if (isset($criteria['status'])) {
            if ($criteria['status'] === 'published') {
                $query->where('published', true);
            } elseif ($criteria['status'] === 'draft') {
                $query->where('published', false);
            }
        }

        if (isset($criteria['author'])) {
            $query->where('author', $criteria['author']);
        }

        if (isset($criteria['date_range'])) {
            if (isset($criteria['date_range']['from'])) {
                $query->where('date', '>=', Carbon::parse($criteria['date_range']['from']));
            }
            if (isset($criteria['date_range']['to'])) {
                $query->where('date', '<=', Carbon::parse($criteria['date_range']['to']));
            }
        }

        if (isset($criteria['limit'])) {
            $query->limit($criteria['limit']);
        }

        $entries = $query->get();
        $exportData = [];

        foreach ($entries as $entry) {
            $entryData = [
                'id' => $entry->id(),
                'title' => $entry->get('title'),
                'slug' => $entry->slug(),
                'published' => $entry->published(),
                'status' => $entry->status(),
                'date' => $entry->date()?->toISOString(),
                'author' => $entry->get('author'),
                'site' => $entry->locale(),
                'blueprint' => $entry->blueprint()?->handle(),
                'collection' => $entry->collection()->handle(),
                'data' => $entry->data()->all(),
            ];

            // Apply field mapping for export
            if (! empty($fieldMapping)) {
                $mappedData = [];
                foreach ($fieldMapping as $targetField => $sourceField) {
                    $mappedData[$targetField] = $entryData['data'][$sourceField] ?? null;
                }
                $entryData['mapped_data'] = $mappedData;
            }

            $exportData[] = $entryData;
        }

        return [
            'operation' => 'export',
            'collection' => $collectionHandle,
            'exported_count' => count($exportData),
            'export_criteria' => $criteria,
            'field_mapping' => $fieldMapping,
            'data' => $exportData,
            'export_metadata' => [
                'exported_at' => Carbon::now()->toISOString(),
                'total_available' => Entry::query()->where('collection', $collectionHandle)->count(),
                'blueprint' => $collectionObj->entryBlueprint()?->handle(),
            ],
        ];
    }

    /**
     * Execute import operation.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function executeImport(string $collectionHandle, array $arguments, bool $dryRun): array
    {
        $data = $arguments['data'] ?? [];
        $fieldMapping = $arguments['field_mapping'] ?? [];
        $transformationRules = $arguments['transformation_rules'] ?? [];
        $conflictResolution = $arguments['conflict_resolution'] ?? 'skip';
        $idField = $arguments['id_field'] ?? 'slug';
        $validateBlueprint = $arguments['validate_blueprint'] ?? true;
        $createMissingUsers = $arguments['create_missing_users'] ?? false;
        $defaultStatus = $arguments['default_status'] ?? 'draft';
        $preserveDates = $arguments['preserve_dates'] ?? true;
        $batchSize = $arguments['batch_size'] ?? 50;

        if (empty($data)) {
            return $this->createErrorResponse('No data provided for import')->toArray();
        }

        $collectionObj = Collection::find($collectionHandle);
        if (! $collectionObj) {
            return $this->createErrorResponse("Collection '{$collectionHandle}' not found")->toArray();
        }

        $blueprint = $collectionObj->entryBlueprint();
        $results = [
            'imported' => [],
            'skipped' => [],
            'updated' => [],
            'errors' => [],
        ];

        $batches = array_chunk($data, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $index => $itemData) {
                try {
                    $result = $this->processImportItem(
                        $itemData,
                        $collectionHandle,
                        $fieldMapping,
                        $transformationRules,
                        $conflictResolution,
                        $idField,
                        $validateBlueprint,
                        $createMissingUsers,
                        $defaultStatus,
                        $preserveDates,
                        $blueprint,
                        $dryRun
                    );

                    $results[$result['action']][] = $result;
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'data' => array_slice($itemData, 0, 3), // Preview of problematic data
                    ];
                }
            }
        }

        // Clear caches after successful import
        if (! $dryRun && (count($results['imported']) > 0 || count($results['updated']) > 0)) {
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);
        }

        return [
            'operation' => $dryRun ? 'import_preview' : 'import',
            'collection' => $collectionHandle,
            'summary' => [
                'total_items' => count($data),
                'imported' => count($results['imported']),
                'updated' => count($results['updated']),
                'skipped' => count($results['skipped']),
                'errors' => count($results['errors']),
            ],
            'results' => $results,
            'settings' => [
                'conflict_resolution' => $conflictResolution,
                'id_field' => $idField,
                'validate_blueprint' => $validateBlueprint,
                'batch_size' => $batchSize,
            ],
            'cache' => $cacheResult ?? null,
        ];
    }

    /**
     * Process a single import item.
     *
     * @param  array<string, mixed>  $itemData
     * @param  array<string, mixed>  $fieldMapping
     * @param  array<string, mixed>  $transformationRules
     */
    /**
     * @param  array<string, mixed>  $itemData
     * @param  array<string, mixed>  $fieldMapping
     * @param  array<string, mixed>  $transformationRules
     * @param  \Statamic\Fields\Blueprint|null  $blueprint
     *
     * @return array<string, mixed>
     */
    private function processImportItem(
        array $itemData,
        string $collectionHandle,
        array $fieldMapping,
        array $transformationRules,
        string $conflictResolution,
        string $idField,
        bool $validateBlueprint,
        bool $createMissingUsers,
        string $defaultStatus,
        bool $preserveDates,
        $blueprint,
        bool $dryRun
    ): array {
        // Apply field mapping
        if (! empty($fieldMapping)) {
            $mappedData = [];
            foreach ($fieldMapping as $targetField => $sourceField) {
                $mappedData[$targetField] = $itemData[$sourceField] ?? null;
            }
            $itemData = array_merge($itemData, $mappedData);
        }

        // Apply transformations
        foreach ($transformationRules as $rule) {
            $field = $rule['field'];
            $transformation = $rule['transformation'];
            $parameters = $rule['parameters'] ?? [];

            if (isset($itemData[$field])) {
                $itemData[$field] = $this->applyTransformation($itemData[$field], $transformation, $parameters);
            }
        }

        // Ensure required fields
        $slug = $itemData['slug'] ?? \Illuminate\Support\Str::slug($itemData['title'] ?? 'untitled');
        $title = $itemData['title'] ?? 'Untitled';

        // Check for existing entry
        $existingEntry = null;
        if ($idField === 'slug') {
            $existingEntry = Entry::query()->where('collection', $collectionHandle)->where('slug', $slug)->first();
        } elseif ($idField === 'id' && isset($itemData['id'])) {
            $existingEntry = Entry::find($itemData['id']);
        } else {
            $existingEntry = Entry::query()->where('collection', $collectionHandle)->where($idField, $itemData[$idField] ?? null)->first();
        }

        if ($existingEntry) {
            if ($conflictResolution === 'skip') {
                return [
                    'action' => 'skipped',
                    'reason' => 'Entry already exists',
                    'existing_id' => $existingEntry->id(),
                    'slug' => $slug,
                ];
            } elseif ($conflictResolution === 'update' || $conflictResolution === 'replace') {
                return $this->updateExistingEntry($existingEntry, $itemData, $conflictResolution === 'replace', $dryRun);
            }
        }

        // Create new entry
        $entryData = $this->prepareEntryData($itemData, $defaultStatus, $preserveDates, $createMissingUsers);

        if ($validateBlueprint && $blueprint) {
            $validationErrors = $this->validateAgainstBlueprint($entryData, $blueprint);
            if (! empty($validationErrors)) {
                throw new \InvalidArgumentException('Blueprint validation failed: ' . implode(', ', $validationErrors));
            }
        }

        if ($dryRun) {
            return [
                'action' => 'imported',
                'preview' => true,
                'slug' => $slug,
                'title' => $title,
                'data_preview' => array_slice($entryData, 0, 5),
            ];
        }

        $entry = Entry::make()
            ->collection($collectionHandle)
            ->slug($slug)
            ->data($entryData)
            ->published($itemData['published'] ?? ($defaultStatus === 'published'));

        if (isset($itemData['date']) && $preserveDates) {
            $entry->date(Carbon::parse($itemData['date']));
        }

        if (isset($itemData['site'])) {
            $entry->locale($itemData['site']);
        }

        $entry->save();

        return [
            'action' => 'imported',
            'entry_id' => $entry->id(),
            'slug' => $slug,
            'title' => $title,
        ];
    }

    /**
     * Apply data transformation.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function applyTransformation(mixed $value, string $transformation, array $parameters): mixed
    {
        switch ($transformation) {
            case 'uppercase':
                return is_string($value) ? strtoupper($value) : $value;
            case 'lowercase':
                return is_string($value) ? strtolower($value) : $value;
            case 'trim':
                return is_string($value) ? trim($value) : $value;
            case 'replace':
                if (is_string($value) && isset($parameters['search'], $parameters['replace'])) {
                    return str_replace($parameters['search'], $parameters['replace'], $value);
                }

                return $value;
            case 'date_format':
                if (isset($parameters['format'])) {
                    return Carbon::parse($value)->format($parameters['format']);
                }

                return $value;
            case 'prefix':
                return ($parameters['prefix'] ?? '') . $value;
            case 'suffix':
                return $value . ($parameters['suffix'] ?? '');
            default:
                return $value;
        }
    }

    /**
     * Validate import data structure.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function validateImport(string $collectionHandle, array $arguments): array
    {
        $data = $arguments['data'] ?? [];
        $fieldMapping = $arguments['field_mapping'] ?? [];

        if (empty($data)) {
            return $this->createErrorResponse('No data provided for validation')->toArray();
        }

        $collectionObj = Collection::find($collectionHandle);
        if (! $collectionObj) {
            return $this->createErrorResponse("Collection '{$collectionHandle}' not found")->toArray();
        }

        $blueprint = $collectionObj->entryBlueprint();
        $validationResults = [];
        $issues = [];

        foreach ($data as $index => $itemData) {
            $itemIssues = [];

            // Check required fields
            if (! isset($itemData['title']) || empty($itemData['title'])) {
                $itemIssues[] = 'Missing or empty title field';
            }

            // Validate against blueprint
            if ($blueprint) {
                $blueprintIssues = $this->validateAgainstBlueprint($itemData, $blueprint);
                $itemIssues = array_merge($itemIssues, $blueprintIssues);
            }

            $validationResults[] = [
                'index' => $index,
                'valid' => empty($itemIssues),
                'issues' => $itemIssues,
                'preview' => [
                    'title' => $itemData['title'] ?? 'No title',
                    'slug' => $itemData['slug'] ?? 'No slug',
                ],
            ];

            if (! empty($itemIssues)) {
                $issues = array_merge($issues, $itemIssues);
            }
        }

        return [
            'operation' => 'validate_import',
            'collection' => $collectionHandle,
            'total_items' => count($data),
            'valid_items' => count(array_filter($validationResults, fn ($item) => $item['valid'])),
            'invalid_items' => count(array_filter($validationResults, fn ($item) => ! $item['valid'])),
            'validation_results' => $validationResults,
            'common_issues' => array_count_values($issues),
        ];
    }

    /**
     * Validate data against blueprint.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string>
     */
    /**
     * @param  array<string, mixed>  $data
     * @param  \Statamic\Fields\Blueprint|null  $blueprint
     *
     * @return array<string>
     */
    private function validateAgainstBlueprint(array $data, $blueprint): array
    {
        $errors = [];

        foreach ($blueprint->fields()->all() as $fieldHandle => $field) {
            if ($field->isRequired() && (! isset($data[$fieldHandle]) || empty($data[$fieldHandle]))) {
                $errors[] = "Required field '{$fieldHandle}' is missing or empty";
            }
        }

        return $errors;
    }

    /**
     * Prepare entry data for creation.
     *
     * @param  array<string, mixed>  $itemData
     *
     * @return array<string, mixed>
     */
    private function prepareEntryData(array $itemData, string $defaultStatus, bool $preserveDates, bool $createMissingUsers): array
    {
        // Remove meta fields
        $metaFields = ['id', 'slug', 'published', 'status', 'date', 'author', 'site', 'blueprint', 'collection'];
        $entryData = array_diff_key($itemData, array_flip($metaFields));

        // Handle author
        if (isset($itemData['author'])) {
            $author = $itemData['author'];
            if (is_string($author) && filter_var($author, FILTER_VALIDATE_EMAIL)) {
                $user = User::findByEmail($author);
                if (! $user && $createMissingUsers) {
                    $user = User::make()->email($author)->save();
                }
                $entryData['author'] = $user?->id();
            } else {
                $entryData['author'] = $author;
            }
        }

        return $entryData;
    }

    /**
     * Update existing entry.
     *
     * @param  array<string, mixed>  $itemData
     */
    /**
     * @param  array<string, mixed>  $itemData
     *
     * @return array<string, mixed>
     */
    private function updateExistingEntry(\Statamic\Contracts\Entries\Entry $existingEntry, array $itemData, bool $replace, bool $dryRun): array
    {
        $entryData = $this->prepareEntryData($itemData, 'draft', true, false);

        if ($dryRun) {
            return [
                'action' => 'updated',
                'preview' => true,
                'entry_id' => $existingEntry->id(),
                'changes' => array_keys(array_diff_assoc($entryData, $existingEntry->data()->all())),
            ];
        }

        if ($replace) {
            $existingEntry->data($entryData);
        } else {
            $currentData = $existingEntry->data()->all();
            $mergedData = array_merge($currentData, $entryData);
            $existingEntry->data($mergedData);
        }

        $existingEntry->save();

        return [
            'action' => 'updated',
            'entry_id' => $existingEntry->id(),
            'slug' => $existingEntry->slug(),
            'title' => $existingEntry->get('title'),
            'method' => $replace ? 'replace' : 'merge',
        ];
    }
}
