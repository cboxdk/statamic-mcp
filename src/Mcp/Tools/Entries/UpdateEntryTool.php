<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

#[Title('Update Statamic Entry')]
class UpdateEntryTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.update';
    }

    protected function getToolDescription(): string
    {
        return 'Update an existing entry';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('id')
            ->description('Entry ID')
            ->required()
            ->raw('data', [
                'type' => 'object',
                'description' => 'Entry data fields to update',
                'additionalProperties' => true,
            ])
            ->optional()
            ->string('slug')
            ->description('New slug for the entry')
            ->optional()
            ->boolean('published')
            ->description('Publication status')
            ->optional()
            ->string('status')
            ->description('Entry status (published, draft, scheduled)')
            ->optional()
            ->string('date')
            ->description('Entry date (for dated collections, ISO 8601 format)')
            ->optional()
            ->string('author')
            ->description('Author user ID or email')
            ->optional()
            ->boolean('validate_blueprint')
            ->description('Validate data against blueprint schema')
            ->optional()
            ->boolean('create_revision')
            ->description('Create revision before updating')
            ->optional()
            ->boolean('publish_working_copy')
            ->description('Publish working copy if exists')
            ->optional()
            ->raw('seo', [
                'type' => 'object',
                'description' => 'SEO metadata to update',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'keywords' => ['type' => 'string'],
                    'canonical_url' => ['type' => 'string'],
                    'no_index' => ['type' => 'boolean'],
                    'no_follow' => ['type' => 'boolean'],
                ],
                'additionalProperties' => false,
            ])
            ->optional()
            ->boolean('dry_run')
            ->description('Preview changes without updating')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $id = $arguments['id'];
        $newData = $arguments['data'] ?? [];
        $newSlug = $arguments['slug'] ?? null;
        $newPublished = $arguments['published'] ?? null;
        $newStatus = $arguments['status'] ?? null;
        $newDate = $arguments['date'] ?? null;
        $newAuthor = $arguments['author'] ?? null;
        $validateBlueprint = $arguments['validate_blueprint'] ?? true;
        $createRevision = $arguments['create_revision'] ?? false;
        $publishWorkingCopy = $arguments['publish_working_copy'] ?? false;
        $seo = $arguments['seo'] ?? [];
        $dryRun = $arguments['dry_run'] ?? false;

        $entry = Entry::find($id);
        if (! $entry) {
            return $this->createErrorResponse("Entry '{$id}' not found")->toArray();
        }

        $collection = $entry->collection();
        $isWorkingCopy = method_exists($entry, 'isWorkingCopy') ? $entry->isWorkingCopy() : false;
        $hasWorkingCopy = method_exists($entry, 'hasWorkingCopy') ? $entry->hasWorkingCopy() : false;

        // Handle author validation
        $authorUser = null;
        if ($newAuthor) {
            if (filter_var($newAuthor, FILTER_VALIDATE_EMAIL)) {
                $authorUser = User::findByEmail($newAuthor);
            } else {
                $authorUser = User::find($newAuthor);
            }
            if (! $authorUser) {
                return $this->createErrorResponse("Author '{$newAuthor}' not found")->toArray();
            }
        }

        // Handle date validation for dated collections
        $parsedDate = null;
        if ($newDate !== null) {
            if (! $collection->dated()) {
                return $this->createErrorResponse("Collection '{$collection->handle()}' is not dated, but date was provided")->toArray();
            }
            try {
                $parsedDate = Carbon::parse($newDate);
            } catch (\Exception $e) {
                return $this->createErrorResponse("Invalid date format: {$newDate}. Use ISO 8601 format.", [
                    'date_error' => $e->getMessage(),
                ])->toArray();
            }
        }

        $changes = [];
        $updatedData = $entry->data()->all();

        // Handle SEO data updates
        if (! empty($seo)) {
            $seoUpdates = array_filter([
                'seo_title' => $seo['title'] ?? null,
                'seo_description' => $seo['description'] ?? null,
                'seo_keywords' => $seo['keywords'] ?? null,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'seo_noindex' => $seo['no_index'] ?? null,
                'seo_nofollow' => $seo['no_follow'] ?? null,
            ], fn ($value) => $value !== null);

            $newData = array_merge($newData, $seoUpdates);
        }

        // Check for data changes
        if (! empty($newData)) {
            $mergedData = array_merge($updatedData, $newData);
            if ($mergedData !== $updatedData) {
                $changes['data'] = [
                    'added_fields' => array_keys(array_diff_key($newData, $updatedData)),
                    'updated_fields' => array_keys(array_intersect_key($newData, $updatedData)),
                ];
                $updatedData = $mergedData;
            }
        }

        // Check for slug change
        if ($newSlug !== null && $newSlug !== $entry->slug()) {
            $changes['slug'] = ['from' => $entry->slug(), 'to' => $newSlug];
        }

        // Check for publication status change
        $finalPublished = $newPublished;
        $finalStatus = $newStatus;

        if ($newStatus) {
            $finalPublished = in_array($newStatus, ['published']);
            if ($newStatus !== $entry->status()) {
                $changes['status'] = ['from' => $entry->status(), 'to' => $newStatus];
            }
        } elseif ($newPublished !== null && $newPublished !== $entry->published()) {
            $changes['published'] = ['from' => $entry->published(), 'to' => $newPublished];
            $finalStatus = $newPublished ? 'published' : 'draft';
        }

        // Check for date change
        if ($parsedDate && (! $entry->date() || ! $parsedDate->equalTo($entry->date()))) {
            $changes['date'] = [
                'from' => $entry->date()?->toISOString(),
                'to' => $parsedDate->toISOString(),
            ];
        }

        // Check for author change
        if ($authorUser && $authorUser->id() !== $entry->get('author')) {
            $changes['author'] = [
                'from' => $entry->get('author'),
                'to' => $authorUser->id(),
            ];
        }

        // Validate against blueprint if requested
        $validationErrors = [];
        if ($validateBlueprint && ! empty($changes)) {
            $blueprint = $entry->blueprint();
            if ($blueprint) {
                foreach ($blueprint->fields()->all() as $fieldHandle => $field) {
                    if ($field->isRequired() && ! isset($updatedData[$fieldHandle])) {
                        $validationErrors[] = "Required field '{$fieldHandle}' is missing";
                    }
                }

                // Basic type validation for changed fields
                foreach ($newData as $fieldHandle => $value) {
                    if ($blueprint->hasField($fieldHandle)) {
                        $field = $blueprint->field($fieldHandle);
                        $fieldType = $field->type();

                        if ($fieldType === 'date' && $value && ! Carbon::hasFormat($value, 'Y-m-d')) {
                            $validationErrors[] = "Field '{$fieldHandle}' must be a valid date (Y-m-d format)";
                        }

                        if ($fieldType === 'integer' && $value && ! is_numeric($value)) {
                            $validationErrors[] = "Field '{$fieldHandle}' must be numeric";
                        }

                        if ($fieldType === 'toggle' && $value && ! is_bool($value)) {
                            $validationErrors[] = "Field '{$fieldHandle}' must be boolean";
                        }
                    }
                }
            }

            if (count($validationErrors) > 0) {
                return $this->createErrorResponse('Blueprint validation failed', [
                    'validation_errors' => $validationErrors,
                    'blueprint' => $blueprint?->handle(),
                ])->toArray();
            }
        }

        if (empty($changes)) {
            return [
                'id' => $id,
                'message' => 'No changes detected',
                'entry' => [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
                    'is_working_copy' => $isWorkingCopy,
                    'has_working_copy' => $hasWorkingCopy,
                ],
            ];
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'id' => $id,
                'proposed_changes' => $changes,
                'has_changes' => true,
                'working_copy_info' => [
                    'is_working_copy' => $isWorkingCopy,
                    'has_working_copy' => $hasWorkingCopy,
                    'will_create_revision' => $createRevision,
                    'will_publish_working_copy' => $publishWorkingCopy,
                ],
                'validation_passed' => $validationErrors === [],
            ];
        }

        try {
            // Handle working copy scenarios
            $workingEntry = $entry;

            if ($publishWorkingCopy && $hasWorkingCopy && ! $isWorkingCopy) {
                // Get working copy to publish
                if (method_exists($entry, 'workingCopy')) {
                    $workingCopy = $entry->workingCopy();
                    if ($workingCopy) {
                        $workingCopy->published(true);
                        $workingCopy->save();
                        $workingEntry = $workingCopy;
                    }
                }
            } elseif ($createRevision && ! $isWorkingCopy) {
                // Create revision/working copy before updating
                if (method_exists($entry, 'makeWorkingCopy')) {
                    $workingCopy = $entry->makeWorkingCopy();
                    $workingEntry = $workingCopy;
                }
            }

            // Apply data updates
            if (isset($changes['data'])) {
                $workingEntry->data($updatedData);
            }

            // Apply slug update
            if (isset($changes['slug'])) {
                $workingEntry->slug($newSlug);
            }

            // Apply publication status
            if (isset($changes['published']) || isset($changes['status'])) {
                if ($finalPublished !== null) {
                    $workingEntry->published($finalPublished);
                }
            }

            // Apply date update
            if (isset($changes['date'])) {
                $workingEntry->date($parsedDate);
            }

            // Apply author update
            if (isset($changes['author'])) {
                $workingEntry->set('author', $authorUser->id());
            }

            $workingEntry->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'entry' => [
                    'id' => $workingEntry->id(),
                    'title' => $workingEntry->get('title'),
                    'slug' => $workingEntry->slug(),
                    'url' => $workingEntry->url(),
                    'published' => $workingEntry->published(),
                    'status' => $workingEntry->status(),
                    'collection' => $workingEntry->collection()->handle(),
                    'site' => $workingEntry->locale(),
                    'date' => $workingEntry->date()?->toISOString(),
                    'author' => $workingEntry->get('author'),
                    'is_working_copy' => method_exists($workingEntry, 'isWorkingCopy') && is_object($workingEntry) && $workingEntry->isWorkingCopy(),
                    'blueprint' => $workingEntry->blueprint()?->handle(),
                    'data' => $workingEntry->data()->all(),
                    'seo' => array_filter([
                        'title' => $workingEntry->get('seo_title'),
                        'description' => $workingEntry->get('seo_description'),
                        'keywords' => $workingEntry->get('seo_keywords'),
                        'canonical_url' => $workingEntry->get('canonical_url'),
                        'no_index' => $workingEntry->get('seo_noindex'),
                        'no_follow' => $workingEntry->get('seo_nofollow'),
                    ], fn ($value) => $value !== null),
                ],
                'changes' => $changes,
                'revision_info' => [
                    'created_revision' => $createRevision && $workingEntry !== $entry,
                    'published_working_copy' => $publishWorkingCopy,
                    'original_entry_id' => $entry->id(),
                    'working_entry_id' => $workingEntry->id(),
                ],
                'validation' => [
                    'blueprint_validated' => $validateBlueprint,
                    'errors' => $validationErrors,
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not update entry: ' . $e->getMessage(), [
                'exception_type' => get_class($e),
                'trace_summary' => array_slice(explode("\n", $e->getTraceAsString()), 0, 3),
            ])->toArray();
        }
    }
}
