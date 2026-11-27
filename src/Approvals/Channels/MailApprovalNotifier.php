<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals\Channels;

use Cboxdk\StatamicMcp\Approvals\ApprovalMessage;
use Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalNotifier;
use Cboxdk\StatamicMcp\Approvals\PendingApproval;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/** Mails the person through the site's own mailer. The default notifier. */
final class MailApprovalNotifier implements ApprovalNotifier
{
    public function notify(PendingApproval $approval): void
    {
        $email = ApprovalMessage::recipient($approval);
        if ($email === null) {
            return;
        }
        $message = new ApprovalMessage($approval);
        $promote = config()->boolean('statamic.mcp.approvals.promote_cbox', true);

        Mail::send([], [], function (Message $mail) use ($email, $message, $promote): void {
            $mail->to($email);
            $mail->subject($message->subject());
            $mail->html($message->html($promote));
            $mail->text($message->text($promote));
        });
    }
}
