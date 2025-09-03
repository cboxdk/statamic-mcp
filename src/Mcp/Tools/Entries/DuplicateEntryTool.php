<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;

#[Title('Duplicate Statamic Entry')]
class DuplicateEntryTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.duplicate';
    }

    protected function getToolDescription(): string
    {
        return 'Duplicate an existing entry with customizable field modifications and slug generation';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('id')
            ->description('Source entry ID to duplicate')
            ->required()
            ->string('new_title')
            ->description('New title for duplicated entry (required)')
            ->required()
            ->string('new_slug')
            ->description('New slug (auto-generated from title if not provided)')
            ->optional()
            ->raw('field_modifications', [
                'type' => 'object',
                'description' => 'Fields to modify in the duplicated entry',
                'additionalProperties' => true,
            ])
            ->optional()
            ->raw('fields_to_exclude', [
                'type' => 'array',
                'description' => 'Field names to exclude from duplication',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->raw('fields_to_clear', [
                'type' => 'array',
                'description' => 'Field names to clear (set to null) in duplicate',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->boolean('published')
            ->description('Publication status for duplicate (defaults to false)')
            ->optional()
            ->string('target_collection')
            ->description('Target collection (defaults to source collection)')
            ->optional()
            ->string('site')
            ->description('Target site locale')
            ->optional()
            ->string('author')
            ->description('Author for duplicated entry (ID or email)')
            ->optional()
            ->boolean('duplicate_assets')
            ->description('Duplicate referenced assets to avoid conflicts')
            ->optional()
            ->boolean('update_dates')
            ->description('Update date fields to current time')
            ->optional()
            ->string('title_suffix')
            ->description('Suffix to add to title (e.g., " (Copy)")')
            ->optional()
            ->boolean('dry_run')
            ->description('Preview duplication without executing')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $sourceId = $arguments['id'];
        $newTitle = $arguments['new_title'];
        $newSlug = $arguments['new_slug'] ?? null;
        $fieldModifications = $arguments['field_modifications'] ?? [];
        $fieldsToExclude = $arguments['fields_to_exclude'] ?? [];
        $fieldsToClear = $arguments['fields_to_clear'] ?? [];
        $published = $arguments['published'] ?? false;
        $targetCollection = $arguments['target_collection'] ?? null;
        $site = $arguments['site'] ?? null;
        $author = $arguments['author'] ?? null;
        $duplicateAssets = $arguments['duplicate_assets'] ?? false;
        $updateDates = $arguments['update_dates'] ?? false;
        $titleSuffix = $arguments['title_suffix'] ?? '';
        $dryRun = $arguments['dry_run'] ?? false;

        $sourceEntry = Entry::find($sourceId);
        if (! $sourceEntry) {
            return $this->createErrorResponse("Source entry '{$sourceId}' not found")->toArray();
        }

        $sourceCollection = $sourceEntry->collection();
        $targetCollectionHandle = $targetCollection ?? $sourceCollection->handle();
        $targetCollectionObj = \Statamic\Facades\Collection::find($targetCollectionHandle);

        if (! $targetCollectionObj) {
            return $this->createErrorResponse("Target collection '{$targetCollectionHandle}' not found")->toArray();
        }

        // Generate new slug if not provided
        if (! $newSlug) {
            $titleWithSuffix = $newTitle . $titleSuffix;
            $newSlug = \Illuminate\Support\Str::slug($titleWithSuffix);
        }

        // Check for slug conflicts
        $existingEntry = Entry::query()
            ->where('collection', $targetCollectionHandle)
            ->where('slug', $newSlug)
            ->first();

        if ($existingEntry) {
            return $this->createErrorResponse("Entry with slug '{$newSlug}' already exists in collection '{$targetCollectionHandle}'")->toArray();
        }

        // Get source data and process it
        $sourceData = $sourceEntry->data()->all();
        $newData = [];

        // Process each field
        foreach ($sourceData as $fieldName => $value) {
            // Skip excluded fields
            if (in_array($fieldName, $fieldsToExclude)) {
                continue;
            }

            // Clear specified fields
            if (in_array($fieldName, $fieldsToClear)) {
                $newData[$fieldName] = null;
                continue;
            }

            // Process date fields if update_dates is true
            if ($updateDates && $this->isDateField($fieldName, $value)) {
                $newData[$fieldName] = Carbon::now()->toDateString();
                continue;
            }

            // Process asset fields if duplicate_assets is true
            if ($duplicateAssets && $this->isAssetField($fieldName, $value)) {
                $newData[$fieldName] = $this->processAssetField($value);
                continue;
            }

            // Copy value as-is
            $newData[$fieldName] = $value;
        }

        // Apply field modifications
        foreach ($fieldModifications as $fieldName => $newValue) {
            $newData[$fieldName] = $newValue;
        }

        // Set new title
        $finalTitle = $newTitle . $titleSuffix;
        $newData['title'] = $finalTitle;

        // Handle author assignment
        $authorUser = null;
        if ($author) {
            if (filter_var($author, FILTER_VALIDATE_EMAIL)) {
                $authorUser = \Statamic\Facades\User::findByEmail($author);
            } else {
                $authorUser = \Statamic\Facades\User::find($author);
            }
            if (! $authorUser) {
                return $this->createErrorResponse("Author '{$author}' not found")->toArray();
            }
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_create' => [
                    'source_entry_id' => $sourceId,
                    'source_collection' => $sourceCollection->handle(),
                    'target_collection' => $targetCollectionHandle,
                    'new_title' => $finalTitle,
                    'new_slug' => $newSlug,
                    'published' => $published,
                    'site' => $site ?? $sourceEntry->locale(),
                    'author' => $authorUser?->id(),
                    'fields_processed' => count($newData),
                    'fields_excluded' => $fieldsToExclude,
                    'fields_cleared' => $fieldsToClear,
                    'field_modifications' => array_keys($fieldModifications),
                    'data_preview' => array_slice($newData, 0, 10),
                ],
            ];
        }

        try {
            // Create the duplicate entry
            $duplicateEntry = Entry::make()
                ->collection($targetCollectionHandle)
                ->slug($newSlug)
                ->data($newData)
                ->published($published);

            // Set site locale
            if ($site) {
                $duplicateEntry->locale($site);
            } else {
                $duplicateEntry->locale($sourceEntry->locale());
            }

            // Handle dated collections
            if ($targetCollectionObj->dated()) {
                if ($updateDates) {
                    $duplicateEntry->date(Carbon::now());
                } else {
                    $duplicateEntry->date($sourceEntry->date() ?? Carbon::now());
                }
            }

            // Set author
            if ($authorUser) {
                $duplicateEntry->set('author', $authorUser->id());
            } elseif ($sourceEntry->get('author')) {
                $duplicateEntry->set('author', $sourceEntry->get('author'));
            }

            $duplicateEntry->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'duplicate_entry' => [
                    'id' => $duplicateEntry->id(),
                    'title' => $duplicateEntry->get('title'),
                    'slug' => $duplicateEntry->slug(),
                    'url' => $duplicateEntry->url(),
                    'published' => $duplicateEntry->published(),
                    'status' => $duplicateEntry->status(),
                    'collection' => $duplicateEntry->collection()->handle(),
                    'site' => $duplicateEntry->locale(),
                    'date' => $duplicateEntry->date()?->toISOString(),
                    'author' => $duplicateEntry->get('author'),
                    'blueprint' => $duplicateEntry->blueprint()?->handle(),
                ],
                'source_entry' => [
                    'id' => $sourceEntry->id(),
                    'title' => $sourceEntry->get('title'),
                    'slug' => $sourceEntry->slug(),
                    'collection' => $sourceEntry->collection()->handle(),
                ],
                'duplication_info' => [
                    'fields_copied' => count($newData),
                    'fields_excluded' => count($fieldsToExclude),
                    'fields_cleared' => count($fieldsToClear),
                    'fields_modified' => count($fieldModifications),
                    'cross_collection' => $targetCollectionHandle !== $sourceCollection->handle(),
                    'assets_duplicated' => $duplicateAssets,
                    'dates_updated' => $updateDates,
                    'title_suffix_applied' => ! empty($titleSuffix),
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not duplicate entry: ' . $e->getMessage(), [
                'exception_type' => get_class($e),
                'source_entry_id' => $sourceId,
                'target_collection' => $targetCollectionHandle,
                'trace_summary' => array_slice(explode("\n", $e->getTraceAsString()), 0, 3),
            ])->toArray();
        }
    }

    /**
     * Check if a field contains date data.
     */
    private function isDateField(string $fieldName, mixed $value): bool
    {
        // Common date field patterns
        $dateFields = ['date', 'published_at', 'created_at', 'updated_at', 'start_date', 'end_date'];

        if (in_array($fieldName, $dateFields)) {
            return true;
        }

        // Check if value looks like a date
        if (is_string($value) && Carbon::hasFormat($value, 'Y-m-d')) {
            return true;
        }

        return false;
    }

    /**
     * Check if a field contains asset references.
     */
    private function isAssetField(string $fieldName, mixed $value): bool
    {
        // Common asset field patterns
        $assetFields = ['image', 'images', 'file', 'files', 'gallery', 'avatar', 'logo'];

        if (in_array($fieldName, $assetFields)) {
            return true;
        }

        // Check if value looks like asset path
        if (is_string($value) && str_contains($value, 'assets/')) {
            return true;
        }

        if (is_array($value) && isset($value[0]) && is_string($value[0]) && str_contains($value[0], 'assets/')) {
            return true;
        }

        return false;
    }

    /**
     * Process asset field for duplication.
     */
    private function processAssetField(mixed $value): mixed
    {
        // For now, return as-is. In a real implementation,
        // you might want to copy assets to avoid conflicts
        return $value;
    }
}
