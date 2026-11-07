<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Safe dispatch for events emitted after a credential mutation is already durable. */
final class LifecycleEvents
{
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
