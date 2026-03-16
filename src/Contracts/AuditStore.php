<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Contracts;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Audit\AuditResult;

interface AuditStore
{
    /**
     * Write an audit entry to the store.
     *
     * @param  array{level: string, message: string, tool?: string, action?: string, status?: string, correlation_id?: string, duration_ms?: float, timestamp: string, metadata?: array<string, mixed>}  $entry
     */
    public function write(array $entry): void;

    /**
     * Query audit entries with optional filtering and pagination.
     */
    public function query(
        ?string $tool,
        ?string $status,
        int $page,
        int $perPage
    ): AuditResult;

    /**
     * Purge entries older than the given date. Returns count deleted.
     * If null, purges all entries.
     */
    public function purge(?Carbon $before = null): int;
}
