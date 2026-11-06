<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

final class CapabilityRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $actions = [];

    private bool $frozen = false;

    public function __construct(private readonly ScopeRegistry $scopes) {}

    public function register(ActionDefinition $action): void
    {
        if ($this->frozen || isset($this->actions[$action->id()])) {
            throw new \LogicException('Action registration is duplicated or frozen.');
        }
        foreach ($action->scopes as $scope) {
            if (! $this->scopes->belongsTo($scope, $action->package)) {
                throw new \LogicException('Action scopes must be registered by the owning package.');
            }
        }
        $this->actions[$action->id()] = $action;
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
