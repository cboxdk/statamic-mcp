<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Contracts\CollectionTreeEditor;
use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Cboxdk\StatamicMcp\Mcp\Support\ConditionalFieldsValidator;
use Cboxdk\StatamicMcp\Mcp\Support\SeoProFieldData;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ChecksResourceVersions;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HandlesRevisions;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ParsesDateValues;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\SanitizesFieldData;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Support\McpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Rules\UniqueEntryValue;
use Statamic\Support\Str;

#[Name('statamic-entries')]
#[Title('Statamic Entries')]
#[Description('Statamic collection entries: pages, posts and other content, with drafts, publishing, localizations and revisions. Field values follow the collection blueprint\'s _format_spec.')]
class EntriesRouter extends BaseRouter
{
    use ChecksResourceVersions;
    use HandlesRevisions;

    /** The values of Entry::status(). */
    private const ENTRY_STATUSES = ['published', 'scheduled', 'expired', 'draft'];

    /**
     * Entry properties a list may filter by besides the blueprint's fields.
     * Collection and site are arguments of their own.
     */
    private const FILTER_PROPERTIES = ['id', 'slug', 'title', 'published', 'status', 'date', 'uri', 'parent', 'origin'];

    use ParsesDateValues;
    use SanitizesFieldData;

    protected function filterOutputFields(array $result): array
    {
        $result = parent::filterOutputFields($result);

        // Parent is metadata beside entry.data, which the base filter handles.
        foreach (['entries', 'structures'] as $domain) {
            if (in_array('parent', app(ResourcePolicy::class)->getDeniedFields($domain), true)
                && isset($result['entry']) && is_array($result['entry'])) {
                unset($result['entry']['parent']);
            }
        }

        return $result;
    }

    protected function filterInputFields(array $arguments): array
    {
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        if (array_key_exists('parent', $data)) {
            if (! in_array($arguments['action'] ?? '', ['create', 'update'], true)) {
                throw ValidationException::withMessages(['parent' => 'Parent is supported only on create and update.']);
            }
            foreach (['entries', 'structures'] as $domain) {
                if (in_array('parent', app(ResourcePolicy::class)->getDeniedFields($domain), true)) {
                    throw ValidationException::withMessages(['parent' => 'Parent changes are denied by resource policy.']);
                }
            }
        }
        if (array_key_exists('parent', $arguments)) {
            throw ValidationException::withMessages(['parent' => 'Send the parent inside data.parent.']);
        }

        return parent::filterInputFields($arguments);
    }

    protected function getDomain(): string
    {
        return 'entries';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [

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
                    'Parent: data.parent is an entry id in this collection/site, or null for the top level. Create and update persist it in the collection tree; requires reorder permission plus entries:write and structures:admin. Parent cannot be combined with other changes on a published entry using revisions. Entry field values. Structure must match the collection blueprint including nested types '
                    . '(bard, replicator, grid). Use statamic-blueprints-read get with the collection\'s '
                    . 'blueprint handle to see required fields, types, and nesting before sending data.'
                ),

            'slug' => JsonSchema::string()
                ->description('Entry slug for the create action. Defaults to a slug generated from the title. For update, pass "slug" inside data instead'),

            'filters' => JsonSchema::object()
                ->description('Exact-match filters for list, as key-value pairs with scalar values. Keys are field handles from the collection\'s blueprints, or one of id, slug, title, published, date, uri, parent, origin, or "status" (published, scheduled, expired, draft). Any other key is refused with the list of valid keys. Example: {"status": "scheduled"}'),

            'include_unpublished' => JsonSchema::boolean()
                ->description('Include draft/unpublished entries in list results. Default: false, which still lists scheduled and expired entries because they are published; read each entry\'s status, or filter by it'),

            'limit' => JsonSchema::integer()
                ->description('Maximum results to return (default: 100, max: 500)'),

            'offset' => JsonSchema::integer()
                ->description('Number of results to skip for pagination. Use with limit for paging'),

            'expected_version' => JsonSchema::string()
                ->description('Optimistic concurrency for update, delete, publish, unpublish, restore_revision and publish_working_copy: the `version` from your last get/create/update. If the stored entry changed since (through MCP, the Control Panel or anything else), the write is refused with a version conflict and nothing is saved; get the entry again and redo the change. Omit to write unconditionally.'),

            'revision_state' => JsonSchema::string()
                ->description('Which state of the entry get returns: "published" (default) the live data, "working_copy" the unpublished working copy, "latest" the working copy when there is one, else the published data')
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

        $siteError = $this->validateSiteHandle($arguments);
        if ($siteError) {
            return $siteError;
        }

        if (in_array($action, self::VERSIONED_ACTIONS, true) && isset($arguments['expected_version'])) {
            return $this->withExpectedVersion($arguments, fn (): array => $this->dispatchEntryAction($action, $arguments));
        }

        return $this->withVersion($action, $this->dispatchEntryAction($action, $arguments));
    }

    /** Write actions that act on one existing entry and can check its version first. */
    private const VERSIONED_ACTIONS = ['update', 'delete', 'publish', 'unpublish', 'restore_revision', 'publish_working_copy'];

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function dispatchEntryAction(string $action, array $arguments): array
    {
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
     * The entry's stored state as an opaque token: its file data, slug and
     * date, plus its working copy when revisions are on. Any write to the
     * entry, from MCP or not, changes it. Tree position is not part of it.
     */
    private function entryVersion(EntryContract $entry): string
    {
        $workingCopy = null;
        if ($this->entryRevisionsEnabled($entry) && $entry->hasWorkingCopy()) {
            $workingCopy = $entry->workingCopy()?->attributes();
        }

        return self::resourceVersion([
            'data' => $entry->fileData(),
            'slug' => $entry->slug(),
            'date' => $entry->hasDate() ? $entry->date()?->toIso8601String() : null,
            'working_copy' => $workingCopy,
        ]);
    }

    protected function currentResourceVersion(array $arguments): ?string
    {
        $entry = is_string($arguments['id'] ?? null) ? $this->resolveEntryInCollection($arguments['id'], $arguments) : null;

        return $entry === null ? null : $this->entryVersion($entry);
    }

    /**
     * Report the entry's version after a successful single-entry action, so a
     * caller can pass it back as expected_version on its next write.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function withVersion(string $action, array $result): array
    {
        if ($action === 'delete' || ($result['success'] ?? true) === false
            || ! is_array($result['entry'] ?? null) || ! is_string($result['entry']['id'] ?? null)) {
            return $result;
        }

        // Read what was stored, not the instance just saved: a database
        // repository keeps that in Blink without the columns it filled on save,
        // so its version never matched the next call's read.
        Blink::flush();
        $entry = Entry::find($result['entry']['id']);
        if ($entry !== null) {
            $result['entry']['version'] = $this->entryVersion($entry);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(): array<string, mixed>  $write
     *
     * @return array<string, mixed>
     */
    private function withExpectedVersion(array $arguments, callable $write): array
    {
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : '';
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        return $this->writeIfVersionMatches(
            $arguments['expected_version'],
            'entry',
            $id,
            function () use ($arguments, $id): string|array {
                $entry = $this->resolveEntryInCollection($id, $arguments);
                $site = $this->resolveSiteHandle($arguments);
                if ($entry !== null && isset($arguments['site']) && $entry->site()->handle() !== $site) {
                    $localized = $entry->in($site);
                    $entry = $localized instanceof EntryContract ? $localized : null;
                }

                return $entry === null
                    ? $this->createErrorResponse('Entry not found: ' . $id)->toArray()
                    : $this->entryVersion($entry);
            },
            fn (): array => $this->withVersion($action, $write()),
        );
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
            'update', 'restore_revision' => ["edit {$collection} entries"],
            'delete' => ["delete {$collection} entries"],
            'localize' => ["create {$collection} entries"],
            'publish', 'unpublish', 'publish_working_copy' => ["publish {$collection} entries"],
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
            if ($policyError = $this->authorizeEntrySite($site)) {
                return $policyError;
            }
            $query = Entry::query()
                ->where('collection', $collection)
                ->where('site', $site);

            $filters = is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [];
            if ($filterError = $this->validateListFilters(
                $filters,
                Collection::find($collection)?->entryBlueprints() ?? [],
                self::FILTER_PROPERTIES,
            )) {
                return $filterError;
            }

            // An entry's status is computed from published, its date and the collection's
            // date behavior, so it has to go through whereStatus(); where('status') throws.
            // Asking for a status is explicit, so include_unpublished doesn't narrow it.
            $status = $filters['status'] ?? null;
            unset($filters['status']);

            if ($status !== null) {
                if (! is_string($status) || ! in_array($status, self::ENTRY_STATUSES, true)) {
                    return $this->createErrorResponse(
                        'Invalid status filter. Use one of: ' . implode(', ', self::ENTRY_STATUSES)
                    )->toArray();
                }

                $query->whereStatus($status);
            } elseif (! $includeUnpublished) {
                $query->where('published', true);
            }

            foreach ($filters as $field => $value) {
                $query->where((string) $field, $value);
            }

            $total = $query->count();
            $entries = $query->offset($offset)->limit($limit)->get();

            $data = $entries->map(function ($entry) {
                return [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'title' => $entry->get('title', $entry->slug()),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
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
        $version = is_string($arguments['revision_state'] ?? null) ? $arguments['revision_state'] : 'published';

        try {
            $entry = $this->resolveEntryInCollection($id, $arguments);

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
            }

            // Get entry for specific site if needed
            if ($entry->site()->handle() !== $site) {
                $localizedEntry = $entry->in($site);
                if (! $localizedEntry) {
                    return $this->createErrorResponse("Entry not available in site: {$site}")->toArray();
                }
                $entry = $localizedEntry;
            }

            if ($policyError = $this->authorizeResolvedEntry(__FUNCTION__, $entry)) {
                return $policyError;
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
                    'parent' => app(CollectionTreeEditor::class)->parent($entryVersion),
                    'site' => $entryVersion->site()->handle(),
                    'slug' => $entryVersion->slug(),
                    'published' => $entryVersion->published(),
                    'status' => $entryVersion->status(),
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
        $parentRequested = array_key_exists('parent', $data);
        $requestedParent = $data['parent'] ?? null;
        unset($data['parent']);

        try {
            if ($policyError = $this->authorizeEntrySite($site)) {
                return $policyError;
            }
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

            // Pin the blueprint to this collection before resolving it.
            //
            // Entry::blueprint() memoizes into Blink under
            // "entry-{$this->id()}-blueprint", and an unsaved entry has no id,
            // so every new entry shares the key "entry--blueprint". A web
            // request creates at most one entry and never notices; this server
            // is long-lived, so the second create in a different collection was
            // handed the first collection's blueprint, and every field the two
            // did not share was dropped from a write that reported success (#52).
            //
            // Assigning the handle goes through Statamic's fluent setter, which
            // forgets that key. The getter below then runs the normal resolution
            // path, so EntryBlueprintFound listeners still fire and addons that
            // inject fields keep working.
            $collectionBlueprint = $collection->entryBlueprint();

            if ($collectionBlueprint !== null) {
                $entry->blueprint($collectionBlueprint->handle());
            }

            // Get blueprint and validate field data
            $blueprint = $entry->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot create entry: Blueprint not found for this collection. A blueprint is required for data validation.')->toArray();
            }

            if (! empty($data)) {
                // Strip entry-level metadata and coerce values to expected types
                $data = $this->sanitizeIncomingFieldData($blueprint, $data);

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
                    (new ConditionalFieldsValidator)
                        ->fields($fields)
                        ->withConditionValues($dataWithSlug)
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
                    // and store only what the caller sent. addValues()
                    // populates every field in the blueprint, so taking all
                    // of values() wrote an explicit null for each field left
                    // alone — a value, not an absence, which later updates
                    // merged into their validation payload and failed on.
                    /** @var array<string, mixed> $processed */
                    $processed = $fields->process()->values()->except(['slug', 'date'])->all();
                    $processed = SeoProFieldData::restoreEmptyValues($blueprint, $processed, $data);

                    $entry->data($this->withPassthroughKeys(array_intersect_key($processed, $data), $data, $blueprint));
                } catch (ValidationException $e) {
                    return $this->formatValidationError($e);
                } catch (\Throwable $e) {
                    return $this->createErrorResponse('Failed to process entry data: ' . $e->getMessage())->toArray();
                }
            } else {
                $entry->data($data);
            }

            if ($parentRequested) {
                app(CollectionTreeEditor::class)->validate($entry, $requestedParent);
            }

            if (! $this->entryRevisionsEnabled($entry) && $entry->published()
                && ($policyError = $this->authorizeResolvedEntry('publishEntry', $entry))) {
                if (is_array($arguments['data'] ?? null) && ! empty($arguments['data']['published'])) {
                    return $policyError;
                }
                $entry->published(false);
            }

            // Revision-aware create: use store() which saves as unpublished
            // and creates an initial revision (matches CP behavior)
            if ($this->entryRevisionsEnabled($entry)) {
                $this->storeInitialRevision($entry, $arguments);
            } else {
                $this->persistStatamic(fn () => $this->touchedBy($entry)->save());
            }

            if ($parentRequested) {
                try {
                    $this->persistStatamic(fn () => app(CollectionTreeEditor::class)->move($entry, $requestedParent));
                } catch (\Throwable $e) {
                    // This call created the entry; do not leave a partial creation behind.
                    $this->persistStatamic(fn () => $entry->delete());
                    throw $e;
                }
            }

            // Clear relevant caches
            $this->clearCachesAfterWrite(['stache', 'static']);

            $response = [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'collection' => $entry->collectionHandle(),
                    'parent' => app(CollectionTreeEditor::class)->parent($entry),
                    'site' => $entry->site()->handle(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
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
     * Entry data keys Statamic reads back itself, outside the blueprint.
     *
     * `Entry::template()` and `Entry::layout()` both fall back to
     * `$this->get(...)`, so a per-entry template is stored as ordinary entry
     * data — but a blueprint need not declare a field for it, and most do not.
     * The write pipeline runs values through
     * `Fields::addValues()->process()->values()`, which only knows blueprint
     * handles, so without this these keys are dropped and the write reports
     * success having changed nothing.
     *
     * Deliberately a closed list: merging back every unrecognised key would
     * reintroduce exactly the silent junk writes `reject_unknown_fields`
     * exists to stop. `parent` is not in it — `Entry::parent()` derives from
     * the structure tree rather than entry data, so storing it would be inert.
     *
     * @var array<int, string>
     */
    private const PASSTHROUGH_DATA_KEYS = ['template', 'layout'];

    /**
     * Carry the passthrough keys from the incoming payload into processed data.
     *
     * A blueprint that declares the field has already handled it through the
     * normal pipeline, so it is left alone.
     *
     * @param  array<string, mixed>  $processed
     * @param  array<string, mixed>  $incoming
     *
     * @return array<string, mixed>
     *
     * @throws FieldFormatException
     */
    private function withPassthroughKeys(array $processed, array $incoming, Blueprint $blueprint): array
    {
        foreach (self::PASSTHROUGH_DATA_KEYS as $key) {
            if (! array_key_exists($key, $incoming) || $blueprint->hasField($key)) {
                continue;
            }

            $value = $incoming[$key];

            if ($value !== null && ! is_string($value)) {
                throw new FieldFormatException(
                    "Field [{$key}] must be a string or null, received " . get_debug_type($value) . '.'
                );
            }

            $processed[$key] = $value;
        }

        return $processed;
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
            $entry = $this->resolveEntryInCollection($id, $arguments);

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
            }

            if ($policyError = $this->authorizeResolvedEntry(__FUNCTION__, $entry)) {
                return $policyError;
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

            if ($policyError = $this->authorizeEntrySite($site)) {
                return $policyError;
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

                    (new ConditionalFieldsValidator)
                        ->fields($fields)
                        ->withConditionValues(array_merge($localization->values()->all(), $dataWithSlug))
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
                    $processed = SeoProFieldData::restoreEmptyValues($blueprint, $processed, $data);

                    $localization->data(
                        $this->withPassthroughKeys(array_intersect_key($processed, $data), $data, $blueprint)
                    );
                } catch (ValidationException $e) {
                    return $this->formatValidationError($e);
                } catch (\Throwable $e) {
                    return $this->createErrorResponse('Failed to process localization data: ' . $e->getMessage())->toArray();
                }
            }

            if (! $this->entryRevisionsEnabled($localization) && $localization->published()
                && ($policyError = $this->authorizeResolvedEntry('publishEntry', $localization))) {
                if (is_array($arguments['data'] ?? null) && ! empty($arguments['data']['published'])) {
                    return $policyError;
                }
                $localization->published(false);
            }
            if ($this->entryRevisionsEnabled($localization)) {
                $this->storeInitialRevision($localization, $arguments);
            } else {
                $this->persistStatamic(fn () => $this->touchedBy($localization)->save());
            }

            $this->clearCachesAfterWrite(['stache', 'static']);

            $response = [
                'entry' => [
                    'id' => $localization->id(),
                    'slug' => $localization->slug(),
                    'collection' => $localization->collectionHandle(),
                    'site' => $localization->site()->handle(),
                    'origin_site' => $entry->site()->handle(),
                    'published' => $localization->published(),
                    'status' => $localization->status(),
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
        // Ignoring it reported success for a rename that never happened.
        if (array_key_exists('slug', $arguments)) {
            return $this->createErrorResponse('To change the slug on update, pass it inside data: {"data": {"slug": "new-slug"}}. Nothing was saved.')->toArray();
        }
        $parentRequested = array_key_exists('parent', $data);
        $requestedParent = $data['parent'] ?? null;
        unset($data['parent']);

        try {
            $entry = $this->resolveEntryInCollection($id, $arguments);

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
            }

            // Get entry for specific site
            if ($entry->site()->handle() !== $site) {
                $localizedEntry = $entry->in($site);
                if ($localizedEntry instanceof EntryContract) {
                    $entry = $localizedEntry;
                } else {
                    return $this->createErrorResponse("Entry not available in site: {$site}")->toArray();
                }
            }

            if ($policyError = $this->authorizeResolvedEntry(__FUNCTION__, $entry)) {
                return $policyError;
            }

            // Resource-dependent profile checks follow authentication and the
            // concrete site/author policy, so denials cannot reveal draft state.
            if (app(InstallationProfile::class)->name() === 'editor' && $entry->published()) {
                return $this->createErrorResponse('Editor updates require an unpublished entry.')->toArray();
            }

            $entry = clone $entry;

            if ($parentRequested && $this->entryRevisionsEnabled($entry) && $entry->published()) {
                if ($data !== []) {
                    return $this->createErrorResponse('Move a published entry separately from content edits when revisions are enabled.')->toArray();
                }
                $parent = $this->persistStatamic(fn () => app(CollectionTreeEditor::class)->move($entry, $requestedParent));

                return ['entry' => ['id' => $entry->id(), 'parent' => $parent], 'updated' => true];
            }

            // Extract published — it's a first-class entry property
            if (array_key_exists('published', $data)) {
                if ($this->entryRevisionsEnabled($entry)) {
                    return $this->createErrorResponse(
                        'The published flag cannot be changed via update when revisions are enabled. Use the publish or unpublish action instead.'
                    )->toArray();
                }

                if ((bool) $data['published'] !== $entry->published()
                    && ($policyError = $this->authorizeResolvedEntry('publishEntry', $entry))) {
                    return $policyError;
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
                $incomingSeoData = $data;
                $data = SeoProFieldData::merge($blueprint, $data, $entry->data()->all());
                $data = $this->sanitizeIncomingFieldData($blueprint, $data);

                // Validate incoming data against blueprint.
                // We merge with existing data so required-field rules pass for
                // unchanged fields — minus stored nulls, which would otherwise
                // fail rules on fields the caller never sent (see
                // mergeStoredDataForValidation). If the full-merge validation
                // throws a TypeError (common with third-party fieldtypes like
                // SEO Pro whose preProcessValidatable can't handle stored data
                // formats), we fall back to validating only the incoming fields.
                $mergedData = $this->mergeStoredDataForValidation($entry->data()->all(), $data);
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
                    'parent' => app(CollectionTreeEditor::class)->parent($entry),
                    'site' => $site,
                ];

                try {
                    (new ConditionalFieldsValidator)
                        ->fields($blueprint->fields()->addValues($mergedData))
                        ->withConditionValues($mergedData)
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
                        (new ConditionalFieldsValidator)
                            ->fields($blueprint->fields()->addValues(array_merge($data, $entryPropertyValues)))
                            ->withConditionValues($mergedData)
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

                $processedData = SeoProFieldData::restoreEmptyValues($blueprint, $processedData, $data);
                $processedData = SeoProFieldData::preserveOmitted($blueprint, $processedData, $incomingSeoData, $entry->data()->all());
                $data = $this->withPassthroughKeys($processedData, $data, $blueprint);
            }

            if ($parentRequested) {
                // Routes can depend on fields such as title, not only slug.
                // Validate the final values without changing the stored entry.
                $proposedEntry = clone $entry;
                $proposedEntry->merge($data);
                app(CollectionTreeEditor::class)->validate($proposedEntry, $requestedParent);
            }

            // Revision-aware save: if revisions enabled and entry is published,
            // save to working copy instead of directly modifying the published entry
            if ($this->entryRevisionsEnabled($entry) && $entry->published()) {
                return $this->saveAsWorkingCopy($entry, $data, is_string($arguments['revision_message'] ?? null) ? $arguments['revision_message'] : null);
            }

            $this->persistStatamic(fn () => $this->touchedBy($entry->merge($data))->save());

            if ($parentRequested) {
                $this->persistStatamic(fn () => app(CollectionTreeEditor::class)->move($entry, $requestedParent));
            }

            // Clear relevant caches
            $this->clearCachesAfterWrite(['stache', 'static']);

            $response = [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'collection' => $entry->collectionHandle(),
                    'parent' => app(CollectionTreeEditor::class)->parent($entry),
                    'site' => $entry->site()->handle(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
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
            $entry = $this->resolveEntryInCollection($id, $arguments);

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
            }

            if ($policyError = $this->authorizeResolvedEntry(__FUNCTION__, $entry)) {
                return $policyError;
            }

            $entryData = [
                'id' => $entry->id(),
                'slug' => $entry->slug(),
                'collection' => $entry->collectionHandle(),
                'parent' => app(CollectionTreeEditor::class)->parent($entry),
                'site' => $entry->site()->handle(),
            ];

            $this->persistStatamic(fn () => $entry->delete());

            // Clear relevant caches
            $this->clearCachesAfterWrite(['stache', 'static']);

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
            $entry = $this->resolveEntryInCollection($id, $arguments);

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
            }

            if ($policyError = $this->authorizeResolvedEntry(__FUNCTION__, $entry)) {
                return $policyError;
            }

            /** @var \Statamic\Entries\Entry $entry */
            // Use Statamic's built-in publish() which delegates to publishWorkingCopy()
            // when revisions are enabled, or sets published(true)->save() otherwise
            $entry = clone $entry;
            $publishedEntry = $this->persistStatamic(fn () => $entry->publish($this->revisionOptions($arguments)));

            if ($publishedEntry instanceof \Statamic\Entries\Entry) {
                $entry = $publishedEntry;
            }

            // Clear relevant caches
            $this->clearCachesAfterWrite(['stache', 'static']);

            $response = [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
                    'date' => $entry->date()?->toISOString(),
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
            $entry = $this->resolveEntryInCollection($id, $arguments);

            if ($entry === null) {
                return $this->createErrorResponse('Entry not found: ' . $id)->toArray();
            }

            if ($policyError = $this->authorizeResolvedEntry(__FUNCTION__, $entry)) {
                return $policyError;
            }

            /** @var \Statamic\Entries\Entry $entry */
            // Use Statamic's built-in unpublish() which delegates to unpublishWorkingCopy()
            // when revisions are enabled, or sets published(false)->save() otherwise
            $entry = clone $entry;
            if ($this->entryRevisionsEnabled($entry)) {
                $unpublishedEntry = $this->persistStatamic(fn () => $entry->unpublish($this->revisionOptions($arguments)));
            } else {
                // Native unpublish() discards save()'s false return value.
                $this->persistStatamic(fn () => $entry->published(false)->save());
                $unpublishedEntry = $entry;
            }

            if ($unpublishedEntry instanceof \Statamic\Entries\Entry) {
                $entry = $unpublishedEntry;
            }

            // Clear relevant caches
            $this->clearCachesAfterWrite(['stache', 'static']);

            $response = [
                'entry' => [
                    'id' => $entry->id(),
                    'slug' => $entry->slug(),
                    'published' => $entry->published(),
                    'status' => $entry->status(),
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

    protected function actions(): array
    {
        return [
            RouterAction::read('list', 'List a collection\'s entries', needs: ['collection'], optional: ['site', 'filters', 'include_unpublished', 'limit', 'offset']),
            RouterAction::read('get', 'Get one entry with its data and version', needs: ['collection', 'id'], optional: ['site', 'revision_state']),
            RouterAction::edit('create', 'Create an entry; data holds field values in the shapes the collection\'s blueprint gives them', needs: ['collection', 'data'], optional: ['site', 'slug', 'revision_message']),
            RouterAction::edit('update', 'Update an entry; a published entry with revisions enabled gets a working copy', needs: ['collection', 'id', 'data'], optional: ['site', 'expected_version', 'merge_sets', 'revision_message']),
            RouterAction::edit('localize', 'Create an entry\'s localization in another site; untranslated fields keep falling back to the origin', needs: ['collection', 'id', 'site'], optional: ['data', 'revision_message']),
            RouterAction::delete('delete', 'Delete an entry', needs: ['collection', 'id'], optional: ['site', 'expected_version']),
            RouterAction::publish('publish', 'Publish an entry, promoting its working copy. In a collection whose future dates are private, a future-dated entry becomes status "scheduled" and stays offline until its date; read status, not published, to know what visitors see', needs: ['collection', 'id'], optional: ['site', 'expected_version', 'revision_message']),
            RouterAction::publish('unpublish', 'Unpublish an entry', needs: ['collection', 'id'], optional: ['site', 'expected_version', 'revision_message']),
            RouterAction::read('list_revisions', 'List an entry\'s revisions (revisions must be enabled)', needs: ['collection', 'id'], optional: ['site']),
            RouterAction::read('get_revision', 'Get one revision\'s data snapshot', needs: ['collection', 'id', 'revision_id'], optional: ['site']),
            RouterAction::edit('restore_revision', 'Restore an entry from a revision; a published entry gets it as a working copy', needs: ['collection', 'id', 'revision_id'], optional: ['site', 'expected_version']),
            RouterAction::publish('publish_working_copy', 'Publish the entry\'s current working copy', needs: ['collection', 'id'], optional: ['site', 'expected_version', 'revision_message']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getTypes(): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    private function authorizeEntrySite(string $handle): ?array
    {
        if (! McpContext::isWebRequest()) {
            return null;
        }
        $site = Site::get($handle);
        $user = User::current();
        if ($site === null || $user === null || ! $user->can('view', $site)) {
            return $this->createErrorResponse('Permission denied: Cannot access this site')->toArray();
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function authorizeResolvedEntry(string $fn, EntryContract $entry): ?array
    {
        if (! McpContext::isWebRequest()) {
            return null;
        }

        $ability = match ($fn) {
            'getEntry', 'listRevisionsAction', 'getRevisionAction' => 'view',
            'deleteEntry' => 'delete',
            'publishEntry', 'unpublishEntry', 'publishWorkingCopyAction' => 'publish',
            default => 'edit',
        };

        /** @var \Statamic\Contracts\Auth\User|null $user */
        $user = User::current();

        if ($user === null || ! $user->can('view', $entry->site()) || ! $user->can($ability, $entry)) {
            return $this->createErrorResponse("Permission denied: your account may not {$ability} this entry (author or site restriction)")->toArray();
        }

        // Publishing through the `published` flag on create or update is
        // still publishing: an edit-only token may not do it.
        $token = request()->attributes->get('mcp_token');
        if ($ability === 'publish' && $token instanceof McpTokenData
            && ! app(TokenService::class)->hasScope($token, TokenScope::EntriesPublish)) {
            return $this->createErrorResponse('Token missing required scope: ' . TokenScope::EntriesPublish->value)->toArray();
        }

        return null;
    }

    /**
     * Resolve an entry by id, but only within the collection the caller named.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function resolveEntryInCollection(string $id, array $arguments): ?EntryContract
    {
        $entry = Entry::find($id);

        if ($entry === null) {
            return null;
        }

        $collection = is_string($arguments['collection'] ?? null) ? $arguments['collection'] : '';

        return $entry->collectionHandle() === $collection ? $entry : null;
    }
}
