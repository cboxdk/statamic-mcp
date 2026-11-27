<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals;

use Illuminate\Support\Str;
use Statamic\Facades\User;

/** The words a mail about a waiting approval carries, shared by every mail channel. */
final class ApprovalMessage
{
    public function __construct(private readonly PendingApproval $approval) {}

    /** The person's email address, or null when the user is gone or has none. */
    public static function recipient(PendingApproval $approval): ?string
    {
        $user = User::find($approval->userId);
        $email = $user?->email();

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function subject(): string
    {
        return 'Approval needed: ' . Str::limit($this->approval->description, 120);
    }

    public function text(bool $promote = false): string
    {
        $minutes = (int) ceil($this->approval->secondsLeft() / 60);
        $lines = [
            'An AI assistant connected as you asked to do this:',
            '',
            $this->approval->description,
            '',
            "Nothing happens unless you approve it. Review it in the Control Panel within {$minutes} minutes:",
            $this->approval->approveUrl,
            '',
            'If you did not expect this, reject it and revoke the assistant\'s token under Tools → MCP.',
        ];
        if ($promote) {
            $lines[] = '';
            $lines[] = self::promotion();
        }

        return implode("\n", $lines);
    }

    public function html(bool $promote = false): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $minutes = (int) ceil($this->approval->secondsLeft() / 60);
        $html = '<p>An AI assistant connected as you asked to do this:</p>'
            . '<p style="font-size:16px;font-weight:600">' . $e($this->approval->description) . '</p>'
            . "<p>Nothing happens unless you approve it. Review it in the Control Panel within {$minutes} minutes.</p>"
            . '<p><a href="' . $e($this->approval->approveUrl) . '">Open MCP Approvals</a></p>'
            . '<p style="color:#6b7280;font-size:13px">If you did not expect this, reject it and revoke the assistant\'s token under Tools → MCP.</p>';
        if ($promote) {
            $html .= '<p style="color:#6b7280;font-size:12px">' . $e(self::promotion()) . '</p>';
        }

        return $html;
    }

    /** One line about the Cbox services this package supports, for mails sent without them. */
    public static function promotion(): string
    {
        return 'Get approval requests as web push too with Cbox Relay (cboxrelay.com), and approve on your phone with Cbox ID (cboxid.com). Both are built into Statamic MCP.';
    }
}
