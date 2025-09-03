<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Permissions;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Permission;

#[Title('List Permissions')]
#[IsReadOnly]
class ListPermissionsTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.permissions.list';
    }

    protected function getToolDescription(): string
    {
        return 'List all available permissions in Statamic with their categories and descriptions';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('category')
            ->description('Filter by permission category (e.g., collections, entries, users)')
            ->optional()
            ->boolean('group_by_category')
            ->description('Group permissions by their category')
            ->optional();
    }

    /**
     * Execute the tool.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $filterCategory = $arguments['category'] ?? null;
        $groupByCategory = $arguments['group_by_category'] ?? false;

        try {
            $permissions = Permission::all();
            $permissionList = [];

            foreach ($permissions as $permission) {
                $permissionData = [
                    'handle' => $permission->handle(),
                    'label' => $permission->label(),
                    'description' => $permission->description(),
                    'category' => $permission->category(),
                ];

                // Filter by category if specified
                if ($filterCategory && $permission->category() !== $filterCategory) {
                    continue;
                }

                if ($groupByCategory) {
                    $permissionList[$permission->category()][] = $permissionData;
                } else {
                    $permissionList[] = $permissionData;
                }
            }

            $response = [
                'permissions' => $permissionList,
            ];

            if ($groupByCategory) {
                $response['categories'] = array_keys($permissionList);
                $response['total_permissions'] = array_sum(array_map('count', $permissionList));
            } else {
                $response['count'] = count($permissionList);
                $response['categories'] = collect($permissions)->pluck('category')->unique()->values()->toArray();
            }

            if ($filterCategory) {
                $response['filtered_by_category'] = $filterCategory;
            }

            return $response;
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list permissions: ' . $e->getMessage())->toArray();
        }
    }
}
