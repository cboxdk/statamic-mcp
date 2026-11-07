<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class ExecutionStarted extends ExecutionEvent
{
    protected const OUTCOME = 'started';
}
