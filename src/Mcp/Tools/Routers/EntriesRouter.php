<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HandlesRevisions;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\NormalizesDateFields;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\SanitizesFieldData;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Validator as FieldsValidator;
use Statamic\Rules\UniqueEntryValue;
use Statamic\Support\Str;

#[Name('statamic-entries')]
#[Title('Statamic Entries')]
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
                    . 'update (collection, id, data; optional: revision_message, merge_sets), '
                    . 'localize (collection, id, site — creates the entry\'s localization in that site; optional: data, revision_message), '
                    . 'delete (collection, id), '
                    . 'publish (collection, id; optional: revision_message), '
                    . 'unpublish (collection, id; optional: revision_message), '
                    . 'list_revisions (collection, id), '
                    . 'get_revision (collection, id, revision_id), '
                    . 'restore_revision (collection, id, revision_id), '
                    . 'publish_working_copy (collection, id; optional: revision_message)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete', 'localize', 'publish', 'unpublish', 'list_revisions', 'get_revision', 'restore_revision', 'publish_working_copy'])
                ->required(),

            'collection' => JsonSchema::string()
                ->description('Collection handle in snake_case. Required for all actions. Example: "blog", "products"')
                ->required(),

            'id' => JsonSchema::string()
                ->description('Entry UUID. Required for get, update, delete, publish, unpublish, list_revisions, get_revision, restore_revision, publish_working_copy actions'),

            'site' => JsonSchema::string()
                ->description(
                    'Site handle for multi-site setups. Defaults to the default site. Example: "default", "en". '
                    . 'For the localize action this is the target site the localization is created in.'
                ),

            'data' => JsonSchema::object()
                ->description(
                    'Entry field values. Structure must match the collection blueprint including nested types '
                    . '(bard, replicator, grid). Use statamic-blueprints action "get" with the collection\'s '
                    . 'blueprint handle to see required fields, types, and nesting before sending data.'
                ),

            'slug' => JsonSchema::string()
                ->description('Entry slug for the create action. Defaults to a slug generated from the title. For update, pass "slug" inside data instead'),

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

            'merge_sets' => JsonSchema::boolean()
                ->description(
                    'Update action only. When true, a replicator field in "data" is merged into the stored '
                    . 'array by item id instead of replacing it: items whose id already exists are replaced in '
                    . 'place, new ids are appended, and stored items you did not send are left untouched. Use it '
                    . 'to change one section of a page builder without resending the whole array. Every item sent '
                    . 'must carry an "id". Removing or reordering items still requires sending the full array with '
                    . 'merge_sets off. Applies to top-level replicator fields only — not bard, whose nodes are not '
                    . 'all addressable by id.'
                ),

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
            'localize' => $this->localizeEntry($arguments),
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
            'localize' => ["create {$collection} entries"],
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

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
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

            // Set slug from arguments (or data, for callers that send it as a
            // field value) or generate from title. Slug is an entry property,
            // not a data key, so it never stays in $data.
            $requestedSlug = $arguments['slug'] ?? $data['slug'] ?? null;
            unset($data['slug']);

            if (! empty($requestedSlug)) {
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

                    // Resolve blueprint-level rule placeholders — Statamic's
                    // default slug field carries
                    // `new UniqueEntryValue({collection}, {id}, {site})`,
                    // which needs {collection} and {site} filled in ({id}
                    // resolves to null: nothing to exclude on create).
                    (new FieldsValidator)
                        ->fields($fields)
                        ->withContext([
                            'entry' => $entry,
                            'collection' => $collection,
                            'site' => $site,
                        ])
                        ->withReplacements([
                            'collection' => $collection->handle(),
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
     * Merge replicator items into the entry's stored array, addressed by the
     * `id` every item already carries: an existing id is replaced in place, a
     * new one appended, and unsent items left alone.
     *
     * Top-level replicators only, and replace-or-append only. Removing and
     * reordering stay with a full-array write, where the intent is
     * unambiguous. Bard is excluded because most of its nodes carry no id.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     *
     * @throws FieldFormatException
     */
    private function mergeReplicatorSets(Blueprint $blueprint, EntryContract $entry, array $data): array
    {
        /** @var SupportCollection<string, Field> $fields */
        $fields = $blueprint->fields()->all();

        foreach ($data as $handle => $incoming) {
            $field = $fields->get($handle);

            if (! $field instanceof Field || $field->type() !== 'replicator') {
                continue;
            }

            if (! is_array($incoming)) {
                throw new FieldFormatException("Field [{$handle}] must be an array of replicator items to merge.");
            }

            $stored = $entry->get($handle);
            $stored = is_array($stored) ? array_values($stored) : [];

            $positions = [];
            foreach ($stored as $index => $item) {
                if (is_array($item) && is_string($item['id'] ?? null)) {
                    $positions[$item['id']] = $index;
                }
            }

            $merged = $stored;
            $seen = [];

            foreach (array_values($incoming) as $index => $item) {
                if (! is_array($item)) {
                    throw new FieldFormatException("Field [{$handle}.{$index}] must be a replicator item object when merge_sets is on.");
                }

                $id = $item['id'] ?? null;

                if (! is_string($id) || $id === '') {
                    throw new FieldFormatException(
                        "Field [{$handle}.{$index}] needs an \"id\" when merge_sets is on — that is how the item to replace is found. "
                        . 'Read the entry to get the ids, or turn merge_sets off to replace the whole array.'
                    );
                }

                if (isset($seen[$id])) {
                    throw new FieldFormatException("Field [{$handle}] sends id \"{$id}\" more than once; ids must be unique within the array.");
                }

                $seen[$id] = true;

                if (isset($positions[$id])) {
                    $merged[$positions[$id]] = $item;

                    continue;
                }

                $merged[] = $item;
            }

            $data[$handle] = array_values($merged);
        }

        return $data;
    }

    /**
     * Create an entry's localization in another site.
     *
     * Uses Statamic's makeLocalization() rather than an assembled entry, so
     * the origin is set — untranslated fields keep falling back — and a
     * structured collection places it in the target site's tree.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function localizeEntry(array $arguments): array
    {
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : '';
        $site = $this->resolveSiteHandle($arguments);
        /** @var array<string, mixed> $data */
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

        try {
            $entry = Entry::find($id);

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
            }

            $collection = $entry->collection();

            if (! in_array($site, $collection->sites()->all(), true)) {
                return $this->createErrorResponse(
                    "Collection [{$collection->handle()}] is not available in site [{$site}]. Available: "
                    . $collection->sites()->join(', ') . '.'
                )->toArray();
            }

            if ($entry->site()->handle() === $site) {
                return $this->createErrorResponse(
                    "Entry [{$id}] already originates in site [{$site}]. Use the update action instead."
                )->toArray();
            }

            if ($entry->in($site) !== null) {
                return $this->createErrorResponse(
                    "Entry [{$id}] already has a localization in site [{$site}]. Use the update action with site [{$site}] to edit it."
                )->toArray();
            }

            $localization = $entry->makeLocalization($site);

            $blueprint = $localization->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot localize entry: Blueprint not found. A blueprint is required for data validation.')->toArray();
            }

            // An empty localization inherits everything from the origin, which
            // is the normal starting point for a translator.
            if ($data !== []) {
                if (array_key_exists('slug', $data)) {
                    $requestedSlug = is_string($data['slug']) ? $data['slug'] : '';
                    unset($data['slug']);

                    if ($requestedSlug !== '') {
                        $localization->slug($requestedSlug);
                    }
                }

                try {
                    $data = $this->sanitizeIncomingFieldData($blueprint, $data);
                    $data = $this->normalizeDateFields($blueprint, $data);

                    // Slug is an entry property, not a data key, so it is absent
                    // from the payload. Statamic's default blueprint marks it
                    // required, so validation has to see the localization's own
                    // value or every localize fails (cf. #39). The id replacement
                    // excludes this entry from UniqueEntryValue, which the origin
                    // would otherwise trip.
                    $dataWithSlug = $data;
                    $localizationSlug = $localization->slug();
                    if (is_string($localizationSlug) && $localizationSlug !== '') {
                        $dataWithSlug['slug'] = $localizationSlug;
                    }

                    $fields = $blueprint->fields()->addValues($dataWithSlug);

                    (new FieldsValidator)
                        ->fields($fields)
                        ->withContext([
                            'entry' => $localization,
                            'collection' => $collection,
                            'site' => $site,
                        ])
                        ->withReplacements([
                            'collection' => $collection->handle(),
                            'id' => $localization->id(),
                            'site' => $site,
                        ])
                        ->validate();

                    // Store only what the caller actually sent. addValues()
                    // populates every field in the blueprint, so taking all of
                    // values() would write an explicit null for each field the
                    // translator left alone — which both defeats the fallback
                    // to the origin and poisons later updates, since update
                    // validates the stored data merged with the incoming and
                    // those nulls fail rules the field would otherwise skip.
                    $processed = $fields->process()->values()->except(['slug', 'date'])->all();

                    $localization->data(array_intersect_key($processed, $data));
                } catch (ValidationException $e) {
                    return $this->formatValidationError($e);
                } catch (\Throwable $e) {
                    return $this->createErrorResponse('Failed to process localization data: ' . $e->getMessage())->toArray();
                }
            }

            if ($this->entryRevisionsEnabled($localization)) {
                $localization->store([
                    'message' => is_string($arguments['revision_message'] ?? null) ? $arguments['revision_message'] : null,
                ]);
            } else {
                $localization->save();
            }

            $this->clearStatamicCaches(['stache', 'static']);

            $response = [
                'entry' => [
                    'id' => $localization->id(),
                    'slug' => $localization->slug(),
                    'collection' => $localization->collectionHandle(),
                    'site' => $localization->site()->handle(),
                    'origin_site' => $entry->site()->handle(),
                    'published' => $localization->published(),
                    'url' => $localization->url(),
                    'title' => $localization->get('title'),
                    'data' => $localization->data()->all(),
                ],
                'localized' => true,
            ];

            if ($this->entryRevisionsEnabled($localization)) {
                $response['revision_status'] = $this->getRevisionStatusMeta($localization);
            }

            return $response;
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to localize entry: {$e->getMessage()}")->toArray();
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

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
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

            // Handle slug separately *before* blueprint validation. Slug is
            // an entry property, not a data key, so a requested change is
            // applied to the entry object and removed from $data. This
            // dedicated check passes the entry's id as the $except argument,
            // so it gives a precise error without rejecting the entry's own
            // slug as "already taken" (issue #27). The blueprint validation
            // below re-validates the applied slug with resolved rule
            // placeholders.
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
                if (($arguments['merge_sets'] ?? false) === true) {
                    try {
                        $data = $this->mergeReplicatorSets($blueprint, $entry, $data);
                    } catch (FieldFormatException $e) {
                        return $this->createErrorResponse($e->getMessage())->toArray();
                    }
                }

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
                /** @var array<string, mixed> $mergedData */
                $mergedData = array_merge($entry->data()->all(), $data);
                $mergedData = $this->sanitizeStoredFieldDataForValidation($blueprint, $mergedData);

                // Slug and date are entry properties, not data keys, so the
                // merged payload never contains them on its own. Statamic's
                // default blueprint declares slug as `required`, so validation
                // must see the entry's effective values or every update fails
                // with "The Slug field is required" (issue #39). Safe to
                // re-validate the slug: withReplacements() below resolves
                // `UniqueEntryValue({collection}, {id}, {site})` with this
                // entry's id, excluding it from the uniqueness check — the
                // exact false positive #27 was about.
                $entryPropertyValues = [];
                $entrySlug = $entry->slug();
                if (is_string($entrySlug) && $entrySlug !== '') {
                    $entryPropertyValues['slug'] = $entrySlug;
                }
                $entryDate = $entry->date();
                if (! array_key_exists('date', $data) && $entry->collection()->dated() && $entryDate !== null) {
                    $entryPropertyValues['date'] = $entryDate->format('Y-m-d\TH:i:s.v\Z');
                }
                $mergedData = array_merge($mergedData, $entryPropertyValues);

                $validationContext = [
                    'entry' => $entry,
                    'collection' => $entry->collection(),
                    'site' => $site,
                ];

                $ruleReplacements = [
                    'id' => $entry->id(),
                    'collection' => $entry->collectionHandle(),
                    'site' => $site,
                ];

                try {
                    (new FieldsValidator)
                        ->fields($blueprint->fields()->addValues($mergedData))
                        ->withContext($validationContext)
                        ->withReplacements($ruleReplacements)
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
                            ->fields($blueprint->fields()->addValues(array_merge($data, $entryPropertyValues)))
                            ->withContext($validationContext)
                            ->withReplacements($ruleReplacements)
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

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
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

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
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

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
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
