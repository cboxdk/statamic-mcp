<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesGroups;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesRoles;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns\HandlesUsers;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('statamic-users')]
#[Title('Statamic Users & Roles')]
#[Description('Statamic users, roles and user groups, and who holds which role.')]
class UsersRouter extends BaseRouter
{
    use HandlesGroups;
    use HandlesRoles;
    use HandlesUsers;

    protected function getDomain(): string
    {
        return 'users';
    }

    protected function facetArguments(): array
    {
        return [
            'read' => ['query', 'status', 'super', 'group', 'include_details', 'include_permissions', 'limit', 'offset', 'filters'],
            'write' => ['data'],
        ];
    }

    protected function actions(): array
    {
        return [
            RouterAction::read('list', 'List users, roles or groups. Needs resource_type; optional: limit, offset, filters.'),
            RouterAction::read('get', 'Get a user (id or email), a role or a group (handle).'),
            RouterAction::admin('create', 'Create a user, role or group. Needs resource_type, data.'),
            RouterAction::admin('update', 'Update a user (id or email), a role or a group (handle). Needs data; changing your own email or password over HTTP also needs data.current_password.'),
            RouterAction::admin('delete', 'Delete a user (id or email), a role or a group (handle).'),
            RouterAction::admin('activate', 'Activate a user account. Needs id or email.', ['user']),
            RouterAction::admin('deactivate', 'Deactivate a user account and revoke its MCP tokens. Needs id or email.', ['user']),
            RouterAction::read('search', 'Search users. Optional: query, role, group, super, status.', ['user']),
            RouterAction::admin('assign_role', 'Give a user a role. Needs id or email, role.', ['user']),
            RouterAction::admin('remove_role', 'Take a role from a user. Needs id or email, role.', ['user']),
        ];
    }

    public function getTypes(): array
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
            'id' => JsonSchema::string()
                ->description('User UUID or resource identifier. Use email as alternative for user operations'),
            'email' => JsonSchema::string()
                ->description('User email address. Alternative to id for user operations. Required in data for user creation'),
            'handle' => JsonSchema::string()
                ->description('Role or group handle in snake_case. Required for role/group get, update, delete'),
            'data' => JsonSchema::object()
                ->description(
                    'Resource data. For users: {"email": "...", "name": "...", "password": "..."}; changing your own email or password over HTTP also needs "current_password". '
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
