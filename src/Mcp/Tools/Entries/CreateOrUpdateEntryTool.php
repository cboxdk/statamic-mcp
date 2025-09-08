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
use Statamic\Facades\Site;
use Statamic\Facades\User;

#[Title('Create or Update Statamic Entry')]
class CreateOrUpdateEntryTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.create_or_update';
    }

    protected function getToolDescription(): string
    {
        return 'Create or update entry with sophisticated logic including working copies, revisions, and blueprint validation';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('collection')
            ->description('Collection handle')
            ->required()
            ->string('title')
            ->description('Entry title')
            ->required()
            ->string('slug')
            ->description('Entry slug (auto-generated from title if not provided)')
            ->optional()
            ->string('id')
            ->description('Entry ID to update (if not provided, creates new entry)')
            ->optional()
            ->raw('lookup_criteria', [
                'type' => 'object',
                'description' => 'Criteria to find existing entry (slug, title, custom field)',
                'properties' => [
                    'field' => ['type' => 'string'],
                    'value' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ])
            ->optional()
            ->raw('data', [
                'type' => 'object',
                'description' => 'Entry data fields',
                'additionalProperties' => true,
            ])
            ->optional()
            ->boolean('published')
            ->description('Whether the entry should be published')
            ->optional()
            ->string('status')
            ->description('Entry status (published, draft, scheduled)')
            ->optional()
            ->string('site')
            ->description('Site locale (defaults to default site)')
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
            ->boolean('create_working_copy')
            ->description('Create as working copy (draft revision)')
            ->optional()
            ->boolean('update_existing')
            ->description('Update existing entry if found, create if not')
            ->optional()
            ->boolean('merge_data')
            ->description('Merge new data with existing data (vs replace)')
            ->optional()
            ->raw('seo', [
                'type' => 'object',
                'description' => 'SEO metadata',
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
        $collectionHandle = $arguments['collection'];
        $title = $arguments['title'];
        $slug = $arguments['slug'] ?? null;
        $id = $arguments['id'] ?? null;
        $lookupCriteria = $arguments['lookup_criteria'] ?? null;
        $data = $arguments['data'] ?? [];
        $published = $arguments['published'] ?? null;
        $status = $arguments['status'] ?? null;
        $site = $arguments['site'] ?? null;
        $date = $arguments['date'] ?? null;
        $author = $arguments['author'] ?? null;
        $validateBlueprint = $arguments['validate_blueprint'] ?? true;
        $createWorkingCopy = $arguments['create_working_copy'] ?? false;
        $updateExisting = $arguments['update_existing'] ?? true;
        $mergeData = $arguments['merge_data'] ?? true;
        $seo = $arguments['seo'] ?? [];
        $dryRun = $arguments['dry_run'] ?? false;

        $collection = Collection::find($collectionHandle);
        if (! $collection) {
            return $this->createErrorResponse("Collection '{$collectionHandle}' not found")->toArray();
        }

        // Validate site (optimized)
        if ($site && ! Site::all()->map(fn ($item) => $item->handle())->contains($site)) {
            return $this->createErrorResponse("Site '{$site}' not found")->toArray();
        }

        // Handle author assignment
        $authorUser = null;
        if ($author) {
            if (filter_var($author, FILTER_VALIDATE_EMAIL)) {
                $authorUser = User::findByEmail($author);
            } else {
                $authorUser = User::find($author);
            }
            if (! $authorUser) {
                return $this->createErrorResponse("Author '{$author}' not found")->toArray();
            }
        }

        // Generate slug if not provided
        if (! $slug) {
            $slug = \Illuminate\Support\Str::slug($title);
        }

        // Handle dated collections
        $parsedDate = null;
        if ($collection->dated()) {
            if ($date) {
                try {
                    $parsedDate = Carbon::parse($date);
                } catch (\Exception $e) {
                    return $this->createErrorResponse("Invalid date format: {$date}. Use ISO 8601 format.", [
                        'date_error' => $e->getMessage(),
                    ])->toArray();
                }
            } else {
                $parsedDate = Carbon::now();
            }
        } elseif ($date) {
            return $this->createErrorResponse("Collection '{$collectionHandle}' is not dated, but date was provided")->toArray();
        }

        // Find existing entry
        $existingEntry = null;
        $operation = 'create';

        if ($id) {
            $existingEntry = Entry::find($id);
            if (! $existingEntry) {
                return $this->createErrorResponse("Entry with ID '{$id}' not found")->toArray();
            }
            $operation = 'update';
        } elseif ($lookupCriteria && isset($lookupCriteria['field'], $lookupCriteria['value'])) {
            // Ultra-aggressive entry detection for parallel CI environments
            \Statamic\Facades\Stache::refresh();

            $field = $lookupCriteria['field'];
            $value = $lookupCriteria['value'];

            if ($field === 'slug') {
                try {
                    $existingEntry = Entry::query()
                        ->where('collection', $collectionHandle)
                        ->where('slug', $value)
                        ->first();
                } catch (\Exception $e) {
                    // Ignore query errors in parallel environments
                }
            } elseif ($field === 'title') {
                try {
                    $existingEntry = Entry::query()
                        ->where('collection', $collectionHandle)
                        ->where('title', $value)
                        ->first();
                } catch (\Exception $e) {
                    // Ignore query errors in parallel environments
                }
            } else {
                try {
                    $existingEntry = Entry::query()
                        ->where('collection', $collectionHandle)
                        ->where($field, $value)
                        ->first();
                } catch (\Exception $e) {
                    // Ignore query errors in parallel environments
                }
            }

            if ($existingEntry) {
                $operation = $updateExisting ? 'update' : 'skip';
            }
        } else {
            // Ultra-aggressive duplicate check for parallel CI environments
            \Statamic\Facades\Stache::refresh();

            // Method 1: Query-based check
            try {
                $existingEntry = Entry::query()
                    ->where('collection', $collectionHandle)
                    ->where('slug', $slug)
                    ->first();
            } catch (\Exception $e) {
                // Ignore query errors in parallel environments
            }

            // Method 2: Direct ID-based check
            if (! $existingEntry) {
                try {
                    $entryId = "{$collectionHandle}::{$slug}";
                    $existingEntry = Entry::find($entryId);
                } catch (\Exception $e) {
                    // Ignore find errors
                }
            }

            // Method 3: Collection-specific check
            if (! $existingEntry) {
                try {
                    $collection = Collection::find($collectionHandle);
                    if ($collection) {
                        $existingEntry = $collection->queryEntries()->where('slug', $slug)->first();
                    }
                } catch (\Exception $e) {
                    // Ignore collection query errors
                }
            }

            if ($existingEntry) {
                $operation = $updateExisting ? 'update' : 'skip';
            }
        }

        // Merge title into data
        $finalData = array_merge($data, ['title' => $title]);

        // Handle SEO data
        if (! empty($seo)) {
            $seoData = array_filter([
                'seo_title' => $seo['title'] ?? null,
                'seo_description' => $seo['description'] ?? null,
                'seo_keywords' => $seo['keywords'] ?? null,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'seo_noindex' => $seo['no_index'] ?? null,
                'seo_nofollow' => $seo['no_follow'] ?? null,
            ], fn ($value) => $value !== null);

            $finalData = array_merge($finalData, $seoData);
        }

        // Merge with existing data if updating and merge_data is true
        if ($operation === 'update' && $existingEntry && $mergeData) {
            $existingData = $existingEntry->data()->all();
            $finalData = array_merge($existingData, $finalData);
        }

        // Validate against blueprint
        $validationErrors = [];

        // Re-fetch collection to handle parallel execution race conditions
        $collection = Collection::find($collectionHandle);
        if (! $collection) {
            return $this->createErrorResponse("Collection '{$collectionHandle}' not found during blueprint validation")->toArray();
        }

        $blueprint = $collection->entryBlueprint();

        if ($validateBlueprint && $blueprint) {
            foreach ($blueprint->fields()->all() as $fieldHandle => $field) {
                if ($field->isRequired() && ! isset($finalData[$fieldHandle])) {
                    $validationErrors[] = "Required field '{$fieldHandle}' is missing";
                }
            }

            // Basic type validation
            foreach ($finalData as $fieldHandle => $value) {
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

            if (count($validationErrors) > 0) {
                return $this->createErrorResponse('Blueprint validation failed', [
                    'validation_errors' => $validationErrors,
                    'blueprint' => $blueprint->handle(),
                    'operation' => $operation,
                ])->toArray();
            }
        }

        // Determine publication status
        $isPublished = false;
        $entryStatus = 'draft';

        if ($status) {
            $entryStatus = $status;
            $isPublished = in_array($status, ['published']);
        } elseif ($published !== null) {
            $isPublished = $published;
            $entryStatus = $published ? 'published' : 'draft';
        } else {
            // Inherit from existing entry or default
            if ($existingEntry) {
                $isPublished = $existingEntry->published();
                $entryStatus = $existingEntry->status();
            } else {
                $entryStatus = $createWorkingCopy ? 'draft' : 'published';
                $isPublished = ! $createWorkingCopy;
            }
        }

        if ($operation === 'skip') {
            return [
                'operation' => 'skipped',
                'reason' => 'Entry already exists and update_existing is false',
                'existing_entry' => [
                    'id' => $existingEntry->id(),
                    'title' => $existingEntry->get('title'),
                    'slug' => $existingEntry->slug(),
                    'url' => $existingEntry->url(),
                ],
            ];
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'operation' => $operation,
                'would_execute' => [
                    'collection' => $collectionHandle,
                    'title' => $title,
                    'slug' => $slug,
                    'published' => $isPublished,
                    'status' => $entryStatus,
                    'site' => $site ?? Site::default()->handle(),
                    'date' => $parsedDate?->toISOString(),
                    'author' => $authorUser?->id(),
                    'create_working_copy' => $createWorkingCopy,
                    'merge_data' => $mergeData,
                    'data' => $finalData,
                    'validation_passed' => $validationErrors === [],
                ],
                'existing_entry' => $existingEntry ? [
                    'id' => $existingEntry->id(),
                    'title' => $existingEntry->get('title'),
                    'slug' => $existingEntry->slug(),
                ] : null,
            ];
        }

        try {
            // Re-fetch collection to handle parallel execution race conditions
            $collection = Collection::find($collectionHandle);
            if (! $collection) {
                return $this->createErrorResponse("Collection '{$collectionHandle}' not found during entry operation")->toArray();
            }

            $workingEntry = null;

            if ($operation === 'create') {
                // Create new entry
                $workingEntry = Entry::make()
                    ->collection($collectionHandle)
                    ->slug($slug)
                    ->data($finalData);

                // Set site
                if ($site) {
                    $workingEntry->locale($site);
                }

                // Set date for dated collections
                if ($parsedDate) {
                    $workingEntry->date($parsedDate);
                }

                // Set author
                if ($authorUser) {
                    $workingEntry->set('author', $authorUser->id());
                }

                // Handle publication status and working copy
                if ($createWorkingCopy) {
                    $workingEntry->published(false);
                    $workingEntry->saveQuietly();

                    // Create working copy if entry supports revisions
                    if (method_exists($workingEntry, 'makeWorkingCopy') && is_object($workingEntry)) {
                        $workingCopy = $workingEntry->makeWorkingCopy();
                        if ($workingCopy) {
                            $workingCopy->save();
                            $workingEntry = $workingCopy;
                        }
                    }
                } else {
                    $workingEntry->published($isPublished);
                    $workingEntry->save();
                }

            } else {
                // Update existing entry
                $workingEntry = $existingEntry;

                // Create working copy or revision if requested
                if ($createWorkingCopy && method_exists($existingEntry, 'makeWorkingCopy') && is_object($existingEntry)) {
                    $workingCopy = $existingEntry->makeWorkingCopy();
                    if ($workingCopy) {
                        $workingEntry = $workingCopy;
                    }
                }

                // Apply updates
                $workingEntry->data($finalData);

                if ($slug !== $workingEntry->slug()) {
                    $workingEntry->slug($slug);
                }

                if ($parsedDate && (! $workingEntry->date() || ! $parsedDate->equalTo($workingEntry->date()))) {
                    $workingEntry->date($parsedDate);
                }

                if ($authorUser && $authorUser->id() !== $workingEntry->get('author')) {
                    $workingEntry->set('author', $authorUser->id());
                }

                if ($isPublished !== $workingEntry->published()) {
                    $workingEntry->published($isPublished);
                }

                $workingEntry->save();
            }

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'operation' => $operation,
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
                'metadata' => [
                    'created_new' => $operation === 'create',
                    'updated_existing' => $operation === 'update',
                    'merged_data' => $mergeData && $operation === 'update',
                    'created_working_copy' => $createWorkingCopy,
                    'original_entry_id' => $existingEntry?->id(),
                    'lookup_criteria' => $lookupCriteria,
                ],
                'validation' => [
                    'blueprint_validated' => $validateBlueprint,
                    'errors' => $validationErrors,
                    'blueprint' => $blueprint?->handle(),
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not create/update entry: ' . $e->getMessage(), [
                'operation' => $operation,
                'exception_type' => get_class($e),
                'trace_summary' => array_slice(explode("\n", $e->getTraceAsString()), 0, 3),
            ])->toArray();
        }
    }
}
