<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HandlesRevisions;
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
#[Description('Manage Statamic collection entries. Use statamic-blueprints get first to understand field structure before create/update. Actions: list, get, create, update, delete, publish, unpublish, list_revisions, get_revision, restore_revision, publish_working_copy.')]
class EntriesRouter extends BaseRouter
{
    use ClearsCaches;
    use HandlesRevisions;
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
                    . 'get (collection, id; optional: version), '
                    . 'create (collection, data — use statamic-blueprints get to see field structure first), '
                    . 'update (collection, id, data; optional: revision_message), '
                    . 'delete (collection, id), '
                    . 'publish (collection, id; optional: revision_message), '
                    . 'unpublish (collection, id; optional: revision_message), '
                    . 'list_revisions (collection, id), '
                    . 'get_revision (collection, id, revision_id), '
                    . 'restore_revision (collection, id, revision_id), '
                    . 'publish_working_copy (collection, id; optional: revision_message)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete', 'publish', 'unpublish', 'list_revisions', 'get_revision', 'restore_revision', 'publish_working_copy'])
                ->required(),

            'collection' => JsonSchema::string()
                ->description('Collection handle in snake_case. Required for all actions. Example: "blog", "products"')
                ->required(),

            'id' => JsonSchema::string()
                ->description('Entry UUID. Required for get, update, delete, publish, unpublish, list_revisions, get_revision, restore_revision, publish_working_copy actions'),

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

            'version' => JsonSchema::string()
                ->description('Which version of the entry to return for get action. "published" (default): live published data, "working_copy": current working copy data, "latest": working copy if exists else published')
                ->enum(['published', 'working_copy', 'latest']),

            'revision_message' => JsonSchema::string()
                ->description('Optional message to attach to a revision (for update, publish, unpublish, publish_working_copy actions)'),

            'revision_id' => JsonSchema::string()
                ->description('Timestamp-based revision ID (required for get_revision and restore_revision actions). Use list_revisions to discover available IDs'),
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
            'list_revisions' => $this->listRevisionsAction($arguments),
            'get_revision' => $this->getRevisionAction($arguments),
            'restore_revision' => $this->restoreRevisionAction($arguments),
            'publish_working_copy' => $this->publishWorkingCopyAction($arguments),
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
        if (in_array($action, ['get', 'update', 'delete', 'publish', 'unpublish', 'list_revisions', 'get_revision', 'restore_revision', 'publish_working_copy'])) {
            if (empty($arguments['id'])) {
                return $this->createErrorResponse("Entry ID is required for {$action} action")->toArray();
            }
        }

        // Revision ID required for get_revision and restore_revision
        if (in_array($action, ['get_revision', 'restore_revision'])) {
            if (empty($arguments['revision_id'])) {
                return $this->createErrorResponse("Revision ID is required for {$action} action")->toArray();
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
            'list', 'get', 'list_revisions', 'get_revision' => ["view {$collection} entries"],
            'create' => ["create {$collection} entries"],
            'update' => ["edit {$collection} entries"],
            'delete' => ["delete {$collection} entries"],
            'publish', 'unpublish', 'restore_revision', 'publish_working_copy' => ["publish {$collection} entries"],
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
        $version = is_string($arguments['version'] ?? null) ? $arguments['version'] : 'published';

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

            $entryVersion = $entry;

            if ($version === 'working_copy' || $version === 'latest') {
                if ($this->entryRevisionsEnabled($entry) && $entry->hasWorkingCopy()) {
                    $workingCopy = $entry->workingCopy();
                    if ($workingCopy !== null) {
                        /** @var \Statamic\Entries\Entry $entryVersion */
                        $entryVersion = $entry->makeFromRevision($workingCopy);
                    }
                } elseif ($version === 'working_copy') {
                    return $this->createErrorResponse('No working copy exists for this entry')->toArray();
                }
            }

            $response = [
                'entry' => [
                    'id' => $entryVersion->id(),
                    'collection' => $entryVersion->collectionHandle(),
                    'site' => $entryVersion->site()->handle(),
                    'slug' => $entryVersion->slug(),
                    'published' => $entryVersion->published(),
                    'date' => $entryVersion->date()?->toISOString(),
                    'last_modified' => $entryVersion->lastModified()?->toISOString(),
                    'url' => $entryVersion->url(),
                    'data' => $entryVersion->data()->all(),
                ],
            ];

            // Include revision status metadata when revisions are enabled
            if ($this->entryRevisionsEnabled($entry)) {
                $response['revision_status'] = $this->getRevisionStatusMeta($entry);
            }

            return $response;

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

            // Revision-aware create: use store() which saves as unpublished
            // and creates an initial revision (matches CP behavior)
            if ($this->entryRevisionsEnabled($entry)) {
                $entry->store([
                    'message' => is_string($arguments['revision_message'] ?? null) ? $arguments['revision_message'] : null,
                ]);
            } else {
                $entry->save();
            }

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            $response = [
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

            if ($this->entryRevisionsEnabled($entry)) {
                $response['revision_status'] = $this->getRevisionStatusMeta($entry);
            }

            return $response;

        } catch (\Exception $e) {
            // FieldFormatException + ValidationException carry curated messages with
            // field paths — they reach the client through this envelope.
            // Other Throwables (e.g. TypeError from third-party fieldtype pipelines)
            // fall through to BaseStatamicTool::execute() which logs them centrally
            // and applies environment-aware sanitization before the message goes
            // to the client.
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
                if ($this->entryRevisionsEnabled($entry)) {
                    return $this->createErrorResponse(
                        'The published flag cannot be changed via update when revisions are enabled. Use the publish or unpublish action instead.'
                    )->toArray();
                }

                $entry->published((bool) $data['published']);
                unset($data['published']);
            }

            // Handle slug separately *before* blueprint validation so the
            // FieldsValidator never sees the slug column. Letting the merged
            // payload include the slug forces UniqueEntryValue to compare the
            // current slug against the entry being updated and reject it as
            // "already taken" — even when the caller never asked to change
            // the slug. Mirrors the createEntry() flow but passes the
            // entry's id as the $except argument so the rule excludes the
            // current entry from the uniqueness check.
            if (array_key_exists('slug', $data)) {
                $newSlug = is_string($data['slug']) ? $data['slug'] : '';
                $slugValidator = Validator::make(['slug' => $newSlug], [
                    'slug' => [
                        'required',
                        'string',
                        new UniqueEntryValue($entry->collectionHandle(), $entry->id(), $site),
                    ],
                ]);

                if ($slugValidator->fails()) {
                    $errors = $slugValidator->errors()->get('slug');
                    /** @var array<string> $flatErrors */
                    $flatErrors = array_map(fn ($error) => is_string($error) ? $error : implode(', ', (array) $error), $errors);

                    return $this->createErrorResponse('Slug validation failed: ' . implode(', ', $flatErrors))->toArray();
                }

                $entry->slug($newSlug);
                unset($data['slug']);
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

                // Validate incoming data against blueprint.
                // We merge with existing data so required-field rules pass for
                // unchanged fields, but if the full-merge validation throws a
                // TypeError (common with third-party fieldtypes like SEO Pro
                // whose preProcessValidatable can't handle stored data formats),
                // we fall back to validating only the incoming fields.
                // NOTE: do NOT inject slug into mergedData. Any slug change
                // was already applied to the entry object above; the
                // FieldsValidator does not need the slug column and including
                // it forces UniqueEntryValue to reject the current entry's
                // own slug. See the slug-handling block earlier in this
                // method (issue #27).
                /** @var array<string, mixed> $mergedData */
                $mergedData = array_merge($entry->data()->all(), $data);
                $mergedData = $this->sanitizeStoredFieldDataForValidation($blueprint, $mergedData);

                $validationContext = [
                    'entry' => $entry,
                    'collection' => $entry->collection(),
                    'site' => $site,
                ];

                try {
                    (new FieldsValidator)
                        ->fields($blueprint->fields()->addValues($mergedData))
                        ->withContext($validationContext)
                        ->validate();
                } catch (ValidationException $e) {
                    return $this->formatValidationError($e);
                } catch (\TypeError $e) {
                    // A TypeError in the validation pipeline typically means a
                    // third-party fieldtype's preProcessValidatable or extraRules
                    // cannot handle the stored data format.  Fall back to
                    // validating only the incoming fields — the existing data was
                    // already valid when it was saved.
                    try {
                        (new FieldsValidator)
                            ->fields($blueprint->fields()->addValues($data))
                            ->withContext($validationContext)
                            ->validate();
                    } catch (ValidationException $inner) {
                        return $this->formatValidationError($inner);
                    } catch (\Throwable $inner) {
                        return $this->createErrorResponse('Failed to process entry data: ' . $inner->getMessage())->toArray();
                    }
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

            // Revision-aware save: if revisions enabled and entry is published,
            // save to working copy instead of directly modifying the published entry
            if ($this->entryRevisionsEnabled($entry) && $entry->published()) {
                return $this->saveAsWorkingCopy($entry, $data, is_string($arguments['revision_message'] ?? null) ? $arguments['revision_message'] : null);
            }

            $entry->merge($data)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            $response = [
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

            if ($this->entryRevisionsEnabled($entry)) {
                $response['revision_status'] = $this->getRevisionStatusMeta($entry);
            }

            return $response;

        } catch (\Exception $e) {
            // FieldFormatException + ValidationException carry curated messages with
            // field paths — they reach the client through this envelope.
            // Other Throwables (e.g. TypeError from third-party fieldtype pipelines)
            // fall through to BaseStatamicTool::execute() which logs them centrally
            // and applies environment-aware sanitization before the message goes
            // to the client.
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

            /** @var \Statamic\Entries\Entry $entry */
            // Use Statamic's built-in publish() which delegates to publishWorkingCopy()
            // when revisions are enabled, or sets published(true)->save() otherwise
            $options = array_filter([
                'message' => is_string($arguments['revision_message'] ?? null) ? $arguments['revision_message'] : null,
            ]);

            $publishedEntry = $entry->publish($options);

            if ($publishedEntry instanceof \Statamic\Entries\Entry) {
                $entry = $publishedEntry;
            }

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            $response = [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'url' => $entry->url(),
                ],
                'published' => true,
            ];

            if ($this->entryRevisionsEnabled($entry)) {
                $response['revision_status'] = $this->getRevisionStatusMeta($entry);
            }

            return $response;

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

            /** @var \Statamic\Entries\Entry $entry */
            // Use Statamic's built-in unpublish() which delegates to unpublishWorkingCopy()
            // when revisions are enabled, or sets published(false)->save() otherwise
            $options = array_filter([
                'message' => is_string($arguments['revision_message'] ?? null) ? $arguments['revision_message'] : null,
            ]);

            $unpublishedEntry = $entry->unpublish($options);

            if ($unpublishedEntry instanceof \Statamic\Entries\Entry) {
                $entry = $unpublishedEntry;
            }

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            $response = [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                ],
                'unpublished' => true,
            ];

            if ($this->entryRevisionsEnabled($entry)) {
                $response['revision_status'] = $this->getRevisionStatusMeta($entry);
            }

            return $response;

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to unpublish entry: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getActions(): array
    {
        return [
            'list' => 'List entries with filtering and pagination',
            'get' => 'Get specific entry with full data (supports version param for revision-aware reads)',
            'create' => 'Create new entry (uses store() with initial revision when revisions enabled)',
            'update' => 'Update existing entry (creates working copy when revisions enabled and entry is published)',
            'delete' => 'Delete entry',
            'publish' => 'Publish entry (promotes working copy when revisions enabled)',
            'unpublish' => 'Unpublish entry (creates revision snapshot when revisions enabled)',
            'list_revisions' => 'List revision history for an entry (requires revisions enabled)',
            'get_revision' => 'Get a specific revision by timestamp ID with full data snapshot',
            'restore_revision' => 'Restore entry from a specific revision (creates working copy for published, updates directly for unpublished)',
            'publish_working_copy' => 'Publish the current working copy (requires existing working copy)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getTypes(): array
    {
        return [
            'entry' => 'Collection-based content items',
        ];
    }
}
