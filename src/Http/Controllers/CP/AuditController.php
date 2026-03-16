<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

class AuditController extends CpController
{
    /**
     * Display paginated audit log entries with optional filtering.
     */
    public function index(Request $request, AuditStore $store): JsonResponse
    {
        $this->authorize('view mcp audit log');

        /** @var int|string $rawPage */
        $rawPage = $request->input('page', 1);
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $request->input('per_page', 25);
        $perPage = min(100, max(10, (int) $rawPerPage));

        $tool = $request->input('tool');
        $status = $request->input('status');

        $result = $store->query(
            is_string($tool) && $tool !== '' ? $tool : null,
            is_string($status) && $status !== '' ? $status : null,
            $page,
            $perPage,
        );

        return response()->json([
            'data' => $result->entries,
            'meta' => [
                'current_page' => $result->currentPage,
                'last_page' => $result->lastPage,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }
}
