<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Fields\Validator;

#[Name('statamic-globals')]
#[Description('Manage Statamic global sets and their values. Use statamic-blueprints get to see field structure before updating. Actions: list, get, update.')]
class GlobalsRouter extends BaseRouter
{
    use ClearsCaches;

    protected function getDomain(): string
    {
        return 'globals';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'list (optional: limit, offset), '
                    . 'get (handle), '
                    . 'update (handle, data)'
                )
                ->enum(['list', 'get', 'update'])
                ->required(),

            'global_set' => JsonSchema::string()
                ->description('Deprecated — use "handle" instead. Global set handle'),

            'handle' => JsonSchema::string()
                ->description('Global set handle in snake_case. Required for get and update. Example: "site_settings", "seo"'),

            'site' => JsonSchema::string()
                ->description('Site handle for multi-site setups. Defaults to the default site. Example: "default", "en"'),

            'data' => JsonSchema::object()
                ->description(
                    'Global set field values. Structure must match the global set blueprint. '
                    . 'Use statamic-blueprints action "get" to see field types before sending data.'
                ),

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

        // Check if tool is enabled for current context
        if ($this->isWebContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Globals tool is disabled for web access')->toArray();
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
            'list' => $this->listGlobals($arguments),
            'get' => $this->getGlobal($arguments),
            'update' => $this->updateGlobal($arguments),
            default => $this->createErrorResponse("Action {$action} not supported for globals")->toArray(),
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
        // Global set handle required for get and update operations
        if (in_array($action, ['get', 'update'])) {
            $rawHandle = $arguments['global_set'] ?? $arguments['handle'] ?? null;
            if (! is_string($rawHandle) || empty($rawHandle)) {
                return $this->createErrorResponse('Global set handle is required for global operations')->toArray();
            }
            $globalSetHandle = $rawHandle;
            if (! GlobalSet::find($globalSetHandle)) {
                return $this->createErrorResponse("Global set not found: {$globalSetHandle}")->toArray();
            }
        }

        // Data required for update actions
        if ($action === 'update' && empty($arguments['data'])) {
            return $this->createErrorResponse('Data is required for update action')->toArray();
        }

        // Site validation
        if (! empty($arguments['site'])) {
            if (! is_string($arguments['site'])) {
                return $this->createErrorResponse('Site handle must be a string')->toArray();
            }
            $siteHandle = $arguments['site'];
            /** @var Collection<int|string, \Statamic\Sites\Site> $allSites */
            $allSites = Site::all();
            if (! $allSites->map->handle()->contains($siteHandle)) {
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
        // Statamic scopes global permissions per set: 'edit {handle} globals'
        $rawHandle = $arguments['global_set'] ?? $arguments['handle'] ?? null;
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
        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();
        $site = is_string($arguments['site'] ?? null) ? $arguments['site'] : $defaultSite->handle();
        $limit = $this->getIntegerArgument($arguments, 'limit', 50, 1, 1000);
        $offset = $this->getIntegerArgument($arguments, 'offset', 0, 0);

        try {
            $globalSets = GlobalSet::all();
            $total = $globalSets->count();

            $paginatedSets = $globalSets->skip($offset)->take($limit);

            $data = $paginatedSets->map(function ($globalSet) use ($site) {
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
                'globals' => $data,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ],
                'site' => $site,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list globals: {$e->getMessage()}")->toArray();
        }
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
        // Accept both 'global_set' and 'handle' parameters
        $rawHandle = $arguments['global_set'] ?? $arguments['handle'] ?? null;
        if (! is_string($rawHandle)) {
            return $this->createErrorResponse('Global set handle must be a string')->toArray();
        }
        $globalSetHandle = $rawHandle;
        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();
        $site = is_string($arguments['site'] ?? null) ? $arguments['site'] : $defaultSite->handle();

        try {
            /** @var \Statamic\Contracts\Globals\GlobalSet|null $globalSet */
            $globalSet = GlobalSet::find($globalSetHandle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$globalSetHandle}")->toArray();
            }

            $variables = $globalSet->in($site);

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

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get global: {$e->getMessage()}")->toArray();
        }
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
        // Accept both 'global_set' and 'handle' parameters
        $rawHandle = $arguments['global_set'] ?? $arguments['handle'] ?? null;
        if (! is_string($rawHandle)) {
            return $this->createErrorResponse('Global set handle must be a string')->toArray();
        }
        $globalSetHandle = $rawHandle;
        /** @var \Statamic\Sites\Site $defaultSite */
        $defaultSite = Site::default();
        $site = is_string($arguments['site'] ?? null) ? $arguments['site'] : $defaultSite->handle();
        $data = is_array($arguments['data'] ?? []) ? ($arguments['data'] ?? []) : [];

        try {
            /** @var \Statamic\Contracts\Globals\GlobalSet|null $globalSet */
            $globalSet = GlobalSet::find($globalSetHandle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set not found: {$globalSetHandle}")->toArray();
            }

            $variables = $globalSet->in($site);

            if (! $variables) {
                return $this->createErrorResponse("Global set not available in site: {$site}")->toArray();
            }

            // Validate data against blueprint before saving
            $blueprint = $globalSet->blueprint();

            if (! $blueprint) {
                return $this->createErrorResponse('Cannot update global set: Blueprint not found. A blueprint is required for data validation.')->toArray();
            }

            if (! empty($data)) {
                // Merge new data with existing for full blueprint validation
                /** @var array<string, mixed> $mergedData */
                $mergedData = array_merge($variables->data()->all(), $data);

                $fieldsValidator = (new Validator)
                    ->fields($blueprint->fields()->addValues($mergedData))
                    ->withContext([
                        'global_set' => $globalSet,
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

            $variables->merge($data)->save();

            // Clear relevant caches
            $this->clearStatamicCaches(['stache', 'static']);

            return [
                'global' => [
                    'handle' => $globalSet->handle(),
                    'title' => $globalSet->title(),
                    'site' => $site,
                    'updated_fields' => array_keys($data),
                ],
                'updated' => true,
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to update global: {$e->getMessage()}")->toArray();
        }
    }

    public function getActions(): array
    {
        return [
            'list' => 'List global sets with values and pagination',
            'get' => 'Get specific global set with values',
            'update' => 'Update global set values',
        ];
    }

    public function getTypes(): array
    {
        return [
            'GlobalSet' => 'Global set configuration object',
            'GlobalValues' => 'Global set values for a specific site',
        ];
    }
}
