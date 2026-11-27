<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals\Contracts;

use Cboxdk\StatamicMcp\Approvals\PendingApproval;

/**
 * Tells a person that an approval is waiting: mail, web push, a webhook, chat.
 *
 * A notifier only informs. It must link to the Control Panel's Approvals page and
 * must never offer a link or reply that decides the approval by itself: mail
 * scanners open links, and a webhook receiver is not the person. A notifier that
 * throws is reported and skipped; the tool call and the other notifiers go on.
 */
interface ApprovalNotifier
{
    public function notify(PendingApproval $approval): void;
}
