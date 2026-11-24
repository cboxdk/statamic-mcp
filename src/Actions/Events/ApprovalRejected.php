<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class ApprovalRejected extends ApprovalEvent
{
    protected const NAME = 'approval.rejected';
}
