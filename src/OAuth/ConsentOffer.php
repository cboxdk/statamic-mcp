<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Server-authenticated, short-lived consent; never reconstruct an offer from POST scopes. */
final class ConsentOffer
{
    private const FIELDS = ['client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope'];

    public function __construct(private readonly CapabilityRegistry $registry) {}

    /** @param list<string> $scopes */
    public function issue(Request $request, string $userId, array $scopes): string
    {
        return Crypt::encryptString(json_encode([
            'user' => $userId,
            'session' => hash('sha256', $request->session()->getId()),
            'params' => $this->params($request),
            'scopes' => $scopes,
            'capabilities' => $this->registry->forScopes($scopes)->toStored(),
            'expires' => now()->addMinutes(10)->getTimestamp(),
            'nonce' => Str::random(64),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{scopes: list<string>, capabilities: CapabilitySnapshot} */
    public function consume(Request $request, string $userId): array
    {
        $encrypted = $request->input('consent_offer');
        abort_unless(is_string($encrypted) && strlen($encrypted) <= 262144, 400, 'Missing or invalid consent offer.');

        try {
            $offer = json_decode(Crypt::decryptString($encrypted), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(400, 'Invalid consent offer.');
        }

        abort_unless(is_array($offer)
            && ($offer['user'] ?? null) === $userId
            && ($offer['session'] ?? null) === hash('sha256', $request->session()->getId())
            && ($offer['params'] ?? null) === $this->params($request)
            && is_int($offer['expires'] ?? null) && $offer['expires'] > now()->getTimestamp()
            && is_string($offer['nonce'] ?? null)
            && is_array($offer['scopes'] ?? null)
            && array_is_list($offer['scopes'])
            && count(array_filter($offer['scopes'], 'is_string')) === count($offer['scopes']),
            400, 'Consent has expired or does not match this request.');

        // Cache::add is atomic across workers on the configured shared cache.
        abort_unless(Cache::add('statamic-mcp:consent:' . hash('sha256', $offer['nonce']), true,
            max(1, $offer['expires'] - now()->getTimestamp())), 400, 'Consent has already been used.');

        /** @var list<string> $scopes */
        $scopes = $offer['scopes'];

        return ['scopes' => $scopes, 'capabilities' => CapabilitySnapshot::fromStored($offer['capabilities'] ?? null) ?? new CapabilitySnapshot([])];
    }

    /** @return array<string, string> */
    private function params(Request $request): array
    {
        $params = [];
        foreach (self::FIELDS as $field) {
            $value = $request->input($field, '');
            abort_unless(is_string($value), 400, 'Invalid consent parameter.');
            $params[$field] = $value;
        }

        return $params;
    }
}
