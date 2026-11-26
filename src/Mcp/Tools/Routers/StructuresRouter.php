<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesCollections;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesGlobalSets;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesNavigations;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesSites;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesTaxonomies;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('statamic-structures')]
#[Title('Statamic Structures')]
#[Description('Statamic structures: collections, taxonomies, navigations, sites and global sets, their settings, and the page and menu trees.')]
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
                    throw new ToolFailure(ErrorCode::Forbidden, 'Tree changes are denied by resource policy.', 'parent');
                }
            }
        }

        return parent::filterInputFields($arguments);
    }

    protected function getDomain(): string
    {
        return 'structures';
    }

    protected function actions(): array
    {
        $writable = ['collection', 'taxonomy', 'navigation', 'globalset'];

        return [
            RouterAction::read('list', 'List collections, taxonomies, navigations, sites or global sets; collections and taxonomies page with limit and offset', optional: ['include_details', 'limit', 'offset']),
            RouterAction::read('get', 'Get one structure\'s configuration; for a collection, include_tree and site read its page tree', needs: ['handle'], optional: ['include_tree', 'include_counts', 'site']),
            RouterAction::admin('create', 'Create a collection, taxonomy, navigation or global set; data holds its settings', $writable, needs: ['handle'], optional: ['data']),
            RouterAction::admin('update', 'Update a structure\'s settings. A navigation\'s data may carry its trees; collection trees are changed with move', $writable, needs: ['handle', 'data']),
            RouterAction::admin('move', 'Move one collection page and its descendants using data {entry, parent}; parent is an entry id or null for the top level', ['collection'], needs: ['handle', 'data'], optional: ['site']),
            RouterAction::admin('delete', 'Delete a structure; its content must be deleted first', $writable, needs: ['handle']),
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
            'handle' => JsonSchema::string()
                ->description('Structure identifier in snake_case. Required for get, create, update, move, delete. Example: "blog" for collection, "tags" for taxonomy'),
            'data' => JsonSchema::object()
                ->description('Structure data for create/update. Fields depend on resource_type. Action get on the read tool shows the current configuration. '
                    . 'Navigation: title, max_depth, collections, and the menu itself as trees — the same [{site, items}] list "get" returns, so edit it and send it back — '
                    . 'or tree (a list of items) with an optional site. Each item is {id?, entry? | url? | title?, data?, children?}; an item without an id gets one. '
                    . 'A tree replaces the whole menu for its site. Unknown keys are refused. Collection: move with data {entry: id, parent: id|null} moves one branch; requires reorder permission and both entries:write and structures:admin. Collection update changes settings only; tree replacement is not supported.'),
            'site' => JsonSchema::string()->description('Collection site for tree reads and moves; defaults to the default site.'),
            'include_tree' => JsonSchema::boolean()->description('On collection get, include the selected site tree. Requires entries:read and structures:read.'),
            'include_counts' => JsonSchema::boolean()
                ->description('On collection get, include how many entries it holds. Default: false'),
            'limit' => JsonSchema::integer()
                ->description('Maximum results to return (default: 50, max: 500)'),
            'offset' => JsonSchema::integer()
                ->description('Number of results to skip for pagination. Use with limit for paging'),
            'include_details' => JsonSchema::boolean()
                ->description('Include detailed configuration and statistics in response'),
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
            if ($action === 'move' && $handle !== '') {
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
                'update' => $handle !== '' ? ["edit {$handle} nav"] : ['configure navs'],
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
     * The resource policy must see the resource the action acts on, which in
     * v4 is always `handle`, a create's new handle included: data.handle may
     * only repeat it. v3 let a create's data.handle win, so the policy and the
     * handler could each read a different name. The default resolver tries
     * `collection`/`taxonomy`/`container` first, so pinning `handle` also keeps
     * an unrelated allowed key from satisfying the policy.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveResourceHandle(array $arguments): ?string
    {
        $raw = $arguments['handle'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
