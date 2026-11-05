<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesCollections;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesGlobalSets;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesNavigations;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesSites;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesTaxonomies;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('statamic-structures')]
#[Title('Statamic Structures')]
#[Description('Manage Statamic structural resources: collections, taxonomies, navigations, sites, and global sets. Use resource_type to select the kind, then action for the operation. Actions: list, get, create, update, delete, configure.')]
class StructuresRouter extends BaseRouter
{
    use HandlesCollections;
    use HandlesGlobalSets;
    use HandlesNavigations;
    use HandlesSites;
    use HandlesTaxonomies;

    protected function filterInputFields(array $arguments): array
    {
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        if (($arguments['resource_type'] ?? '') === 'collection' && (array_key_exists('entry', $data) || array_key_exists('parent', $data))) {
            foreach (['entries', 'structures'] as $domain) {
                if (array_intersect(['entry', 'parent'], app(ResourcePolicy::class)->getDeniedFields($domain))) {
                    throw ValidationException::withMessages(['parent' => 'Tree changes are denied by resource policy.']);
                }
            }
        }

        return parent::filterInputFields($arguments);
    }

    protected function getDomain(): string
    {
        return 'structures';
    }

    public function getActions(): array
    {
        return [
            'list' => 'List structures by type with configuration details',
            'get' => 'Get specific structure configuration and details',
            'create' => 'Create new structures with configuration',
            'update' => 'Update structure configuration and settings',
            'delete' => 'Delete structures with safety checks',
            'configure' => 'Configure structure-specific settings and options',
        ];
    }

    public function getTypes(): array
    {
        return [
            'collection' => 'Content collections that organize entries',
            'taxonomy' => 'Taxonomies for categorizing and tagging content',
            'navigation' => 'Navigation trees and menu structures',
            'site' => 'Multi-site configuration and localization',
            'globalset' => 'Global sets for site-wide settings and configuration',
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'list (resource_type), '
                    . 'get (resource_type, handle), '
                    . 'create (resource_type, handle, data), '
                    . 'update (resource_type, handle, data), '
                    . 'delete (resource_type, handle), '
                    . 'configure (resource_type, handle, data)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete', 'configure'])
                ->required(),
            'resource_type' => JsonSchema::string()
                ->description('Type of structure to manage. Determines available actions and data format.')
                ->enum(['collection', 'taxonomy', 'navigation', 'site', 'globalset'])
                ->required(),
            'handle' => JsonSchema::string()
                ->description('Structure identifier in snake_case. Required for get, create, update, delete. Example: "blog" for collection, "tags" for taxonomy'),
            'data' => JsonSchema::object()
                ->description('Structure configuration. Fields depend on resource_type. Use statamic-structures action "get" to see current configuration before updating. '
                    . 'Navigation: title, max_depth, collections, and the menu itself as trees — the same [{site, items}] list "get" returns, so edit it and send it back — '
                    . 'or tree (a list of items) with an optional site. Each item is {id?, entry? | url? | title?, data?, children?}; an item without an id gets one. '
                    . 'A tree replaces the whole menu for its site. Unknown keys are refused. Collection: update with data {entry: id, parent: id|null} moves one branch; requires reorder permission and both entries:write and structures:write. Collection tree replacement is not supported.'),
            'site' => JsonSchema::string()->description('Collection site for tree reads and moves; defaults to the default site.'),
            'include_tree' => JsonSchema::boolean()->description('On collection get, include the selected site tree. Requires entries:read and structures:read.'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed configuration and statistics in response'),
            'filters' => JsonSchema::object()
                ->description('Filter conditions for list operations'),
        ]);
    }

    /**
     * Route structure operations to appropriate handlers with security checks.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        $type = is_string($arguments['resource_type'] ?? null) ? $arguments['resource_type'] : '';

        // Validate action-specific requirements
        if (in_array($action, ['get', 'update', 'delete']) && empty($arguments['handle'])) {
            return $this->createErrorResponse("Handle is required for {$action} action")->toArray();
        }

        // Route to type-specific handlers
        return match ($type) {
            'collection' => $this->handleCollectionAction($action, $arguments),
            'taxonomy' => $this->handleTaxonomyAction($action, $arguments),
            'navigation' => $this->handleNavigationAction($action, $arguments),
            'site' => $this->handleSiteAction($action, $arguments),
            'globalset' => $this->handleGlobalSetAction($action, $arguments),
            default => $this->createErrorResponse("Unknown structure type: {$type}")->toArray(),
        };
    }

    /**
     * Get required permissions for action.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string|array<int, string>>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        $type = $arguments['resource_type'] ?? '';

        if ($type === 'collection') {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';

            if ($action === 'list') {
                return []; // Each collection is filtered by policy and view permission.
            }
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            if ($action === 'update' && $handle !== '' && (array_key_exists('entry', $data) || array_key_exists('parent', $data))) {
                return [['configure collections', "reorder {$handle} entries"]];
            }
            if ($action === 'get' && $handle !== '') {
                return [['configure collections', "view {$handle} entries"]];
            }

            return ['configure collections'];
        }

        if ($type === 'taxonomy') {
            return ['configure taxonomies'];
        }

        if ($type === 'navigation') {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';

            return match ($action) {
                'list' => ['configure navs'],
                'get' => $handle !== '' ? ["view {$handle} nav"] : ['configure navs'],
                'update', 'configure' => $handle !== '' ? ["edit {$handle} nav"] : ['configure navs'],
                'create', 'delete' => ['configure navs'],
                default => ['super'],
            };
        }

        if ($type === 'site') {
            return ['configure sites'];
        }

        if ($type === 'globalset') {
            return ['configure globals'];
        }

        return ['super'];
    }

    /**
     * The resource policy must see the same resource the action acts on, which
     * is the top-level handle except on create. Create prefers a string
     * data.handle, after denied fields have been removed, just as its handler
     * does. The default resolver tries `collection`/`taxonomy`/`container`
     * first, so an unrelated extra key — e.g. a permitted `collection` alongside
     * a denied `handle` — could satisfy the policy for a resource the handler
     * never touches.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveResourceHandle(array $arguments): ?string
    {
        $raw = $arguments['handle'] ?? null;

        if (($arguments['action'] ?? null) === 'create') {
            $arguments = $this->filterInputFields($arguments);
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];

            if (is_string($data['handle'] ?? null)) {
                $raw = $data['handle'];
            }
        }

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
