<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals\Cbox;

use Cbox\Relay\Client\Data\EventType;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Relay;
use Cboxdk\StatamicMcp\Approvals\ApprovalMessage;
use Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalNotifier;
use Cboxdk\StatamicMcp\Approvals\PendingApproval;
use Illuminate\Support\Facades\Cache;

/**
 * Tells the person through Cbox Relay (cboxrelay.com): a mail, a web push to every
 * browser they turned notifications on in, and an event for the site's webhook
 * receivers (Slack, Teams, n8n…). Each part is its own channel and can be switched
 * off in `approvals.relay`. Every send carries an idempotency key per approval, so
 * a retried request never notifies twice.
 *
 * Nothing sent here can approve anything: the mail and the push open the Approvals
 * page, and the webhook event carries no link that decides.
 */
final class RelayApprovalNotifier implements ApprovalNotifier
{
    public const string EVENT = 'statamic_mcp.approval.requested';

    public function __construct(private readonly Relay $relay) {}

    public function notify(PendingApproval $approval): void
    {
        $failures = [];
        foreach (['mail', 'push', 'webhook'] as $channel) {
            if (! config()->boolean("statamic.mcp.approvals.relay.{$channel}", true)) {
                continue;
            }
            try {
                match ($channel) {
                    'mail' => $this->mail($approval),
                    'push' => $this->push($approval),
                    'webhook' => $this->webhook($approval),
                };
            } catch (\Throwable $failed) {
                $failures[] = $failed;
            }
        }
        foreach ($failures as $failed) {
            report($failed);
        }
    }

    private function mail(PendingApproval $approval): void
    {
        $email = ApprovalMessage::recipient($approval);
        $from = config('mail.from.address');
        if ($email === null || ! is_string($from) || $from === '') {
            return;
        }
        $name = config('mail.from.name');
        $message = new ApprovalMessage($approval);

        $this->relay->sendMail(new OutgoingMail(
            from: new MailAddress($from, is_string($name) ? $name : null),
            to: [$email],
            subject: $message->subject(),
            html: $message->html(),
            text: $message->text(),
            headers: ['auto-submitted' => 'auto-generated'],
            trackClicks: false,
            trackOpens: false,
            tags: ['statamic-mcp-approval'],
            metadata: ['approval_id' => $approval->id],
        ), 'statamic-mcp:approval:' . $approval->id . ':mail');
    }

    private function push(PendingApproval $approval): void
    {
        $payload = [
            'title' => 'Approval needed',
            'body' => mb_substr($approval->description, 0, 200),
            'url' => $approval->approveUrl,
            'tag' => 'statamic-mcp-approval-' . substr($approval->id, 0, 16),
        ];
        foreach ($this->relay->pushSubscriptions()->list(CboxRelay::pushRef($approval->userId)) as $subscription) {
            $this->relay->push($subscription->id, $payload, ttl: $approval->secondsLeft(), urgency: PushUrgency::High,
                idempotencyKey: 'statamic-mcp:approval:' . $approval->id . ':push:' . $subscription->id);
        }
    }

    private function webhook(PendingApproval $approval): void
    {
        $this->declareEventType();
        $this->relay->publish(self::EVENT, [
            'approval_id' => $approval->id,
            'user_id' => $approval->userId,
            'description' => $approval->description,
            'tool' => $approval->tool,
            'action' => $approval->action,
            'site' => $approval->site,
            'expires_at' => $approval->expiresAt,
            'approve_url' => $approval->approveUrl,
        ], 'statamic-mcp:approval:' . $approval->id, subject: $approval->userId);
    }

    /**
     * Relay only accepts declared event types, and declaring replaces the whole
     * list, so the type is added to what the project already offers, once a day.
     */
    private function declareEventType(): void
    {
        Cache::remember('statamic-mcp:relay-approval-event-declared', 86400, function (): bool {
            $types = $this->relay->eventTypes();
            $existing = $types->all();
            foreach ($existing as $type) {
                if ($type->type === self::EVENT) {
                    return true;
                }
            }
            $types->declare([...$existing, new EventType(self::EVENT, 'Statamic MCP', 'Approval requested',
                'An AI assistant asked to do something that needs a person\'s approval in the Statamic Control Panel.')]);

            return true;
        });
    }
}
