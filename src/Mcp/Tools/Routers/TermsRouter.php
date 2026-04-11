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
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Validator as FieldsValidator;
use Statamic\Rules\UniqueTermValue;
use Statamic\Support\Str;

#[Name('statamic-terms')]
#[Description('Manage Statamic taxonomy terms. Use statamic-blueprints get first to understand field structure before create/update. Actions: list, get, create, update, delete.')]
class TermsRouter extends BaseRouter
{
    use ClearsCaches;
    use NormalizesDateFields;
    use SanitizesFieldData;

    protected function getDomain(): string
    {
        return 'terms';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'list (taxonomy; optional: limit, offset), '
                    . 'get (taxonomy, id or slug), '
                    . 'create (taxonomy, data), '
                    . 'update (taxonomy, id or slug, data), '
                    . 'delete (taxonomy, id or slug)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete'])
                ->required(),

            'taxonomy' => JsonSchema::string()
                ->description('Taxonomy handle in snake_case. Required for all actions. Example: "tags", "categories"')
                ->required(),

            'id' => JsonSchema::string()
                ->description('Term ID. Required for get, update, delete when slug is not provided'),

            'slug' => JsonSchema::string()
                ->description('Term slug. Alternative to id for get, update, delete actions'),

            'site' => JsonSchema::string()
                ->description('Site handle for multi-site setups. Defaults to the default site. Example: "default", "en"'),

            'data' => JsonSchema::object()
                ->description(
                    'Term field values. Structure must match the taxonomy blueprint. '
                    . 'Use statamic-blueprints action "get" to see field types and structure before sending data.'
                ),

            'filters' => JsonSchema::object()
                ->description('Filter conditions as key-value pairs. Keys are field handles from the blueprint. Example: {"status": "published"}'),

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

        // Taxonomy is required for all term operations
        if (empty($arguments['taxonomy'])) {
            return $this->createErrorResponse('Taxonomy handle is required for term operations')->toArray();
        }

        if (! is_string($arguments['taxonomy'])) {
            return $this->createErrorResponse('Taxonomy handle must be a string')->toArray();
        }
        $taxonomyHandle = $arguments['taxonomy'];
        if (! Taxonomy::find($taxonomyHandle)) {
            return $this->createErrorResponse("Taxonomy not found: {$taxonomyHandle}")->toArray();
        }

        // Validate action-specific requirements
        $validationError = $this->validateActionRequirements($action, $arguments);
        if ($validationError) {
            return $validationError;
        }

        // Execute action
        return match ($action) {
            'list' => $this->listTerms($arguments),
            'get' => $this->getTerm($arguments),
            'create' => $this->createTerm($arguments),
            'update' => $this->updateTerm($arguments),
            'delete' => $this->deleteTerm($arguments),
            default => $this->createErrorResponse("Action {$action} not supported for terms")->toArray(),
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
        // ID or slug required for specific actions
        if (in_array($action, ['get', 'update', 'delete'])) {
            if (empty($arguments['id']) && empty($arguments['slug'])) {
                return $this->createErrorResponse("Term ID or slug is required for {$action} action")->toArray();
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
        $taxonomy = is_string($arguments['taxonomy'] ?? '') ? ($arguments['taxonomy'] ?? '') : '';

        return match ($action) {
            'list', 'get' => ["view {$taxonomy} terms"],
            'create', 'update', 'delete' => ["edit {$taxonomy} terms"],
            default => [],
        };
    }

    /**
     * List terms with filtering and pagination.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listTerms(array $arguments): array
    {
        $taxonomy = $arguments['taxonomy'];
        $site = $this->resolveSiteHandle($arguments);
        $pagination = $this->getPaginationArgs($arguments, 50, 1000);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];

        try {
            $query = Term::query()
                ->where('taxonomy', $taxonomy)
                ->where('site', $site);

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
            $terms = $query->offset($offset)->limit($limit)->get();

            $data = $terms->map(function ($term) {
                return [
                    'id' => $term->id(),
                    'slug' => $term->slug(),
                    'title' => $term->get('title', $term->slug()),
                    'taxonomy' => $term->taxonomyHandle(),
                    'site' => $term->site()->handle(),
                    'url' => $term->url(),
                ];
            })->all();

            return [
                'terms' => $data,
                'pagination' => $this->buildPaginationMeta($total, $limit, $offset),
                'taxonomy' => $taxonomy,
                'site' => $site,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list terms: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Get a specific term.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getTerm(array $arguments): array
    {
        $taxonomy = $arguments['taxonomy'];
        $site = $this->resolveSiteHandle($arguments);

        try {
            // Try to find by ID first, then by slug
            if (! empty($arguments['id'])) {
                $termId = is_string($arguments['id']) ? $arguments['id'] : '';
                $term = Term::find($termId);
                if ($term && $term->taxonomyHandle() !== $taxonomy) {
                    $term = null; // Term belongs to different taxonomy
                }
            } elseif (! empty($arguments['slug'])) {
                $term = Term::query()
                    ->where('taxonomy', $taxonomy)
                    ->where('slug', $arguments['slug'])
                    ->first();
            } else {
                return $this->createErrorResponse('Term ID or slug is required for get action')->toArray();
            }

            if (! $term) {
                $rawIdentifier = $arguments['id'] ?? $arguments['slug'];
                $identifier = is_string($rawIdentifier) ? $rawIdentifier : '';

                return $this->createErrorResponse("Term not found: {$identifier}")->toArray();
            }

            // Get term for specific site if needed
            if ($term->site()->handle() !== $site) {
                $localizedTerm = $term->in($site);
                if ($localizedTerm) {
                    $term = $localizedTerm;
                }
            }

            return [
                'term' => [
                    'id' => $term->id(),
                    'taxonomy' => $term->taxonomyHandle(),
                    'site' => $term->site()->handle(),
                    'slug' => $term->slug(),
                    'url' => $term->url(),
                    'data' => $term->data()->all(),
                    'entries_count' => $this->getBooleanArgument($arguments, 'include_counts', false)
                        ? $term->queryEntries()->count()
                        : null,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get term: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Create a new term.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createTerm(array $arguments): array
    {
        /** @var \Statamic\Contracts\Taxonomies\Taxonomy $taxonomy */
        $taxonomy = Taxonomy::find(is_string($arguments['taxonomy']) ? $arguments['taxonomy'] : '');
        $site = $this->resolveSiteHandle($arguments);
        /** @var array<string, mixed> $data */
        $data = is_array($arguments['data'] ?? []) ? ($arguments['data'] ?? []) : [];

        try {
            $term = Term::make()
                ->taxonomy($taxonomy);

            // Set slug if provided, otherwise generate from title
            if (! empty($arguments['slug'])) {
                $requestedSlug = $arguments['slug'];
                $requestedSlug = is_string($requestedSlug) ? $requestedSlug : '';

                // Use Statamic's built-in validation for unique slugs
                $slugValidator = Validator::make(['slug' => $requestedSlug], [
                    'slug' => [
                        'required',
                        'string',
                        new UniqueTermValue($taxonomy->handle(), null, $site),
                    ],
                ]);

                if ($slugValidator->fails()) {
                    $errors = $slugValidator->errors()->get('slug');
                    /** @var array<string> $flatErrors */
                    $flatErrors = array_map(fn ($error) => is_string($error) ? $error : implode(', ', (array) $error), $errors);

                    return $this->createErrorResponse('Slug validation failed: ' . implode(', ', $flatErrors))->toArray();
                }

                $term->slug($requestedSlug);
            } elseif (! $term->slug() && isset($data['title'])) {
                $titleValue = $data['title'];
                $term->slug(Str::slug(is_string($titleValue) ? $titleValue : ''));
            }

            // Get blueprint and validate field data
            $blueprint = $term->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot create term: Blueprint not found for this taxonomy. A blueprint is required for data validation.')->toArray();
            }

            if (! empty($data)) {
                // Strip entry-level metadata and coerce values to expected types
                $data = $this->sanitizeIncomingFieldData($blueprint, $data);

                // Normalize date field values to the format Statamic expects
                $data = $this->normalizeDateFields($blueprint, $data);

                // Add slug to data for validation if it's set
                $dataWithSlug = $data;
                if ($term->slug()) {
                    $dataWithSlug['slug'] = $term->slug();
                }

                // Use Statamic's Fields Validator for blueprint-based validation
                try {
                    $fieldsValidator = (new FieldsValidator)
                        ->fields($blueprint->fields()->addValues($dataWithSlug))
                        ->withContext([
                            'term' => $term,
                            'taxonomy' => $taxonomy,
                            'site' => $site,
                        ]);

                    $validatedData = $fieldsValidator->validate();
                    // Remove slug from validated data since it's handled separately
                    unset($validatedData['slug']);
                    $term->data($validatedData);
                } catch (ValidationException $e) {
                    return $this->formatValidationError($e);
                } catch (\Throwable $e) {
                    return $this->createErrorResponse('Failed to process term data: ' . $e->getMessage())->toArray();
                }
            } else {
                $term->data($data);
            }

            $term->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'term' => [
                    'id' => $term->id(),
                    'slug' => $term->slug(),
                    'taxonomy' => $term->taxonomyHandle(),
                    'site' => $term->site()->handle(),
                    'url' => $term->url(),
                    'data' => $term->data()->all(),
                ],
                'created' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to create term: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Update an existing term.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateTerm(array $arguments): array
    {
        $taxonomy = $arguments['taxonomy'];
        $site = $this->resolveSiteHandle($arguments);
        $data = $arguments['data'] ?? [];

        try {
            // Try to find by ID first, then by slug
            if (! empty($arguments['id'])) {
                $termId = is_string($arguments['id']) ? $arguments['id'] : '';
                $term = Term::find($termId);
            } elseif (! empty($arguments['slug'])) {
                $term = Term::query()
                    ->where('taxonomy', $taxonomy)
                    ->where('slug', $arguments['slug'])
                    ->first();
            } else {
                return $this->createErrorResponse('Term ID or slug is required for update action')->toArray();
            }

            if (! $term) {
                $rawIdentifier = $arguments['id'] ?? $arguments['slug'];
                $identifier = is_string($rawIdentifier) ? $rawIdentifier : '';

                return $this->createErrorResponse("Term not found: {$identifier}")->toArray();
            }

            // Get term for specific site
            if ($term->site()->handle() !== $site) {
                $localizedTerm = $term->in($site);
                if ($localizedTerm) {
                    $term = $localizedTerm;
                } else {
                    return $this->createErrorResponse("Term not available in site: {$site}")->toArray();
                }
            }

            // Validate data against blueprint before saving
            $blueprint = $term->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot update term: Blueprint not found. A blueprint is required for data validation.')->toArray();
            }

            /** @var array<string, mixed> $validatedData */
            $validatedData = is_array($data) ? $data : [];

            if (! empty($validatedData)) {
                // Strip entry-level metadata and coerce values to expected types
                $validatedData = $this->sanitizeIncomingFieldData($blueprint, $validatedData);

                // Normalize date field values to the format Statamic expects
                $validatedData = $this->normalizeDateFields($blueprint, $validatedData);

                // Merge new data with existing for full blueprint validation
                // Include slug since blueprint validates it as required
                /** @var array<string, mixed> $mergedData */
                $mergedData = array_merge($term->data()->all(), $validatedData);
                $mergedData['slug'] = $term->slug();

                // Sanitize merged data — existing values may be in legacy formats
                $mergedData = $this->sanitizeStoredFieldDataForValidation($blueprint, $mergedData);

                try {
                    $fieldsValidator = (new FieldsValidator)
                        ->fields($blueprint->fields()->addValues($mergedData))
                        ->withContext([
                            'term' => $term,
                            'taxonomy' => Taxonomy::find(is_string($taxonomy) ? $taxonomy : ''),
                            'site' => $site,
                        ]);

                    $fieldsValidator->validate();
                } catch (ValidationException $e) {
                    return $this->formatValidationError($e);
                } catch (\Throwable $e) {
                    return $this->createErrorResponse('Failed to process term data: ' . $e->getMessage())->toArray();
                }
            }

            $term->merge($validatedData)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'term' => [
                    'id' => $term->id(),
                    'slug' => $term->slug(),
                    'taxonomy' => $term->taxonomyHandle(),
                    'site' => $term->site()->handle(),
                    'url' => $term->url(),
                ],
                'updated' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update term: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * Delete a term.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteTerm(array $arguments): array
    {
        $taxonomy = $arguments['taxonomy'];

        try {
            // Try to find by ID first, then by slug
            if (! empty($arguments['id'])) {
                $termId = is_string($arguments['id']) ? $arguments['id'] : '';
                $term = Term::find($termId);
            } elseif (! empty($arguments['slug'])) {
                $term = Term::query()
                    ->where('taxonomy', $taxonomy)
                    ->where('slug', $arguments['slug'])
                    ->first();
            } else {
                return $this->createErrorResponse('Term ID or slug is required for delete action')->toArray();
            }

            if (! $term) {
                $rawIdentifier = $arguments['id'] ?? $arguments['slug'];
                $identifier = is_string($rawIdentifier) ? $rawIdentifier : '';

                return $this->createErrorResponse("Term not found: {$identifier}")->toArray();
            }

            $termData = [
                'id' => $term->id(),
                'slug' => $term->slug(),
                'taxonomy' => $term->taxonomyHandle(),
                'site' => $term->site()->handle(),
            ];

            // Check for entries using this term
            $entriesCount = $term->queryEntries()->count();
            if ($entriesCount > 0) {
                return $this->createErrorResponse("Cannot delete term: {$entriesCount} entries are using this term")->toArray();
            }

            $term->delete();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'term' => $termData,
                'deleted' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to delete term: {$e->getMessage()}")->toArray();
        }
    }

    public function getActions(): array
    {
        return [
            'list' => 'List terms with filtering and pagination',
            'get' => 'Get specific term with full data',
            'create' => 'Create new term',
            'update' => 'Update existing term',
            'delete' => 'Delete term',
        ];
    }

    public function getTypes(): array
    {
        return [
            'term' => 'Taxonomy classification items',
        ];
    }
}
