<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Operations;

enum OperationState: string
{
    case Prepared = 'prepared';
    case AwaitingApproval = 'awaiting_approval';
    case Ready = 'ready';
    case Executing = 'executing';
    case Succeeded = 'succeeded';
    case FailedNoEffect = 'failed_no_effect';
    case OutcomeUnknown = 'outcome_unknown';
    case Reconciled = 'reconciled';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Prepared => [self::AwaitingApproval, self::Ready, self::Expired, self::Cancelled],
            self::AwaitingApproval => [self::Ready, self::Expired, self::Cancelled],
            self::Ready => [self::Executing, self::Expired, self::Cancelled],
            self::Executing => [self::Succeeded, self::FailedNoEffect, self::OutcomeUnknown],
            self::OutcomeUnknown => [self::Reconciled],
            default => [],
        }, true);
    }
}
