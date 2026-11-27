---
title: "Approval channels"
description: "Tell people about waiting approvals by mail, web push and webhooks, let them approve on their phone, or add your own channel"
weight: 20
---

# Approval channels

A gated action over HTTP waits for the person the call acts as to approve it
under **Tools → MCP → Approvals**. Approval channels tell that person something
is waiting, and can let them answer somewhere else than the Control Panel.

There are two kinds, and the difference matters:

- **Notifiers** tell. Mail, web push, a webhook to Slack, Teams or n8n. They
  carry the description and a link to the Approvals page, never the call's
  arguments, and nothing they send can approve anything. Mail scanners open
  links, and a webhook receiver is not the person.
- **Authorities** decide. An authority asks the person on a device that proves
  it is them, and its answer is recorded exactly like a Control Panel decision:
  the approval stays bound to its owner and to one call, runs once, and goes
  stale if the record changed.

A channel that fails is reported and skipped. The tool call still answers
`APPROVAL_REQUIRED`, and the Approvals page always works.

## Built in

`approvals.channels` defaults to `auto`, which picks what the site has:

| Channel | What it does | Needs |
|---|---|---|
| `mail` | Mails the person through the site's own mailer. Used when Cbox Relay is not set up. | A configured mailer |
| `relay` | [Cbox Relay](https://cboxrelay.com): a mail with delivery status, a web push to every browser the person turned notifications on in, and a `statamic_mcp.approval.requested` webhook event. | `cboxdk/laravel-relay-client` and `RELAY_KEY` |
| `cbox_id` | [Cbox ID](https://cboxid.com): the person approves or rejects on their phone (OpenID CIBA). | `cboxdk/laravel-id-client` and a Cbox ID client an operator registered for CIBA |

Both Cbox clients need PHP 8.4.

### Cbox Relay

```bash
composer require cboxdk/laravel-relay-client
```

Set `RELAY_KEY` (and `RELAY_PROJECT` if the key covers several projects). Mail
goes out from `mail.from.address`. Each part can be switched off:
`STATAMIC_MCP_APPROVAL_RELAY_MAIL`, `..._PUSH`, `..._WEBHOOK`.

- **Web push:** the Approvals page shows *Turn on notifications*. The browser
  subscribes through Relay under the signed-in user; a notification opens the
  Approvals page.
- **Webhooks:** register an endpoint in Relay for
  `statamic_mcp.approval.requested`. The package declares the type, keeping the
  project's other types. The payload holds `approval_id`, `user_id`,
  `description`, `tool`, `action`, `site`, `expires_at` and `approve_url`.

### Cbox ID

```bash
composer require cboxdk/laravel-id-client
```

Configure the client (`CBOX_ID_ISSUER`, `CBOX_ID_CLIENT_ID`,
`CBOX_ID_CLIENT_SECRET`). CIBA is only open to clients an operator registered at
Cbox ID, not to dynamically registered ones.

The person is found by the Cbox ID user id (`usr_…`) in the user field
`approvals.cbox_id.user_field` (default `cbox_id`), or else by their email,
which Cbox ID accepts once it is confirmed. The phone shows a six-character code
and the description; the code is the start of the approval id.

The answer is picked up when the assistant repeats the call or the person opens
the Approvals page.

## Your own channel

Implement `Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalNotifier` or
`ApprovalAuthority` and list the class:

```php
'approvals' => [
    'channels' => ['relay', App\Approvals\SlackNotifier::class],
],
```

```php
use Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalNotifier;
use Cboxdk\StatamicMcp\Approvals\PendingApproval;

final class SlackNotifier implements ApprovalNotifier
{
    public function notify(PendingApproval $approval): void
    {
        // $approval->description, ->approveUrl, ->userId, ->expiresAt
    }
}
```

An authority answers `settle(string $approvalId): ?bool` with `true` only when
the person the approval belongs to approved it, `false` when they rejected it,
and `null` while there is no answer.

`'none'` turns every channel off. Set `STATAMIC_MCP_PROMOTE_CBOX=false` to leave
the mention of Cbox Relay and Cbox ID out of mails and the Approvals page.
