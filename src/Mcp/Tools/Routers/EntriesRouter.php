<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\NormalizesDateFields;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\SanitizesFieldData;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Validator as FieldsValidator;
use Statamic\Rules\UniqueEntryValue;
use Statamic\Support\Str;

#[Name('statamic-entries')]
#[Description('Manage Statamic collection entries. Use statamic-blueprints get first to understand field structure before create/update. Actions: list, get, create, update, delete, publish, unpublish.')]
class EntriesRouter extends BaseRouter
{
    use ClearsCaches;
    use NormalizesDateFields;
    use SanitizesFieldData;

    protected function getDomain(): string
    {
        return 'entries';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'list (collection; optional: limit, offset, filters, include_unpublished), '
                    . 'get (collection, id), '
                    . 'create (collection, data — use statamic-blueprints get to see field structure first), '
                    . 'update (collection, id, data), '
                    . 'delete (collection, id), '
                    . 'publish (collection, id), '
                    . 'unpublish (collection, id)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete', 'publish', 'unpublish'])
                ->required(),

            'collection' => JsonSchema::string()
                ->description('Collection handle in snake_case. Required for all actions. Example: "blog", "products"')
                ->required(),

            'id' => JsonSchema::string()
                ->description('Entry UUID. Required for get, update, delete, publish, unpublish actions'),

            'site' => JsonSchema::string()
                ->description('Site handle for multi-site setups. Defaults to the default site. Example: "default", "en"'),

            'data' => JsonSchema::object()
                ->description(
                    'Entry field values. Structure must match the collection blueprint including nested types '
                    . '(bard, replicator, grid). Use statamic-blueprints action "get" with the collection\'s '
                    . 'blueprint handle to see required fields, types, and nesting before sending data.'
                ),

            'filters' => JsonSchema::object()
                ->description('Filter conditions as key-value pairs. Keys are field handles from the blueprint. Example: {"status": "published"}'),

            'include_unpublished' => JsonSchema::boolean()
                ->description('Include draft/unpublished entries in list results. Default: false'),

            'limit' => JsonSchema::integer()
                ->description('Maximum results to return (default: 100, max: 500)'),

            'offset' => JsonSchema::integer()
                ->description('Number of results to skip for pagination. Use with limit for paging'),
        ]);
    }

    /**
     * Route actions to appropriate handlers with security checks.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        // Collection is required for all entry operations
        if (empty($arguments['collection'])) {
            return $this->createErrorResponse('Collection handle is required for entry operations')->toArray();
        }

        $collectionHandle = is_string($arguments['collection']) ? $arguments['collection'] : '';
        if (! Collection::find($collectionHandle)) {
            return $this->createErrorResponse("Collection not found: {$collectionHandle}")->toArray();
        }

        // Validate action-specific requirements
        $validationError = $this->validateActionRequirements($action, $arguments);
        if ($validationError) {
            return $validationError;
        }

        // Execute action
        return match ($action) {
            'list' => $this->listEntries($arguments),
            'get' => $this->getEntry($arguments),
            'create' => $this->createEntry($arguments),
            'update' => $this->updateEntry($arguments),
            'delete' => $this->deleteEntry($arguments),
            'publish' => $this->publishEntry($arguments),
            'unpublish' => $this->unpublishEntry($arguments),
            default => $this->createErrorResponse("Action {$action} not supported for entries")->toArray(),
        };
    }

    /**
     * Validate action-specific requirements.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    private function validateActionRequirements(string $action, array $arguments): ?array
    {
        // ID required for specific actions
        if (in_array($action, ['get', 'update', 'delete', 'publish', 'unpublish'])) {
            if (empty($arguments['id'])) {
                return $this->createErrorResponse("Entry ID is required for {$action} action")->toArray();
            }
        }

        // Data required for create actions
        if ($action === 'create' && empty($arguments['data'])) {
            return $this->createErrorResponse('Data is required for create action')->toArray();
        }

        // Site validation
        $siteError = $this->validateSiteHandle($arguments);
        if ($siteError) {
            return $siteError;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        $collection = is_string($arguments['collection'] ?? '') ? ($arguments['collection'] ?? '') : '';

        return match ($action) {
            'list', 'get' => ["view {$collection} entries"],
            'create' => ["create {$collection} entries"],
            'update' => ["edit {$collection} entries"],
            'delete' => ["delete {$collection} entries"],
            'publish', 'unpublish' => ["publish {$collection} entries"],
            default => [],
        };
    }

    /**
     * List entries with filtering and pagination.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listEntries(array $arguments): array
    {
        $collection = is_string($arguments['collection']) ? $arguments['collection'] : '';
        $site = $this->resolveSiteHandle($arguments);
        $includeUnpublished = $this->getBooleanArgument($arguments, 'include_unpublished', false);
        $pagination = $this->getPaginationArgs($arguments, 50, 1000);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];

        try {
            $query = Entry::query()
                ->where('collection', $collection)
                ->where('site', $site);

            if (! $includeUnpublished) {
                $query->where('published', true);
            }

            // Apply filters if provided (only allow string field names)
            if (! empty($arguments['filters']) && is_array($arguments['filters'])) {
                foreach ($arguments['filters'] as $field => $value) {
                    if (! is_string($field) || $field === '') {
                        continue;
                    }
                    $query->where($field, $value);
                }
            }

            $total = $query->count();
            $entries = $query->offset($offset)->limit($limit)->get();

            $data = $entries->map(function ($entry) {
                return [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'title' => $entry->get('title', $entry->slug()),
                    'published' => $entry->published(),
                    'date' => $entry->date()?->toISOString(),
                    'last_modified' => $entry->lastModified()?->toISOString(),
                    'url' => $entry->url(),
                ];
            })->all();

            return [
                'entries' => $data,
                'pagination' => $this->buildPaginationMeta($total, $limit, $offset),
                'collection' => $collection,
                'site' => $site,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list entries: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Get a specific entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getEntry(array $arguments): array
    {
        $id = is_string($arguments['id']) ? $arguments['id'] : '';
        $site = $this->resolveSiteHandle($arguments);

        try {
            $entry = Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            // Get entry for specific site if needed
            if ($entry->site()->handle() !== $site) {
                $localizedEntry = $entry->in($site);
                if ($localizedEntry) {
                    $entry = $localizedEntry;
                }
            }

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'collection' => $entry->collectionHandle(),
                    'site' => $entry->site()->handle(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'date' => $entry->date()?->toISOString(),
                    'last_modified' => $entry->lastModified()?->toISOString(),
                    'url' => $entry->url(),
                    'data' => $entry->data()->all(),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Create a new entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createEntry(array $arguments): array
    {
        $collectionHandle = is_string($arguments['collection']) ? $arguments['collection'] : '';
        /** @var \Statamic\Contracts\Entries\Collection $collection */
        $collection = Collection::find($collectionHandle);
        $site = $this->resolveSiteHandle($arguments);
        /** @var array<string, mixed> $data */
        $data = is_array($arguments['data'] ?? []) ? ($arguments['data'] ?? []) : [];

        try {
            $entry = Entry::make()
                ->collection($collection)
                ->locale($site);

            // Set slug from arguments or generate from title
            if (! empty($arguments['slug'])) {
                $requestedSlug = $arguments['slug'];
                $requestedSlug = is_string($requestedSlug) ? $requestedSlug : '';

                // Use Statamic's built-in validation for unique slugs
                $slugValidator = Validator::make(['slug' => $requestedSlug], [
                    'slug' => [
                        'required',
                        'string',
                        new UniqueEntryValue($collection->handle(), null, $site),
                    ],
                ]);

                if ($slugValidator->fails()) {
                    $errors = $slugValidator->errors()->get('slug');
                    /** @var array<string> $flatErrors */
                    $flatErrors = array_map(fn ($error) => is_string($error) ? $error : implode(', ', (array) $error), $errors);

                    return $this->createErrorResponse('Slug validation failed: ' . implode(', ', $flatErrors))->toArray();
                }

                $entry->slug($requestedSlug);
            } elseif (! $entry->slug() && isset($data['title'])) {
                $titleValue = $data['title'];
                $entry->slug(Str::slug(is_string($titleValue) ? $titleValue : ''));
            }

            // Extract published — it's a first-class entry property, not a data field
            if (array_key_exists('published', $data)) {
                $entry->published((bool) $data['published']);
                unset($data['published']);
            }

            // For dated collections, parse the date and set it on the entry.
            // Keep a normalized copy in data so the FieldsValidator sees the required field.
            if (array_key_exists('date', $data) && $collection->dated()) {
                try {
                    $parsedDate = $this->parseDateValue($data['date']);
                    $entry->date($parsedDate);
                    $data['date'] = $parsedDate->format('Y-m-d\TH:i:s.v\Z');
                } catch (\Throwable $e) {
                    return $this->createErrorResponse("Invalid date value: {$e->getMessage()}")->toArray();
                }
            }

            // Get blueprint and validate field data
            $blueprint = $entry->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot create entry: Blueprint not found for this collection. A blueprint is required for data validation.')->toArray();
            }

            if (! empty($data)) {
                // Strip entry-level metadata and coerce values to expected types
                $data = $this->sanitizeIncomingFieldData($blueprint, $data);

                // Normalize date field values to the format Statamic expects
                $data = $this->normalizeDateFields($blueprint, $data);

                // Add slug to data for validation if it's set
                $dataWithSlug = $data;
                if ($entry->slug()) {
                    $dataWithSlug['slug'] = $entry->slug();
                }

                // Use Statamic's Fields Validator for blueprint-based validation,
                // then process through fieldtypes for storage format (matches CP pipeline).
                try {
                    $fields = $blueprint->fields()->addValues($dataWithSlug);

                    (new FieldsValidator)
                        ->fields($fields)
                        ->withContext([
                            'entry' => $entry,
                            'collection' => $collection,
                            'site' => $site,
                        ])
                        ->validate();

                    // Process through fieldtypes (Terms strips prefixes,
                    // Bard normalizes nodes, Relationship wraps values, etc.)
                    $entry->data(
                        $fields->process()->values()->except(['slug', 'date'])->all()
                    );
                } catch (ValidationException $e) {
                    return $this->formatValidationError($e);
                } catch (\Throwable $e) {
                    return $this->createErrorResponse('Failed to process entry data: ' . $e->getMessage())->toArray();
                }
            } else {
                $entry->data($data);
            }

            $entry->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'collection' => $entry->collectionHandle(),
                    'site' => $entry->site()->handle(),
                    'published' => $entry->published(),
                    'url' => $entry->url(),
                    'title' => $entry->get('title'),
                    'data' => $entry->data()->all(),
                ],
                'created' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Update an existing entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateEntry(array $arguments): array
    {
        $id = is_string($arguments['id']) ? $arguments['id'] : '';
        $site = $this->resolveSiteHandle($arguments);
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

        try {
            $entry = Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            // Get entry for specific site
            if ($entry->site()->handle() !== $site) {
                $localizedEntry = $entry->in($site);
                if ($localizedEntry) {
                    $entry = $localizedEntry;
                } else {
                    return $this->createErrorResponse("Entry not available in site: {$site}")->toArray();
                }
            }

            // Extract published — it's a first-class entry property
            if (array_key_exists('published', $data)) {
                $entry->published((bool) $data['published']);
                unset($data['published']);
            }

            // For dated collections, parse the date and set it on the entry.
            // Keep a normalized copy in data so the FieldsValidator sees the required field.
            if (array_key_exists('date', $data) && $entry->collection()->dated()) {
                try {
                    $parsedDate = $this->parseDateValue($data['date']);
                    $entry->date($parsedDate);
                    $data['date'] = $parsedDate->format('Y-m-d\TH:i:s.v\Z');
                } catch (\Throwable $e) {
                    return $this->createErrorResponse("Invalid date value: {$e->getMessage()}")->toArray();
                }
            }

            // Validate data against blueprint before saving
            $blueprint = $entry->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot update entry: Blueprint not found. A blueprint is required for data validation.')->toArray();
            }

            if (! empty($data)) {
                // Strip entry-level metadata and coerce values to expected types
                $data = $this->sanitizeIncomingFieldData($blueprint, $data);

                // Normalize date field values to the format Statamic expects
                $data = $this->normalizeDateFields($blueprint, $data);

                // Merge new data with existing for full blueprint validation
                // Include slug since blueprint validates it as required
                /** @var array<string, mixed> $mergedData */
                $mergedData = array_merge($entry->data()->all(), $data);
                $mergedData['slug'] = $entry->slug();

                // Sanitize the merged data — existing entry values may be in
                // legacy formats (e.g. plain strings for Bard fields) that
                // crash Statamic's preProcessValidatable() pipeline.
                $mergedData = $this->sanitizeStoredFieldDataForValidation($blueprint, $mergedData);

                try {
                    (new FieldsValidator)
                        ->fields($blueprint->fields()->addValues($mergedData))
                        ->withContext([
                            'entry' => $entry,
                            'collection' => $entry->collection(),
                            'site' => $site,
                        ])
                        ->validate();
                } catch (ValidationException $e) {
                    return $this->formatValidationError($e);
                } catch (\Throwable $e) {
                    return $this->createErrorResponse('Failed to process entry data: ' . $e->getMessage())->toArray();
                }

                // Remove date — it's already set on the entry object
                unset($data['date']);

                // Process incoming data through fieldtypes for storage format
                $incomingKeys = array_keys($data);
                /** @var array<string, mixed> $processedData */
                $processedData = $blueprint->fields()->addValues($data)
                    ->process()->values()
                    ->only($incomingKeys)
                    ->all();

                $data = $processedData;
            }

            $entry->merge($data)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'collection' => $entry->collectionHandle(),
                    'site' => $entry->site()->handle(),
                    'published' => $entry->published(),
                    'last_modified' => $entry->lastModified()?->toISOString(),
                    'url' => $entry->url(),
                ],
                'updated' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Delete an entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteEntry(array $arguments): array
    {
        $id = is_string($arguments['id']) ? $arguments['id'] : '';

        try {
            $entry = Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            $entryData = [
                'id' => $entry->id(),
                'slug' => $entry->slug(),
                'collection' => $entry->collectionHandle(),
                'site' => $entry->site()->handle(),
            ];

            $entry->delete();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => $entryData,
                'deleted' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Publish an entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function publishEntry(array $arguments): array
    {
        $id = is_string($arguments['id']) ? $arguments['id'] : '';

        try {
            $entry = Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            $entry->published(true)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'url' => $entry->url(),
                ],
                'published' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to publish entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Unpublish an entry.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function unpublishEntry(array $arguments): array
    {
        $id = is_string($arguments['id']) ? $arguments['id'] : '';

        try {
            $entry = Entry::find($id);

            $notFound = $this->requireResource($entry, 'Entry', $id);
            if ($notFound) {
                return $notFound;
            }

            $entry->published(false)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                ],
                'unpublished' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to unpublish entry: {$e->getMessage()}")->toArray();
        }
    }

    public function getActions(): array
    {
        return [
            'list' => 'List entries with filtering and pagination',
            'get' => 'Get specific entry with full data',
            'create' => 'Create new entry',
            'update' => 'Update existing entry',
            'delete' => 'Delete entry',
            'publish' => 'Publish entry',
            'unpublish' => 'Unpublish entry',
        ];
    }

    public function getTypes(): array
    {
        return [
            'entry' => 'Collection-based content items',
        ];
    }
}
