<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Audit;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Illuminate\Support\Facades\Log;

/**
 * File-based append-only audit log store.
 *
 * Each entry is stored as a newline-delimited JSON line.
 */
class FileAuditStore implements AuditStore
{
    private const MAX_FILE_SIZE = 52428800; // 50MB

    private string $path;

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
        $dir = dirname($this->path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->path, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
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
        $lines = $this->readLines();

        // Reverse so newest entries (last written) come first
        $lines = array_reverse($lines);

        /** @var array<int, array<string, mixed>> $entries */
        $entries = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            /** @var array<string, mixed> $decoded */
            $toolValue = isset($decoded['tool']) && is_string($decoded['tool']) ? $decoded['tool'] : '';
            if ($tool !== null && ! str_contains($toolValue, $tool)) {
                continue;
            }

            $statusValue = isset($decoded['status']) && is_string($decoded['status']) ? $decoded['status'] : '';
            if ($status !== null && $statusValue !== $status) {
                continue;
            }

            $entries[] = $decoded;
        }

        $total = count($entries);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $pageEntries = array_slice($entries, $offset, $perPage);

        return new AuditResult(
            entries: $pageEntries,
            total: $total,
            currentPage: $page,
            lastPage: $lastPage,
            perPage: $perPage,
        );
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
            file_put_contents($this->path, '', LOCK_EX);

            return $count;
        }

        // Write kept lines to a temp file, then replace the original
        $tempPath = $this->path . '.tmp.' . uniqid();
        $purgeCount = 0;

        $reader = new \SplFileObject($this->path, 'r');
        $writer = new \SplFileObject($tempPath, 'w');

        while (! $reader->eof()) {
            $line = $reader->fgets();
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                $writer->fwrite(rtrim($line, "\n\r") . "\n");

                continue;
            }

            /** @var array<string, mixed> $decoded */
            $timestamp = isset($decoded['timestamp']) && is_string($decoded['timestamp']) ? Carbon::parse($decoded['timestamp']) : null;

            if ($timestamp !== null && $timestamp->lt($before)) {
                $purgeCount++;
            } else {
                $writer->fwrite(rtrim($line, "\n\r") . "\n");
            }
        }

        unset($reader, $writer);
        rename($tempPath, $this->path);

        return $purgeCount;
    }

    /**
     * Read all non-empty lines from the log file using line-by-line reading.
     *
     * If the file exceeds MAX_FILE_SIZE, only the last MAX_FILE_SIZE bytes are read
     * to prevent out-of-memory errors on production servers with large audit logs.
     *
     * @return array<int, string>
     */
    private function readLines(): array
    {
        if (! file_exists($this->path)) {
            return [];
        }

        $fileSize = filesize($this->path);
        if ($fileSize === false || $fileSize === 0) {
            return [];
        }

        $seekOffset = 0;
        if ($fileSize > self::MAX_FILE_SIZE) {
            Log::warning('Audit log file exceeds 50MB, reading only the last 50MB', [
                'path' => $this->path,
                'size_bytes' => $fileSize,
            ]);
            $seekOffset = $fileSize - self::MAX_FILE_SIZE;
        }

        $lines = [];
        $file = new \SplFileObject($this->path, 'r');

        if ($seekOffset > 0) {
            $file->fseek($seekOffset);
            // Discard partial first line after seeking
            $file->fgets();
        }

        while (! $file->eof()) {
            $line = $file->fgets();
            if (trim($line) !== '') {
                $lines[] = rtrim($line, "\n\r");
            }
        }

        return $lines;
    }
}
