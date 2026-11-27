<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;

/**
 * Something happened to an approval. Metadata only: the approval id (an opaque
 * operation id), the action it covers and how it was decided; never the call's
 * arguments, the description or who the person is.
 */
abstract readonly class ApprovalEvent implements LifecycleEvent
{
    protected const NAME = '';

    public function __construct(
        public string $id,
        public string $approvalId,
        public string $actionId,
        public string $occurredAt,
        public ?string $via = null,
        public ?string $reason = null,
        public int $schemaVersion = 1,
    ) {
        if (static::NAME === '' || $schemaVersion !== 1
            || ($via !== null && ! in_array($via, ['control_panel', 'authority'], true))
            || ($reason !== null && ! in_array($reason, ['person', 'stale'], true))) {
            throw new \InvalidArgumentException('Invalid approval event metadata.');
        }
    }

    public function eventId(): string
    {
        return $this->id;
    }

    public function eventName(): string
    {
        return static::NAME;
    }

    public function metadata(): array
    {
        return ['schema_version' => $this->schemaVersion, 'event_id' => $this->id, 'event' => $this->eventName(),
            'approval_id' => $this->approvalId, 'operation_id' => $this->approvalId, 'action_id' => $this->actionId,
            'via' => $this->via, 'reason' => $this->reason, 'occurred_at' => $this->occurredAt];
    }
}
