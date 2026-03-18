<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesCollections;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesGlobalSets;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesNavigations;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesSites;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesTaxonomies;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('statamic-structures')]
#[Description('Manage Statamic structural resources: collections, taxonomies, navigations, sites, and global sets. Use resource_type to select the kind, then action for the operation. Actions: list, get, create, update, delete, configure.')]
class StructuresRouter extends BaseRouter
{
    use ClearsCaches;
    use HandlesCollections;
    use HandlesGlobalSets;
    use HandlesNavigations;
    use HandlesSites;
    use HandlesTaxonomies;

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
                ->description('Structure configuration. Fields depend on resource_type. Use statamic-structures action "get" to see current configuration before updating.'),
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
     * @return array<string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        $type = $arguments['resource_type'] ?? '';

        if ($type === 'collection') {
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
}
