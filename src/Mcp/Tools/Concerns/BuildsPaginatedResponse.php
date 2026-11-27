<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

trait BuildsPaginatedResponse
{
    /**
     * A page of an already-authorized, deterministically ordered result set.
     * A cursor is a position, not a snapshot: changes between calls may move
     * records. It grants no access; each page runs authorization again.
     *
     * @param  array<array-key, array<string, mixed>>  $items
     * @param  array<string, mixed>  $arguments
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, total: int}
     */
    protected function buildPage(array $items, int $total, int $offset, array $arguments): array
    {
        $next = $offset + count($items);

        return [
            'items' => array_values($items),
            'next_cursor' => $items === [] ? null : $this->nextCursor($total, $next, $arguments),
            'total' => $total,
        ];
    }

    /**
     * Paginate small configuration catalogs after filtering and serialization.
     * Content repositories paginate their query instead.
     *
     * @param  array<array-key, array{handle: string}>  $items
     * @param  array<string, mixed>  $arguments
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string, total: int}
     */
    protected function paginateItems(array $items, array $arguments): array
    {
        usort($items, static fn (array $a, array $b): int => strcmp($a['handle'], $b['handle']));
        ['limit' => $limit, 'offset' => $offset] = $this->getPaginationArgs($arguments);

        return $this->buildPage(array_slice($items, $offset, $limit), count($items), $offset, $arguments);
    }

    /** @param array<string, mixed> $arguments */
    private function cursorQuery(array $arguments): string
    {
        unset($arguments['cursor'], $arguments['limit']);

        return hash('sha256', $this->name() . json_encode(Arr::sortRecursive($arguments), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $arguments */
    private function cursorOffset(array $arguments): int
    {
        $cursor = $arguments['cursor'] ?? null;
        if ($cursor === null) {
            return 0;
        }
        $decoded = null;
        if (is_string($cursor) && strlen($cursor) <= 4096) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                // Refuse, rather than silently returning the first page again.
            }
        }
        if (! is_array($decoded) || ($decoded['v'] ?? null) !== 1
            || ! is_int($decoded['offset'] ?? null) || $decoded['offset'] < 0
            || ($decoded['query'] ?? null) !== $this->cursorQuery($arguments)) {
            throw new ToolFailure(ErrorCode::Validation, 'Invalid cursor or different query. Reuse next_cursor with the same arguments, or omit cursor to start again.', 'cursor');
        }

        return $decoded['offset'];
    }

    /** @param array<string, mixed> $arguments */
    protected function nextCursor(int $total, int $next, array $arguments): ?string
    {
        return $next < $total
            ? Crypt::encryptString(json_encode(['v' => 1, 'offset' => $next, 'query' => $this->cursorQuery($arguments)], JSON_THROW_ON_ERROR))
            : null;
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
            'offset' => $this->cursorOffset($arguments),
        ];
    }
}
