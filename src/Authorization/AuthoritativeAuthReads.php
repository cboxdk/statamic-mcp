<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Statamic\Auth\Eloquent\User;
use Statamic\Auth\Eloquent\UserRepository;
use Statamic\Contracts\Auth\UserRepository as Repository;

/** Native SQL authorization must not depend on replication lag. */
final class AuthoritativeAuthReads
{
    public function __construct(private readonly Repository $users, private readonly DatabaseManager $database) {}

    /** @template T
     * @param  Closure(): T  $callback
     *
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        if (! $this->users instanceof UserRepository) {
            return $callback();
        }
        $actor = $this->users->make();
        if (! $actor instanceof User || ! ($model = $actor->model()) instanceof Model) {
            throw new \LogicException('Cannot resolve the native user database connection.');
        }
        $name = config('statamic.users.database');
        if ($name !== null && ! is_string($name)) {
            throw new \LogicException('Invalid native user database connection.');
        }
        $connections = [$model->getConnection(), $this->database->connection($name)];
        $restore = [];
        try {
            foreach ($connections as $connection) {
                $id = spl_object_id($connection);
                if (isset($restore[$id])) {
                    continue;
                }
                if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                    throw new \LogicException('Native SQL authorization requires committed reads outside a transaction.');
                }
                $restore[$id] = [$connection, $connection->getRawReadPdo()];
                $connection->setReadPdo($connection->getPdo());
            }

            return $callback();
        } finally {
            foreach (array_reverse($restore) as [$connection, $pdo]) {
                $connection->setReadPdo($pdo);
            }
        }
    }
}
