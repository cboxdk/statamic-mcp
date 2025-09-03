<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Groups;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\UserGroup;

#[Title('List User Groups')]
#[IsReadOnly]
class ListGroupsTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.groups.list';
    }

    protected function getToolDescription(): string
    {
        return 'List all user groups with their permissions and user counts';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_permissions')
            ->description('Include detailed permissions for each group')
            ->optional()
            ->boolean('include_users')
            ->description('Include user count and user list for each group')
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
        $includePermissions = $arguments['include_permissions'] ?? false;
        $includeUsers = $arguments['include_users'] ?? false;

        try {
            $groups = UserGroup::all();
            $groupList = [];

            foreach ($groups as $group) {
                $groupData = [
                    'handle' => $group->handle(),
                    'title' => $group->title(),
                ];

                if ($includePermissions) {
                    $groupData['permissions'] = $group->permissions();
                    $groupData['has_permissions'] = ! empty($group->permissions());
                }

                if ($includeUsers) {
                    $users = $group->queryUsers()->get();
                    $groupData['user_count'] = $users->count();
                    $groupData['users'] = $users->map(function ($user) {
                        return [
                            'id' => $user->id(),
                            'email' => $user->email(),
                            'name' => $user->name(),
                        ];
                    })->toArray();
                }

                $groupList[] = $groupData;
            }

            return [
                'groups' => $groupList,
                'count' => count($groupList),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list groups: ' . $e->getMessage())->toArray();
        }
    }
}
