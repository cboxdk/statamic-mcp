<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP;

use Cboxdk\StatamicMcp\Approvals\ApprovalRefused;
use Cboxdk\StatamicMcp\Approvals\Approvals;
use Cboxdk\StatamicMcp\Http\Controllers\CP\Concerns\ResolvesUserId;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * A person approves or rejects the gated calls an agent made as them.
 *
 * Only the user a call acts as sees and decides it. Approving needs a fresh
 * elevated session (password, passkey or emailed code), so a browser agent
 * driving the same Control Panel tab cannot approve on the session alone.
 * Approving never grants anything: the agent's retried call is authorized
 * again in full before it runs.
 */
class ApprovalController extends CpController
{
    use ResolvesUserId;

    public function index(Approvals $approvals): Response
    {
        $this->authorize('view mcp dashboard');

        return Inertia::render('statamic-mcp::McpApprovalsPage', [
            'approvals' => $approvals->pendingFor('user:' . $this->resolveUserId()),
            'elevatedSessions' => (bool) config('statamic.users.elevated_sessions_enabled'),
        ]);
    }

    public function approve(Approvals $approvals, string $approval): JsonResponse
    {
        return $this->decide(fn () => $approvals->approve($approval, 'user:' . $this->resolveUserId()), 'approved');
    }

    public function reject(Approvals $approvals, string $approval): JsonResponse
    {
        return $this->decide(fn () => $approvals->reject($approval, 'user:' . $this->resolveUserId()), 'rejected');
    }

    /** @param  callable(): void  $decision */
    private function decide(callable $decision, string $state): JsonResponse
    {
        $this->authorize('view mcp dashboard');
        try {
            $decision();
        } catch (ApprovalRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], 409);
        }

        return response()->json(['state' => $state]);
    }
}
