<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesGroups;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesRoles;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesUsers;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('statamic-users')]
#[Description('Manage Statamic users, roles, and user groups. Set resource_type first, then choose an action. Actions: list, get, search, create, update, delete, activate, deactivate, assign_role, remove_role.')]
class UsersRouter extends BaseRouter
{
    use ClearsCaches;
    use HandlesGroups;
    use HandlesRoles;
    use HandlesUsers;

    protected function getDomain(): string
    {
        return 'users';
    }

    protected function getActions(): array
    {
        return [
            'list' => 'List users, roles, or groups with filtering',
            'get' => 'Get specific user, role, or group details',
            'create' => 'Create new user, role, or group',
            'update' => 'Update existing user, role, or group',
            'delete' => 'Delete user, role, or group',
            'activate' => 'Activate user account',
            'deactivate' => 'Deactivate user account',
            'search' => 'Search and filter users by name, email, role, group, or status',
            'assign_role' => 'Assign role to user',
            'remove_role' => 'Remove role from user',
        ];
    }

    protected function getTypes(): array
    {
        return [
            'user' => 'Statamic user accounts with authentication and authorization',
            'role' => 'Permission-based roles for access control',
            'group' => 'User groups for organizing users and role assignment',
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return array_merge(parent::defineSchema($schema), [
            'action' => JsonSchema::string()
                ->description(
                    'Action to perform. Required params per action: '
                    . 'list (resource_type; optional: limit, offset), '
                    . 'get (resource_type; id or email for users, handle for roles/groups), '
                    . 'search (resource_type=user; optional: query, role, group, super, status), '
                    . 'create (resource_type, data), '
                    . 'update (resource_type; id/email for users, handle for roles/groups; data), '
                    . 'delete (resource_type; id/email for users, handle for roles/groups), '
                    . 'activate (resource_type=user, id or email), '
                    . 'deactivate (resource_type=user, id or email), '
                    . 'assign_role (resource_type=user, id or email, role), '
                    . 'remove_role (resource_type=user, id or email, role)'
                )
                ->enum(['list', 'get', 'create', 'update', 'delete', 'activate', 'deactivate', 'search', 'assign_role', 'remove_role'])
                ->required(),
            'resource_type' => JsonSchema::string()
                ->description('Type of user resource. "user" for accounts, "role" for permission roles, "group" for user groups')
                ->enum(['user', 'role', 'group'])
                ->required(),
            'id' => JsonSchema::string()
                ->description('User UUID or resource identifier. Use email as alternative for user operations'),
            'email' => JsonSchema::string()
                ->description('User email address. Alternative to id for user operations. Required in data for user creation'),
            'handle' => JsonSchema::string()
                ->description('Role or group handle in snake_case. Required for role/group get, update, delete'),
            'data' => JsonSchema::object()
                ->description(
                    'Resource data. For users: {"email": "...", "name": "...", "password": "..."}. '
                    . 'For roles: {"handle": "...", "title": "...", "permissions": [...]}. '
                    . 'For groups: {"handle": "...", "title": "...", "roles": [...]}'
                ),
            'role' => JsonSchema::string()
                ->description('Role handle for assign_role/remove_role actions. Example: "editor", "author"'),
            'query' => JsonSchema::string()
                ->description('Search query to match against user name and email (case-insensitive)'),
            'status' => JsonSchema::string()
                ->description('Filter users by account status')
                ->enum(['active', 'inactive']),
            'super' => JsonSchema::boolean()
                ->description('Filter by super admin status. true = only supers, false = only non-supers'),
            'group' => JsonSchema::string()
                ->description('Group handle to filter users by membership'),
            'include_details' => JsonSchema::boolean()
                ->description('Include roles, groups, preferences, and extended profile data'),
            'include_permissions' => JsonSchema::boolean()
                ->description('Include resolved permission list for roles'),
            'limit' => JsonSchema::integer()
                ->description('Maximum number of results to return (default: 100, max: 500)'),
            'offset' => JsonSchema::integer()
                ->description('Number of results to skip for pagination'),
            'filters' => JsonSchema::object()
                ->description('Additional filter conditions'),
        ]);
    }

    /**
     * Route user operations to appropriate handlers with security checks.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        $action = is_string($arguments['action'] ?? null) ? $arguments['action'] : '';

        // Check if tool is enabled for current context
        if (! $this->isCliContext() && ! $this->isWebToolEnabled()) {
            return $this->createErrorResponse('Permission denied: Users tool is disabled for web access')->toArray();
        }

        // Apply security checks for web context
        if ($this->isWebContext()) {
            $permissionError = $this->checkWebPermissions($action, $arguments);
            if ($permissionError) {
                return $permissionError;
            }
        }

        // Validate required parameters
        $type = is_string($arguments['resource_type'] ?? null) ? $arguments['resource_type'] : '';

        // Route to type-specific handlers
        return match ($type) {
            'user' => $this->handleUserAction($action, $arguments),
            'role' => $this->handleRoleAction($action, $arguments),
            'group' => $this->handleGroupAction($action, $arguments),
            default => $this->createErrorResponse("Unknown user type: {$type}")->toArray(),
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

        if ($type === 'user') {
            return match ($action) {
                'list', 'get', 'search' => ['view users'],
                'create' => ['create users'],
                'update', 'activate', 'deactivate' => ['edit users'],
                'assign_role', 'remove_role' => ['assign roles'],
                'delete' => ['delete users'],
                default => ['super'],
            };
        }

        if ($type === 'role') {
            // Statamic has only 'edit roles' for all role operations
            return ['edit roles'];
        }

        if ($type === 'group') {
            // Statamic has only 'edit user groups' for all group operations
            return ['edit user groups'];
        }

        return ['super'];
    }
}
