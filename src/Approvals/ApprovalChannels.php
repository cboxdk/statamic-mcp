<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals;

use Cboxdk\StatamicMcp\Approvals\Cbox\CboxId;
use Cboxdk\StatamicMcp\Approvals\Cbox\CboxIdApprovalAuthority;
use Cboxdk\StatamicMcp\Approvals\Cbox\CboxRelay;
use Cboxdk\StatamicMcp\Approvals\Cbox\RelayApprovalNotifier;
use Cboxdk\StatamicMcp\Approvals\Channels\MailApprovalNotifier;
use Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalAuthority;
use Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalNotifier;
use Illuminate\Contracts\Container\Container;

/**
 * The notifiers and authorities an approval goes out through.
 *
 * Every channel is isolated: one that throws is reported and skipped, and the
 * tool call answers APPROVAL_REQUIRED all the same. The Control Panel's
 * Approvals page always works, whatever the channels do.
 */
final class ApprovalChannels
{
    /**
     * @param  list<ApprovalNotifier>  $notifiers
     * @param  list<ApprovalAuthority>  $authorities
     */
    public function __construct(
        private readonly array $notifiers = [],
        private readonly array $authorities = [],
    ) {}

    /**
     * Build the channels `approvals.channels` names. 'auto' takes Cbox Relay when
     * configured, else the site's mailer, plus Cbox ID when configured.
     */
    public static function fromConfig(Container $app): self
    {
        $setting = config('statamic.mcp.approvals.channels', 'auto');
        $names = is_array($setting) ? $setting : preg_split('/[\\s,]+/', is_string($setting) ? $setting : 'auto', -1, PREG_SPLIT_NO_EMPTY);
        $names = $names === false ? [] : array_values(array_filter($names, 'is_string'));
        if ($names === ['auto']) {
            $names = [CboxRelay::available() ? 'relay' : 'mail'];
            if (CboxId::available()) {
                $names[] = 'cbox_id';
            }
        }

        $notifiers = [];
        $authorities = [];
        foreach ($names as $name) {
            if ($name === 'none') {
                continue;
            }
            $channel = $app->make(match ($name) {
                'mail' => MailApprovalNotifier::class,
                'relay' => RelayApprovalNotifier::class,
                'cbox_id' => CboxIdApprovalAuthority::class,
                default => $name,
            });
            if ($channel instanceof ApprovalNotifier) {
                $notifiers[] = $channel;
            }
            if ($channel instanceof ApprovalAuthority) {
                $authorities[] = $channel;
            }
            if (! $channel instanceof ApprovalNotifier && ! $channel instanceof ApprovalAuthority) {
                throw new \InvalidArgumentException("Approval channel '{$name}' is neither an ApprovalNotifier nor an ApprovalAuthority.");
            }
        }

        return new self($notifiers, $authorities);
    }

    public function announce(PendingApproval $approval): void
    {
        foreach ($this->notifiers as $notifier) {
            try {
                $notifier->notify($approval);
            } catch (\Throwable $failed) {
                report($failed);
            }
        }
        foreach ($this->authorities as $authority) {
            try {
                $authority->start($approval);
            } catch (\Throwable $failed) {
                report($failed);
            }
        }
    }

    /**
     * The first answer an authority has: true approved, false rejected, null none.
     * A rejection anywhere wins over an approval elsewhere.
     */
    public function settle(string $approvalId): ?bool
    {
        $answer = null;
        foreach ($this->authorities as $authority) {
            try {
                $settled = $authority->settle($approvalId);
            } catch (\Throwable $failed) {
                report($failed);

                continue;
            }
            if ($settled === false) {
                return false;
            }
            $answer = $settled ?? $answer;
        }

        return $answer;
    }

    public function hasAuthorities(): bool
    {
        return $this->authorities !== [];
    }
}
