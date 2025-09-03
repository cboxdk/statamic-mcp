<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Users;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\User;

#[Title('List Statamic Users')]
#[IsReadOnly]
class ListUsersTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.users.list';
    }

    protected function getToolDescription(): string
    {
        return 'List Statamic users with filtering and pagination';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('limit')
            ->description('Maximum number of users to return (default: 50)')
            ->optional()
            ->integer('offset')
            ->description('Number of users to skip (default: 0)')
            ->optional()
            ->string('role')
            ->description('Filter by user role')
            ->optional()
            ->string('group')
            ->description('Filter by user group')
            ->optional()
            ->boolean('super')
            ->description('Filter by super admin status')
            ->optional()
            ->boolean('include_data')
            ->description('Include user data fields (default: false)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $limit = $arguments['limit'] ?? 50;
        $offset = $arguments['offset'] ?? 0;
        $role = $arguments['role'] ?? null;
        $group = $arguments['group'] ?? null;
        $super = $arguments['super'] ?? null;
        $includeData = $arguments['include_data'] ?? false;

        $users = User::all();

        // Apply filters
        if ($role !== null) {
            $users = $users->filter(fn ($user) => $user->hasRole($role));
        }

        if ($group !== null) {
            $users = $users->filter(fn ($user) => $user->isInGroup($group));
        }

        if ($super !== null) {
            $users = $users->filter(fn ($user) => $user->isSuper() === $super);
        }

        $totalCount = $users->count();
        $users = $users->skip($offset)->take($limit);

        $userData = $users->map(function ($user) use ($includeData) {
            $result = [
                'id' => $user->id(),
                'email' => $user->email(),
                'name' => $user->name(),
                'super' => $user->isSuper(),
                'roles' => $user->roles()->map(fn ($role) => $role->handle())->all(),
                'groups' => $user->groups()->map(fn ($group) => $group->handle())->all(),
                'last_login' => $user->lastLogin()?->toISOString(),
                'created_at' => $user->date()?->toISOString(),
                'status' => $user->status(),
            ];

            if ($includeData) {
                $result['data'] = $user->data()->all();
            }

            return $result;
        })->all();

        return [
            'users' => $userData,
            'total_count' => $totalCount,
            'returned_count' => count($userData),
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + count($userData)) < $totalCount,
        ];
    }
}
