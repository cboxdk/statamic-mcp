<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\DataTransferObjects;

/** Stable machine-readable tool failures; clients must not parse message text. */
enum ErrorCode: string
{
    case Validation = 'VALIDATION';
    case NotFound = 'NOT_FOUND';
    case Forbidden = 'FORBIDDEN';
    case ScopeMissing = 'SCOPE_MISSING';
    case TooLarge = 'TOO_LARGE';
    case Internal = 'INTERNAL';
    case VersionConflict = 'VERSION_CONFLICT';
    case OutcomeUnknown = 'OUTCOME_UNKNOWN';
    case ApprovalRequired = 'APPROVAL_REQUIRED';
    case ApprovalPending = 'APPROVAL_PENDING';
    case ApprovalRejected = 'APPROVAL_REJECTED';
    case ApprovalStale = 'APPROVAL_STALE';
    case ApprovalMismatch = 'APPROVAL_MISMATCH';
    case ApprovalExpired = 'APPROVAL_EXPIRED';
    case ApprovalSpent = 'APPROVAL_SPENT';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case IdempotencyKeySpent = 'IDEMPOTENCY_KEY_SPENT';
    case IdempotencyKeyExpired = 'IDEMPOTENCY_KEY_EXPIRED';
}
