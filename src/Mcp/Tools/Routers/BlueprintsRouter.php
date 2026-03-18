<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Exceptions\FieldtypeNotFoundException;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Field;
use Statamic\Fields\FieldtypeRepository;

#[Name('statamic-blueprints')]
#[Description('Manage Statamic blueprints — the schema definitions for all content types. Call get before creating/updating entries, terms, or globals to understand required fields. Actions: list, get, create, update, delete, scan, generate, types, validate.')]
class BlueprintsRouter extends BaseRouter
{
    use ClearsCaches;

    protected function getDomain(): string
    {
        return 'blueprints';
    }

    public function getActions(): array
    {
        return [
            'list' => 'List blueprints in specific namespaces with optional details',
            'get' => 'Get specific blueprint with full field definitions',
            'create' => 'Create new blueprint from field definitions',
            'update' => 'Update existing blueprint fields and configuration',
            'delete' => 'Delete blueprints with safety checks',
            'scan' => 'Scan and analyze blueprint usage patterns',
            'generate' => 'Generate blueprints from user-provided field definitions',
            'types' => 'Generate TypeScript/PHP type definitions from blueprints',
            'validate' => 'Validate blueprint structure and field definitions',
        ];
    }

    public function getTypes(): array
    {
        return [
            'collections' => 'Blueprints for collection entries and content structure',
            'taxonomies' => 'Blueprints for taxonomy terms and categorization',
            'globals' => 'Blueprints for global settings and configuration',
            'forms' => 'Blueprints for form fields and submissions',
            'assets' => 'Blueprints for asset metadata and properties',
            'users' => 'Blueprints for user profiles and authentication',
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'list (namespace; optional: include_fields), '
                    . 'get (handle, namespace; requires collection_handle for collections namespace, taxonomy_handle for taxonomies namespace), '
                    . 'create (handle, namespace, fields — each field needs "handle" and "field" object with "type"), '
                    . 'update (handle, namespace, fields), '
                    . 'delete (handle, namespace), '
                    . 'scan (namespace), '
                    . 'types (namespace, output_format), '
                    . 'validate (handle, namespace)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete', 'scan', 'generate', 'types', 'validate'])
                ->required(),
            'handle' => JsonSchema::string()
                ->description('Blueprint identifier in snake_case. Example: "blog_post", "product"'),
            'namespace' => JsonSchema::string()
                ->description('Blueprint namespace determining storage location. Use "collections" for collection blueprints, "taxonomies" for taxonomy blueprints.')
                ->enum(['collections', 'taxonomies', 'globals', 'forms', 'assets', 'users']),
            'collection_handle' => JsonSchema::string()
                ->description('Collection handle. Required when namespace is "collections". Example: "blog"'),
            'taxonomy_handle' => JsonSchema::string()
                ->description('Taxonomy handle. Required when namespace is "taxonomies". Example: "tags"'),
            'title' => JsonSchema::string()
                ->description('Human-readable blueprint title. Auto-generated from handle if not provided'),
            'fields' => JsonSchema::array()
                ->items(JsonSchema::object())
                ->description(
                    'Array of field definition objects. Each MUST have "handle" (string) and "field" (object with at minimum a "type" key). '
                    . 'Use statamic-schema tool to see available field types. '
                    . 'Example: [{"handle": "title", "field": {"type": "text", "display": "Title"}}]'
                ),
            'include_details' => JsonSchema::boolean()
                ->description('Include field type and configuration details in response. Default: false for list, true for get'),
            'include_fields' => JsonSchema::boolean()
                ->description('Include field definitions in list response. Default: false (reduces response size)'),
            'include_config' => JsonSchema::boolean()
                ->description('Include full field config in responses (default true for get, false for list)'),
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

        // Validate action-specific requirements
        if (in_array($action, ['get', 'update', 'delete', 'validate']) && empty($arguments['handle'])) {
            return $this->createErrorResponse("Handle is required for {$action} action")->toArray();
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
        try {
            $namespace = $arguments['namespace'] ?? null;
            $includeDetails = $this->getBooleanArgument($arguments, 'include_details', false);
            $includeFields = $this->getBooleanArgument($arguments, 'include_fields', true);

            $blueprints = $this->collectAllBlueprints(is_string($namespace) ? $namespace : null);

            $total = $blueprints->count();
            $pagination = $this->getPaginationArgs($arguments);
            $limit = $pagination['limit'];
            $offset = $pagination['offset'];

            $data = $blueprints->values()->skip($offset)->take($limit)
                ->map(function (mixed $blueprint) use ($includeDetails, $includeFields): array {
                    /** @var \Statamic\Fields\Blueprint $blueprint */
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
                'blueprints' => $data->toArray(),
                'pagination' => $this->buildPaginationMeta($total, $limit, $offset),
                'namespace' => $namespace,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list blueprints: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getBlueprint(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle']) ? $arguments['handle'] : '';
            $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;
            $collectionHandle = isset($arguments['collection_handle']) && is_string($arguments['collection_handle']) ? $arguments['collection_handle'] : null;
            $taxonomyHandle = isset($arguments['taxonomy_handle']) && is_string($arguments['taxonomy_handle']) ? $arguments['taxonomy_handle'] : null;
            $includeConfig = $this->getBooleanArgument($arguments, 'include_config', true);

            $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);

            $notFound = $this->requireResource($blueprint, 'Blueprint', $handle);
            if ($notFound) {
                return $notFound;
            }

            $data = [
                'handle' => $blueprint->handle(),
                'title' => $blueprint->title(),
                'namespace' => $blueprint->namespace(),
                'hidden' => $blueprint->hidden(),
                'order' => $blueprint->order(),
                'fields' => $blueprint->fields()->all()->map(function (mixed $field) use ($includeConfig): array {
                    /** @var Field $field */
                    $fieldData = [
                        'handle' => $field->handle(),
                        'type' => $field->type(),
                        'display' => $field->display(),
                    ];

                    if ($includeConfig) {
                        $fieldData['config'] = $field->config();
                    }

                    return $fieldData;
                })->toArray(),
            ];

            return ['blueprint' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get blueprint: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createBlueprint(array $arguments): array
    {
        try {
            $handle = isset($arguments['handle']) && is_string($arguments['handle']) ? $arguments['handle'] : null;
            $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : 'collections';
            $collectionHandle = isset($arguments['collection_handle']) && is_string($arguments['collection_handle']) ? $arguments['collection_handle'] : null;
            $taxonomyHandle = isset($arguments['taxonomy_handle']) && is_string($arguments['taxonomy_handle']) ? $arguments['taxonomy_handle'] : null;
            $fields = isset($arguments['fields']) && is_array($arguments['fields']) ? $arguments['fields'] : [];
            $title = isset($arguments['title']) && is_string($arguments['title']) ? $arguments['title'] : Str::title(str_replace('_', ' ', (string) $handle));

            if (! $handle) {
                return $this->createErrorResponse('Handle is required for blueprint creation')->toArray();
            }

            // Validate namespace-specific requirements
            if ($namespace === 'collections' && ! $collectionHandle) {
                return $this->createErrorResponse('collection_handle is required when namespace=collections. Example: collection_handle="brands", handle="brand"')->toArray();
            }

            if ($namespace === 'taxonomies' && ! $taxonomyHandle) {
                return $this->createErrorResponse('taxonomy_handle is required when namespace=taxonomies. Example: taxonomy_handle="categories", handle="category"')->toArray();
            }

            // Validate collection/taxonomy exists
            if ($namespace === 'collections') {
                // collection_handle is guaranteed to exist here due to validation above
                if (! Collection::find($collectionHandle)) {
                    return $this->createErrorResponse("Collection not found: {$collectionHandle}. Create collection first with statamic-structures.")->toArray();
                }
            }

            if ($namespace === 'taxonomies') {
                // taxonomy_handle is guaranteed to exist here due to validation above
                if (! Taxonomy::find($taxonomyHandle)) {
                    return $this->createErrorResponse("Taxonomy not found: {$taxonomyHandle}. Create taxonomy first with statamic-structures.")->toArray();
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
            if (! $lockFile || ! flock($lockFile, LOCK_EX)) {
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
                if (! empty($fields)) {
                    $validationResult = $this->validateFields($fields);
                    if (isset($validationResult['success']) && $validationResult['success'] === false) {
                        return $validationResult;
                    }
                    /** @var array<int, array{handle: string, field: array<string, mixed>}> $validatedFields */
                    $validatedFields = $validationResult['validated'];
                    $contents['fields'] = $validatedFields;
                }

                // Create the blueprint
                $blueprint = Blueprint::make($safeHandle)
                    ->setNamespace($blueprintNamespace)
                    ->setContents($contents);

                // Save the blueprint
                $blueprint->save();

                // Clear Statamic caches
                $this->clearStatamicCaches(['stache']);

                return [
                    'blueprint' => [
                        'handle' => $blueprint->handle(),
                        'title' => $blueprint->title(),
                        'namespace' => $blueprint->namespace(),
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
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create blueprint: {$e->getMessage()}")->toArray();
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
    private function findBlueprint(string $handle, ?string $namespace = null, ?string $collectionHandle = null, ?string $taxonomyHandle = null): ?\Statamic\Fields\Blueprint
    {
        if ($namespace) {
            // For collections, use collection_handle to build correct namespace
            if ($namespace === 'collections' && $collectionHandle) {
                try {
                    $blueprint = collect(Blueprint::in("collections.{$collectionHandle}")->all())->firstWhere('handle', $handle);
                    if ($blueprint) {
                        return $blueprint;
                    }
                } catch (\Exception $e) {
                    // Ignore if namespace doesn't exist
                }
            }

            // For taxonomies, use taxonomy_handle to build correct namespace
            if ($namespace === 'taxonomies' && $taxonomyHandle) {
                try {
                    $blueprint = collect(Blueprint::in("taxonomies.{$taxonomyHandle}")->all())->firstWhere('handle', $handle);
                    if ($blueprint) {
                        return $blueprint;
                    }
                } catch (\Exception $e) {
                    // Ignore if namespace doesn't exist
                }
            }

            // Try the exact namespace
            $blueprint = collect(Blueprint::in($namespace)->all())->firstWhere('handle', $handle);

            if ($blueprint) {
                return $blueprint;
            }

            return null;
        }

        // Search all standard namespaces (single pass, no redundant lookups)
        $standardNamespaces = ['collections', 'taxonomies', 'globals', 'forms', 'assets', 'users'];

        foreach ($standardNamespaces as $searchNamespace) {
            try {
                $blueprint = collect(Blueprint::in($searchNamespace)->all())->firstWhere('handle', $handle);
                if ($blueprint) {
                    return $blueprint;
                }
            } catch (\Exception $e) {
                // Ignore if namespace doesn't exist
            }
        }

        // Try collection-specific namespace (e.g., collections.pages)
        try {
            $blueprint = collect(Blueprint::in("collections.{$handle}")->all())->firstWhere('handle', $handle);
            if ($blueprint) {
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

        $fieldtypeRepo = app(FieldtypeRepository::class);
        /** @var array<string, bool> $seenHandles */
        $seenHandles = [];
        /** @var array<int, array{handle: string, field: array<string, mixed>}> $validated */
        $validated = [];

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                return $this->createErrorResponse(
                    "Field at index {$index} must be an object. "
                    . 'Expected format: {"handle": "title", "field": {"type": "text", "display": "Title"}}'
                )->toArray();
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
                /** @var \Illuminate\Support\Collection<string, string> $handles */
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
                            . 'Create the taxonomy first with statamic-structures action "create", resource_type "taxonomy".'
                        )->toArray();
                    }
                }
            }

            if (isset($fieldConfig['collections']) && is_array($fieldConfig['collections'])) {
                foreach ($fieldConfig['collections'] as $colHandle) {
                    if (is_string($colHandle) && ! Collection::find($colHandle)) {
                        return $this->createErrorResponse(
                            "Field \"{$handle}\" references collection \"{$colHandle}\" which does not exist. "
                            . 'Create the collection first with statamic-structures action "create", resource_type "collection".'
                        )->toArray();
                    }
                }
            }

            // Strip template expressions from string config values (security)
            foreach ($fieldConfig as $key => $value) {
                if (is_string($value) && (str_contains($value, '{{') || str_contains($value, '{!!'))) {
                    $fieldConfig[$key] = strip_tags((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $value));
                }
            }

            $validated[] = [
                'handle' => $handle,
                'field' => $fieldConfig,
            ];
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
        try {
            $handle = is_string($arguments['handle']) ? $arguments['handle'] : '';
            $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;
            $fields = isset($arguments['fields']) && is_array($arguments['fields']) ? $arguments['fields'] : null;
            $title = isset($arguments['title']) && is_string($arguments['title']) ? $arguments['title'] : null;

            // Find the blueprint
            $collectionHandle = isset($arguments['collection_handle']) && is_string($arguments['collection_handle']) ? $arguments['collection_handle'] : null;
            $taxonomyHandle = isset($arguments['taxonomy_handle']) && is_string($arguments['taxonomy_handle']) ? $arguments['taxonomy_handle'] : null;

            $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);

            $notFound = $this->requireResource($blueprint, 'Blueprint', $handle);
            if ($notFound) {
                return $notFound;
            }

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
                /** @var array<int, array{handle: string, field: array<string, mixed>}> $validatedFields */
                $validatedFields = $validationResult['validated'];
                $contents['fields'] = $validatedFields;
            }

            $blueprint->setContents($contents);
            $blueprint->save();

            // Clear Statamic caches
            $this->clearStatamicCaches(['stache']);

            return [
                'blueprint' => [
                    'handle' => $blueprint->handle(),
                    'title' => $blueprint->title(),
                    'namespace' => $blueprint->namespace(),
                    'fields' => $blueprint->fields()->all()->map(function (mixed $field): array {
                        /** @var Field $field */
                        return [
                            'handle' => $field->handle(),
                            'type' => $field->type(),
                            'display' => $field->display(),
                            'config' => $field->config(),
                        ];
                    })->toArray(),
                ],
                'updated' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update blueprint: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteBlueprint(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle']) ? $arguments['handle'] : '';
            $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;
            $confirm = $this->getBooleanArgument($arguments, 'confirm', false);

            if (! $confirm) {
                return $this->createErrorResponse('Deletion requires explicit confirmation (set confirm to true)')->toArray();
            }

            // Find the blueprint
            $collectionHandle = isset($arguments['collection_handle']) && is_string($arguments['collection_handle']) ? $arguments['collection_handle'] : null;
            $taxonomyHandle = isset($arguments['taxonomy_handle']) && is_string($arguments['taxonomy_handle']) ? $arguments['taxonomy_handle'] : null;

            $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);

            $notFound = $this->requireResource($blueprint, 'Blueprint', $handle);
            if ($notFound) {
                return $notFound;
            }

            // Store blueprint info before deletion
            $blueprintInfo = [
                'handle' => $blueprint->handle(),
                'title' => $blueprint->title(),
                'namespace' => $blueprint->namespace(),
            ];

            // Delete the blueprint
            $blueprint->delete();

            // Clear Statamic caches
            $this->clearStatamicCaches(['stache']);

            return [
                'deleted' => true,
                'blueprint' => $blueprintInfo,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete blueprint: {$e->getMessage()}")->toArray();
        }
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
        try {
            $handle = isset($arguments['handle']) && is_string($arguments['handle']) ? $arguments['handle'] : null;
            $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : 'collections';
            $title = isset($arguments['title']) && is_string($arguments['title']) ? $arguments['title'] : Str::title(str_replace(['_', '-'], ' ', (string) $handle));

            if (! $handle) {
                return $this->createErrorResponse('Handle is required for blueprint generation')->toArray();
            }

            // Validate namespace
            $validNamespaces = ['collections', 'taxonomies', 'globals', 'forms', 'assets', 'users'];
            if (! in_array($namespace, $validNamespaces, true)) {
                return $this->createErrorResponse("Invalid namespace: {$namespace}. Valid: " . implode(', ', $validNamespaces))->toArray();
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

            // Build field definitions from user-provided input
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
                $fieldDefinitions[$fieldHandle] = $fieldConfig;
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

            // Save the blueprint
            $blueprint->save();

            // Clear Statamic caches
            $this->clearStatamicCaches(['stache']);

            return [
                'blueprint' => [
                    'handle' => $blueprint->handle(),
                    'title' => $blueprint->title(),
                    'namespace' => $blueprint->namespace(),
                    'fields' => $blueprint->fields()->all()->map(function (mixed $field): array {
                        /** @var Field $field */
                        return [
                            'handle' => $field->handle(),
                            'type' => $field->type(),
                            'display' => $field->display(),
                            'config' => $field->config(),
                        ];
                    })->toArray(),
                ],
                'generated' => true,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to generate blueprint: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function analyzeTypes(array $arguments): array
    {
        try {
            $outputFormat = $arguments['output_format'] ?? 'typescript';
            $pagination = $this->getPaginationArgs($arguments, 50, 200);
            $limit = $pagination['limit'];
            $offset = $pagination['offset'];
            $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;

            $blueprints = $this->collectAllBlueprints($namespace);
            $total = $blueprints->count();
            $paged = $blueprints->values()->skip($offset)->take($limit);

            $types = [];
            foreach ($paged as $blueprint) {
                /** @var \Statamic\Fields\Blueprint $blueprint */
                $fields = $blueprint->fields()->all()->map(function (mixed $field): array {
                    /** @var Field $field */
                    return [
                        'handle' => $field->handle(),
                        'type' => $field->type(),
                        'required' => $field->isRequired(),
                    ];
                })->toArray();

                $types[$blueprint->handle()] = $fields;
            }

            return [
                'format' => $outputFormat,
                'types' => $types,
                'pagination' => $this->buildPaginationMeta($total, $limit, $offset),
                'generated_at' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to analyze types: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function validateBlueprint(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle']) ? $arguments['handle'] : '';
            $namespace = isset($arguments['namespace']) && is_string($arguments['namespace']) ? $arguments['namespace'] : null;

            $collectionHandle = isset($arguments['collection_handle']) && is_string($arguments['collection_handle']) ? $arguments['collection_handle'] : null;
            $taxonomyHandle = isset($arguments['taxonomy_handle']) && is_string($arguments['taxonomy_handle']) ? $arguments['taxonomy_handle'] : null;

            $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);

            $notFound = $this->requireResource($blueprint, 'Blueprint', $handle);
            if ($notFound) {
                return $notFound;
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
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to validate blueprint: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Collect all blueprints across standard namespaces, optionally filtered.
     *
     * @param  string|null  $namespace  Filter to a specific namespace
     *
     * @return \Illuminate\Support\Collection<int|string, mixed>
     */
    private function collectAllBlueprints(?string $namespace = null): \Illuminate\Support\Collection
    {
        $blueprints = collect(Blueprint::in('collections')->all())
            ->merge(Blueprint::in('taxonomies')->all())
            ->merge(Blueprint::in('globals')->all())
            ->merge(Blueprint::in('forms')->all())
            ->merge(Blueprint::in('assets')->all())
            ->merge(Blueprint::in('users')->all());

        // Include collection-specific blueprints
        if (! $namespace || $namespace === 'collections') {
            $collectionHandles = Collection::handles()->all();
            foreach ($collectionHandles as $collectionHandle) {
                try {
                    /** @var string $collectionHandle */
                    $collectionBlueprints = collect(Blueprint::in("collections.{$collectionHandle}")->all());
                    $blueprints = $blueprints->merge($collectionBlueprints);
                } catch (\Exception $e) {
                    // Ignore if collection namespace doesn't exist
                }
            }
        }

        if ($namespace) {
            $blueprints = $blueprints->filter(function (mixed $blueprint) use ($namespace): bool {
                /** @var \Statamic\Fields\Blueprint $blueprint */
                return $blueprint->namespace() === $namespace ||
                       ($namespace === 'collections' && str_starts_with($blueprint->namespace() ?? '', 'collections.'));
            });
        }

        return $blueprints;
    }

    /**
     * Get required permissions for action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        // Statamic uses 'configure fields' for all blueprint/fieldset operations
        return ['configure fields'];
    }
}
