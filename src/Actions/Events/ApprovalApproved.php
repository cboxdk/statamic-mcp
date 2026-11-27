<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Events;

final readonly class ApprovalApproved extends ApprovalEvent
{
    protected const NAME = 'approval.approved';
}
