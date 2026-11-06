<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;

/** Single-node durable store. The lock is held across the atomic rename. */
final class FileOperationStore implements OperationStore
{
    public function __construct(private readonly string $directory) {}

    public function create(Operation $operation): bool
    {
        if ($operation->state !== OperationState::Prepared || $operation->version !== 1) {
            throw new \InvalidArgumentException('New operations must be prepared at version one.');
        }

        return $this->locked($operation->id, function (string $path) use ($operation): bool {
            if (is_file($path)) {
                return false;
            }
            $this->write($path, $operation);

            return true;
        });
    }

    public function find(string $id): ?Operation
    {
        return $this->locked($id, fn (string $path): ?Operation => $this->read($path));
    }

    public function transition(Operation $expected, Operation $next): bool
    {
        if ($next->id !== $expected->id || $next->bindingDigest !== $expected->bindingDigest
            || $next->expiresAt !== $expected->expiresAt || $next->version !== $expected->version + 1
            || ! $expected->state->canTransitionTo($next->state)) {
            throw new \InvalidArgumentException('Invalid operation transition binding.');
        }

        return $this->locked($expected->id, function (string $path) use ($expected, $next): bool {
            $current = $this->read($path);
            if ($current === null || $current->toArray() !== $expected->toArray()) {
                return false;
            }
            // Recheck expiry inside the lock, not only when the candidate was built.
            if ($current->expiresAt <= time() && in_array($next->state, [OperationState::Ready, OperationState::Executing], true)) {
                return false;
            }
            $this->write($path, $next);

            return true;
        });
    }

    private function read(string $path): ?Operation
    {
        if (! is_file($path)) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size > 3_000_000) {
            throw new \RuntimeException('Operation record exceeds its storage budget.');
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Cannot read operation record.');
        }

        return Operation::fromJson($json);
    }

    private function write(string $path, Operation $operation): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create operation record.');
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw new \RuntimeException('Cannot restrict operation record permissions.');
            }
            $json = json_encode($operation->toArray(), JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle) || ! fsync($handle)) {
                throw new \RuntimeException('Cannot persist operation record.');
            }
            if (! rename($temporary, $path)) {
                throw new \RuntimeException('Cannot commit operation record.');
            }
        } finally {
            fclose($handle);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @template T
     * @param  callable(string): T  $callback
     *
     * @return T
     */
    private function locked(string $id, callable $callback): mixed
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid operation id.');
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create operation storage.');
        }
        // Never delete/recreate lock files: that would allow two different inodes to be locked.
        $lock = fopen($this->directory . '/' . $id . '.lock', 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open operation lock.');
        }
        try {
            $deadline = hrtime(true) + 5_000_000_000;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Operation storage is busy.');
                }
                usleep(10000);
            }

            return $callback($this->directory . '/' . $id . '.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
