<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Audit;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\DurableAuditStore;
use Illuminate\Support\Facades\Log;

/**
 * File-based append-only audit log store.
 *
 * Each entry is stored as a newline-delimited JSON line.
 */
class FileAuditStore implements DurableAuditStore
{
    private const MAX_FILE_SIZE = 52428800; // 50MB

    private const MAX_BACKUP_FILES = 5;

    private string $path;

    private bool $locked = false;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (function (): string {
            $configured = config('statamic.mcp.storage.audit_path');

            return is_string($configured) ? $configured : storage_path('statamic-mcp/audit.log');
        })();
    }

    /**
     * Append a JSON-encoded entry to the log file.
     *
     * @param  array{level: string, message: string, tool?: string, action?: string, status?: string, correlation_id?: string, duration_ms?: float, timestamp: string, metadata?: array<string, mixed>}  $entry
     */
    public function write(array $entry): void
    {
        $this->withLock(function () use ($entry): void {
            $json = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            $this->rotateIfNeeded();
            $handle = fopen($this->path, 'a+b');
            if ($handle === false) {
                throw new \RuntimeException('Cannot open audit log.');
            }
            try {
                if (fseek($handle, 0, SEEK_END) !== 0) {
                    throw new \RuntimeException('Cannot seek audit log.');
                }
                $size = ftell($handle);
                if ($size === false) {
                    throw new \RuntimeException('Cannot inspect audit log.');
                }
                if ($size > 0) {
                    if (fseek($handle, -1, SEEK_END) !== 0) {
                        throw new \RuntimeException('Cannot inspect audit tail.');
                    }
                    $last = fread($handle, 1);
                    if (! is_string($last) || strlen($last) !== 1) {
                        throw new \RuntimeException('Cannot inspect audit tail.');
                    }
                    // Preserve interrupted tail bytes as evidence, but never join
                    // the next complete record to a partial JSON line.
                    if ($last !== "\n") {
                        $json = "\n" . $json;
                    }
                }
                if (! chmod($this->path, 0600) || fwrite($handle, $json) !== strlen($json)
                    || ! fflush($handle) || ! fsync($handle)) {
                    throw new \RuntimeException('Cannot persist audit entry.');
                }
                $this->syncDirectory();
            } finally {
                fclose($handle);
            }
        });
    }

    public function writeDurable(array $entry): void
    {
        $this->write($entry);
    }

    /**
     * Read, filter, and paginate audit entries (newest first).
     */
    public function query(
        ?string $tool,
        ?string $status,
        int $page,
        int $perPage
    ): AuditResult {
        return $this->withLock(function () use ($tool, $status, $page, $perPage): AuditResult {
            if ($page < 1 || $perPage < 1) {
                throw new \InvalidArgumentException('Audit pagination must be positive.');
            }
            $total = 0;
            foreach ($this->matchingEntries($tool, $status) as $entry) {
                $total++;
            }
            $lastPage = max(1, (int) ceil($total / $perPage));
            // Avoid integer overflow for an arbitrary out-of-range page request.
            $offset = $page > $lastPage ? $total : ($page - 1) * $perPage;
            $end = max(0, $total - $offset);
            $start = max(0, $end - $perPage);
            $pageEntries = [];
            $index = 0;
            if ($end > $start) {
                foreach ($this->matchingEntries($tool, $status) as $entry) {
                    if ($index >= $end) {
                        break;
                    }
                    if ($index >= $start) {
                        $pageEntries[] = $entry;
                    }
                    $index++;
                }
            }
            $pageEntries = array_reverse($pageEntries);

            return new AuditResult(
                entries: $pageEntries,
                total: $total,
                currentPage: $page,
                lastPage: $lastPage,
                perPage: $perPage,
            );
        });
    }

    /**
     * Purge entries from the log.
     *
     * If $before is null, truncates the entire file and returns the count of
     * all entries that were present. Otherwise, removes entries whose timestamp
     * is strictly before $before and rewrites the file with the remaining lines.
     */
    public function purge(?Carbon $before = null): int
    {
        return $this->withLock(function () use ($before): int {
            if (! file_exists($this->path)) {
                return 0;
            }

            if ($before === null) {
                $count = 0;
                $file = new \SplFileObject($this->path, 'r');
                while (! $file->eof()) {
                    $line = $file->fgets();
                    if (trim($line) !== '') {
                        $count++;
                    }
                }
                unset($file);
                $handle = fopen($this->path, 'wb');
                if ($handle === false) {
                    throw new \RuntimeException('Cannot purge audit log.');
                }
                try {
                    if (! fflush($handle) || ! fsync($handle)) {
                        throw new \RuntimeException('Cannot persist audit purge.');
                    }
                } finally {
                    fclose($handle);
                }

                return $count;
            }

            // Write kept lines to a temp file, then replace the original
            $tempPath = $this->path . '.tmp';
            if (is_link($tempPath)) {
                throw new \RuntimeException('Invalid audit temporary file.');
            }
            $purgeCount = 0;

            $reader = new \SplFileObject($this->path, 'r');
            $writer = new \SplFileObject($tempPath, 'w');
            if (! chmod($tempPath, 0600)) {
                throw new \RuntimeException('Cannot restrict audit temporary file.');
            }

            while (! $reader->eof()) {
                $line = $reader->fgets();
                if (trim($line) === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (! is_array($decoded)) {
                    $this->writePurgeLine($writer, rtrim($line, "\n\r") . "\n");

                    continue;
                }

                /** @var array<string, mixed> $decoded */
                $timestamp = isset($decoded['timestamp']) && is_string($decoded['timestamp']) ? Carbon::parse($decoded['timestamp']) : null;

                if ($timestamp !== null && $timestamp->lt($before)) {
                    $purgeCount++;
                } else {
                    $this->writePurgeLine($writer, rtrim($line, "\n\r") . "\n");
                }
            }

            if (! $writer->fflush()) {
                throw new \RuntimeException('Cannot flush audit purge.');
            }
            unset($reader, $writer);
            $handle = fopen($tempPath, 'r+b');
            if ($handle === false) {
                throw new \RuntimeException('Cannot open audit purge.');
            }
            try {
                if (! fsync($handle) || ! rename($tempPath, $this->path)) {
                    throw new \RuntimeException('Cannot commit audit purge.');
                }
                $this->syncDirectory();
            } finally {
                fclose($handle);
            }

            return $purgeCount;
        });
    }

    /**
     * Read all non-empty lines from the log file using line-by-line reading.
     *
     * If the file exceeds MAX_FILE_SIZE, only the last MAX_FILE_SIZE bytes are read
     * to prevent out-of-memory errors on production servers with large audit logs.
     *
     * @return \Generator<int, string>
     */
    private function readLines(): \Generator
    {
        if (! file_exists($this->path)) {
            return;
        }

        $fileSize = filesize($this->path);
        if ($fileSize === false || $fileSize === 0) {
            return;
        }

        $seekOffset = 0;
        if ($fileSize > self::MAX_FILE_SIZE) {
            Log::warning('Audit log file exceeds 50MB, reading only the last 50MB', [
                'path' => $this->path,
                'size_bytes' => $fileSize,
            ]);
            $seekOffset = $fileSize - self::MAX_FILE_SIZE;
        }

        $file = new \SplFileObject($this->path, 'r');

        if ($seekOffset > 0) {
            $file->fseek($seekOffset);
            // Discard partial first line after seeking
            $file->fgets();
        }

        while (! $file->eof()) {
            $line = $file->fgets();
            if (trim($line) !== '') {
                yield rtrim($line, "\n\r");
            }
        }

    }

    /**
     * Rotate the log file if it exceeds the maximum size.
     */
    private function rotateIfNeeded(): void
    {
        if (! file_exists($this->path)) {
            return;
        }

        clearstatcache(true, $this->path);
        $size = @filesize($this->path);

        if ($size === false || $size < self::MAX_FILE_SIZE) {
            return;
        }

        $date = date('Y-m-d');
        $rotatedPath = $this->path . '.' . $date . '.bak';

        // Handle multiple rotations on the same day
        $suffix = 0;
        while (file_exists($rotatedPath)) {
            $suffix++;
            $rotatedPath = $this->path . '.' . $date . '.bak.' . $suffix;
        }

        if (! rename($this->path, $rotatedPath)) {
            throw new \RuntimeException('Cannot rotate audit log.');
        }
        $this->pruneBackups();
    }

    /**
     * Remove old backup files beyond the retention limit.
     */
    private function pruneBackups(): void
    {
        $pattern = $this->path . '.*.bak*';
        $backups = glob($pattern);

        if ($backups === false || count($backups) <= self::MAX_BACKUP_FILES) {
            return;
        }

        // Sort by modification time, newest first
        usort($backups, fn (string $a, string $b): int => (int) filemtime($b) - (int) filemtime($a));

        // Delete everything beyond the retention limit
        foreach (array_slice($backups, self::MAX_BACKUP_FILES) as $oldBackup) {
            if (! unlink($oldBackup)) {
                throw new \RuntimeException('Cannot prune audit backup.');
            }
        }
    }

    private function writePurgeLine(\SplFileObject $writer, string $line): void
    {
        if ($writer->fwrite($line) !== strlen($line)) {
            throw new \RuntimeException('Cannot write audit purge.');
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function matchingEntries(?string $tool, ?string $status): \Generator
    {
        foreach ($this->readLines() as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }
            $entry = [];
            foreach ($decoded as $key => $value) {
                if (! is_string($key)) {
                    continue 2;
                }
                $entry[$key] = $value;
            }
            $toolValue = is_string($entry['tool'] ?? null) ? $entry['tool'] : '';
            if ($tool !== null && ! str_contains($toolValue, $tool)) {
                continue;
            }
            if ($status !== null && ($entry['status'] ?? '') !== $status) {
                continue;
            }
            yield $entry;
        }
    }

    private function syncDirectory(): void
    {
        $handle = fopen(dirname($this->path), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open audit directory.');
        }
        try {
            if (! fsync($handle)) {
                throw new \RuntimeException('Cannot persist audit directory.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @template T
     * @param  callable(): T  $callback
     *
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        if ($this->locked) {
            return $callback();
        }
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create audit directory.');
        }
        $handle = fopen($this->path . '.lock', 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open audit lock.');
        }
        try {
            $deadline = hrtime(true) + 5_000_000_000;
            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Audit storage is busy.');
                }
                usleep(10000);
            }
            $this->locked = true;
            clearstatcache();

            return $callback();
        } finally {
            $this->locked = false;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
