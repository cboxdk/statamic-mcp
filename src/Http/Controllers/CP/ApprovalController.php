<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP;

use Cboxdk\StatamicMcp\Approvals\ApprovalRefused;
use Cboxdk\StatamicMcp\Approvals\Approvals;
use Cboxdk\StatamicMcp\Approvals\Cbox\CboxId;
use Cboxdk\StatamicMcp\Approvals\Cbox\CboxRelay;
use Cboxdk\StatamicMcp\Http\Controllers\CP\Concerns\ResolvesUserId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            'pushKey' => $this->pushKey(),
            'cbox' => [
                'promote' => config()->boolean('statamic.mcp.approvals.promote_cbox', true),
                'relay' => CboxRelay::available(),
                'id' => CboxId::available(),
            ],
        ]);
    }

    /** Remember this browser for web push through Cbox Relay. */
    public function subscribe(Request $request): JsonResponse
    {
        $this->authorize('view mcp dashboard');
        if ($this->pushKey() === null) {
            return response()->json(['message' => __('statamic-mcp::messages.push_requires_relay')], 409);
        }
        $subscription = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);
        app(CboxRelay::CLIENT)->pushSubscriptions()->subscribe($subscription, CboxRelay::pushRef($this->resolveUserId()));

        return response()->json(['state' => 'subscribed']);
    }

    /** The service worker that shows the push and opens the Approvals page on click. */
    public function serviceWorker(): \Illuminate\Http\Response
    {
        $js = <<<'JS'
            self.addEventListener('push', (event) => {
                let data = {};
                try { data = event.data ? event.data.json() : {}; } catch (e) {}
                event.waitUntil(self.registration.showNotification(data.title || __MCP_APPROVAL_TITLE__, {
                    body: data.body || '', tag: data.tag, data: { url: data.url }, requireInteraction: true,
                }));
            });
            self.addEventListener('notificationclick', (event) => {
                event.notification.close();
                const url = event.notification.data && event.notification.data.url;
                if (url && new URL(url).origin === self.location.origin) {
                    event.waitUntil(clients.openWindow(url));
                }
            });
            JS;

        $js = str_replace('__MCP_APPROVAL_TITLE__', json_encode(__('statamic-mcp::messages.approval_needed'), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $js);

        return response($js, 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-cache']);
    }

    private function pushKey(): ?string
    {
        if (! CboxRelay::available() || ! config()->boolean('statamic.mcp.approvals.relay.push', true)) {
            return null;
        }
        try {
            $key = Cache::remember('statamic-mcp:relay-push-key', 86400, fn (): string => app(CboxRelay::CLIENT)->pushKey());
        } catch (\Throwable $failed) {
            report($failed);

            return null;
        }

        return $key !== '' ? $key : null;
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
