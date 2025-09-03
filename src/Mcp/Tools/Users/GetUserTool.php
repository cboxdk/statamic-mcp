<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Users;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\User;

#[Title('Get Statamic User')]
#[IsReadOnly]
class GetUserTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.users.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get a specific Statamic user by ID or email';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('identifier')
            ->description('User ID or email address')
            ->required()
            ->boolean('include_data')
            ->description('Include user data fields (default: true)')
            ->optional()
            ->boolean('include_permissions')
            ->description('Include user permissions (default: false)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $identifier = $arguments['identifier'];
        $includeData = $arguments['include_data'] ?? true;
        $includePermissions = $arguments['include_permissions'] ?? false;

        // Try to find user by email first, then by ID
        $user = User::findByEmail($identifier) ?? User::find($identifier);

        if (! $user) {
            return $this->createErrorResponse("User '{$identifier}' not found")->toArray();
        }

        $result = [
            'id' => $user->id(),
            'email' => $user->email(),
            'name' => $user->name(),
            'initials' => $user->initials(),
            'avatar' => $user->avatar(),
            'super' => $user->isSuper(),
            'roles' => $user->roles()->map(fn ($role) => [
                'handle' => $role->handle(),
                'title' => $role->title(),
                'permissions' => $includePermissions ? $role->permissions()->all() : [],
            ])->all(),
            'groups' => $user->groups()->map(fn ($group) => [
                'handle' => $group->handle(),
                'title' => $group->title(),
            ])->all(),
            'preferences' => $user->preferences(),
            'last_login' => $user->lastLogin()?->toISOString(),
            'created_at' => $user->date()?->toISOString(),
            'status' => $user->status(),
            'blueprint' => $user->blueprint()?->handle(),
        ];

        if ($includeData) {
            $result['data'] = $user->data()->all();
        }

        if ($includePermissions && ! $user->isSuper()) {
            $result['permissions'] = $user->permissions()->all();
        }

        return $result;
    }
}
