<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class RefreshReplayDetected extends GrantEvent
{
    protected const NAME = 'grant.refresh_replay_detected';
}
