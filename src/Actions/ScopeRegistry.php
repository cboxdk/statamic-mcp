<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

final class ScopeRegistry
{
    /** @var array<string, array{package: string, label: string}> */
    private array $scopes = [];

    private bool $frozen = false;

    public function register(string $package, string $scope, string $label): void
    {
        if ($this->frozen || isset($this->scopes[$scope]) || trim($label) === ''
            || ! preg_match('/^[a-z][a-z0-9._-]*:[a-z][a-z0-9._-]*$/D', $scope)) {
            throw new \LogicException('Scope registration is invalid, duplicated or frozen.');
        }
        $this->scopes[$scope] = ['package' => $package, 'label' => $label];
    }

    public function belongsTo(string $scope, string $package): bool
    {
        return ($this->scopes[$scope]['package'] ?? null) === $package;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
