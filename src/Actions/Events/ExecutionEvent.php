<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;

/** Metadata only: never carries arguments, output, credentials or exceptions. */
readonly class ExecutionEvent implements LifecycleEvent
{
    protected const OUTCOME = null;

    public function __construct(
        public string $id,
        public string $operationId,
        public string $actionId,
        public int $actionVersion,
        public string $transport,
        public string $outcome,
        public string $effects,
        public float $durationMilliseconds,
        public string $occurredAt,
        public int $schemaVersion = 1,
        public ?string $executionId = null,
    ) {
        if (! in_array($outcome, ['started', 'completed', 'denied', 'failed', 'replayed'], true)
            || (static::OUTCOME !== null && static::OUTCOME !== $outcome)
            || ! in_array($effects, ['none', 'committed', 'unknown'], true)
            || ! in_array($transport, ['http', 'stdio'], true)
            || ! is_finite($durationMilliseconds) || $durationMilliseconds < 0
            || $schemaVersion !== 1 || $actionVersion < 1) {
            throw new \InvalidArgumentException('Invalid execution event metadata.');
        }
    }

    public function eventId(): string
    {
        return $this->id;
    }

    public function eventName(): string
    {
        return 'execution.' . $this->outcome;
    }

    public function metadata(): array
    {
        return ['schema_version' => $this->schemaVersion, 'event_id' => $this->id,
            'event' => $this->eventName(), 'operation_id' => $this->operationId, 'execution_id' => $this->executionId,
            'action_id' => $this->actionId, 'action_version' => $this->actionVersion,
            'transport' => $this->transport, 'outcome' => $this->outcome, 'effects' => $this->effects,
            'duration_ms' => $this->durationMilliseconds, 'occurred_at' => $this->occurredAt];
    }
}
