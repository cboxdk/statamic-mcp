<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Support\UserIds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\User;

/** Bind the CP permission picker to the capabilities it actually displayed. */
final class PersonalTokenConsent
{
    public function __construct(private readonly CapabilityRegistry $registry, private readonly ScopeAvailability $availability) {}

    public function offer(Request $request): string
    {
        $user = User::current();
        abort_unless($user !== null, 403);
        $scopes = $this->availability->filterValues($user, $this->availability->names());

        return Crypt::encryptString(json_encode([
            'purpose' => 'personal-token',
            'user' => UserIds::normalize($user->id()),
            'session' => hash('sha256', $request->session()->getId()),
            'expires' => now()->addHour()->getTimestamp(),
            'scopes' => $scopes,
            'snapshot' => $this->registry->forScopes($scopes)->toStored(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $scopes */
    public function approve(Request $request, array $scopes): ?CapabilitySnapshot
    {
        $offer = $request->input('capability_offer');
        if ($offer === null && array_filter($scopes, fn (string $scope): bool => TokenScope::tryFrom($scope) === null) === []) {
            // Legacy core-only CP clients do not gain access to registered actions.
            return null;
        }
        $error = fn (): ValidationException => ValidationException::withMessages([
            'capability_offer' => 'The permission list has expired or changed. Reload the page before creating this token.',
        ]);
        if (! is_string($offer) || strlen($offer) > 262144) {
            throw $error();
        }
        try {
            $value = json_decode(Crypt::decryptString($offer), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw $error();
        }
        $user = User::current();
        if ($user === null || ! is_array($value) || ($value['purpose'] ?? null) !== 'personal-token'
            || ($value['user'] ?? null) !== UserIds::normalize($user->id())
            || ($value['session'] ?? null) !== hash('sha256', $request->session()->getId())
            || ! is_int($value['expires'] ?? null) || $value['expires'] <= now()->getTimestamp()
            || ! is_array($value['scopes'] ?? null)) {
            throw $error();
        }
        foreach ($scopes as $scope) {
            if (! in_array($scope, $value['scopes'], true)) {
                throw $error();
            }
        }
        $snapshot = CapabilitySnapshot::fromStored($value['snapshot'] ?? null);
        if ($snapshot === null) {
            throw $error();
        }

        // Each explicit creation gets a separate grant identity, even from the same page.
        return new CapabilitySnapshot(array_intersect_assoc($snapshot->actions, $this->registry->forScopes($scopes)->actions),
            scopes: array_intersect_key($snapshot->scopes, array_flip($scopes)));
    }
}
