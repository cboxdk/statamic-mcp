<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;

/** A grant reference is an identifier, never a credential or a raw OAuth client URL. */
abstract readonly class GrantEvent implements LifecycleEvent
{
    protected const NAME = '';

    public function __construct(
        public string $id,
        public string $grantId,
        public string $transport,
        public string $occurredAt,
        public int $scopeCount = 0,
        public int $schemaVersion = 1,
    ) {
        if (! in_array($transport, ['http', 'stdio', 'cp', 'internal'], true)
            || $scopeCount < 0 || $schemaVersion !== 1 || static::NAME === '') {
            throw new \InvalidArgumentException('Invalid grant event metadata.');
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
        return ['schema_version' => $this->schemaVersion, 'event_id' => $this->id,
            'event' => $this->eventName(), 'grant_id' => $this->grantId,
            'transport' => $this->transport, 'occurred_at' => $this->occurredAt, 'scope_count' => $this->scopeCount];
    }
}
