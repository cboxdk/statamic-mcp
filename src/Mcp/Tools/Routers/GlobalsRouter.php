<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Support\ConditionalFieldsValidator;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ChecksResourceVersions;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\SanitizesFieldData;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Statamic\Contracts\Globals\Variables;
use Statamic\Facades\Blink;
use Statamic\Facades\GlobalSet;

#[Name('statamic-globals')]
#[Title('Statamic Global Sets')]
#[Description('Statamic global set values: site-wide content such as footer text or contact details, per site. Field values follow the global set blueprint\'s _format_spec.')]
class GlobalsRouter extends BaseRouter
{
    use ChecksResourceVersions;
    use SanitizesFieldData;

    protected function getDomain(): string
    {
        return 'globals';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [

            'handle' => JsonSchema::string()
                ->description('Global set handle in snake_case. Required for get and update. Example: "site_settings", "seo"'),

            'site' => JsonSchema::string()
                ->description('Site handle for multi-site setups. Defaults to the default site. Example: "default", "en"'),

            'data' => JsonSchema::object()
                ->description(
                    'Global set field values. Structure must match the global set blueprint. '
                    . 'Each field\'s shape is in the global set blueprint\'s _format_spec (statamic-blueprints-read get).'
                ),

            'expected_version' => JsonSchema::string()
                ->description('Optimistic concurrency for update: the `version` from your last get/update of this global set in this site. If its stored values changed since (through MCP, the Control Panel or anything else), the update is refused with a version conflict and nothing is saved; get the global set again and redo the change. Omit to write unconditionally.'),

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

        // Validate action-specific requirements
        $validationError = $this->validateActionRequirements($action, $arguments);
        if ($validationError) {
            return $validationError;
        }

        if ($action === 'update' && array_key_exists('expected_version', $arguments)) {
            $handle = $this->globalSetHandle($arguments);
            $site = $this->resolveSiteHandle($arguments);

            return $this->writeIfVersionMatches(
                $arguments['expected_version'],
                'global set',
                $handle . '@' . $site,
                function () use ($handle, $site): string|array {
                    $variables = GlobalSet::find($handle)?->in($site);

                    return $variables === null
                        ? $this->createErrorResponse("Global set not available in site: {$site}", code: ErrorCode::NotFound, field: 'site')->toArray()
                        : $this->globalVersion($variables);
                },
                fn (): array => $this->withGlobalVersion($this->updateGlobal($arguments), $handle, $site),
            );
        }

        return match ($action) {
            'list' => $this->listGlobals($arguments),
            'get' => $this->withGlobalVersion($this->getGlobal($arguments), $this->globalSetHandle($arguments), $this->resolveSiteHandle($arguments)),
            'update' => $this->withGlobalVersion($this->updateGlobal($arguments), $this->globalSetHandle($arguments), $this->resolveSiteHandle($arguments)),
            default => $this->createErrorResponse("Action {$action} not supported for globals")->toArray(),
        };
    }

    /** @param  array<string, mixed>  $arguments */
    private function globalSetHandle(array $arguments): string
    {
        $handle = $arguments['handle'] ?? null;

        return is_string($handle) ? $handle : '';
    }

    /**
     * One site's stored values as an opaque token. Statamic keeps each site's
     * values in their own file, so a write in another site does not change it.
     */
    private function globalVersion(Variables $variables): string
    {
        return self::resourceVersion(['data' => $variables->data()->all()]);
    }

    protected function currentResourceVersion(array $arguments): ?string
    {
        $variables = GlobalSet::find($this->globalSetHandle($arguments))?->in($this->resolveSiteHandle($arguments));

        return $variables === null ? null : $this->globalVersion($variables);
    }

    /**
     * Report the site's version after a successful get or update, so a caller
     * can pass it back as expected_version on its next update.
     *
     * @param  array<string, mixed>  $result
     *
     * @return array<string, mixed>
     */
    private function withGlobalVersion(array $result, string $handle, string $site): array
    {
        if (($result['success'] ?? true) === false || ! is_array($result['global'] ?? null)) {
            return $result;
        }

        Blink::flush();
        $globalSet = GlobalSet::find($handle);
        $variables = $globalSet?->in($site);
        if ($variables !== null) {
            $result['global']['localized'] = $globalSet->sites()->count() > 1;
            $result['global']['sites'] = $globalSet->sites()->all();
            $blueprint = $globalSet->blueprint();
            $result['global']['blueprint'] = $blueprint === null ? null : ['handle' => $blueprint->handle(), 'title' => $blueprint->title()];
            $result['global']['data'] = $variables->data()->all();
            $result['global']['version'] = $this->globalVersion($variables);
        }

        return $result;
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
        // Global set handle required for get and update operations
        if (in_array($action, ['get', 'update'])) {
            $globalSetHandle = $this->globalSetHandle($arguments);
            if (! GlobalSet::find($globalSetHandle)) {
                $site = $this->resolveSiteHandle($arguments);

                return $this->missingResource('Global set', $globalSetHandle, candidates: GlobalSet::all()->filter(fn ($set): bool => $set instanceof \Statamic\Contracts\Globals\GlobalSet && ($this->isCliContext()
                        || (($variables = $set->in($site)) !== null && Gate::forUser(auth()->user())->allows('edit', $variables))))->map->handle()->all(),
                    arguments: $arguments,
                )->toArray();
            }
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
        // Statamic scopes global permissions per set: 'edit {handle} globals'
        $rawHandle = $arguments['handle'] ?? null;
        $handle = is_string($rawHandle) ? $rawHandle : '';

        return match ($action) {
            'list' => ['configure globals'],
            'get', 'update' => $handle !== '' ? ["edit {$handle} globals"] : ['configure globals'],
            default => [],
        };
    }

    /**
     * List global sets with their current values.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listGlobals(array $arguments): array
    {
        $site = $this->resolveSiteHandle($arguments);
        $pagination = $this->getPaginationArgs($arguments, 50, 1000);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];
        $globalSets = collect(GlobalSet::all()->all())->filter(function ($set) use ($site) {
            if (! $set instanceof \Statamic\Contracts\Globals\GlobalSet) {
                return false;
            }

            return app(ResourcePolicy::class)->canAccess('globals', $set->handle(), 'read')
                && (! $this->isWebContext() || ($set->in($site) !== null && Gate::forUser(auth()->user())->allows('edit', $set->in($site))));
        });
        $total = $globalSets->count();
        $paginatedSets = $globalSets->sortBy->handle()->skip($offset)->take($limit);
        $data = $paginatedSets->map(function ($globalSet) use ($site): array {
            /** @var \Statamic\Contracts\Globals\GlobalSet $globalSet */
            $variables = $globalSet->in($site);

            return [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'site' => $site,
                'localized' => $globalSet->sites()->count() > 1,
                'sites' => $globalSet->sites()->all(),
                'has_values' => $variables ? $variables->data()->isNotEmpty() : false,
            ];
        })->all();

        return [
            ...$this->buildPage($data, $total, $offset, $arguments),
            'site' => $site,
        ];
    }

    /**
     * Get a specific global set with its values.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getGlobal(array $arguments): array
    {
        $rawHandle = $arguments['handle'] ?? null;
        if (! is_string($rawHandle)) {
            return $this->createErrorResponse('Global set handle must be a string')->toArray();
        }
        $globalSetHandle = $rawHandle;
        $site = $this->resolveSiteHandle($arguments);
        /** @var \Statamic\Contracts\Globals\GlobalSet|null $globalSet */
        $globalSet = GlobalSet::find($globalSetHandle);
        if ($globalSet === null) {
            return $this->missingResource('Global set', $globalSetHandle)->toArray();
        }
        $variables = $globalSet->in($site);
        if ($this->isWebContext() && ($variables === null || ! Gate::forUser(auth()->user())->allows('edit', $variables))) {
            return $this->createErrorResponse('Permission denied: Cannot access this global set site.', code: ErrorCode::Forbidden)->toArray();
        }

        return [
            'global' => [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'site' => $site,
                'localized' => $globalSet->sites()->count() > 1,
                'sites' => $globalSet->sites()->all(),
                'data' => $variables ? $variables->data()->all() : [],
                'blueprint' => $globalSet->blueprint() ? [
                    'handle' => $globalSet->blueprint()->handle(),
                    'title' => $globalSet->blueprint()->title(),
                ] : null,
            ],
        ];
    }

    /**
     * Update values in a global set.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateGlobal(array $arguments): array
    {
        $rawHandle = $arguments['handle'] ?? null;
        if (! is_string($rawHandle)) {
            return $this->createErrorResponse('Global set handle must be a string')->toArray();
        }
        $globalSetHandle = $rawHandle;
        $site = $this->resolveSiteHandle($arguments);
        $data = is_array($arguments['data'] ?? []) ? ($arguments['data'] ?? []) : [];
        /** @var \Statamic\Contracts\Globals\GlobalSet|null $globalSet */
        $globalSet = GlobalSet::find($globalSetHandle);
        if ($globalSet === null) {
            return $this->missingResource('Global set', $globalSetHandle)->toArray();
        }
        $variables = $globalSet->in($site);
        if ($this->isWebContext() && ($variables === null || ! Gate::forUser(auth()->user())->allows('edit', $variables))) {
            return $this->createErrorResponse('Permission denied: Cannot access this global set site.', code: ErrorCode::Forbidden)->toArray();
        }
        if (! $variables) {
            return $this->createErrorResponse("Global set not available in site: {$site}", code: ErrorCode::NotFound, field: 'site')->toArray();
        }
        if (! $variables instanceof Variables) {
            return $this->createErrorResponse('Unsupported global variables implementation.', code: ErrorCode::Internal)->toArray();
        }
        $variables = clone $variables;
        // Validate data against blueprint before saving
        $blueprint = $globalSet->blueprint();
        if (! $blueprint) {
            return $this->createErrorResponse('Cannot update global set: Blueprint not found. A blueprint is required for data validation.', code: ErrorCode::NotFound)->toArray();
        }
        if (! empty($data)) {
            // Strip entry-level metadata and coerce values to expected types
            $data = $this->sanitizeIncomingFieldData($blueprint, $data);

            // Merge new data with existing for full blueprint validation,
            // minus stored nulls (see mergeStoredDataForValidation).
            $mergedData = $this->mergeStoredDataForValidation($variables->data()->all(), $data);

            // Backward compat: globals saved by MCP prior to v2.1 may have
            // raw strings in structured fields. Safe to remove once all
            // MCP-created content has been re-saved.
            $mergedData = $this->sanitizeStoredFieldDataForValidation($blueprint, $mergedData);

            $validationContext = [
                'global_set' => $globalSet,
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
                        ->fields($blueprint->fields()->addValues($data))
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
            $incomingKeys = array_keys($data);
            /** @var array<string, mixed> $processedData */
            $processedData = $blueprint->fields()->addValues($data)
                ->process()->values()
                ->only($incomingKeys)
                ->all();

            $data = $processedData;
        }
        $this->persistStatamic(fn () => $variables->merge($data)->save());
        // Clear relevant caches
        $this->clearCachesAfterWrite(['stache', 'static']);

        return [
            'global' => [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'site' => $site,
                'updated_fields' => array_keys($data),
            ],
            'updated' => true,
        ];
    }

    protected function actions(): array
    {
        return [
            RouterAction::read('list', 'List global sets with their values', optional: ['site', 'limit', 'cursor']),
            RouterAction::read('get', 'Get one global set\'s values and version', needs: ['handle'], optional: ['site', 'fields']),
            RouterAction::edit('update', 'Update a global set\'s values in one site', needs: ['handle', 'data'], optional: ['site', 'expected_version']),
        ];
    }

    public function getTypes(): array
    {
        return [];
    }

    /**
     * The resource policy must see the global set the action resolves, which
     * is `handle` and nothing else. In v3 a `global_set` alias let the policy
     * approve one set while the action changed another; pinning the key here
     * keeps that from coming back through the default resolver's list.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveResourceHandle(array $arguments): ?string
    {
        $raw = $arguments['handle'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
