<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Approvals\Cbox;

use Cbox\Id\Client\Contracts\BackchannelAuthentication;
use Cbox\Id\Client\Exceptions\AuthorizationDenied;
use Cbox\Id\Client\Exceptions\AuthorizationExpired;
use Cbox\Id\Client\ValueObjects\PendingBackchannelAuthentication;
use Cboxdk\StatamicMcp\Approvals\Contracts\ApprovalAuthority;
use Cboxdk\StatamicMcp\Approvals\PendingApproval;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\User;

/**
 * Asks the person on their phone through Cbox ID (cboxid.com), with OpenID CIBA.
 *
 * The request names the person by their Cbox ID user id (the user field set in
 * `approvals.cbox_id.user_field`) or, failing that, their email, which Cbox ID only
 * accepts once confirmed. The binding message shows the action and a short code the
 * Approvals page shows too. The pending request stays server-side; the agent never
 * sees it, so only the person's answer on their device settles it.
 *
 * CIBA is only open to clients an operator registered at Cbox ID, not to
 * dynamically registered ones.
 */
final class CboxIdApprovalAuthority implements ApprovalAuthority
{
    public function __construct(private readonly BackchannelAuthentication $backchannel) {}

    public static function code(string $approvalId): string
    {
        return strtoupper(substr($approvalId, 0, 6));
    }

    public function start(PendingApproval $approval): void
    {
        $hint = $this->loginHint($approval->userId);
        if ($hint === null) {
            return;
        }
        $binding = mb_substr(self::code($approval->id) . ' · ' . $approval->description, 0, 255);
        $resource = config('statamic.mcp.approvals.cbox_id.resource');
        $pending = $this->backchannel->start($hint, $this->scope(), $binding,
            is_string($resource) && $resource !== '' ? $resource : null,
            max(60, $approval->secondsLeft()));

        Cache::put($this->key($approval->id), ['request' => $pending->toArray(), 'next_poll' => 0, 'expires_at' => $approval->expiresAt],
            max(60, $approval->secondsLeft()));
    }

    public function settle(string $approvalId): ?bool
    {
        $stored = Cache::get($this->key($approvalId));
        if (! is_array($stored) || ! is_array($stored['request'] ?? null)) {
            return null;
        }
        $nextPoll = is_int($stored['next_poll'] ?? null) ? $stored['next_poll'] : 0;
        if ($nextPoll > now()->getTimestamp()) {
            return null;
        }
        /** @var array<string, mixed> $data */
        $data = $stored['request'];
        $request = PendingBackchannelAuthentication::fromArray($data);

        try {
            $poll = $this->backchannel->poll($request);
        } catch (AuthorizationDenied) {
            Cache::forget($this->key($approvalId));

            return false;
        } catch (AuthorizationExpired) {
            Cache::forget($this->key($approvalId));

            return null;
        }
        if ($poll->granted()) {
            Cache::forget($this->key($approvalId));

            return true;
        }
        // The approval's own expiry, on this server's clock, bounds how long the request is kept.
        $expiresAt = is_int($stored['expires_at'] ?? null) ? $stored['expires_at'] : now()->getTimestamp() + 60;
        Cache::put($this->key($approvalId), ['request' => $data, 'next_poll' => now()->getTimestamp() + $poll->interval, 'expires_at' => $expiresAt],
            max(1, $expiresAt - now()->getTimestamp()));

        return null;
    }

    private function loginHint(string $userId): ?string
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }
        $field = config('statamic.mcp.approvals.cbox_id.user_field', 'cbox_id');
        $cboxId = is_string($field) ? $user->get($field) : null;
        if (is_string($cboxId) && $cboxId !== '') {
            return $cboxId;
        }
        $email = $user->email();

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function scope(): string
    {
        $scope = config('statamic.mcp.approvals.cbox_id.scope', 'openid');

        return is_string($scope) && $scope !== '' ? $scope : 'openid';
    }

    private function key(string $approvalId): string
    {
        return 'statamic-mcp:cbox-id-approval:' . $approvalId;
    }
}
