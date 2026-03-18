<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Audit;

/**
 * Paginated result set from audit store queries.
 */
class AuditResult
{
    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $perPage,
    ) {}
}
