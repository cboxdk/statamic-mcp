<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

trait BuildsPaginatedResponse
{
    /**
     * Build a standardized pagination metadata array.
     *
     * @return array{total: int, limit: int, offset: int, has_more: bool}
     */
    protected function buildPaginationMeta(int $total, int $limit, int $offset): array
    {
        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /**
     * Extract pagination arguments from tool arguments.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array{limit: int, offset: int}
     */
    protected function getPaginationArgs(array $arguments, int $defaultLimit = 50, int $maxLimit = 500): array
    {
        return [
            'limit' => $this->getIntegerArgument($arguments, 'limit', $defaultLimit, 1, $maxLimit),
            'offset' => $this->getIntegerArgument($arguments, 'offset', 0, 0),
        ];
    }
}
