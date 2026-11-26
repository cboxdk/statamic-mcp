<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Support\ConditionalFieldsValidator;
use Cboxdk\StatamicMcp\Mcp\Support\SeoProFieldData;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ChecksResourceVersions;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\SanitizesFieldData;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Facades\Blink;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Fields\Field;
use Statamic\Rules\UniqueTermValue;
use Statamic\Support\Str;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Term as StoredTerm;

#[Name('statamic-terms')]
#[Title('Statamic Taxonomy Terms')]
#[Description('Statamic taxonomy terms: tags, categories and other classifications. Field values follow the taxonomy blueprint\'s _format_spec.')]
class TermsRouter extends BaseRouter
{
    use ChecksResourceVersions;
    use SanitizesFieldData;

    /**
     * Term properties a list may filter by besides the blueprint's fields.
     * Taxonomy and site are arguments of their own.
     */
    private const FILTER_PROPERTIES = ['id', 'slug', 'title', 'uri'];

    protected function getDomain(): string
    {
        return 'terms';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [

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
                    . 'Each field\'s shape is in the taxonomy blueprint\'s _format_spec (statamic-blueprints-read get).'
                ),

            'filters' => JsonSchema::object()
                ->description('Exact-match filters for list, as key-value pairs with scalar values. Keys are field handles from the taxonomy\'s blueprints, or one of id, slug, title, uri. Any other key is refused with the list of valid keys. Example: {"slug": "news"}'),

            'expected_version' => JsonSchema::string()
                ->description('Optimistic concurrency for update and delete: the `version` from your last get/create/update. It covers the whole term, every site included. If the stored term changed since (through MCP, the Control Panel or anything else), the write is refused with a version conflict and nothing is saved; get the term again and redo the change. Omit to write unconditionally.'),

            'include_counts' => JsonSchema::boolean()
                ->description('Include how many entries use the term. Default: false'),

            'limit' => JsonSchema::integer()
                ->description('Maximum results to return (default: 50, max: 1000)'),

            'cursor' => JsonSchema::string()
                ->description('next_cursor from the previous page. Keep the other arguments unchanged. Omit to start again.'),
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

        // Statamic's TermPolicy checks the site only on create; the CP keeps
        // every other term screen to sites the user may access through its
        // site selector. MCP has no selector, so every action checks the site
        // it reaches here, the way entries do.
        if ($this->isWebContext() && ($siteError = $this->authorizeTermSite($this->resolveSiteHandle($arguments)))) {
            return $siteError;
        }

        if (! Taxonomy::find($taxonomyHandle)) {
            return $this->missingResource('Taxonomy', $taxonomyHandle, 'taxonomy',
                Taxonomy::all()->map->handle()->all(), $arguments,
            )->toArray();
        }

        // Validate action-specific requirements
        $validationError = $this->validateActionRequirements($action, $arguments);
        if ($validationError) {
            return $validationError;
        }

        if (in_array($action, ['update', 'delete'], true) && array_key_exists('expected_version', $arguments)) {
            $term = $this->findTerm($arguments);
            if ($term !== null) {
                return $this->writeIfVersionMatches(
                    $arguments['expected_version'],
                    'term',
                    $term->id(),
                    function () use ($arguments, $term): string|array {
                        $current = $this->findTerm(['id' => $term->id()] + $arguments);

                        return $current === null
                            ? $this->missingResource('Term', $term->id(), 'id')->toArray()
                            : $this->termVersion($current);
                    },
                    fn (): array => $this->withTermVersion($action, $this->dispatchTermAction($action, $arguments)),
                );
            }
        }

        return $this->withTermVersion($action, $this->dispatchTermAction($action, $arguments));
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function dispatchTermAction(string $action, array $arguments): array
    {
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
     * The term named by id or slug in the requested taxonomy, or null.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function findTerm(array $arguments): ?\Statamic\Contracts\Taxonomies\Term
    {
        if (is_string($arguments['id'] ?? null) && $arguments['id'] !== '') {
            $term = Term::find($arguments['id']);
        } elseif (is_string($arguments['slug'] ?? null) && $arguments['slug'] !== '') {
            $term = Term::query()->where('taxonomy', $arguments['taxonomy'])->where('slug', $arguments['slug'])->first();
        } else {
            return null;
        }

        return $term instanceof \Statamic\Contracts\Taxonomies\Term && $term->taxonomyHandle() === $arguments['taxonomy'] ? $term : null;
    }

    /**
     * The term's stored state as an opaque token: its slug and the data of
     * every site, which Statamic keeps in one file. Any write to the term,
     * from MCP or not, changes it.
     */
    private function termVersion(\Statamic\Contracts\Taxonomies\Term $term): string
    {
        $stored = $term instanceof LocalizedTerm ? $term->term() : $term;
        if (! $stored instanceof StoredTerm) {
            return self::resourceVersion(['id' => $term->id(), 'data' => $term->data()->all()]);
        }

        return self::resourceVersion(['slug' => $stored->slug(), 'data' => $stored->fileData()]);
    }

    protected function currentResourceVersion(array $arguments): ?string
    {
        $term = $this->findTerm($arguments);

        return $term === null ? null : $this->termVersion($term);
    }

    /**
     * Report the term's version after a successful single-term action, so a
     * caller can pass it back as expected_version on its next write.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function withTermVersion(string $action, array $result): array
    {
        if (! in_array($action, ['get', 'create', 'update'], true) || ($result['success'] ?? true) === false
            || ! is_array($result['term'] ?? null) || ! is_string($result['term']['id'] ?? null)) {
            return $result;
        }

        Blink::flush();
        $term = Term::find($result['term']['id']);
        if ($term !== null) {
            $result['term']['version'] = $this->termVersion($term);
            if ($action !== 'get' && is_string($result['term']['site'] ?? null)) {
                $localized = $term->in($result['term']['site']);
                $result['term']['data'] = $localized?->data()->all() ?? [];
            }
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function authorizeTermSite(string $handle): ?array
    {
        $site = Site::get($handle);
        $user = User::current();
        if ($site === null || $user === null || ! $user->can('view', $site)) {
            return $this->createErrorResponse('Permission denied: Cannot access this site', code: ErrorCode::Forbidden)->toArray();
        }

        return null;
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
            'create' => ["create {$taxonomy} terms"],
            'update' => ["edit {$taxonomy} terms"],
            'delete' => ["delete {$taxonomy} terms"],
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
        $query = Term::query()
            ->where('taxonomy', $taxonomy)
            ->where('site', $site);
        $filters = is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [];
        if ($filterError = $this->validateListFilters(
            $filters,
            Taxonomy::find(is_string($taxonomy) ? $taxonomy : '')?->termBlueprints() ?? [],
            self::FILTER_PROPERTIES,
        )) {
            return $filterError;
        }
        foreach ($filters as $field => $value) {
            $query->where((string) $field, $value);
        }
        $total = $query->count();
        $terms = $query->orderBy('id')->offset($offset)->limit($limit)->get();
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
            ...$this->buildPage($data, $total, $offset, $arguments),
            'taxonomy' => $taxonomy,
            'site' => $site,
        ];
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

            return $this->missingResource('Term', $identifier, isset($arguments['id']) ? 'id' : 'slug')->toArray();
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
            return $this->createErrorResponse('Cannot create term: Blueprint not found for this taxonomy. A blueprint is required for data validation.', code: ErrorCode::NotFound)->toArray();
        }
        if (! empty($data)) {
            // Strip entry-level metadata and coerce values to expected types
            $data = $this->sanitizeIncomingFieldData($blueprint, $data);

            // Add slug to data for validation if it's set
            $dataWithSlug = $data;
            if ($term->slug()) {
                $dataWithSlug['slug'] = $term->slug();
            }

            // Use Statamic's Fields Validator for blueprint-based validation,
            // then process through fieldtypes for storage format (matches CP pipeline).
            try {
                $fields = $blueprint->fields()->addValues($dataWithSlug);

                (new ConditionalFieldsValidator)
                    ->fields($fields)
                    ->withConditionValues($dataWithSlug)
                    ->withContext([
                        'term' => $term,
                        'taxonomy' => $taxonomy,
                        'site' => $site,
                    ])
                    ->validate();

                // Process through fieldtypes for storage format
                $processed = $fields->process()->values()->except(['slug']);
                // SEO Pro processes an absent value as false. Leave omitted
                // SEO fields unset so new terms inherit their section defaults.
                foreach ($blueprint->fields()->all() as $handle => $field) {
                    if ($field instanceof Field && $field->type() === 'seo_pro' && ! array_key_exists($handle, $data)) {
                        $processed->forget($handle);
                    }
                }
                $term->data(SeoProFieldData::restoreEmptyValues($blueprint, $processed->all(), $data));
            } catch (ValidationException $e) {
                return $this->formatValidationError($e);
            } catch (\Throwable $e) {
                throw $e;
            }
        } else {
            $term->data($data);
        }
        $this->persistStatamic(fn () => $term->save());
        // Clear relevant caches
        $this->clearCachesAfterWrite(['stache', 'static']);

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
        // Try to find by ID first, then by slug
        if (! empty($arguments['id'])) {
            $termId = is_string($arguments['id']) ? $arguments['id'] : '';
            $term = Term::find($termId);

            if ($term !== null && $term->taxonomyHandle() !== $taxonomy) {
                $term = null;
            }
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

            return $this->missingResource('Term', $identifier, isset($arguments['id']) ? 'id' : 'slug')->toArray();
        }
        // Get term for specific site
        if ($term->site()->handle() !== $site) {
            $localizedTerm = $term->in($site);
            if ($localizedTerm) {
                $term = $localizedTerm;
            } else {
                return $this->createErrorResponse("Term not available in site: {$site}", code: ErrorCode::NotFound, field: 'site')->toArray();
            }
        }
        if (! $term instanceof \Statamic\Contracts\Taxonomies\Term) {
            return $this->createErrorResponse('Unsupported term implementation.')->toArray();
        }
        $term = clone $term;
        // Validate data against blueprint before saving
        $blueprint = $term->blueprint();
        if (! $blueprint) {
            return $this->createErrorResponse('Cannot update term: Blueprint not found. A blueprint is required for data validation.', code: ErrorCode::NotFound)->toArray();
        }
        /** @var array<string, mixed> $validatedData */
        $validatedData = is_array($data) ? $data : [];
        if (! empty($validatedData)) {
            // Strip entry-level metadata and coerce values to expected types
            $incomingSeoData = $validatedData;
            $validatedData = SeoProFieldData::merge($blueprint, $validatedData, $term->data()->all());
            $validatedData = $this->sanitizeIncomingFieldData($blueprint, $validatedData);

            // Merge new data with existing for full blueprint validation,
            // minus stored nulls (see mergeStoredDataForValidation).
            // Include slug since blueprint validates it as required
            $mergedData = $this->mergeStoredDataForValidation($term->data()->all(), $validatedData);
            $mergedData['slug'] = $term->slug();

            // Backward compat: terms saved by MCP prior to v2.1 may have
            // raw strings in structured fields. Safe to remove once all
            // MCP-created content has been re-saved.
            $mergedData = $this->sanitizeStoredFieldDataForValidation($blueprint, $mergedData);

            $validationContext = [
                'term' => $term,
                'taxonomy' => Taxonomy::find(is_string($taxonomy) ? $taxonomy : ''),
                'site' => $site,
            ];

            try {
                (new ConditionalFieldsValidator)
                    ->fields($blueprint->fields()->addValues($mergedData))
                    ->withConditionValues($mergedData)
                    ->withContext($validationContext)
                    ->validate();
            } catch (ValidationException $e) {
                return $this->formatValidationError($e);
            } catch (\TypeError $e) {
                // Third-party fieldtype validation crash — fall back to
                // validating only the incoming fields.
                try {
                    (new ConditionalFieldsValidator)
                        ->fields($blueprint->fields()->addValues($validatedData))
                        ->withConditionValues($mergedData)
                        ->withContext($validationContext)
                        ->validate();
                } catch (ValidationException $inner) {
                    return $this->formatValidationError($inner);
                } catch (\Throwable $inner) {
                    throw $inner;
                }
            } catch (\Throwable $e) {
                throw $e;
            }

            // Process incoming data through fieldtypes for storage format
            $incomingKeys = array_keys($validatedData);
            /** @var array<string, mixed> $processedData */
            $processedData = $blueprint->fields()->addValues($validatedData)
                ->process()->values()
                ->only($incomingKeys)
                ->all();

            $processedData = SeoProFieldData::restoreEmptyValues($blueprint, $processedData, $validatedData);
            $processedData = SeoProFieldData::preserveOmitted($blueprint, $processedData, $incomingSeoData, $term->data()->all());
            $validatedData = $processedData;
        }
        $this->persistStatamic(fn () => $term->merge($validatedData)->save());
        // Clear relevant caches
        $this->clearCachesAfterWrite(['stache', 'static']);

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
        // Try to find by ID first, then by slug
        if (! empty($arguments['id'])) {
            $termId = is_string($arguments['id']) ? $arguments['id'] : '';
            $term = Term::find($termId);

            if ($term !== null && $term->taxonomyHandle() !== $taxonomy) {
                $term = null;
            }
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

            return $this->missingResource('Term', $identifier, isset($arguments['id']) ? 'id' : 'slug')->toArray();
        }
        if ($this->isWebContext() && ($siteError = $this->authorizeTermSite($term->site()->handle()))) {
            return $siteError;
        }
        $termData = [
            'id' => $term->id(),
            'slug' => $term->slug(),
            'taxonomy' => $term->taxonomyHandle(),
            'site' => $term->site()->handle(),
            'url' => $term->url(),
            'data' => $term->data()->all(),
            'version' => $this->termVersion($term),
        ];
        // Check for entries using this term
        $entriesCount = $term->queryEntries()->count();
        if ($entriesCount > 0) {
            return $this->createErrorResponse("Cannot delete term: {$entriesCount} entries are using this term")->toArray();
        }
        $this->persistStatamic(fn () => $term->delete());
        // Clear relevant caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'term' => $termData,
            'deleted' => true,
        ];
    }

    protected function actions(): array
    {
        return [
            RouterAction::read('list', 'List a taxonomy\'s terms', needs: ['taxonomy'], optional: ['site', 'filters', 'limit', 'cursor']),
            RouterAction::read('get', 'Get one term with its data and version', needs: ['taxonomy', 'id|slug'], optional: ['site', 'include_counts']),
            RouterAction::edit('create', 'Create a term', needs: ['taxonomy', 'data'], optional: ['site', 'slug']),
            RouterAction::edit('update', 'Update a term', needs: ['taxonomy', 'id|slug', 'data'], optional: ['site', 'expected_version']),
            RouterAction::delete('delete', 'Delete a term', needs: ['taxonomy', 'id|slug'], optional: ['site', 'expected_version']),
        ];
    }

    public function getTypes(): array
    {
        return [];
    }
}
