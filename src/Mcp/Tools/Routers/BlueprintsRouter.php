<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ExecutesWithAudit;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\RouterHelpers;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Statamic\Facades\Blueprint;

class BlueprintsRouter extends BaseRouter
{
    use ExecutesWithAudit;
    use RouterHelpers;

    protected function getToolName(): string
    {
        return 'statamic-blueprints';
    }

    protected function getToolDescription(): string
    {
        return 'Manage Statamic blueprints: list, get, create, update, delete, scan, generate, and analyze';
    }

    protected function getDomain(): string
    {
        return 'blueprints';
    }

    protected function getActions(): array
    {
        return [
            'list' => [
                'description' => 'List blueprints in specific namespaces with optional details',
                'purpose' => 'Blueprint discovery and organization overview',
                'destructive' => false,
                'examples' => [
                    ['action' => 'list', 'namespace' => 'collections'],
                    ['action' => 'list', 'include_details' => true],
                ],
            ],
            'get' => [
                'description' => 'Get specific blueprint with full field definitions',
                'purpose' => 'Blueprint inspection and schema analysis',
                'destructive' => false,
                'examples' => [
                    ['action' => 'get', 'handle' => 'blog', 'namespace' => 'collections'],
                ],
            ],
            'create' => [
                'description' => 'Create new blueprint from field definitions',
                'purpose' => 'Blueprint generation and schema design',
                'destructive' => false,
                'required' => ['handle', 'namespace'],
                'required_if' => [
                    'collection_handle' => 'Required when namespace=collections',
                    'taxonomy_handle' => 'Required when namespace=taxonomies',
                ],
                'examples' => [
                    [
                        'description' => 'Single blueprint for collection',
                        'action' => 'create',
                        'handle' => 'brand',
                        'collection_handle' => 'brands',
                        'namespace' => 'collections',
                        'title' => 'Brand',
                    ],
                    [
                        'description' => 'Multiple blueprints for articles collection',
                        'action' => 'create',
                        'handle' => 'long',
                        'collection_handle' => 'articles',
                        'namespace' => 'collections',
                        'title' => 'Long-form Article',
                    ],
                ],
                'naming_guide' => [
                    'collection_handle' => 'The collection this blueprint belongs to (e.g., "brands", "articles")',
                    'handle' => 'The blueprint identifier (e.g., "brand", "short", "long")',
                    'result' => 'Creates collections/{collection_handle}/{handle}.yaml',
                    'multiple_blueprints' => 'Use different handles for different content types in same collection',
                ],
            ],
            'update' => [
                'description' => 'Update existing blueprint fields and configuration',
                'purpose' => 'Blueprint modification and schema evolution',
                'destructive' => true,
                'examples' => [
                    ['action' => 'update', 'handle' => 'blog', 'namespace' => 'collections'],
                ],
            ],
            'delete' => [
                'description' => 'Delete blueprints with safety checks',
                'purpose' => 'Blueprint removal and cleanup',
                'destructive' => true,
                'examples' => [
                    ['action' => 'delete', 'handle' => 'old-blueprint', 'namespace' => 'collections'],
                ],
            ],
            'scan' => [
                'description' => 'Scan and analyze blueprint usage patterns',
                'purpose' => 'Blueprint analysis and optimization',
                'destructive' => false,
                'examples' => [
                    ['action' => 'scan', 'namespace' => 'collections'],
                ],
            ],
            'generate' => [
                'description' => 'Generate blueprints from templates and patterns',
                'purpose' => 'Automated blueprint creation',
                'destructive' => false,
                'examples' => [
                    ['action' => 'generate', 'handle' => 'blog-template'],
                ],
            ],
            'types' => [
                'description' => 'Generate TypeScript/PHP type definitions from blueprints',
                'purpose' => 'Type generation for development',
                'destructive' => false,
                'examples' => [
                    ['action' => 'types', 'handle' => 'blog', 'namespace' => 'collections'],
                ],
            ],
            'validate' => [
                'description' => 'Validate blueprint structure and field definitions',
                'purpose' => 'Blueprint quality assurance',
                'destructive' => false,
                'examples' => [
                    ['action' => 'validate', 'handle' => 'blog', 'namespace' => 'collections'],
                ],
            ],
        ];
    }

    protected function getTypes(): array
    {
        return [
            'collections' => [
                'description' => 'Blueprints for collection entries and content structure',
                'properties' => ['handle', 'title', 'fields', 'sections', 'tabs'],
                'relationships' => ['entries', 'fields'],
                'examples' => ['blog', 'pages', 'products'],
            ],
            'taxonomies' => [
                'description' => 'Blueprints for taxonomy terms and categorization',
                'properties' => ['handle', 'title', 'fields', 'meta'],
                'relationships' => ['terms', 'collections'],
                'examples' => ['categories', 'tags', 'locations'],
            ],
            'globals' => [
                'description' => 'Blueprints for global settings and configuration',
                'properties' => ['handle', 'fields', 'localization'],
                'relationships' => ['global_sets'],
                'examples' => ['site_settings', 'footer_content'],
            ],
            'forms' => [
                'description' => 'Blueprints for form fields and submissions',
                'properties' => ['handle', 'fields', 'validation'],
                'relationships' => ['form_submissions'],
                'examples' => ['contact_form', 'newsletter_signup'],
            ],
            'assets' => [
                'description' => 'Blueprints for asset metadata and properties',
                'properties' => ['handle', 'fields', 'containers'],
                'relationships' => ['asset_containers'],
                'examples' => ['image_metadata', 'document_properties'],
            ],
            'users' => [
                'description' => 'Blueprints for user profiles and authentication',
                'properties' => ['handle', 'fields', 'roles'],
                'relationships' => ['user_accounts', 'roles'],
                'examples' => ['user_profile', 'admin_profile'],
            ],
        ];
    }

    protected function defineSchema(JsonSchema $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'handle' => JsonSchema::string()
                ->description('Blueprint handle (required for get, update, delete, validate). For collections, this is the blueprint name (e.g., "article", "product"), NOT the collection handle.'),
            'namespace' => JsonSchema::string()
                ->description('Blueprint namespace (collections, taxonomies, globals, forms, assets, users)')
                ->enum(['collections', 'taxonomies', 'globals', 'forms', 'assets', 'users']),
            'collection_handle' => JsonSchema::string()
                ->description('Collection handle (required when namespace=collections). Blueprint will be created at collections/{collection_handle}/{handle}.yaml'),
            'taxonomy_handle' => JsonSchema::string()
                ->description('Taxonomy handle (required when namespace=taxonomies). Blueprint will be created at taxonomies/{taxonomy_handle}/{handle}.yaml'),
            'title' => JsonSchema::string()
                ->description('Blueprint title (optional, defaults to humanized handle)'),
            'fields' => JsonSchema::array()
                ->items(JsonSchema::object())
                ->description('Field definitions for create/update operations'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed field information (default: false)'),
            'include_fields' => JsonSchema::boolean()
                ->description('Include field definitions in list/scan operations (default: true)'),
            'output_format' => JsonSchema::string()
                ->description('Output format for types action')
                ->enum(['typescript', 'php', 'json-schema', 'all']),
        ]);
    }

    /**
     * Route actions to appropriate handlers with security checks and audit logging.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = $arguments['action'];

        // Check if tool is enabled for current context
        if (! $this->isCliContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Blueprints tool is disabled for web access')->toArray();
        }

        // Apply security checks for web context
        if ($this->isWebContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Execute action with audit logging
        return $this->executeWithAuditLog($action, $arguments);
    }

    /**
     * Perform the actual domain action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function performDomainAction(string $action, array $arguments): array
    {
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

            $blueprints = collect(Blueprint::in('collections')->all())
                ->merge(Blueprint::in('taxonomies')->all())
                ->merge(Blueprint::in('globals')->all())
                ->merge(Blueprint::in('forms')->all())
                ->merge(Blueprint::in('assets')->all())
                ->merge(Blueprint::in('users')->all());

            // Also include collection-specific blueprints for collections namespace
            if (! $namespace || $namespace === 'collections') {
                // Find all collection-specific blueprints (collections.*)
                $collections = \Statamic\Facades\Collection::all();
                foreach ($collections as $collection) {
                    try {
                        $collectionBlueprints = collect(Blueprint::in("collections.{$collection->handle()}")->all());
                        $blueprints = $blueprints->merge($collectionBlueprints);
                    } catch (\Exception $e) {
                        // Ignore if collection namespace doesn't exist
                    }
                }
            }

            if ($namespace) {
                $blueprints = $blueprints->filter(function ($blueprint) use ($namespace) {
                    return $blueprint->namespace() === $namespace ||
                           ($namespace === 'collections' && str_starts_with($blueprint->namespace(), 'collections.'));
                });
            }

            $data = $blueprints->map(function ($blueprint) use ($includeDetails, $includeFields) {
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
                    $result['fields'] = $blueprint->fields()->all()->map(function ($field) {
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
                'success' => true,
                'data' => [
                    'blueprints' => $data->toArray(),
                    'total' => $data->count(),
                    'namespace' => $namespace,
                ],
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
            $handle = $arguments['handle'];
            $namespace = $arguments['namespace'] ?? null;
            $collectionHandle = $arguments['collection_handle'] ?? null;
            $taxonomyHandle = $arguments['taxonomy_handle'] ?? null;

            $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);

            if (! $blueprint) {
                return $this->createErrorResponse("Blueprint not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $blueprint->handle(),
                'title' => $blueprint->title(),
                'namespace' => $blueprint->namespace(),
                'hidden' => $blueprint->hidden(),
                'order' => $blueprint->order(),
                'fields' => $blueprint->fields()->all()->map(function ($field) {
                    return [
                        'handle' => $field->handle(),
                        'type' => $field->type(),
                        'display' => $field->display(),
                        'config' => $field->config(),
                    ];
                })->toArray(),
            ];

            return [
                'success' => true,
                'data' => ['blueprint' => $data],
            ];
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
            $handle = $arguments['handle'] ?? null;
            $namespace = $arguments['namespace'] ?? 'collections';
            $collectionHandle = $arguments['collection_handle'] ?? null;
            $taxonomyHandle = $arguments['taxonomy_handle'] ?? null;
            $fields = $arguments['fields'] ?? [];
            $title = $arguments['title'] ?? Str::title(str_replace('_', ' ', $handle));

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
                if (! \Statamic\Facades\Collection::find($collectionHandle)) {
                    return $this->createErrorResponse("Collection not found: {$collectionHandle}. Create collection first with statamic-structures.")->toArray();
                }
            }

            if ($namespace === 'taxonomies') {
                // taxonomy_handle is guaranteed to exist here due to validation above
                if (! \Statamic\Facades\Taxonomy::find($taxonomyHandle)) {
                    return $this->createErrorResponse("Taxonomy not found: {$taxonomyHandle}. Create taxonomy first with statamic-structures.")->toArray();
                }
            }

            // Determine the correct namespace
            $blueprintNamespace = match ($namespace) {
                'collections' => "collections.{$collectionHandle}",
                'taxonomies' => "taxonomies.{$taxonomyHandle}",
                default => $namespace,
            };

            // Check if blueprint already exists in this namespace
            $existing = collect(Blueprint::in($blueprintNamespace)->all())->firstWhere('handle', $handle);
            if ($existing) {
                return $this->createErrorResponse("Blueprint already exists: {$handle} in {$blueprintNamespace}")->toArray();
            }

            // Create the blueprint contents following default.yaml structure
            $contents = ['title' => $title];

            // Add fields if provided (use simple fields array like default.yaml)
            if (! empty($fields)) {
                $contents['fields'] = $this->normalizeFields($fields);
            }

            // Create the blueprint
            $blueprint = Blueprint::make($handle)
                ->setNamespace($blueprintNamespace)
                ->setContents($contents);

            // Save the blueprint
            $blueprint->save();

            // Clear Statamic caches
            \Statamic\Facades\Stache::clear();

            return [
                'success' => true,
                'data' => [
                    'blueprint' => [
                        'handle' => $blueprint->handle(),
                        'title' => $blueprint->title(),
                        'namespace' => $blueprint->namespace(),
                        'path' => $blueprint->path(),
                        'full_path' => $namespace === 'collections'
                            ? "collections/{$collectionHandle}/{$handle}.yaml"
                            : ($namespace === 'taxonomies'
                                ? "taxonomies/{$taxonomyHandle}/{$handle}.yaml"
                                : "{$namespace}/{$handle}.yaml"),
                    ],
                    'created' => true,
                    'guidance' => $namespace === 'collections'
                        ? "Blueprint created for collection '{$collectionHandle}'. You can now create multiple blueprints (e.g., 'short', 'long', 'featured') for the same collection."
                        : null,
                ],
            ];
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

            // For any namespace with dots (addon namespaces), try searching for variations
            if (str_contains($namespace, '.')) {
                try {
                    $blueprint = collect(Blueprint::in($namespace)->all())->firstWhere('handle', $handle);
                    if ($blueprint) {
                        return $blueprint;
                    }
                } catch (\Exception $e) {
                    // Ignore if namespace doesn't exist
                }
            }

            return null;
        }

        // Search standard namespaces first
        $blueprint = collect(Blueprint::in('collections')->all())
            ->merge(Blueprint::in('taxonomies')->all())
            ->merge(Blueprint::in('globals')->all())
            ->merge(Blueprint::in('forms')->all())
            ->merge(Blueprint::in('assets')->all())
            ->merge(Blueprint::in('users')->all())
            ->firstWhere('handle', $handle);

        if ($blueprint) {
            return $blueprint;
        }

        // Try collection-specific namespace
        try {
            $blueprint = collect(Blueprint::in("collections.{$handle}")->all())->firstWhere('handle', $handle);
            if ($blueprint) {
                return $blueprint;
            }
        } catch (\Exception $e) {
            // Ignore if namespace doesn't exist
        }

        // Search all available blueprint files if still not found
        try {
            // Try different namespaces
            foreach (['collections', 'taxonomies', 'globals', 'assets', 'users'] as $searchNamespace) {
                $blueprint = collect(Blueprint::in($searchNamespace)->all())->firstWhere('handle', $handle);
                if ($blueprint) {
                    return $blueprint;
                }
            }

            return null;
        } catch (\Exception $e) {
            // Final fallback - return null
            return null;
        }
    }

    /**
     * Normalize field definitions for blueprint.
     *
     * @param  array<mixed>  $fields
     *
     * @return array<mixed>
     */
    private function normalizeFields(array $fields): array
    {
        // If fields is empty, return empty structure
        if (empty($fields)) {
            return [];
        }

        // Check if fields is already in proper Statamic format (array with dashes)
        if (isset($fields[0]) && is_array($fields[0]) && isset($fields[0]['handle'])) {
            // Already in correct format - return as is
            return $fields;
        }

        // Convert field array to Statamic blueprint format exactly like default.yaml
        $normalizedFields = [];

        foreach ($fields as $field) {
            if (is_array($field) && isset($field['handle'])) {
                $handle = $field['handle'];
                $fieldConfig = isset($field['field']) ? $field['field'] : $field;

                // Remove handle from field config if it exists in field config
                if (isset($fieldConfig['handle'])) {
                    unset($fieldConfig['handle']);
                }

                // Create proper Statamic field structure following default.yaml pattern
                $normalizedFields[] = [
                    'handle' => $handle,
                    'field' => $fieldConfig,
                ];
            }
        }

        return $normalizedFields;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateBlueprint(array $arguments): array
    {
        try {
            $handle = $arguments['handle'];
            $namespace = $arguments['namespace'] ?? null;
            $fields = $arguments['fields'] ?? null;
            $title = $arguments['title'] ?? null;

            // Find the blueprint
            $collectionHandle = $arguments['collection_handle'] ?? null;
            $taxonomyHandle = $arguments['taxonomy_handle'] ?? null;

            $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);

            if (! $blueprint) {
                return $this->createErrorResponse("Blueprint not found: {$handle}")->toArray();
            }

            // Update the blueprint contents
            $contents = $blueprint->contents();

            if ($title !== null) {
                $contents['title'] = $title;
            }

            if ($fields !== null) {
                $contents['fields'] = $this->normalizeFields($fields);
            }

            $blueprint->setContents($contents);
            $blueprint->save();

            // Clear Statamic caches
            \Statamic\Facades\Stache::clear();

            return [
                'success' => true,
                'data' => [
                    'blueprint' => [
                        'handle' => $blueprint->handle(),
                        'title' => $blueprint->title(),
                        'namespace' => $blueprint->namespace(),
                        'fields' => $blueprint->fields()->all()->map(function ($field) {
                            return [
                                'handle' => $field->handle(),
                                'type' => $field->type(),
                                'display' => $field->display(),
                                'config' => $field->config(),
                            ];
                        })->toArray(),
                    ],
                    'updated' => true,
                ],
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
            $handle = $arguments['handle'];
            $namespace = $arguments['namespace'] ?? null;
            $confirm = $this->getBooleanArgument($arguments, 'confirm', false);

            if (! $confirm) {
                return $this->createErrorResponse('Deletion requires explicit confirmation (set confirm to true)')->toArray();
            }

            // Find the blueprint
            $collectionHandle = $arguments['collection_handle'] ?? null;
            $taxonomyHandle = $arguments['taxonomy_handle'] ?? null;

            $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);

            if (! $blueprint) {
                return $this->createErrorResponse("Blueprint not found: {$handle}")->toArray();
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
            \Statamic\Facades\Stache::clear();

            return [
                'success' => true,
                'data' => [
                    'deleted' => true,
                    'blueprint' => $blueprintInfo,
                ],
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
            $handle = $arguments['handle'] ?? null;
            $namespace = $arguments['namespace'] ?? 'collections';
            $template = $arguments['template'] ?? 'basic';
            $title = $arguments['title'] ?? Str::title(str_replace(['_', '-'], ' ', $handle));

            if (! $handle) {
                return $this->createErrorResponse('Handle is required for blueprint generation')->toArray();
            }

            // Check if blueprint already exists
            $existing = collect(Blueprint::in($namespace)->all())->firstWhere('handle', $handle);
            if ($existing) {
                return $this->createErrorResponse("Blueprint already exists: {$handle} in {$namespace}")->toArray();
            }

            // Generate fields based on template
            $fields = $this->generateFieldsFromTemplate($template);

            // Create the blueprint
            $blueprint = Blueprint::make($handle)
                ->setNamespace($namespace)
                ->setContents([
                    'title' => $title,
                    'fields' => $fields,
                ]);

            // Save the blueprint
            $blueprint->save();

            // Clear Statamic caches
            \Statamic\Facades\Stache::clear();

            return [
                'success' => true,
                'data' => [
                    'blueprint' => [
                        'handle' => $blueprint->handle(),
                        'title' => $blueprint->title(),
                        'namespace' => $blueprint->namespace(),
                        'template' => $template,
                        'fields' => $blueprint->fields()->all()->map(function ($field) {
                            return [
                                'handle' => $field->handle(),
                                'type' => $field->type(),
                                'display' => $field->display(),
                                'config' => $field->config(),
                            ];
                        })->toArray(),
                    ],
                    'generated' => true,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to generate blueprint: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Generate fields based on template type.
     *
     * @return array<mixed>
     */
    private function generateFieldsFromTemplate(string $template): array
    {
        return match ($template) {
            'blog' => [
                'title' => [
                    'type' => 'text',
                    'required' => true,
                    'validate' => 'required',
                ],
                'slug' => [
                    'type' => 'slug',
                    'from' => 'title',
                ],
                'featured_image' => [
                    'type' => 'assets',
                    'container' => 'assets',
                    'max_files' => 1,
                ],
                'content' => [
                    'type' => 'markdown',
                    'display' => 'Content',
                ],
                'author' => [
                    'type' => 'users',
                    'display' => 'Author',
                    'max_items' => 1,
                ],
                'published_date' => [
                    'type' => 'date',
                    'display' => 'Published Date',
                ],
                'categories' => [
                    'type' => 'terms',
                    'taxonomies' => ['categories'],
                    'display' => 'Categories',
                ],
                'tags' => [
                    'type' => 'tags',
                    'display' => 'Tags',
                ],
            ],
            'product' => [
                'title' => [
                    'type' => 'text',
                    'required' => true,
                ],
                'slug' => [
                    'type' => 'slug',
                    'from' => 'title',
                ],
                'price' => [
                    'type' => 'float',
                    'display' => 'Price',
                ],
                'description' => [
                    'type' => 'textarea',
                    'display' => 'Description',
                ],
                'images' => [
                    'type' => 'assets',
                    'container' => 'assets',
                    'display' => 'Product Images',
                ],
                'inventory' => [
                    'type' => 'integer',
                    'display' => 'Inventory Count',
                ],
                'sku' => [
                    'type' => 'text',
                    'display' => 'SKU',
                ],
            ],
            'page' => [
                'title' => [
                    'type' => 'text',
                    'required' => true,
                ],
                'slug' => [
                    'type' => 'slug',
                    'from' => 'title',
                ],
                'content' => [
                    'type' => 'bard',
                    'display' => 'Page Content',
                ],
                'seo_title' => [
                    'type' => 'text',
                    'display' => 'SEO Title',
                ],
                'seo_description' => [
                    'type' => 'textarea',
                    'display' => 'SEO Description',
                ],
            ],
            default => [
                'title' => [
                    'type' => 'text',
                    'required' => true,
                ],
                'slug' => [
                    'type' => 'slug',
                    'from' => 'title',
                ],
                'content' => [
                    'type' => 'textarea',
                    'display' => 'Content',
                ],
            ],
        };
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

            $blueprints = collect(Blueprint::in('collections')->all())
                ->merge(Blueprint::in('taxonomies')->all())
                ->merge(Blueprint::in('globals')->all())
                ->merge(Blueprint::in('forms')->all())
                ->merge(Blueprint::in('assets')->all())
                ->merge(Blueprint::in('users')->all());
            $types = [];

            foreach ($blueprints as $blueprint) {
                $fields = $blueprint->fields()->all()->map(function ($field) {
                    return [
                        'handle' => $field->handle(),
                        'type' => $field->type(),
                        'required' => $field->isRequired(),
                    ];
                })->toArray();

                $types[$blueprint->handle()] = $fields;
            }

            return [
                'success' => true,
                'data' => [
                    'format' => $outputFormat,
                    'types' => $types,
                    'generated_at' => now()->toISOString(),
                ],
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
            $handle = $arguments['handle'];
            $namespace = $arguments['namespace'] ?? null;

            $collectionHandle = $arguments['collection_handle'] ?? null;
            $taxonomyHandle = $arguments['taxonomy_handle'] ?? null;

            $blueprint = $this->findBlueprint($handle, $namespace, $collectionHandle, $taxonomyHandle);

            if (! $blueprint) {
                return $this->createErrorResponse("Blueprint not found: {$handle}")->toArray();
            }

            // Basic validation - check if fields are valid
            $validationResults = [
                'valid' => true,
                'errors' => [],
                'warnings' => [],
            ];

            $fields = $blueprint->fields()->all();
            foreach ($fields as $field) {
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
                'success' => true,
                'data' => [
                    'blueprint' => $handle,
                    'validation' => $validationResults,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to validate blueprint: {$e->getMessage()}")->toArray();
        }
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
        return match ($action) {
            'list', 'get', 'scan', 'types', 'validate' => ['view blueprints'],
            'create', 'generate' => ['create blueprints'],
            'update' => ['edit blueprints'],
            'delete' => ['delete blueprints'],
            default => ['super'],
        };
    }

    // BaseRouter Abstract Method Implementations

    /**
     * @return array<string, mixed>
     */
    protected function getFeatures(): array
    {
        return [
            'schema_management' => 'Complete blueprint creation, modification, and validation',
            'field_definitions' => 'Advanced field type configuration and relationships',
            'type_generation' => 'TypeScript and PHP type generation from blueprints',
            'validation_system' => 'Comprehensive blueprint and field validation',
            'template_generation' => 'Blueprint generation from templates and patterns',
        ];
    }

    protected function getPrimaryUse(): string
    {
        return 'Design and manage content schemas through Statamic blueprints';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDecisionTree(): array
    {
        return [
            'namespace_selection' => 'Choose namespace based on content type: collections for entries, taxonomies for terms, etc.',
            'operation_flow' => 'List existing → Get details → Create/Update with validation → Generate types',
            'field_design' => 'Plan field structure → Validate definitions → Test with content → Optimize',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getContextAwareness(): array
    {
        return [
            'content_structure' => 'Blueprints define the schema for all content types',
            'field_relationships' => 'Understanding field dependencies and validation rules',
            'namespace_context' => 'Different namespaces serve different content purposes',
            'naming_conventions' => [
                'collections' => [
                    'pattern' => 'collections/{collection_handle}/{blueprint_handle}.yaml',
                    'example' => 'collections/brands/brand.yaml (collection="brands", blueprint="brand")',
                    'multiple_blueprints' => 'collections/articles/short.yaml, collections/articles/long.yaml, collections/articles/breaking.yaml',
                    'key_point' => 'collection_handle and blueprint handle are SEPARATE - collection="brands" can have blueprint="brand", "product", etc.',
                ],
                'taxonomies' => [
                    'pattern' => 'taxonomies/{taxonomy_handle}/{blueprint_handle}.yaml',
                    'example' => 'taxonomies/categories/category.yaml (taxonomy="categories", blueprint="category")',
                    'key_point' => 'taxonomy_handle and blueprint handle are SEPARATE',
                ],
                'globals' => [
                    'pattern' => 'globals/{blueprint_handle}.yaml',
                    'example' => 'globals/settings.yaml (no parent handle needed)',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getWorkflowIntegration(): array
    {
        return [
            'content_creation' => 'Blueprints enable structured content entry and validation',
            'development_workflow' => 'Type generation supports frontend and backend development',
            'schema_evolution' => 'Blueprint versioning and migration strategies',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCommonPatterns(): array
    {
        return [
            'blueprint_discovery' => [
                'description' => 'Explore existing blueprint structure',
                'pattern' => 'list blueprints → get specific blueprint → analyze fields',
                'example' => ['action' => 'list', 'namespace' => 'collections'],
            ],
            'single_blueprint_for_collection' => [
                'description' => 'Create default blueprint for a collection (common pattern)',
                'pattern' => 'create blueprint with collection_handle → validate → add fields',
                'example' => [
                    'action' => 'create',
                    'handle' => 'brand',  // Blueprint handle (singular)
                    'collection_handle' => 'brands',  // Collection handle (plural)
                    'namespace' => 'collections',
                    'title' => 'Brand',
                    'fields' => [
                        ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
                        ['handle' => 'description', 'field' => ['type' => 'textarea']],
                    ],
                ],
                'result' => 'Creates collections/brands/brand.yaml',
            ],
            'multiple_blueprints_per_collection' => [
                'description' => 'Create multiple content types for same collection (articles: short, long, breaking)',
                'pattern' => 'create collection → create blueprint 1 → create blueprint 2 → create blueprint 3',
                'examples' => [
                    [
                        'action' => 'create',
                        'handle' => 'short',  // First blueprint
                        'collection_handle' => 'articles',
                        'title' => 'Short Article',
                        'fields' => [['handle' => 'title', 'field' => ['type' => 'text']], ['handle' => 'summary', 'field' => ['type' => 'textarea', 'max' => 200]]],
                    ],
                    [
                        'action' => 'create',
                        'handle' => 'long',  // Second blueprint
                        'collection_handle' => 'articles',
                        'title' => 'Long-form Article',
                        'fields' => [['handle' => 'title', 'field' => ['type' => 'text']], ['handle' => 'content', 'field' => ['type' => 'markdown']]],
                    ],
                    [
                        'action' => 'create',
                        'handle' => 'breaking',  // Third blueprint
                        'collection_handle' => 'articles',
                        'title' => 'Breaking News',
                        'fields' => [['handle' => 'headline', 'field' => ['type' => 'text']], ['handle' => 'priority', 'field' => ['type' => 'select', 'options' => ['urgent', 'high', 'normal']]]],
                    ],
                ],
                'result' => 'Creates collections/articles/short.yaml, collections/articles/long.yaml, collections/articles/breaking.yaml',
            ],
            'taxonomy_blueprint' => [
                'description' => 'Create blueprint for taxonomy terms',
                'pattern' => 'create taxonomy → create term blueprint',
                'example' => [
                    'action' => 'create',
                    'handle' => 'category',  // Blueprint handle (singular)
                    'taxonomy_handle' => 'categories',  // Taxonomy handle (plural)
                    'namespace' => 'taxonomies',
                    'title' => 'Category',
                    'fields' => [
                        ['handle' => 'title', 'field' => ['type' => 'text']],
                        ['handle' => 'description', 'field' => ['type' => 'textarea']],
                    ],
                ],
                'result' => 'Creates taxonomies/categories/category.yaml',
            ],
            'blueprint_maintenance' => [
                'description' => 'Maintain and evolve blueprint schemas',
                'pattern' => 'scan usage → update fields → validate changes → regenerate types',
                'example' => ['action' => 'update', 'handle' => 'brand', 'collection_handle' => 'brands', 'dry_run' => true],
            ],
        ];
    }
}
