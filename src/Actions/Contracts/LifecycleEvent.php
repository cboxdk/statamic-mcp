<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Contracts;

/** Metadata-only observation. Security decisions belong to policies, never listeners. */
interface LifecycleEvent
{
    public function eventId(): string;

    public function eventName(): string;

    /** @return array<string, string|int|float|bool|null> */
    public function metadata(): array;
}
