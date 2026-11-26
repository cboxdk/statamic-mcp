<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;

/** Transport rejection, without URLs, input, identity or credentials. */
final readonly class RequestRejected implements LifecycleEvent
{
    public function __construct(
        public string $id,
        public string $correlationId,
        public int $status,
        public float $durationMilliseconds,
        public ?string $traceparent,
    ) {}

    public function eventId(): string
    {
        return $this->id;
    }

    public function eventName(): string
    {
        return 'request.rejected';
    }

    public function metadata(): array
    {
        $code = match ($this->status) {
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            413 => 'TOO_LARGE',
            429 => 'RATE_LIMITED',
            default => 'VALIDATION',
        };

        return ['schema_version' => 1, 'event_id' => $this->id, 'event' => $this->eventName(),
            'transport' => 'http', 'correlation_id' => $this->correlationId, 'http_status' => $this->status,
            'reason' => strtolower($code), 'error_code' => $code, 'duration_ms' => $this->durationMilliseconds,
            'traceparent' => $this->traceparent, 'occurred_at' => gmdate('c')];
    }
}
