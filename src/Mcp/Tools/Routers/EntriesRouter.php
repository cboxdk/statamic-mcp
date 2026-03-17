<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Validator as FieldsValidator;
use Statamic\Rules\UniqueEntryValue;
use Statamic\Sites\Sites;
use Statamic\Support\Str;

#[Name('statamic-entries')]
#[Description('Manage Statamic collection entries. Use statamic-blueprints get first to understand field structure before create/update. Actions: list, get, create, update, delete, publish, unpublish.')]
class EntriesRouter extends BaseRouter
{
    use ClearsCaches;

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

        // Check if tool is enabled for current context
        if ($this->isWebContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Entries tool is disabled for web access')->toArray();
        }

        // Validate action-specific requirements
        $validationError = $this->validateActionRequirements($action, $arguments);
        if ($validationError) {
            return $validationError;
        }

        // Apply security checks for web context
        if ($this->isWebContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
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
        if (! empty($arguments['site'])) {
            $siteHandle = is_string($arguments['site']) ? $arguments['site'] : '';
            /** @var Sites $sites */
            $sites = Site::all();
            if (! $sites->map->handle()->contains($siteHandle)) {
                return $this->createErrorResponse("Invalid site handle: {$siteHandle}")->toArray();
            }
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
        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();
        $site = is_string($arguments['site'] ?? null) ? $arguments['site'] : $defaultSite->handle();
        $includeUnpublished = $this->getBooleanArgument($arguments, 'include_unpublished', false);
        $limit = $this->getIntegerArgument($arguments, 'limit', 50, 1, 1000);
        $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

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
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
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
        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();
        $site = is_string($arguments['site'] ?? null) ? $arguments['site'] : $defaultSite->handle();

        try {
            $entry = Entry::find($id);

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
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
        /** @var \Statamic\Sites\Site $defaultSiteObj */
        $defaultSiteObj = Site::default();
        $siteDefault = $defaultSiteObj->handle();
        $siteRaw = $arguments['site'] ?? $siteDefault;
        $site = is_string($siteRaw) ? $siteRaw : $siteDefault;
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

            // Get blueprint and validate field data
            $blueprint = $entry->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot create entry: Blueprint not found for this collection. A blueprint is required for data validation.')->toArray();
            }

            if (! empty($data)) {
                // Add slug to data for validation if it's set
                $dataWithSlug = $data;
                if ($entry->slug()) {
                    $dataWithSlug['slug'] = $entry->slug();
                }

                // Use Statamic's Fields Validator for blueprint-based validation
                $fieldsValidator = (new FieldsValidator)
                    ->fields($blueprint->fields()->addValues($dataWithSlug))
                    ->withContext([
                        'entry' => $entry,
                        'collection' => $collection,
                        'site' => $site,
                    ]);

                try {
                    $validatedData = $fieldsValidator->validate();
                    // Remove slug from validated data since it's handled separately
                    unset($validatedData['slug']);
                    $entry->data($validatedData);
                } catch (ValidationException $e) {
                    $errors = [];
                    foreach ($e->errors() as $field => $fieldErrors) {
                        $errors[] = "{$field}: " . implode(', ', $fieldErrors);
                    }

                    return $this->createErrorResponse('Field validation failed: ' . implode('; ', $errors))->toArray();
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
        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();
        $site = is_string($arguments['site'] ?? null) ? $arguments['site'] : $defaultSite->handle();
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

        try {
            $entry = Entry::find($id);

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
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

            // Validate data against blueprint before saving
            $blueprint = $entry->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot update entry: Blueprint not found. A blueprint is required for data validation.')->toArray();
            }

            if (! empty($data)) {
                // Merge new data with existing for full blueprint validation
                // Include slug since blueprint validates it as required
                /** @var array<string, mixed> $mergedData */
                $mergedData = array_merge($entry->data()->all(), $data);
                $mergedData['slug'] = $entry->slug();

                $fieldsValidator = (new FieldsValidator)
                    ->fields($blueprint->fields()->addValues($mergedData))
                    ->withContext([
                        'entry' => $entry,
                        'collection' => $entry->collection(),
                        'site' => $site,
                    ]);

                try {
                    $fieldsValidator->validate();
                } catch (ValidationException $e) {
                    $errors = [];
                    foreach ($e->errors() as $field => $fieldErrors) {
                        $errors[] = "{$field}: " . implode(', ', $fieldErrors);
                    }

                    return $this->createErrorResponse('Field validation failed: ' . implode('; ', $errors))->toArray();
                }
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

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
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

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
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

            if (! $entry) {
                return $this->createErrorResponse("Entry not found: {$id}")->toArray();
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
