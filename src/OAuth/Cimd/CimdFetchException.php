<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Cimd;

/**
 * Exception thrown when fetching a CIMD metadata document fails.
 *
 * Covers network errors, HTTP failures, SSRF blocks, size limit violations, and timeouts.
 */
final class CimdFetchException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
