<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;

/** Single-node durable store. The lock is held across the atomic rename. */
final class FileOperationStore implements OperationStore
{
    public function __construct(private readonly string $directory, private readonly int $maxRecords = 256)
    {
        if ($maxRecords < 1) {
            throw new \InvalidArgumentException('Operation capacity must be positive.');
        }
    }

    public function create(Operation $operation): bool
    {
        if ($operation->state !== OperationState::Prepared || $operation->version !== 1) {
            throw new \InvalidArgumentException('New operations must be prepared at version one.');
        }

        return $this->locked($operation->id, function (string $path) use ($operation): bool {
            if (is_file($path)) {
                return false;
            }
            $count = $this->countRecords();
            if ($count >= $this->maxRecords) {
                throw new \RuntimeException('Operation storage is full. Prune expired operations or reconcile unknown outcomes.');
            }
            // Reserve before persistence: a crash may overcount, never undercount.
            $this->writeCount($count + 1);
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
            || $next->encryptedIntent !== $expected->encryptedIntent
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
            if ($current->expiresAt <= now()->getTimestamp() && in_array($next->state, [OperationState::Ready, OperationState::Executing], true)) {
                return false;
            }
            $this->write($path, $next);

            return true;
        });
    }

    public function prune(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Prune batch must contain 1–1000 records.');
        }

        return $this->locked(str_repeat('0', 64), function () use ($limit): int {
            $count = $this->countRecords();
            $deleted = 0;
            foreach ($this->recordPaths() as $path) {
                $record = $this->read($path);
                if ($record === null || $record->expiresAt > now()->getTimestamp()
                    || in_array($record->state, [OperationState::Executing, OperationState::OutcomeUnknown], true)) {
                    continue;
                }
                if (! unlink($path)) {
                    throw new \RuntimeException('Cannot prune operation record.');
                }
                if (is_file($path . '.tmp') && ! unlink($path . '.tmp')) {
                    throw new \RuntimeException('Cannot prune operation temporary file.');
                }
                $deleted++;
                if ($deleted >= $limit) {
                    break;
                }
            }
            $this->syncDirectory();
            // Lower the reservation only after deletion is durable.
            if ($count < $deleted) {
                throw new \RuntimeException('Operation capacity metadata is corrupt.');
            }
            $this->writeCount($count - $deleted);

            return $deleted;
        });
    }

    public function reconcileCapacity(): int
    {
        return $this->locked(str_repeat('0', 64), function (): int {
            $count = $this->occupiedSlots();
            $this->writeCount($count);

            return $count;
        });
    }

    /** @return \Generator<int, string> */
    private function recordPaths(): \Generator
    {
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if ($file->isFile() && preg_match('/^[a-f0-9]{64}\.json$/D', $file->getFilename())) {
                yield $file->getPathname();
            }
        }
    }

    private function occupiedSlots(): int
    {
        $slots = [];
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if ($file->isFile() && preg_match('/^([a-f0-9]{64})\.json(?:\.tmp)?$/D', $file->getFilename(), $match)) {
                // An orphan candidate may contain recovery evidence. Keep its slot.
                $slots[$match[1]] = true;
            }
        }

        return count($slots);
    }

    private function countRecords(): int
    {
        $path = $this->directory . '/.count';
        if (! is_file($path)) {
            $count = $this->occupiedSlots();
            $this->writeCount($count);

            return $count;
        }
        $raw = file_get_contents($path, length: 32);
        if (! is_string($raw) || ! preg_match('/^[0-9]{1,10}$/D', $raw)) {
            throw new \RuntimeException('Operation capacity metadata is corrupt.');
        }

        return (int) $raw;
    }

    private function writeCount(int $count): void
    {
        $temporary = $this->directory . '/.count.tmp';
        if (is_link($temporary)) {
            throw new \RuntimeException('Invalid operation capacity temporary file.');
        }
        $handle = fopen($temporary, 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot reserve operation capacity.');
        }
        try {
            $data = (string) $count;
            if (! chmod($temporary, 0600) || fwrite($handle, $data) !== strlen($data)
                || ! fflush($handle) || ! fsync($handle) || ! rename($temporary, $this->directory . '/.count')) {
                throw new \RuntimeException('Cannot persist operation capacity.');
            }
            $this->syncDirectory();
        } finally {
            fclose($handle);
        }
    }

    private function syncDirectory(): void
    {
        $directory = fopen($this->directory, 'r');
        if ($directory === false) {
            throw new \RuntimeException('Cannot open operation directory.');
        }
        try {
            if (! fsync($directory)) {
                throw new \RuntimeException('Cannot persist operation directory.');
            }
        } finally {
            fclose($directory);
        }
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
        $temporary = $path . '.tmp';
        if (is_link($temporary)) {
            throw new \RuntimeException('Invalid operation temporary file.');
        }
        $handle = fopen($temporary, 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create operation record.');
        }
        try {
            if (! chmod($temporary, 0600)) {
                throw new \RuntimeException('Cannot restrict operation record permissions.');
            }
            $json = json_encode($operation->toArray(), JSON_THROW_ON_ERROR);
            if (strlen($json) > 3_000_000) {
                throw new \RuntimeException('Operation record exceeds its storage budget.');
            }
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle) || ! fsync($handle)) {
                throw new \RuntimeException('Cannot persist operation record.');
            }
            if (! rename($temporary, $path)) {
                throw new \RuntimeException('Cannot commit operation record.');
            }
            $this->syncDirectory();
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
        // One permanent lock bounds artifacts even for lookups of unknown handles.
        // Never replace this inode while workers are running.
        $lock = fopen($this->directory . '/.store.lock', 'c+b');
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
