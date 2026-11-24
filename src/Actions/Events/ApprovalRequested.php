<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class ApprovalRequested extends ApprovalEvent
{
    protected const NAME = 'approval.requested';
}
