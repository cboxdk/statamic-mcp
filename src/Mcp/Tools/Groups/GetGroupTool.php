<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Groups;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\UserGroup;

#[Title('Get User Group')]
#[IsReadOnly]
class GetGroupTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.groups.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific user group';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Group handle')
            ->required()
            ->boolean('include_users')
            ->description('Include detailed user list')
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
        $handle = $arguments['handle'];
        $includeUsers = $arguments['include_users'] ?? false;

        try {
            $group = UserGroup::find($handle);
            if (! $group) {
                return $this->createErrorResponse("Group '{$handle}' not found")->toArray();
            }

            $groupData = [
                'handle' => $group->handle(),
                'title' => $group->title(),
                'permissions' => $group->permissions(),
                'has_permissions' => ! empty($group->permissions()),
            ];

            if ($includeUsers) {
                $users = $group->queryUsers()->get();
                $groupData['user_count'] = $users->count();
                $groupData['users'] = $users->map(function ($user) {
                    return [
                        'id' => $user->id(),
                        'email' => $user->email(),
                        'name' => $user->name(),
                        'roles' => $user->roles()->map->handle()->toArray(),
                        'groups' => $user->groups()->map->handle()->toArray(),
                    ];
                })->toArray();
            } else {
                $groupData['user_count'] = $group->queryUsers()->count();
            }

            return [
                'group' => $groupData,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not get group: ' . $e->getMessage())->toArray();
        }
    }
}
