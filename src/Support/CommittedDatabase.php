<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

use Closure;
use Illuminate\Database\Connection;

/** Acknowledged security state must not depend on an unrelated host transaction. */
final class CommittedDatabase
{
    /** @template T
     * @param  Closure(): T  $callback
     *
     * @return T
     */
    public static function run(Connection $connection, Closure $callback): mixed
    {
        self::check($connection);
        $result = $callback();
        self::check($connection);

        return $result;
    }

    private static function check(Connection $connection): void
    {
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new \LogicException('Security storage requires an independent committed connection.');
        }
    }
}
