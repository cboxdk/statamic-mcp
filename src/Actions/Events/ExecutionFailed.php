<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class ExecutionFailed extends ExecutionEvent
{
    protected const OUTCOME = 'failed';
}
