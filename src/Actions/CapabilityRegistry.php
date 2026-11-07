<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

final class CapabilityRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $actions = [];

    /** @var array<string, true> */
    private array $toolNames = [];

    private bool $frozen = false;

    public function __construct(private readonly ScopeRegistry $scopes) {}

    public function register(ActionDefinition $action): void
    {
        if ($this->frozen || isset($this->actions[$action->id()]) || isset($this->toolNames[$action->toolName()])
            || count($this->actions) >= 1024) {
            throw new \LogicException('Action registration is duplicated or frozen.');
        }
        foreach ($action->scopes as $scope) {
            if (! $this->scopes->belongsTo($scope, $action->package)) {
                throw new \LogicException('Action scopes must be registered by the owning package.');
            }
        }
        $this->actions[$action->id()] = $action;
        $this->toolNames[$action->toolName()] = true;
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
        foreach ($this->actions as $action) {
            // A wildcard for legacy core scopes never grants addon capabilities.
            if (array_diff($action->scopes, $scopes) === []) {
                $actions[$action->id()] = $action->fingerprint();
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
        $snapshot = [];
        foreach ($ids as $id) {
            $snapshot[$id] = $this->get($id)->fingerprint();
        }
        ksort($snapshot);

        return $snapshot;
    }
}
