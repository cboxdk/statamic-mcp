<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals;

/**
 * What a notifier or an authority is told about an approval waiting for a person.
 *
 * It holds the description and the Control Panel link, never the call's arguments:
 * a notification leaves the site (mail, push, a webhook receiver), and arguments can
 * carry content. The person reads the exact call on the Approvals page.
 */
final readonly class PendingApproval
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $description,
        public string $tool,
        public string $action,
        public ?string $site,
        public int $expiresAt,
        public string $approveUrl,
    ) {}

    /** Seconds until the approval expires, never below zero. */
    public function secondsLeft(): int
    {
        return max(0, $this->expiresAt - now()->getTimestamp());
    }
}
