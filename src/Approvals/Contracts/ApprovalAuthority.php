<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals\Contracts;

use Cboxdk\StatamicMcp\Approvals\PendingApproval;

/**
 * An outside party that can decide an approval for the person, such as their
 * identity provider asking them on their own device (OpenID CIBA).
 *
 * An authority never changes the approval itself. It answers settle(), and the
 * package records the answer through Approvals::approve()/reject(), so the owner
 * binding, the single use and the stale check hold whatever the channel. Only
 * answer true when the person the approval belongs to approved it.
 */
interface ApprovalAuthority
{
    /** Ask the person. Called once, after the approval is recorded. */
    public function start(PendingApproval $approval): void;

    /** true: approved; false: rejected; null: no answer (yet), or nothing was asked. */
    public function settle(string $approvalId): ?bool;
}
