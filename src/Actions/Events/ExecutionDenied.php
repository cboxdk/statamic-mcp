<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class ExecutionDenied extends ExecutionEvent
{
    protected const OUTCOME = 'denied';
}
