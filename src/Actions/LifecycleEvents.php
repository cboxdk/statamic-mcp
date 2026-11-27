<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Safe dispatch for events emitted after a credential mutation is already durable. */
final class LifecycleEvents
{
    /** Accept only a W3C version-00 trace context, never arbitrary header content. */
    public static function traceparent(): ?string
    {
        $value = request()->header('traceparent');
        if (! is_string($value) || preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D', $value, $parts) !== 1
            || $parts[1] === str_repeat('0', 32) || $parts[2] === str_repeat('0', 16)) {
            return null;
        }

        return $value;
    }

    public static function dispatch(LifecycleEvent $event): void
    {
        try {
            app(LifecycleObservers::class)->dispatch($event);
        } catch (\Throwable) {
            self::reportFailure();
        }
    }

    /** Replay records are storage metadata; malformed metadata must not undo revocation. */
    public static function refreshReplay(mixed $storedSnapshot): void
    {
        try {
            $snapshot = CapabilitySnapshot::fromStored($storedSnapshot);
            self::dispatch(new Events\RefreshReplayDetected((string) Str::uuid(),
                $snapshot->grantId ?? 'legacy', 'http', gmdate('c')));
        } catch (\Throwable) {
            self::reportFailure();
        }
    }

    /** Existing Statamic Git notifications remain supported, without a post-save veto. */
    public static function legacy(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable) {
            self::reportFailure();
        }
    }

    private static function reportFailure(): void
    {
        try {
            Log::warning('MCP lifecycle notification failed.');
        } catch (\Throwable) {
            // Never recursively report a broken reporting channel.
        }
    }
}
