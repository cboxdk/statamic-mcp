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

#[Title('Create Statamic Entry')]
class CreateEntryTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.create';
    }

    protected function getToolDescription(): string
    {
        return 'Create a new entry in a collection';
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
            ->raw('seo', [
                'type' => 'object',
                'description' => 'SEO metadata (title, description, etc.)',
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
            ->description('Preview changes without creating')
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
        $data = $arguments['data'] ?? [];
        $published = $arguments['published'] ?? null;
        $status = $arguments['status'] ?? null;
        $site = $arguments['site'] ?? null;
        $date = $arguments['date'] ?? null;
        $author = $arguments['author'] ?? null;
        $validateBlueprint = $arguments['validate_blueprint'] ?? true;
        $createWorkingCopy = $arguments['create_working_copy'] ?? false;
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

        // Check for existing entry with same slug (with cache refresh for accuracy)
        \Statamic\Facades\Stache::refresh();
        $existingEntry = Entry::query()->where('collection', $collectionHandle)->where('slug', $slug)->first();
        if ($existingEntry) {
            return $this->createErrorResponse("Entry with slug '{$slug}' already exists in collection '{$collectionHandle}'")->toArray();
        }

        // Merge title into data
        $data = array_merge($data, ['title' => $title]);

        // Handle SEO data
        if (! empty($seo)) {
            $data = array_merge($data, array_filter([
                'seo_title' => $seo['title'] ?? null,
                'seo_description' => $seo['description'] ?? null,
                'seo_keywords' => $seo['keywords'] ?? null,
                'canonical_url' => $seo['canonical_url'] ?? null,
                'seo_noindex' => $seo['no_index'] ?? null,
                'seo_nofollow' => $seo['no_follow'] ?? null,
            ], fn ($value) => $value !== null));
        }

        // Validate against blueprint if requested
        $validationErrors = [];
        if ($validateBlueprint) {
            $blueprint = $collection->entryBlueprint();
            if ($blueprint) {
                foreach ($blueprint->fields()->all() as $fieldHandle => $field) {
                    if ($field->isRequired() && ! isset($data[$fieldHandle])) {
                        $validationErrors[] = "Required field '{$fieldHandle}' is missing";
                    }
                }

                // Basic type validation
                foreach ($data as $fieldHandle => $value) {
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
            $entryStatus = $createWorkingCopy ? 'draft' : 'published';
            $isPublished = ! $createWorkingCopy;
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_create' => [
                    'collection' => $collectionHandle,
                    'title' => $title,
                    'slug' => $slug,
                    'published' => $isPublished,
                    'status' => $entryStatus,
                    'site' => $site ?? Site::default()->handle(),
                    'date' => $parsedDate?->toISOString(),
                    'author' => $authorUser?->id(),
                    'create_working_copy' => $createWorkingCopy,
                    'data' => $data,
                    'validation_passed' => $validationErrors === [],
                ],
            ];
        }

        try {
            // Create entry
            $entry = Entry::make()
                ->collection($collectionHandle)
                ->slug($slug)
                ->data($data);

            // Set site
            if ($site) {
                $entry->locale($site);
            }

            // Set date for dated collections
            if ($parsedDate) {
                $entry->date($parsedDate);
            }

            // Set author
            if ($authorUser) {
                $entry->set('author', $authorUser->id());
            }

            // Handle publication status and working copy
            if ($createWorkingCopy) {
                $entry->published(false);
                // Save as working copy without publishing
                $entry->saveQuietly();

                // Create working copy if entry supports revisions
                if (method_exists($entry, 'makeWorkingCopy') && is_object($entry)) {
                    $workingCopy = $entry->makeWorkingCopy();
                    if ($workingCopy) {
                        $workingCopy->save();
                        $entry = $workingCopy;
                    }
                }
            } else {
                $entry->published($isPublished);
                $entry->save();
            }

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'title' => $entry->get('title'),
                    'slug' => $entry->slug(),
                    'url' => $entry->url(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
                    'collection' => $entry->collection()->handle(),
                    'site' => $entry->locale(),
                    'date' => $entry->date()?->toISOString(),
                    'author' => $entry->get('author'),
                    'is_working_copy' => method_exists($entry, 'isWorkingCopy') && is_object($entry) && $entry->isWorkingCopy(),
                    'blueprint' => $entry->blueprint()?->handle(),
                    'data' => $entry->data()->all(),
                    'seo' => array_filter([
                        'title' => $entry->get('seo_title'),
                        'description' => $entry->get('seo_description'),
                        'keywords' => $entry->get('seo_keywords'),
                        'canonical_url' => $entry->get('canonical_url'),
                        'no_index' => $entry->get('seo_noindex'),
                        'no_follow' => $entry->get('seo_nofollow'),
                    ], fn ($value) => $value !== null),
                ],
                'validation' => [
                    'blueprint_validated' => $validateBlueprint,
                    'errors' => $validationErrors,
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not create entry: ' . $e->getMessage(), [
                'exception_type' => get_class($e),
                'trace_summary' => array_slice(explode("\n", $e->getTraceAsString()), 0, 3),
            ])->toArray();
        }
    }
}
