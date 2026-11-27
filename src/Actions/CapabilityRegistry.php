<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

final class CapabilityRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $actions = [];

    /** @var array<string, true> */
    private array $toolNames = [];

    /** @var array<string, array{fingerprint: string, scopes: list<string>}> */
    private array $bindings = [];

    private bool $frozen = false;

    public function __construct(private readonly ScopeRegistry $scopes) {}

    public function register(ActionDefinition $action): void
    {
        if ($this->frozen || isset($this->actions[$action->id()]) || isset($this->toolNames[$action->toolName()])
            || count($this->actions) >= 1024) {
            throw new \LogicException('Action registration is duplicated or frozen.');
        }
        $this->registerBindings($action->package, [$action->id() => ['fingerprint' => $action->fingerprint(), 'scopes' => $action->scopes]]);
        $this->actions[$action->id()] = $action;
        $this->toolNames[$action->toolName()] = true;
    }

    /** Register a whole router atomically, without creating a tool per action.
     * @param  array<string, array{fingerprint: string, scopes: list<string>}>  $bindings
     */
    public function registerBindings(string $package, array $bindings): void
    {
        if ($this->frozen || count($this->bindings) + count($bindings) > 1024) {
            throw new \LogicException('Capability registration is invalid, duplicated or frozen.');
        }
        foreach ($bindings as $id => $binding) {
            if (isset($this->bindings[$id]) || ! str_starts_with($id, $package . '/') || $binding['scopes'] === []) {
                throw new \LogicException('Capability registration is invalid, duplicated or frozen.');
            }
            new CapabilitySnapshot([$id => $binding['fingerprint']]);
            foreach ($binding['scopes'] as $scope) {
                if (! $this->scopes->belongsTo($scope, $package)) {
                    throw new \LogicException('Action scopes must be registered by the owning package.');
                }
            }
        }
        $this->bindings += $bindings;
    }

    public function freeze(): void
    {
        $this->scopes->freeze();
        ksort($this->actions);
        $this->frozen = true;
    }

    public function get(string $id): ActionDefinition
    {
        if (! $this->frozen) {
            throw new \LogicException('Freeze the registry before serving actions.');
        }

        return $this->actions[$id] ?? throw new \InvalidArgumentException('Unknown action.');
    }

    /** @return list<ActionDefinition> */
    public function definitions(): array
    {
        if (! $this->frozen) {
            throw new \LogicException('Freeze the registry before serving actions.');
        }

        return array_values($this->actions);
    }

    /** @param list<string> $scopes */
    public function forScopes(array $scopes): CapabilitySnapshot
    {
        if (! $this->frozen) {
            throw new \LogicException('Freeze the registry before issuing grants.');
        }

        $actions = [];
        foreach ($this->bindings as $id => $binding) {
            // A wildcard for legacy core scopes never grants addon capabilities.
            if (array_diff($binding['scopes'], $scopes) === []) {
                $actions[$id] = $binding['fingerprint'];
            }
        }

        $definitions = [];
        foreach ($scopes as $scope) {
            $fingerprint = $this->scopes->fingerprint($scope);
            if ($fingerprint !== null) {
                $definitions[$scope] = $fingerprint;
            }
        }

        return new CapabilitySnapshot($actions, scopes: $definitions);
    }

    /** Capture only the actions the issuer actually approved, never '*'.
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    public function snapshot(array $ids): array
    {
        if (! $this->frozen) {
            throw new \LogicException('Freeze the registry before issuing grants.');
        }
        $snapshot = [];
        foreach ($ids as $id) {
            $snapshot[$id] = $this->bindings[$id]['fingerprint'] ?? throw new \InvalidArgumentException('Unknown action.');
        }
        ksort($snapshot);

        return $snapshot;
    }
}
