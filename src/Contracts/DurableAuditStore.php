<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Contracts;

/** Explicit capability required before a registered action can write content. */
interface DurableAuditStore extends AuditStore
{
    /** Return only after persistence, independently of a caller's open transaction.
     * Throw on unavailable storage, veto or incomplete persistence.
     *
     * @param  array{level: string, message: string, tool?: string, action?: string, status?: string, correlation_id?: string, duration_ms?: float, timestamp: string, metadata?: array<string, mixed>}  $entry
     */
    public function writeDurable(array $entry): void;
}
