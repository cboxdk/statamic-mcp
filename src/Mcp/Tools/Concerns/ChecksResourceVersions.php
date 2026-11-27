<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Blink;

/**
 * Optimistic concurrency for one stored record: a version token derived from
 * its stored state, and a write that runs only while the record still has the
 * version the caller read.
 */
trait ChecksResourceVersions
{
    /**
     * The record's stored state as an opaque token. Any write to that state,
     * from MCP or not, changes it.
     *
     * @param  array<array-key, mixed>  $state
     */
    protected static function resourceVersion(array $state): string
    {
        return hash('sha256', json_encode(
            self::canonicalForVersion($state),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Run a write only if the record is still the version the caller read.
     *
     * The check and the write run under a per-record lock, so two MCP writers
     * with the same expected version cannot both succeed. A Control Panel or
     * queue write does not take this lock: it is caught when it landed before
     * the check, not when it lands between the check and the save.
     *
     * @param  callable(): (string|array<string, mixed>)  $currentVersion  the stored version, or an error response when the record cannot be read
     * @param  callable(): array<string, mixed>  $write
     *
     * @return array<string, mixed>
     */
    protected function writeIfVersionMatches(mixed $expected, string $noun, string $lockKey, callable $currentVersion, callable $write): array
    {
        if (! is_string($expected) || $expected === '') {
            return $this->createErrorResponse('expected_version must be the version string returned by a previous get, create or update.')->toArray();
        }

        $store = Cache::store()->getStore();
        if (! $store instanceof LockProvider) {
            return $this->createErrorResponse('expected_version requires a cache store with atomic locks.')->toArray();
        }

        try {
            $result = [];
            $store->lock('statamic-mcp:' . $noun . ':' . hash('sha256', $lockKey), 30)
                ->block(5, function () use ($expected, $noun, $currentVersion, $write, &$result): void {
                    Blink::flush();
                    $current = $currentVersion();
                    if (is_array($current)) {
                        $result = $current;

                        return;
                    }
                    if (! hash_equals($current, $expected)) {
                        $result = $this->createErrorResponse(
                            "Version conflict: the {$noun} changed since you read it. Nothing was saved. Get the {$noun} again and redo the change.",
                            ['code' => 'VERSION_CONFLICT', 'current_version' => $current],
                        )->toArray();

                        return;
                    }
                    $result = $write();
                });

            return $result;
        } catch (LockTimeoutException) {
            return $this->createErrorResponse("Another write to this {$noun} is in progress. Nothing was saved; try again.")->toArray();
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @return array<array-key, mixed>
     */
    private static function canonicalForVersion(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::canonicalForVersion($child);
            }
        }

        return $value;
    }
}
