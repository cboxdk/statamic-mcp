<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

/** Metadata only: never carries arguments, output, credentials or exceptions. */
final readonly class ExecutionEvent
{
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
    ) {}
}
