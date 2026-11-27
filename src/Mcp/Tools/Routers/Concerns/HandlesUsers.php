<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Illuminate\Support\Facades\Hash;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * User operations for the UsersRouter.
 */
trait HandlesUsers
{
    /**
     * Handle user operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleUserAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listUsers($arguments),
            'search' => $this->searchUsers($arguments),
            'get' => $this->getUser($arguments),
            'create' => $this->createUser($arguments),
            'update' => $this->updateUser($arguments),
            'delete' => $this->deleteUser($arguments),
            'activate' => $this->activateUser($arguments),
            'deactivate' => $this->deactivateUser($arguments),
            'assign_role' => $this->assignRole($arguments),
            'remove_role' => $this->removeRole($arguments),
            default => $this->createErrorResponse("Unknown user action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listUsers(array $arguments): array
    {
        if (! $this->hasPermission('view', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot list users', code: ErrorCode::Forbidden)->toArray();
        }
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $pagination = $this->getPaginationArgs($arguments, defaultLimit: 100);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];
        $query = User::query();
        $total = $query->count();
        $users = $query->orderBy('id')->offset($offset)->limit($limit)->get()->map(function ($user) use ($includeDetails): array {
            /** @var \Statamic\Contracts\Auth\User $user */
            return $this->serializeUser($user, $includeDetails);
        })->values()->all();

        return $this->buildPage($users, $total, $offset, $arguments);
    }

    /**
     * Search and filter users by name, email, role, group, or status.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function searchUsers(array $arguments): array
    {
        if (! $this->hasPermission('view', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot search users', code: ErrorCode::Forbidden)->toArray();
        }
        $query = is_string($arguments['query'] ?? null) ? strtolower($arguments['query']) : null;
        $roleFilter = is_string($arguments['role'] ?? null) ? $arguments['role'] : null;
        $groupFilter = is_string($arguments['group'] ?? null) ? $arguments['group'] : null;
        $superFilter = isset($arguments['super']) ? (bool) $arguments['super'] : null;
        $statusFilter = is_string($arguments['status'] ?? null) ? $arguments['status'] : null;
        $includeDetails = $this->getBooleanArgument($arguments, 'include_details', true);
        $pagination = $this->getPaginationArgs($arguments, defaultLimit: 100);
        $limit = $pagination['limit'];
        $offset = $pagination['offset'];
        $users = collect(User::all()->all())->filter(function ($user) use ($query, $roleFilter, $groupFilter, $superFilter, $statusFilter) {
            /** @var \Statamic\Contracts\Auth\User $user */

            // Query filter - match name or email
            if ($query !== null) {
                $name = strtolower((string) $user->name());
                $emailValue = $user->email();
                $email = strtolower(is_string($emailValue) ? $emailValue : '');
                if (! str_contains($name, $query) && ! str_contains($email, $query)) {
                    return false;
                }
            }

            // Role filter
            if ($roleFilter !== null && ! $user->hasRole($roleFilter)) {
                return false;
            }

            // Group filter
            if ($groupFilter !== null) {
                /** @var array<int, string> $groupHandles */
                $groupHandles = $user->groups()->map->handle()->all();
                if (! in_array($groupFilter, $groupHandles, true)) {
                    return false;
                }
            }

            // Super admin filter
            if ($superFilter !== null && $user->isSuper() !== $superFilter) {
                return false;
            }

            // Status filter
            if ($statusFilter !== null) {
                /** @var string $userStatus */
                $userStatus = $user->get('status', 'active');
                if ($userStatus !== $statusFilter) {
                    return false;
                }
            }

            return true;
        });
        $total = $users->count();
        $results = $users->sortBy->id()->slice($offset, $limit)->map(function ($user) use ($includeDetails): array {
            /** @var \Statamic\Contracts\Auth\User $user */
            return $this->serializeUser($user, $includeDetails);
        })->values()->all();
        // Build applied filters summary
        $appliedFilters = array_filter([
            'query' => $query,
            'role' => $roleFilter,
            'group' => $groupFilter,
            'super' => $superFilter,
            'status' => $statusFilter,
        ], fn ($v) => $v !== null);

        return [
            ...$this->buildPage($results, $total, $offset, $arguments),
            'filters_applied' => $appliedFilters,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getUser(array $arguments): array
    {
        if (! $this->hasPermission('view', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot view users', code: ErrorCode::Forbidden)->toArray();
        }
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
        $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
        if (! $id && ! $email) {
            return $this->createErrorResponse('Either ID or email is required')->toArray();
        }
        $user = $id ? User::find($id) : User::findByEmail((string) $email);
        if (! $user) {
            return $this->missingResource('User', $id ?: $email, $id ? 'id' : 'email')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\User $user */
        $data = array_merge($this->serializeUser($user, true), [
            'permissions' => $user->permissions()->all(),
        ]);

        return ['user' => $data];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function createUser(array $arguments): array
    {
        if (! $this->hasPermission('create', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot create users', code: ErrorCode::Forbidden)->toArray();
        }
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        $email = is_string($data['email'] ?? null) ? $data['email'] : null;
        if (! $email) {
            return $this->createErrorResponse('Email is required')->toArray();
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->createErrorResponse("Invalid email format: {$email}")->toArray();
        }
        $existsError = $this->checkHandleNotExists(User::findByEmail($email), 'User with email', $email);
        if ($existsError !== null) {
            return $existsError;
        }
        /** @var \Statamic\Contracts\Auth\User $user */
        $user = User::make()->email($email);
        // Set user data
        if (isset($data['name'])) {
            $user->set('name', $data['name']);
        }
        if (isset($data['password']) && is_string($data['password'])) {
            if (strlen($data['password']) < 8) {
                return $this->createErrorResponse('Password must be at least 8 characters long')->toArray();
            }
            $user->password($data['password']);
        }
        // Super admin promotion requires the caller to already be a super admin
        if (isset($data['super']) && $data['super']) {
            /** @var \Statamic\Contracts\Auth\User|null $authenticatedUser */
            $authenticatedUser = auth()->user();
            if (! $this->isCliContext() && (! $authenticatedUser || ! $authenticatedUser->isSuper())) {
                return $this->createErrorResponse('Permission denied: Only super admins can grant super admin status', code: ErrorCode::Forbidden)->toArray();
            }
            $user->makeSuper();
        }
        // Attaching roles is its own authority ('assign roles'), separate
        // from 'create users'. Check it before the user is saved, so a
        // caller who may create users but not assign roles cannot seed a
        // privileged account.
        if (isset($data['roles']) && is_array($data['roles']) && $data['roles'] !== [] && ! $this->hasPermission('assign', 'roles')) {
            return $this->createErrorResponse('Permission denied: Assigning roles requires the assign roles permission', code: ErrorCode::Forbidden)->toArray();
        }
        $this->persistStatamic(fn () => $user->save(), ['stache']);
        // Assign roles if provided
        if (isset($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $roleHandle) {
                $role = is_string($roleHandle) ? Role::find($roleHandle) : null;
                if ($role) {
                    $user->assignRole($role);
                }
            }
            $this->persistStatamic(fn () => $user->save(), ['stache']);
        }
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'user' => [
                ...$this->serializeUser($user, true),
                'created' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function updateUser(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot update users', code: ErrorCode::Forbidden)->toArray();
        }
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
        $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
        $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
        if (! $id && ! $email) {
            return $this->createErrorResponse('Either ID or email is required')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\User|null $user */
        $user = $id ? User::find($id) : User::findByEmail((string) $email);
        if (! $user) {
            return $this->missingResource('User', $id ?: $email, $id ? 'id' : 'email')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\User|null $caller */
        $caller = auth()->user();
        $isSelf = $caller !== null && $caller->id() === $user->id();
        // Your own email or password is what signs you in to the Control
        // Panel. A token is not the person, so over HTTP the change needs
        // the current password too, as Statamic's own password form does.
        $changesLogin = (is_string($data['email'] ?? null) && $data['email'] !== $user->email())
            || is_string($data['password'] ?? null);
        if ($isSelf && $changesLogin && $this->isWebContext()) {
            $current = $data['current_password'] ?? null;
            if (! is_string($current) || ! Hash::check($current, (string) $user->password())) {
                return $this->createErrorResponse('Changing your own email or password needs your current password in data.current_password. Nothing was saved.')->toArray();
            }
        }
        // Update user data — only whitelisted fields allowed
        if (isset($data['name'])) {
            $user->set('name', $data['name']);
        }
        if (isset($data['email']) && is_string($data['email'])) {
            if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                return $this->createErrorResponse("Invalid email format: {$data['email']}")->toArray();
            }
            $user->email($data['email']);
        }
        if (isset($data['password']) && is_string($data['password'])) {
            // Setting another user's password is 'change passwords' in
            // Statamic (UserPolicy::editPassword), not 'edit users'. Without
            // this an 'edit users' holder could set a super admin's password
            // and sign in as them.
            if (! $isSelf && ! $this->hasPermission('change', 'passwords')) {
                return $this->createErrorResponse("Permission denied: Changing another user's password requires the change passwords permission", code: ErrorCode::Forbidden)->toArray();
            }
            if (strlen($data['password']) < 8) {
                return $this->createErrorResponse('Password must be at least 8 characters long')->toArray();
            }
            $user->password($data['password']);
        }
        if (! empty($data['super'])) {
            /** @var \Statamic\Contracts\Auth\User|null $authenticatedUser */
            $authenticatedUser = auth()->user();
            if (! $this->isCliContext() && (! $authenticatedUser || ! $authenticatedUser->isSuper())) {
                return $this->createErrorResponse('Permission denied: Only super admins can grant super admin status', code: ErrorCode::Forbidden)->toArray();
            }
            $user->makeSuper();
        }
        $this->persistStatamic(fn () => $user->save(), ['stache']);
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'user' => [
                ...$this->serializeUser($user, true),
                'updated' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deleteUser(array $arguments): array
    {
        if (! $this->hasPermission('delete', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot delete users', code: ErrorCode::Forbidden)->toArray();
        }
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
        $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
        if (! $id && ! $email) {
            return $this->createErrorResponse('Either ID or email is required')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\User|null $user */
        $user = $id ? User::find($id) : User::findByEmail((string) $email);
        if (! $user) {
            return $this->missingResource('User', $id ?: $email, $id ? 'id' : 'email')->toArray();
        }
        // Prevent deletion of super user if it's the only one
        if ($user->isSuper()) {
            $superCount = User::all()->filter(function ($u) {
                /** @var \Statamic\Contracts\Auth\User $u */
                return $u->isSuper();
            })->count();
            if ($superCount <= 1) {
                return $this->createErrorResponse('Cannot delete the last super user')->toArray();
            }
        }
        $snapshot = $this->serializeUser($user, true);
        $this->persistStatamic(fn () => $user->delete(), ['stache']);
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'user' => [
                ...$snapshot,
                'deleted' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function activateUser(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot activate users', code: ErrorCode::Forbidden)->toArray();
        }
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
        $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
        if (! $id && ! $email) {
            return $this->createErrorResponse('Either ID or email is required')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\User|null $user */
        $user = $id ? User::find($id) : User::findByEmail((string) $email);
        if (! $user) {
            return $this->missingResource('User', $id ?: $email, $id ? 'id' : 'email')->toArray();
        }
        // Activate user by ensuring they are not blocked/suspended
        // In Statamic, users can be activated by removing any 'blocked' status or similar
        $user->set('status', 'active');
        $this->persistStatamic(fn () => $user->save(), ['stache']);
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'user' => [
                ...$this->serializeUser($user, true),
                'status' => $user->get('status', 'active'),
            ],
            'activated' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function deactivateUser(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot deactivate users', code: ErrorCode::Forbidden)->toArray();
        }
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
        $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
        $reason = is_string($arguments['reason'] ?? null) ? $arguments['reason'] : 'deactivated';
        if (! $id && ! $email) {
            return $this->createErrorResponse('Either ID or email is required')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\User|null $user */
        $user = $id ? User::find($id) : User::findByEmail((string) $email);
        if (! $user) {
            return $this->missingResource('User', $id ?: $email, $id ? 'id' : 'email')->toArray();
        }
        // Prevent deactivating super users as safety measure
        if ($user->isSuper()) {
            return $this->createErrorResponse('Cannot deactivate super users for security reasons')->toArray();
        }
        // Deactivate user by setting status
        $user->set('status', 'inactive');
        $user->set('deactivation_reason', $reason);
        $user->set('deactivated_at', now()->toISOString());
        $this->persistStatamic(fn () => $user->save(), ['stache']);
        // Deactivation must actually cut off access: revoke the user's MCP
        // tokens so existing bearer tokens stop working. AuthenticateForMcp
        // also refuses an inactive user holding a previously issued token.
        app(TokenService::class)->revokeAllForUser((string) $user->id());
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'user' => [
                ...$this->serializeUser($user, true),
                'status' => $user->get('status'),
                'deactivation_reason' => $reason,
            ],
            'deactivated' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function assignRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot assign role to users', code: ErrorCode::Forbidden)->toArray();
        }
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
        $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
        $roleHandle = is_string($arguments['role'] ?? null) ? $arguments['role'] : null;
        if ((! $id && ! $email) || ! $roleHandle) {
            return $this->createErrorResponse('User identifier and role handle are required')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\User|null $user */
        $user = $id ? User::find($id) : User::findByEmail((string) $email);
        if (! $user) {
            return $this->missingResource('User', $id ?: $email, $id ? 'id' : 'email')->toArray();
        }
        $role = Role::find($roleHandle);
        if (! $role) {
            return $this->missingResource('Role', $roleHandle, 'role')->toArray();
        }
        $user->assignRole($role);
        $this->persistStatamic(fn () => $user->save(), ['stache']);
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'user' => [
                ...$this->serializeUser($user, true),
                'role_assigned' => $roleHandle,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function removeRole(array $arguments): array
    {
        if (! $this->hasPermission('edit', 'users')) {
            return $this->createErrorResponse('Permission denied: Cannot remove role from users', code: ErrorCode::Forbidden)->toArray();
        }
        $id = is_string($arguments['id'] ?? null) ? $arguments['id'] : null;
        $email = is_string($arguments['email'] ?? null) ? $arguments['email'] : null;
        $roleHandle = is_string($arguments['role'] ?? null) ? $arguments['role'] : null;
        if ((! $id && ! $email) || ! $roleHandle) {
            return $this->createErrorResponse('User identifier and role handle are required')->toArray();
        }
        /** @var \Statamic\Contracts\Auth\User|null $user */
        $user = $id ? User::find($id) : User::findByEmail((string) $email);
        if (! $user) {
            return $this->missingResource('User', $id ?: $email, $id ? 'id' : 'email')->toArray();
        }
        $role = Role::find($roleHandle);
        if (! $role) {
            return $this->missingResource('Role', $roleHandle, 'role')->toArray();
        }
        $user->removeRole($role);
        $this->persistStatamic(fn () => $user->save(), ['stache']);
        // Clear caches
        $this->clearCachesAfterWrite(['stache']);

        return [
            'user' => [
                ...$this->serializeUser($user, true),
                'role_removed' => $roleHandle,
            ],
        ];
    }

    /**
     * Serialize a Statamic user to an array.
     *
     * @return array<string, mixed>
     */
    private function serializeUser(\Statamic\Contracts\Auth\User $user, bool $includeDetails = false): array
    {
        $data = [
            'id' => (string) $user->id(),
            'email' => $user->email(),
            'name' => $user->name(),
            'super' => $user->isSuper(),
        ];

        if ($includeDetails) {
            $data = array_merge($data, [
                'roles' => $user->roles()->map->handle()->values()->all(),
                'groups' => $user->groups()->map->handle()->values()->all(),
                'preferences' => $user->preferences(),
                'last_login' => $user->lastLogin()?->timestamp,
                'avatar' => $user->avatar(),
                'initials' => $user->initials(),
                'data' => $user->data()->only(array_merge(
                    ['name', 'email', 'avatar', 'status'],
                    $user->blueprint()->fields()->all()->keys()->all(),
                ))->except([
                    'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
                    'two_factor_confirmed_at', 'passkeys', 'webauthn', 'recovery_codes',
                ])->all(),
            ]);
            // Version the public record, never produce a public digest of
            // credentials (including an unsaved plaintext password).
            $data['version'] = self::resourceVersion(array_intersect_key($data, array_flip([
                'id', 'email', 'name', 'super', 'data', 'roles', 'groups', 'preferences',
            ])));
        }

        return $data;
    }
}
