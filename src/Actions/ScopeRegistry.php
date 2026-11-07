<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Statamic\Contracts\Auth\User;

final class ScopeRegistry
{
    /** @var array<string, array{package: string, label: string, permissions: list<string>}> */
    private array $scopes = [];

    private bool $frozen = false;

    /** @param list<string> $permissions All named Statamic permissions are required; empty means super users only. */
    public function register(string $package, string $scope, string $label, array $permissions = []): void
    {
        if ($this->frozen || isset($this->scopes[$scope]) || trim($label) === ''
            || strlen($label) > 200
            || ! preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/D', $package)
            || (TokenScope::tryFrom($scope) !== null && $package !== 'cboxdk/statamic-mcp')
            || ! preg_match('/^[a-z][a-z0-9._-]*:[a-z][a-z0-9._-]*$/D', $scope)) {
            throw new \LogicException('Scope registration is invalid, duplicated or frozen.');
        }
        foreach ($permissions as $permission) {
            if (trim($permission) === '') {
                throw new \LogicException('Scope permissions cannot be empty.');
            }
        }
        $this->scopes[$scope] = ['package' => $package, 'label' => $label, 'permissions' => $permissions];
    }

    public function belongsTo(string $scope, string $package): bool
    {
        return ($this->scopes[$scope]['package'] ?? null) === $package;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->scopes);
    }

    public function label(string $scope): ?string
    {
        return $this->scopes[$scope]['label'] ?? null;
    }

    public function fingerprint(string $scope): ?string
    {
        $definition = $this->scopes[$scope] ?? null;
        if ($definition === null) {
            return null;
        }
        sort($definition['permissions']);

        return hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
    }

    public function allows(?User $user, string $scope): bool
    {
        $definition = $this->scopes[$scope] ?? null;
        if ($definition === null || $user === null || $user->get('status') === 'inactive') {
            return false;
        }
        if ($user->isSuper()) {
            return true;
        }
        if ($definition['permissions'] === []) {
            return false;
        }
        foreach ($definition['permissions'] as $permission) {
            if (! $user->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
