<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Exceptions;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;

/** A deliberate refusal whose message is safe to show to the caller. */
final class ToolFailure extends \RuntimeException
{
    public function __construct(public readonly ErrorCode $errorCode, string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
