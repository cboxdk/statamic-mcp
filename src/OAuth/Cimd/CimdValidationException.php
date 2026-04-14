<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Cimd;

/**
 * Exception thrown when a CIMD metadata document fails validation.
 */
final class CimdValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
