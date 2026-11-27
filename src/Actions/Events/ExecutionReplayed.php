<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class ExecutionReplayed extends ExecutionEvent
{
    protected const OUTCOME = 'replayed';
}
