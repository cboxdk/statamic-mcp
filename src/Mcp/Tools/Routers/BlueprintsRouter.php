<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Support\BlueprintLayout;
use Cboxdk\StatamicMcp\Mcp\Support\FieldFormatSpec;
use Cboxdk\StatamicMcp\Mcp\Support\FieldReferences;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ChecksResourceVersions;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HandlesFieldsets;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Cboxdk\StatamicMcp\Support\McpConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Exceptions\FieldtypeNotFoundException;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint as BlueprintContract;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fields\FieldtypeRepository;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

#[Name('statamic-blueprints')]
#[Title('Statamic Blueprints')]
#[Description('Statamic blueprints: the fields of every entry, term, global set, asset and user, with each field\'s _format_spec, the exact wire format a write must send.')]
class BlueprintsRouter extends BaseRouter
{
    use ChecksResourceVersions;
    use HandlesFieldsets;

    protected function getDomain(): string
    {
        return 'blueprints';
    }

    protected function filterInputFields(array $arguments): array
    {
        Validator::make($arguments, [
            'tab' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'remove_fields' => ['sometimes', 'array'],
            'remove_fields.*' => ['string'],
            'replace_fields' => ['sometimes', 'boolean'],
        ])->validate();
        if (is_array($arguments['fields'] ?? null) && $this->isWriteAction(is_string($arguments['action'] ?? null) ? $arguments['action'] : '')) {
            FieldReferences::validate($arguments['fields']);
            $arguments['fields'] = $this->visibleDefinitions(['fields' => $arguments['fields']])['fields'];
        }

        return parent::filterInputFields($arguments);
    }

    protected function currentResourceVersion(array $arguments): ?string
    {
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';
        if (str_ends_with($action, '_fieldset')) {
            if (! FieldReferences::validHandle($handle)) {
                return null;
            }
            $record = FieldReferences::find($handle);
        } else {
            if ($this->validateBlueprintLocation($handle,
                is_string($arguments['namespace'] ?? null) ? $arguments['namespace'] : 'globals',
                is_string($arguments['collection'] ?? null) ? $arguments['collection'] : null,
                is_string($arguments['taxonomy'] ?? null) ? $arguments['taxonomy'] : null) !== null) {
                return null;
            }
            $record = $this->findBlueprint($handle,
                is_string($arguments['namespace'] ?? null) ? $arguments['namespace'] : null,
                is_string($arguments['collection'] ?? null) ? $arguments['collection'] : null,
                is_string($arguments['taxonomy'] ?? null) ? $arguments['taxonomy'] : null);
        }

        return self::resourceVersion($record?->contents() ?? ['absent' => true]);
    }

    public function toArray(): array
    {
        $tool = parent::toArray();
        if ($this->presentedFacet() === 'read') {
            $limit = McpConfig::int('security.max_response_size', 100000);
            $tool['_meta']['anthropic/maxResultSizeChars'] = $limit > 0 ? min($limit, 500000) : 500000;
        }

        return $tool;
    }

    protected function actions(): array
    {
        $where = ['namespace', 'collection', 'taxonomy'];

        return [
            RouterAction::read('list', 'List blueprints', optional: ['namespace', 'include_fields', 'include_details', 'limit', 'cursor']),
            RouterAction::read('get', 'Get a blueprint\'s fields with each field\'s _format_spec, the wire format a write expects. In the collections and taxonomies namespaces, name the collection or taxonomy; field is a dot path to one field or set', needs: ['handle'], optional: [...$where, 'field', 'fields', 'include_config', 'include_format_spec', 'max_format_depth']),
            RouterAction::admin('create', 'Create a blueprint from inline fields, field references or fieldset imports', needs: ['handle'], optional: [...$where, 'fields', 'title', 'tab']),
            RouterAction::admin('update', 'Update fields in place, retaining imports and sections; new fields go in tab. remove_fields removes explicit handles or import keys', needs: ['handle'], optional: [...$where, 'fields', 'replace_fields', 'remove_fields', 'tab', 'title']),
            RouterAction::admin('delete', 'Delete a blueprint', needs: ['handle'], optional: $where),
            RouterAction::read('scan', 'Report how blueprints and fieldtypes are used', optional: ['namespace', 'include_fields', 'include_details', 'limit', 'cursor']),
            RouterAction::admin('generate', 'Create a blueprint from field definitions you supply', needs: ['handle', 'fields'], optional: ['namespace', 'title']),
            RouterAction::read('types', 'Generate TypeScript, PHP or JSON Schema types from blueprints; output_format defaults to typescript', optional: ['namespace', 'output_format', 'limit', 'cursor']),
            RouterAction::read('validate', 'Validate a blueprint\'s structure and field definitions', needs: ['handle'], optional: $where),
            RouterAction::read('list_fieldsets', 'List reusable fieldsets', optional: ['limit', 'cursor']),
            RouterAction::read('get_fieldset', 'Read a fieldset with its stored definitions and resolved field formats', needs: ['handle']),
            RouterAction::admin('create_fieldset', 'Create a reusable fieldset from native field definitions', needs: ['handle', 'fields'], optional: ['title']),
            RouterAction::admin('update_fieldset', 'Merge fieldset definitions in place, preserving sections and imports', needs: ['handle'], optional: ['fields', 'title', 'remove_fields', 'replace_fields', 'expected_version']),
            RouterAction::admin('delete_fieldset', 'Delete an unused fieldset; imports and field references prevent deletion', needs: ['handle'], optional: ['expected_version']),
            RouterAction::read('fieldtypes', 'List installed fieldtypes with their native config field schemas. Use handle to inspect one type', optional: ['handle', 'limit', 'cursor']),
        ];
    }

    public function getTypes(): array
    {
        return [];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'handle' => JsonSchema::string()
                ->description('Blueprint identifier in snake_case. Example: "blog_post", "product"'),
            'namespace' => JsonSchema::string()
                ->description('Blueprint namespace determining storage location. Use "collections" for collection blueprints, "taxonomies" for taxonomy blueprints.')
                ->enum(['collections', 'taxonomies', 'globals', 'assets', 'users']),
            'collection' => JsonSchema::string()
                ->description('Collection handle. Required when namespace is "collections". Example: "blog"'),
            'taxonomy' => JsonSchema::string()
                ->description('Taxonomy handle. Required when namespace is "taxonomies". Example: "tags"'),
            'title' => JsonSchema::string()
                ->description('Human-readable blueprint title. Auto-generated from handle if not provided'),
            'expected_version' => JsonSchema::string()
                ->description('For update_fieldset and delete_fieldset: the version returned by get_fieldset. Refuses the write if the fieldset changed.'),
            'fields' => $this->presentedFacet() === 'read' ? parent::defineSchema($schema)['fields'] : JsonSchema::array()
                ->items(JsonSchema::object())
                ->description(
                    'Native definitions: {"handle":"title","field":{"type":"text"}}, '
                    . '{"handle":"summary","field":"shared.summary","config":{"display":"Summary"}}, '
                    . 'or {"import":"shared","prefix":"hero_","config":{"summary":{"display":"Hero summary"}}}. '
                    . 'Updates merge by handle or import+prefix. Imports stay linked to their fieldset.'
                ),
            'tab' => JsonSchema::string()
                ->description('Destination tab for new fields; existing fields stay in their sections. Omit for the first tab. A missing tab is created.'),
            'remove_fields' => JsonSchema::array()->items(JsonSchema::string())
                ->description('For update: handles to remove. Remove a whole import with import:{fieldset}|{prefix}, e.g. import:shared|hero_. Imported fields cannot be removed individually.'),
            'replace_fields' => JsonSchema::boolean()
                ->description('For update: replace all fields in tab, or all tabs when tab is omitted. Keeps tab and section metadata. Default false.'),
            'include_details' => JsonSchema::boolean()
                ->description('Include field type and configuration details in response. Default: false for list, true for get'),
            'include_fields' => JsonSchema::boolean()
                ->description('Only applies to list. Include field handle/type/display in list response. Default: true.'),
            'limit' => JsonSchema::integer()
                ->description('Maximum blueprints to return (default: 50, max: 500; types max: 200).'),
            'cursor' => JsonSchema::string()
                ->description('next_cursor from the previous page. Keep the other arguments unchanged. Omit to start again.'),
            'field' => JsonSchema::string()
                ->description(
                    'Only applies to get. Dot path narrowing the response to one field or set instead of the '
                    . 'whole blueprint, so a deep spec fits in a reply: "page_builder" for a field, '
                    . '"page_builder.ContentSection" for one replicator or bard set, '
                    . '"page_builder.ContentSection.media" to go deeper. Segments are the handles the spec '
                    . 'already reports — allowed_set_types for a set, group_fields for a group. Both '
                    . 'include_config and include_format_spec are scoped to the subtree. An unresolvable path '
                    . 'lists the valid segments at the level it failed.'
                ),

            'include_config' => JsonSchema::boolean()
                ->description('Only applies to get. Include full field config in the response. Default: true.'),
            'include_format_spec' => JsonSchema::boolean()
                ->description('Only applies to get. Include _format_spec on each field describing the exact wire format (shape, allowed types, set definitions, common mistakes, example). Highly recommended before writing entries with bard, replicator, grid, or group fields. Default: true.'),
            'max_format_depth' => JsonSchema::integer()
                ->description('Only applies to get. Recursion depth for _format_spec into nested set fields. 0 = top-level only. Default: 2.'),
            'output_format' => JsonSchema::string()
                ->description('Type generation output format. Choose based on your project\'s language')
                ->enum(['typescript', 'php', 'json-schema', 'all']),
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
        if (in_array($action, ['update_fieldset', 'delete_fieldset'], true) && array_key_exists('expected_version', $arguments)) {
            $expected = $arguments['expected_version'];
            unset($arguments['expected_version']);

            return $this->writeIfVersionMatches($expected, 'fieldset', is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '',
                fn (): string => $this->currentResourceVersion($arguments) ?? '',
                fn (): array => $this->fieldsetAction($arguments));
        }

        return match ($action) {
            'list' => $this->listBlueprints($arguments),
            'get' => $this->getBlueprint($arguments),
            'create' => $this->createBlueprint($arguments),
            'update' => $this->updateBlueprint($arguments),
            'delete' => $this->deleteBlueprint($arguments),
            'scan' => $this->scanBlueprints($arguments),
            'generate' => $this->generateBlueprint($arguments),
            'types' => $this->analyzeTypes($arguments),
            'validate' => $this->validateBlueprint($arguments),
            'list_fieldsets' => $this->listFieldsets($arguments),
            'get_fieldset', 'create_fieldset', 'update_fieldset', 'delete_fieldset' => $this->fieldsetAction($arguments),
            'fieldtypes' => $this->listFieldtypes($arguments),
            default => $this->createErrorResponse("Unknown action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listBlueprints(array $arguments): array
    {
        $namespace = $arguments['namespace'] ?? null;
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', false);
        $includeFields = $this->getBooleanArgument($arguments, 'include_fields', true);
        $blueprints = $this->collectAllBlueprints(is_string($namespace) ? $namespace : null);
        $total = $blueprints->count();
        $pagination = $this->getPaginationArgs($arguments);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];
        $data = $blueprints->sortBy(fn (BlueprintContract $blueprint): string => $blueprint->namespace() . '.' . $blueprint->handle())->values()->skip($offset)->take($limit)
            ->map(function (mixed $blueprint) use ($includeDetails, $includeFields): array {
                /** @var BlueprintContract $blueprint */
                $result = [
                    'handle' => $blueprint->handle(),
                    'title' => $blueprint->title(),
                    'namespace' => $blueprint->namespace(),
                ];

                if ($includeDetails) {
                    $result['hidden'] = $blueprint->hidden();
                    $result['order'] = $blueprint->order();
                }

                if ($includeFields) {
                    $result['fields'] = $blueprint->fields()->all()->map(function (mixed $field): array {
                        /** @var Field $field */
                        return [
                            'handle' => $field->handle(),
                            'type' => $field->type(),
                            'display' => $field->display(),
                        ];
                    })->toArray();
                }

                return $result;
            })->values();

        return [
            ...$this->buildPage($data->all(), $total, $offset, $arguments),
            'namespace' => $namespace,
        ];
    }

    /**
     * Narrow the response to one field or set.
     *
     * A page builder's spec is proportional to every set it can hold, so on a
     * real blueprint the full response runs to hundreds of kilobytes and a
     * client cannot take it at any useful depth. Almost always the caller
     * wants one component, and asking for it by path keeps both the depth and
     * the payload under control.
     *
     * @return array<string, mixed>
     */
    private function getBlueprintSubtree(BlueprintContract $blueprint, string $path, bool $includeConfig, ?FieldFormatSpec $formatSpec): array
    {
        /** @var SupportCollection<string, Field> $fields */
        $fields = $blueprint->fields()->all();
        $walked = [];
        $field = null;

        foreach (explode('.', $path) as $segment) {
            if ($field !== null) {
                $parentIsSetHolder = $field->fieldtype() instanceof Replicator;
                $next = $this->childFields($field, $segment);

                if ($next === null) {
                    return $this->unresolvableSegment($path, $segment, $walked, $this->childSegments($field));
                }

                $fields = $next;

                // What the segment named depends on the parent. On a replicator
                // or bard it is a set handle, which resolves to that set's whole
                // field collection; on a group or grid it is one inner field.
                // Reading it off the resolved collection instead would mistake a
                // set for a field whenever a set contains a field of the same
                // handle.
                $field = $parentIsSetHolder ? null : $fields->get($segment);

                $walked[] = $segment;

                continue;
            }

            if (! $fields->has($segment)) {
                return $this->unresolvableSegment($path, $segment, $walked, $fields->keys()->all());
            }

            $field = $fields->get($segment);
            $walked[] = $segment;
        }

        $data = [
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            'namespace' => $blueprint->namespace(),
            'field_path' => $path,
        ];

        if ($field instanceof Field) {
            $data['field'] = $this->describeField($field, $includeConfig, $formatSpec);
        } else {
            $data['fields'] = $fields
                ->map(fn (mixed $f): array => $this->describeField($f, $includeConfig, $formatSpec))
                ->toArray();
        }

        return ['blueprint' => $data];
    }

    /**
     * Fields reachable one segment below a field: a replicator or bard set, or
     * the inner fields of a group or grid.
     *
     * @return SupportCollection<string, Field>|null
     */
    private function childFields(Field $field, string $segment): ?SupportCollection
    {
        $fieldtype = $field->fieldtype();

        // Bard extends Replicator, so this covers both.
        if ($fieldtype instanceof Replicator) {
            if (! $fieldtype->flattenedSetsConfig()->has($segment)) {
                return null;
            }

            /** @var SupportCollection<string, Field> $setFields */
            $setFields = $fieldtype->fields($segment)->all();

            return $setFields;
        }

        if ($fieldtype instanceof Group || $fieldtype instanceof Grid) {
            /** @var SupportCollection<string, Field> $inner */
            $inner = $fieldtype->fields()->all();

            return $inner->has($segment) ? $inner : null;
        }

        return null;
    }

    /**
     * The segments that would resolve below a field, for the error message.
     *
     * @return array<int, string>
     */
    private function childSegments(Field $field): array
    {
        $fieldtype = $field->fieldtype();

        // Bard extends Replicator, so this covers both.
        if ($fieldtype instanceof Replicator) {
            /** @var array<int, string> $setHandles */
            $setHandles = array_map('strval', array_keys($fieldtype->flattenedSetsConfig()->all()));

            return $setHandles;
        }

        if ($fieldtype instanceof Group || $fieldtype instanceof Grid) {
            /** @var array<int, string> $handles */
            $handles = array_map('strval', $fieldtype->fields()->all()->keys()->all());

            return $handles;
        }

        return [];
    }

    /**
     * @param  array<int, string>  $walked
     * @param  array<int, string>  $valid
     *
     * @return array<string, mixed>
     */
    private function unresolvableSegment(string $path, string $segment, array $walked, array $valid): array
    {
        sort($valid);
        $at = $walked === [] ? 'the top level' : '[' . implode('.', $walked) . ']';

        return $this->createErrorResponse(sprintf(
            'Field path [%s] does not resolve: [%s] is not a field or set at %s. Valid segments there: %s.',
            $path,
            $segment,
            $at,
            $valid === [] ? '(none — this field has no children)' : implode(', ', $valid)
        ))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function describeField(Field $field, bool $includeConfig, ?FieldFormatSpec $formatSpec): array
    {
        $field = clone $field;
        /** @var array<string, mixed> $config */
        $config = $this->visibleDefinitions($field->config());
        $field->setConfig($config);
        $data = [
            'handle' => $field->handle(),
            'type' => $field->type(),
            'display' => $field->display(),
        ];

        if ($includeConfig) {
            $data['config'] = $field->config();
        }

        if ($formatSpec !== null && ($spec = $formatSpec->for($field)) !== null) {
            $data['_format_spec'] = $spec;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getBlueprint(array $arguments): array
    {
        $handle = is_string($arguments['handle']) ? $arguments['handle'] : '';
        $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;
        $collectionHandle = isset($arguments['collection']) && is_string($arguments['collection']) ? $arguments['collection'] : null;
        $taxonomyHandle = isset($arguments['taxonomy']) && is_string($arguments['taxonomy']) ? $arguments['taxonomy'] : null;
        $includeConfig = $this->getBooleanArgument($arguments, 'include_config', true);
        $includeFormatSpec = $this->getBooleanArgument($arguments, 'include_format_spec', true);
        $maxFormatDepth = $this->getIntegerArgument($arguments, 'max_format_depth', 2, 0, 5);
        $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);
        if ($blueprint === null) {
            return $this->missingResource('Blueprint', $handle, candidates: $this->candidateBlueprintHandles($namespace, $collectionHandle, $taxonomyHandle), arguments: $arguments)->toArray();
        }
        FieldReferences::validateContents($blueprint->contents());
        $version = self::resourceVersion($blueprint->contents());
        // Resolve through the section so Entry/TermBlueprintFound listeners can add runtime fields.
        $resolvedNamespace = $blueprint->namespace();
        if (is_string($resolvedNamespace) && str_starts_with($resolvedNamespace, 'collections.')) {
            $blueprint = Collection::find(substr($resolvedNamespace, 12))?->entryBlueprint($blueprint->handle()) ?? $blueprint;
        } elseif (is_string($resolvedNamespace) && str_starts_with($resolvedNamespace, 'taxonomies.')) {
            $blueprint = Taxonomy::find(substr($resolvedNamespace, 11))?->termBlueprint($blueprint->handle()) ?? $blueprint;
        }
        $formatSpec = $includeFormatSpec ? new FieldFormatSpec($maxFormatDepth) : null;
        $fieldPath = isset($arguments['field']) && is_string($arguments['field']) && $arguments['field'] !== ''
            ? $arguments['field']
            : null;
        if ($fieldPath !== null) {
            $result = $this->getBlueprintSubtree($blueprint, $fieldPath, $includeConfig, $formatSpec);
            if (isset($result['blueprint']) && is_array($result['blueprint'])) {
                $result['blueprint']['version'] = $version;
            }

            return $result;
        }
        $data = $this->blueprintRecord($blueprint, $includeConfig, $formatSpec);
        $data['version'] = $version;
        if ($formatSpec !== null && ($iconSets = $formatSpec->collectedIconSets()) !== []) {
            $data['icon_sets'] = $iconSets;
        }

        return ['blueprint' => $data];
    }

    /** @return array<string, mixed> */
    private function blueprintRecord(BlueprintContract $blueprint, bool $includeConfig = true, ?FieldFormatSpec $formatSpec = null): array
    {
        FieldReferences::validateContents($blueprint->contents());
        $sources = BlueprintLayout::sources($blueprint);
        $data = [
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            'namespace' => $blueprint->namespace(),
            'hidden' => $blueprint->hidden(),
            'order' => $blueprint->order(),
            'version' => self::resourceVersion($blueprint->contents()),
            'fields' => $blueprint->fields()->all()->map(function (mixed $field) use ($includeConfig, $formatSpec, $sources): array {
                /** @var Field $field */
                return [
                    ...$this->describeField($field, $includeConfig, $formatSpec),
                    'source' => $sources[$field->handle()] ?? null,
                ];
            })->toArray(),
        ];
        if ($includeConfig) {
            $data['tabs'] = $this->visibleDefinitions($blueprint->contents())['tabs'];
        }

        return $data;
    }

    /**
     * Raw definitions use handle values rather than keyed maps. An import containing
     * a denied field is omitted as a whole; the permitted expanded fields remain above.
     *
     * @param  array<array-key, mixed>  $definitions
     *
     * @return array<array-key, mixed>
     */
    private function visibleDefinitions(array $definitions): array
    {
        $denied = app(ResourcePolicy::class)->getDeniedFields('blueprints');

        return FieldReferences::mapContents($definitions, function (array $definition) use ($denied): ?array {
            if (in_array($definition['handle'] ?? null, $denied, true)) {
                return null;
            }
            if (isset($definition['import']) && BlueprintLayout::containsDeniedFields($definition, $denied)) {
                return null;
            }

            return $definition;
        });
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createBlueprint(array $arguments): array
    {
        $handle = isset($arguments['handle']) && is_string($arguments['handle']) ? $arguments['handle'] : null;
        $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : 'collections';
        $collectionHandle = isset($arguments['collection']) && is_string($arguments['collection']) ? $arguments['collection'] : null;
        $taxonomyHandle = isset($arguments['taxonomy']) && is_string($arguments['taxonomy']) ? $arguments['taxonomy'] : null;
        $fields = isset($arguments['fields']) && is_array($arguments['fields']) ? $arguments['fields'] : [];
        $title = isset($arguments['title']) && is_string($arguments['title']) ? $arguments['title'] : Str::title(str_replace('_', ' ', (string) $handle));
        if (! $handle) {
            return $this->createErrorResponse('Handle is required for blueprint creation')->toArray();
        }
        if ($locationError = $this->validateBlueprintLocation($handle, $namespace, $collectionHandle, $taxonomyHandle)) {
            return $locationError;
        }
        // Validate namespace-specific requirements
        if ($namespace === 'collections' && ! $collectionHandle) {
            return $this->createErrorResponse('collection is required when namespace=collections. Example: collection="brands", handle="brand"')->toArray();
        }
        if ($namespace === 'taxonomies' && ! $taxonomyHandle) {
            return $this->createErrorResponse('taxonomy is required when namespace=taxonomies. Example: taxonomy="categories", handle="category"')->toArray();
        }
        // Validate collection/taxonomy exists
        if ($namespace === 'collections') {
            // collection is guaranteed to exist here due to validation above
            if (! Collection::find($collectionHandle)) {
                return $this->createErrorResponse("Collection not found: {$collectionHandle}. Create the collection first with statamic-structures-admin.", code: ErrorCode::NotFound, field: 'collection')->toArray();
            }
        }
        if ($namespace === 'taxonomies') {
            // taxonomy is guaranteed to exist here due to validation above
            if (! Taxonomy::find($taxonomyHandle)) {
                return $this->createErrorResponse("Taxonomy not found: {$taxonomyHandle}. Create the taxonomy first with statamic-structures-admin.", code: ErrorCode::NotFound, field: 'taxonomy')->toArray();
            }
        }
        // At this point $handle is guaranteed non-null (checked above), and
        // $collectionHandle/$taxonomyHandle are guaranteed non-null when required (checked above).
        $safeHandle = (string) $handle;
        $safeCollectionHandle = (string) $collectionHandle;
        $safeTaxonomyHandle = (string) $taxonomyHandle;
        // Determine the correct namespace
        $blueprintNamespace = match ($namespace) {
            'collections' => "collections.{$safeCollectionHandle}",
            'taxonomies' => "taxonomies.{$safeTaxonomyHandle}",
            default => $namespace,
        };
        // Use file lock to prevent race conditions in concurrent creation
        $lockKey = md5("{$blueprintNamespace}.{$safeHandle}");
        $lockPath = storage_path("framework/cache/blueprint-{$lockKey}.lock");
        $lockDir = dirname($lockPath);
        if (! is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        $lockFile = fopen($lockPath, 'c');
        if (! $lockFile) {
            return $this->createErrorResponse('Could not acquire lock for blueprint creation')->toArray();
        }
        if (! flock($lockFile, LOCK_EX)) {
            fclose($lockFile);

            return $this->createErrorResponse('Could not acquire lock for blueprint creation')->toArray();
        }
        try {
            // Check if blueprint already exists in this namespace (inside lock)
            $existing = collect(Blueprint::in($blueprintNamespace)->all())->firstWhere('handle', $safeHandle);
            $existsError = $this->checkHandleNotExists($existing, "Blueprint in {$blueprintNamespace}", $safeHandle);
            if ($existsError !== null) {
                return $existsError;
            }

            // Create the blueprint contents following default.yaml structure
            $contents = ['title' => $title];

            // Validate and add fields if provided
            if (! empty($fields) || isset($arguments['tab'])) {
                $validationResult = $this->validateFields($fields);
                if (isset($validationResult['success']) && $validationResult['success'] === false) {
                    return $validationResult;
                }
                /** @var list<array<string, mixed>> $validatedFields */
                $validatedFields = $validationResult['validated'];
                $contents = BlueprintLayout::update($contents, $validatedFields, is_string($arguments['tab'] ?? null) ? $arguments['tab'] : null);
            }

            // Create the blueprint
            $blueprint = Blueprint::make($safeHandle)
                ->setNamespace($blueprintNamespace)
                ->setContents($contents);

            $this->persistStatamic(fn () => $blueprint->save(), ['stache']);

            return [
                'blueprint' => [
                    ...$this->blueprintRecord($blueprint),
                    'path' => $blueprint->path(),
                    'full_path' => $namespace === 'collections'
                        ? "collections/{$safeCollectionHandle}/{$safeHandle}.yaml"
                        : ($namespace === 'taxonomies'
                            ? "taxonomies/{$safeTaxonomyHandle}/{$safeHandle}.yaml"
                            : "{$namespace}/{$safeHandle}.yaml"),
                ],
                'created' => true,
                'guidance' => $namespace === 'collections'
                    ? "Blueprint created for collection '{$safeCollectionHandle}'. You can now create multiple blueprints (e.g., 'short', 'long', 'featured') for the same collection."
                    : null,
            ];
        } finally {
            flock($lockFile, LOCK_UN);
            fclose($lockFile);
            @unlink($lockPath);
        }
    }

    /**
     * Find a blueprint by handle and namespace, accounting for collection-specific namespaces and custom addon namespaces.
     *
     * @param  string  $handle  Blueprint handle
     * @param  string|null  $namespace  Blueprint namespace (collections, taxonomies, etc.)
     * @param  string|null  $collectionHandle  Collection handle for collection blueprints
     * @param  string|null  $taxonomyHandle  Taxonomy handle for taxonomy blueprints
     */
    private function findBlueprint(string $handle, ?string $namespace = null, ?string $collectionHandle = null, ?string $taxonomyHandle = null): ?BlueprintContract
    {
        if ($namespace === 'forms' || str_starts_with($namespace ?? '', 'forms.')) {
            return null;
        }
        if ($namespace) {
            // For collections, use the collection argument to build correct namespace
            if ($namespace === 'collections' && $collectionHandle) {
                try {
                    $blueprint = collect(Blueprint::in("collections.{$collectionHandle}")->all())->firstWhere('handle', $handle);
                    if ($blueprint instanceof BlueprintContract) {
                        return $blueprint;
                    }
                } catch (\Exception $e) {
                    // Ignore if namespace doesn't exist
                }
            }

            // For taxonomies, use the taxonomy argument to build correct namespace
            if ($namespace === 'taxonomies' && $taxonomyHandle) {
                try {
                    $blueprint = collect(Blueprint::in("taxonomies.{$taxonomyHandle}")->all())->firstWhere('handle', $handle);
                    if ($blueprint instanceof BlueprintContract) {
                        return $blueprint;
                    }
                } catch (\Exception $e) {
                    // Ignore if namespace doesn't exist
                }
            }

            // Try the exact namespace
            $blueprint = collect(Blueprint::in($namespace)->all())->firstWhere('handle', $handle);

            if ($blueprint instanceof BlueprintContract) {

                return $blueprint;
            }

            return null;
        }

        // Search all standard namespaces (single pass, no redundant lookups)
        $standardNamespaces = ['collections', 'taxonomies', 'globals', 'assets', 'users'];

        foreach ($standardNamespaces as $searchNamespace) {
            try {
                $blueprint = collect(Blueprint::in($searchNamespace)->all())->firstWhere('handle', $handle);
                if ($blueprint instanceof BlueprintContract) {
                    return $blueprint;
                }
            } catch (\Exception $e) {
                // Ignore if namespace doesn't exist
            }
        }

        // Try collection-specific namespace (e.g., collections.pages)
        try {
            $blueprint = collect(Blueprint::in("collections.{$handle}")->all())->firstWhere('handle', $handle);
            if ($blueprint instanceof BlueprintContract) {
                return $blueprint;
            }
        } catch (\Exception $e) {
            // Ignore if namespace doesn't exist
        }

        return null;
    }

    /**
     * Common near-miss parameter names that LLMs send incorrectly.
     *
     * @var array<string, string>
     */
    private const PARAM_CORRECTIONS = [
        'taxonomy' => 'taxonomies',
        'collection' => 'collections',
        'field_type' => 'type',
        'fieldtype' => 'type',
        'name' => 'handle',
    ];

    /**
     * Validate field definitions strictly. Returns validated fields array on success,
     * or an error response array (with 'success' => false) on failure.
     *
     * @param  array<int|string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    private function validateFields(array $fields): array
    {
        if (empty($fields)) {
            return ['validated' => []];
        }

        FieldReferences::validate($fields);
        $fieldtypeRepo = app(FieldtypeRepository::class);
        /** @var array<string, bool> $seenHandles */
        $seenHandles = [];
        /** @var list<array<string, mixed>> $validated */
        $validated = [];

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                return $this->createErrorResponse(
                    "Field at index {$index} must be an object. "
                    . 'Expected format: {"handle": "title", "field": {"type": "text", "display": "Title"}}'
                )->toArray();
            }

            // Inline configs and import/reference overrides have the same safety rules.
            foreach (['field', 'config'] as $configKey) {
                if (is_array($field[$configKey] ?? null)) {
                    array_walk_recursive($field[$configKey], static function (mixed &$value): void {
                        if (is_string($value) && (str_contains($value, '{{') || str_contains($value, '{!!'))) {
                            $value = strip_tags((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $value));
                        }
                    });
                }
            }

            if (isset($field['import']) || is_string($field['field'] ?? null)) {
                /** @var array<string, mixed> $field */
                if (isset($field['import'], $field['handle']) || isset($field['import'], $field['field'])) {
                    return $this->createErrorResponse('An import must not also define handle or field.')->toArray();
                }
                if (! isset($field['import']) && (! is_string($field['handle'] ?? null) || $field['handle'] === '')) {
                    return $this->createErrorResponse('A field reference requires a handle.')->toArray();
                }
                // Let native resolution validate referenced fields and overrides without flattening them.
                (new Fields([$field]))->all();
                $validated[] = $field;

                continue;
            }

            if (! isset($field['handle']) || ! is_string($field['handle'])) {
                // Check for near-miss: did they use "name" instead of "handle"?
                $hint = '';
                if (isset($field['name'])) {
                    $hint = ' Did you mean "handle"? You sent "name".';
                }

                return $this->createErrorResponse(
                    "Field at index {$index} must have a \"handle\" key (string).{$hint} "
                    . 'Example: {"handle": "title", "field": {"type": "text", "display": "Title"}}'
                )->toArray();
            }

            $handle = $field['handle'];

            // Check for duplicate handles
            if (isset($seenHandles[$handle])) {
                return $this->createErrorResponse(
                    "Duplicate field handle: \"{$handle}\". Each field must have a unique handle."
                )->toArray();
            }
            $seenHandles[$handle] = true;

            // Require the "field" key — no auto-wrapping
            if (! isset($field['field']) || ! is_array($field['field'])) {
                // Build a helpful hint about what they sent
                $sentKeys = array_keys($field);
                $extraKeys = array_diff($sentKeys, ['handle']);
                $hint = '';
                if (in_array('type', $extraKeys, true)) {
                    $hint = ' It looks like you put "type" at the top level. Move it inside a "field" object.';
                }

                return $this->createErrorResponse(
                    "Field \"{$handle}\" is missing the \"field\" key.{$hint} "
                    . "Correct format: {\"handle\": \"{$handle}\", \"field\": {\"type\": \"text\", \"display\": \"...\"}}"
                )->toArray();
            }

            /** @var array<string, mixed> $fieldConfig */
            $fieldConfig = $field['field'];

            // Check for near-miss param names in field config
            foreach (self::PARAM_CORRECTIONS as $wrong => $correct) {
                if (isset($fieldConfig[$wrong]) && ! isset($fieldConfig[$correct])) {
                    return $this->createErrorResponse(
                        "Field \"{$handle}\" has \"{$wrong}\" in its config. "
                        . "Did you mean \"{$correct}\"? Rename \"{$wrong}\" to \"{$correct}\"."
                    )->toArray();
                }
            }

            // Validate type exists
            $type = $fieldConfig['type'] ?? null;
            if (! is_string($type)) {
                return $this->createErrorResponse(
                    "Field \"{$handle}\" is missing \"type\" in its field config. "
                    . "Example: {\"handle\": \"{$handle}\", \"field\": {\"type\": \"text\"}}"
                )->toArray();
            }

            try {
                $fieldtypeRepo->find($type);
            } catch (FieldtypeNotFoundException) {
                /** @var SupportCollection<string, string> $handles */
                $handles = $fieldtypeRepo->handles();
                $available = $handles->values()->sort()->implode(', ');

                return $this->createErrorResponse(
                    "Unknown field type \"{$type}\" for field \"{$handle}\". "
                    . "Available types: {$available}"
                )->toArray();
            }

            // Validate references to taxonomies and collections exist
            if (isset($fieldConfig['taxonomies']) && is_array($fieldConfig['taxonomies'])) {
                foreach ($fieldConfig['taxonomies'] as $taxHandle) {
                    if (is_string($taxHandle) && ! Taxonomy::find($taxHandle)) {
                        return $this->createErrorResponse(
                            "Field \"{$handle}\" references taxonomy \"{$taxHandle}\" which does not exist. "
                            . 'Create the taxonomy first with statamic-structures-admin action "create", resource_type "taxonomy".'
                        )->toArray();
                    }
                }
            }

            if (isset($fieldConfig['collections']) && is_array($fieldConfig['collections'])) {
                foreach ($fieldConfig['collections'] as $colHandle) {
                    if (is_string($colHandle) && ! Collection::find($colHandle)) {
                        return $this->createErrorResponse(
                            "Field \"{$handle}\" references collection \"{$colHandle}\" which does not exist. "
                            . 'Create the collection first with statamic-structures-admin action "create", resource_type "collection".'
                        )->toArray();
                    }
                }
            }

            $validated[] = [
                'handle' => $handle,
                'field' => $fieldConfig,
            ];
        }

        (new BlueprintContract)->setContents(['fields' => $validated])->validateUniqueHandles();
        foreach ((new Fields($validated))->all() as $resolved) {
            /** @var Field $resolved */
            $resolved->fieldtype();
        }

        return ['validated' => $validated];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateBlueprint(array $arguments): array
    {
        $handle = is_string($arguments['handle']) ? $arguments['handle'] : '';
        $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;
        $fields = isset($arguments['fields']) && is_array($arguments['fields']) ? $arguments['fields'] : null;
        $title = isset($arguments['title']) && is_string($arguments['title']) ? $arguments['title'] : null;
        // Find the blueprint
        $collectionHandle = isset($arguments['collection']) && is_string($arguments['collection']) ? $arguments['collection'] : null;
        $taxonomyHandle = isset($arguments['taxonomy']) && is_string($arguments['taxonomy']) ? $arguments['taxonomy'] : null;
        $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);
        if ($blueprint === null) {
            return $this->missingResource('Blueprint', $handle, candidates: $this->candidateBlueprintHandles($namespace, $collectionHandle, $taxonomyHandle), arguments: $arguments)->toArray();
        }
        // Blink holds the found instance: a refused save must not leave it changed.
        $blueprint = clone $blueprint;
        // Update the blueprint contents
        $contents = $blueprint->contents();
        if ($title !== null) {
            $contents['title'] = $title;
        }
        if ($fields !== null) {
            $validationResult = $this->validateFields($fields);
            if (isset($validationResult['success']) && $validationResult['success'] === false) {
                return $validationResult;
            }
            /** @var list<array<string, mixed>> $validatedFields */
            $validatedFields = $validationResult['validated'];
        }
        /** @var list<string> $remove */
        $remove = is_array($arguments['remove_fields'] ?? null) ? $arguments['remove_fields'] : [];
        $contents = BlueprintLayout::update($contents, $validatedFields ?? [],
            is_string($arguments['tab'] ?? null) ? $arguments['tab'] : null, $remove, ($arguments['replace_fields'] ?? false) === true);
        FieldReferences::validateContents($contents);
        $blueprint->setContents($contents);
        $this->persistStatamic(fn () => $blueprint->save(), ['stache']);

        return [
            'blueprint' => $this->blueprintRecord($blueprint),
            'updated' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteBlueprint(array $arguments): array
    {
        $handle = is_string($arguments['handle']) ? $arguments['handle'] : '';
        $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;
        // Find the blueprint
        $collectionHandle = isset($arguments['collection']) && is_string($arguments['collection']) ? $arguments['collection'] : null;
        $taxonomyHandle = isset($arguments['taxonomy']) && is_string($arguments['taxonomy']) ? $arguments['taxonomy'] : null;
        $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);
        if ($blueprint === null) {
            return $this->missingResource('Blueprint', $handle, candidates: $this->candidateBlueprintHandles($namespace, $collectionHandle, $taxonomyHandle), arguments: $arguments)->toArray();
        }
        // Store blueprint info before deletion
        $blueprintInfo = $this->blueprintRecord($blueprint);
        $this->persistStatamic(fn () => $blueprint->delete(), ['stache']);

        return [
            'deleted' => true,
            'blueprint' => $blueprintInfo,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function scanBlueprints(array $arguments): array
    {
        return $this->listBlueprints($arguments);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function generateBlueprint(array $arguments): array
    {
        $handle = isset($arguments['handle']) && is_string($arguments['handle']) ? $arguments['handle'] : null;
        $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : 'collections';
        $title = isset($arguments['title']) && is_string($arguments['title']) ? $arguments['title'] : Str::title(str_replace(['_', '-'], ' ', (string) $handle));
        if (! $handle) {
            return $this->createErrorResponse('Handle is required for blueprint generation')->toArray();
        }
        if ($locationError = $this->validateBlueprintLocation($handle, $namespace)) {
            return $locationError;
        }
        // Fields must be provided by the user/LLM — never hardcoded
        $fields = isset($arguments['fields']) && is_array($arguments['fields']) ? $arguments['fields'] : [];
        if (empty($fields)) {
            return $this->createErrorResponse('Fields are required for blueprint generation. Provide field definitions as an array of objects with at least "handle" and "type" keys.')->toArray();
        }
        // At this point $handle is guaranteed non-null
        $safeHandle = $handle;
        // Check if blueprint already exists
        $existing = collect(Blueprint::in($namespace)->all())->firstWhere('handle', $safeHandle);
        $existsError = $this->checkHandleNotExists($existing, "Blueprint in {$namespace}", $safeHandle);
        if ($existsError !== null) {
            return $existsError;
        }
        // Build field definitions from user-provided input.
        // The generate action accepts a simplified format where "type" is at the
        // top level, so we normalize to the standard {handle, field} indexed array
        // that Statamic's Blueprint::setContents() expects.
        /** @var array<int, array{handle: string, field: array<string, mixed>}> $fieldDefinitions */
        $fieldDefinitions = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $fieldHandle = is_string($field['handle'] ?? null) ? $field['handle'] : null;
            $fieldType = is_string($field['type'] ?? null) ? $field['type'] : null;
            if (! $fieldHandle || ! $fieldType) {
                continue;
            }
            $fieldConfig = $field;
            unset($fieldConfig['handle']);
            $fieldDefinitions[] = [
                'handle' => $fieldHandle,
                'field' => $fieldConfig,
            ];
        }
        if (empty($fieldDefinitions)) {
            return $this->createErrorResponse('No valid field definitions found. Each field must have at least "handle" and "type".')->toArray();
        }
        // Create the blueprint
        $blueprint = Blueprint::make($safeHandle)
            ->setNamespace($namespace)
            ->setContents([
                'title' => $title,
                'fields' => $fieldDefinitions,
            ]);
        $this->persistStatamic(fn () => $blueprint->save(), ['stache']);

        return [
            'blueprint' => $this->blueprintRecord($blueprint),
            'generated' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function analyzeTypes(array $arguments): array
    {
        $outputFormat = $arguments['output_format'] ?? 'typescript';
        $pagination = $this->getPaginationArgs($arguments, 50, 200);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];
        $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;
        $blueprints = $this->collectAllBlueprints($namespace);
        $total = $blueprints->count();
        $paged = $blueprints->sortBy(fn (BlueprintContract $blueprint): string => $blueprint->namespace() . '.' . $blueprint->handle())->values()->skip($offset)->take($limit);
        $types = [];
        foreach ($paged as $blueprint) {
            /** @var BlueprintContract $blueprint */
            $fields = $blueprint->fields()->all()->map(function (mixed $field): array {
                /** @var Field $field */
                return [
                    'handle' => $field->handle(),
                    'type' => $field->type(),
                    'required' => $field->isRequired(),
                ];
            })->toArray();

            $handle = $blueprint->handle();
            if ($handle === null) {
                continue;
            }

            $types[] = ['handle' => $handle, 'namespace' => $blueprint->namespace(), 'fields' => $fields];
        }

        return [
            'format' => $outputFormat,
            ...$this->buildPage($types, $total, $offset, $arguments),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function validateBlueprint(array $arguments): array
    {
        $handle = is_string($arguments['handle']) ? $arguments['handle'] : '';
        $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;
        $collectionHandle = isset($arguments['collection']) && is_string($arguments['collection']) ? $arguments['collection'] : null;
        $taxonomyHandle = isset($arguments['taxonomy']) && is_string($arguments['taxonomy']) ? $arguments['taxonomy'] : null;
        $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);
        if ($blueprint === null) {
            return $this->missingResource('Blueprint', $handle, candidates: $this->candidateBlueprintHandles($namespace, $collectionHandle, $taxonomyHandle), arguments: $arguments)->toArray();
        }
        // Basic validation - check if fields are valid
        $validationResults = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
        ];
        $fields = $blueprint->fields()->all();
        foreach ($fields as $field) {
            /** @var Field $field */
            // Basic field validation
            if (empty($field->handle())) {
                $validationResults['valid'] = false;
                $validationResults['errors'][] = 'Field missing handle';
            }

            if (empty($field->type())) {
                $validationResults['valid'] = false;
                $validationResults['errors'][] = "Field '{$field->handle()}' missing type";
            }
        }

        return [
            'blueprint' => $handle,
            'validation' => $validationResults,
        ];
    }

    /**
     * Collect all blueprints across standard namespaces, optionally filtered.
     *
     * @param  string|null  $namespace  Filter to a specific namespace
     *
     * @return SupportCollection<int|string, BlueprintContract>
     */
    private function collectAllBlueprints(?string $namespace = null, bool $filterPolicy = true): SupportCollection
    {
        $blueprints = collect(Blueprint::in('collections')->all())
            ->concat(Blueprint::in('taxonomies')->all())
            ->concat(Blueprint::in('globals')->all())
            ->concat(Blueprint::in('assets')->all())
            ->concat(Blueprint::in('users')->all());

        // Blueprint::in() is non-recursive and keyed by handle. Concatenate
        // namespaces so two resources can both have a "default" blueprint.
        if (! $namespace || $namespace === 'collections') {
            /** @var list<string> $collectionHandles */
            $collectionHandles = Collection::handles()->all();
            foreach ($collectionHandles as $collectionHandle) {
                $blueprints = $blueprints->concat(Blueprint::in("collections.{$collectionHandle}")->all());
            }
        }
        if (! $namespace || $namespace === 'taxonomies') {
            /** @var list<string> $taxonomyHandles */
            $taxonomyHandles = Taxonomy::handles()->all();
            foreach ($taxonomyHandles as $taxonomyHandle) {
                $blueprints = $blueprints->concat(Blueprint::in("taxonomies.{$taxonomyHandle}")->all());
            }
        }

        if ($namespace) {
            $blueprints = $blueprints->filter(function (mixed $blueprint) use ($namespace): bool {
                /** @var BlueprintContract $blueprint */
                return $blueprint->namespace() === $namespace ||
                       str_starts_with($blueprint->namespace() ?? '', $namespace . '.');
            });
        }

        $policy = app(ResourcePolicy::class);

        return $blueprints->filter(fn (mixed $blueprint): bool => $blueprint instanceof BlueprintContract
            && $blueprint->handle() !== null
            && (! $filterPolicy || $policy->canAccess('blueprints', $blueprint->handle(), 'read')));
    }

    /**
     * Only suggest handles that the same lookup can actually resolve. A
     * collection-specific typo must not suggest another collection's schema.
     *
     * @return list<string>
     */
    private function candidateBlueprintHandles(?string $namespace, ?string $collection, ?string $taxonomy): array
    {
        $effectiveNamespace = match ($namespace) {
            'collections' => $collection ? "collections.{$collection}" : $namespace,
            'taxonomies' => $taxonomy ? "taxonomies.{$taxonomy}" : $namespace,
            default => $namespace,
        };
        $blueprints = $effectiveNamespace === null
            ? $this->collectAllBlueprints()
            : collect(Blueprint::in($effectiveNamespace)->all());

        $handles = [];
        foreach ($blueprints as $blueprint) {
            if ($blueprint instanceof BlueprintContract && ($handle = $blueprint->handle()) !== null
                && $this->findBlueprint($handle, $namespace, $collection, $taxonomy) !== null) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * Get required permissions for action.
     *
     * Writing a blueprint changes the content model, which is 'configure
     * fields' in Statamic. Reading one is not: the server's own instructions
     * tell an agent to read the blueprint before every entry write, and an
     * editor who may write entries rarely holds a 'configure' permission.
     * Reads therefore follow the same rule as the statamic://blueprints
     * resources: any 'configure' permission, or none at all when
     * `resources.require_statamic_permission` is off and the token scope
     * plus the resource policy decide (#54).
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string|array<int, string>>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        if ($this->isWriteAction($action)) {
            return ['configure fields'];
        }

        if (! config('statamic.mcp.resources.require_statamic_permission', true)) {
            return [];
        }

        return [['configure fields', 'configure collections', 'configure taxonomies']];
    }

    /**
     * Validate the handle and namespace before Statamic builds a file path from
     * them. Statamic assembles the path as `namespaceDir/{handle}.yaml` and does
     * not resolve `..` in the handle, so an unvalidated handle like `../evil`
     * would write YAML outside the namespace directory. The Control Panel
     * validates blueprint handles; the MCP surface did not.
     *
     * @return array<string, mixed>|null an error response, or null when valid
     */
    private function validateBlueprintLocation(?string $handle, string $namespace, ?string $collectionHandle = null, ?string $taxonomyHandle = null): ?array
    {
        $validNamespaces = ['collections', 'taxonomies', 'globals', 'assets', 'users'];
        if (! in_array($namespace, $validNamespaces, true)) {
            return $this->createErrorResponse("Invalid namespace: {$namespace}. Valid: " . implode(', ', $validNamespaces))->toArray();
        }

        foreach (['handle' => $handle, 'collection' => $collectionHandle, 'taxonomy' => $taxonomyHandle] as $label => $value) {
            if ($value !== null && ! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
                return $this->createErrorResponse("Invalid {$label}: only letters, digits, underscores and hyphens are allowed.")->toArray();
            }
        }

        return null;
    }

    /**
     * The resource policy names blueprints by handle. The default resolver
     * reads collection and taxonomy first, which here only say where the
     * blueprint lives, so a policy allowing the collection would approve
     * any of its blueprints.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveResourceHandle(array $arguments): ?string
    {
        $handle = $arguments['handle'] ?? null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }
}
