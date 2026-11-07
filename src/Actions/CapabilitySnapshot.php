<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

/** Immutable consent: adding a registration or changing its definition cannot expand it. */
final readonly class CapabilitySnapshot
{
    /** @var array<string, string> */
    public array $actions;

    /** Stable consent identity, independent of a rotating access-token credential. */
    public string $grantId;

    /** @var array<string, string> */
    public array $scopes;

    /** @param array<string, string> $actions
     * @param  array<string, string>  $scopes
     */
    public function __construct(array $actions, ?string $grantId = null, array $scopes = [])
    {
        $grantId ??= bin2hex(random_bytes(32));
        if (! preg_match('/^[a-f0-9]{64}$/D', $grantId)) {
            throw new \InvalidArgumentException('Invalid grant identity.');
        }
        $this->grantId = $grantId;
        if (count($actions) > 1024 || count($scopes) > 1024) {
            throw new \InvalidArgumentException('Capability snapshot exceeds its budget.');
        }
        foreach ($actions as $id => $fingerprint) {
            if (! preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\/[a-z][a-z0-9_-]{0,63}$/D', $id)
                || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                throw new \InvalidArgumentException('Invalid capability snapshot.');
            }
        }
        foreach ($scopes as $scope => $fingerprint) {
            if (! preg_match('/^[a-z][a-z0-9._-]*:[a-z][a-z0-9._-]*$/D', $scope)
                || ! preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                throw new \InvalidArgumentException('Invalid scope snapshot.');
            }
        }
        ksort($actions);
        ksort($scopes);
        $this->actions = $actions;
        $this->scopes = $scopes;
    }

    public function permits(ActionDefinition $action): bool
    {
        $approved = $this->actions[$action->id()] ?? null;

        return $approved !== null && hash_equals($approved, $action->fingerprint());
    }

    /** @return array{grant_id: string, actions: array<string, string>, scopes: array<string, string>} */
    public function toStored(): array
    {
        return ['grant_id' => $this->grantId, 'actions' => $this->actions, 'scopes' => $this->scopes];
    }

    public static function fromStored(mixed $value): ?self
    {
        if ($value === null) {
            return null; // Legacy grant: never treated as wildcard consent.
        }
        if (! is_array($value) || ! is_string($value['grant_id'] ?? null) || ! is_array($value['actions'] ?? null) || ! is_array($value['scopes'] ?? null)) {
            throw new \UnexpectedValueException('Invalid stored capability snapshot.');
        }
        $actions = [];
        foreach ($value['actions'] as $id => $fingerprint) {
            if (! is_string($id) || ! is_string($fingerprint)) {
                throw new \UnexpectedValueException('Invalid stored capability snapshot.');
            }
            $actions[$id] = $fingerprint;
        }

        $scopes = [];
        foreach ($value['scopes'] as $scope => $fingerprint) {
            if (! is_string($scope) || ! is_string($fingerprint)) {
                throw new \UnexpectedValueException('Invalid stored scope snapshot.');
            }
            $scopes[$scope] = $fingerprint;
        }

        return new self($actions, $value['grant_id'], $scopes);
    }
}
