<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Audit;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\AuditStore;

class DatabaseAuditStore implements AuditStore
{
    /** @var list<string> */
    private const KNOWN_COLUMNS = ['level', 'message', 'tool', 'action', 'status', 'correlation_id', 'duration_ms'];

    /**
     * @param  array<string, mixed>  $entry
     */
    public function write(array $entry): void
    {
        /** @var array<string, mixed> $data */
        $data = [];
        /** @var array<string, mixed> $context */
        $context = [];

        foreach ($entry as $key => $value) {
            if (in_array($key, self::KNOWN_COLUMNS, true)) {
                $data[$key] = $value;
            } elseif ($key === 'timestamp') {
                $data['logged_at'] = $value;
            } elseif ($key !== 'metadata') {
                $context[$key] = $value;
            }
        }

        if (isset($entry['metadata']) && is_array($entry['metadata'])) {
            $context = array_merge($context, $entry['metadata']);
        }

        if ($context !== []) {
            $data['context'] = $context;
        }

        McpAuditEntry::create($data);
    }

    public function query(?string $tool, ?string $status, int $page, int $perPage): AuditResult
    {
        $query = McpAuditEntry::query()->orderByDesc('logged_at');

        if ($tool !== null && $tool !== '') {
            $escapedTool = str_replace(['%', '_'], ['\\%', '\\_'], $tool);
            $query->where('tool', 'like', '%' . $escapedTool . '%');
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $entries = $query->skip($offset)->take($perPage)->get()->map(function (McpAuditEntry $entry): array {
            /** @var array<string, mixed> $data */
            $data = [
                'level' => $entry->level,
                'message' => $entry->message,
                'timestamp' => $entry->logged_at->toIso8601String(),
            ];

            if ($entry->tool !== null) {
                $data['tool'] = $entry->tool;
            }
            if ($entry->action !== null) {
                $data['action'] = $entry->action;
            }
            if ($entry->status !== null) {
                $data['status'] = $entry->status;
            }
            if ($entry->correlation_id !== null) {
                $data['correlation_id'] = $entry->correlation_id;
            }
            if ($entry->duration_ms !== null) {
                $data['duration_ms'] = $entry->duration_ms;
            }
            if ($entry->context !== null) {
                $data = array_merge($data, $entry->context);
            }

            return $data;
        })->all();

        return new AuditResult(
            entries: array_values($entries),
            total: $total,
            currentPage: $page,
            lastPage: $lastPage,
            perPage: $perPage,
        );
    }

    public function purge(?Carbon $before = null): int
    {
        if ($before === null) {
            $count = McpAuditEntry::count();
            McpAuditEntry::truncate();

            return $count;
        }

        return McpAuditEntry::where('logged_at', '<', $before)->delete();
    }
}
