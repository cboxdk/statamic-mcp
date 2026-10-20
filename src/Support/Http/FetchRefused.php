<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support\Http;

use RuntimeException;

/**
 * An outbound fetch that OutboundFetcher would not make, or could not finish.
 *
 * The message is written for the caller and safe to return to an MCP client:
 * it never names the address a host resolved to, so a refusal cannot be used
 * to map the server's internal network.
 */
final class FetchRefused extends RuntimeException
{
    public const BAD_URL = 'bad_url';

    public const BLOCKED_ADDRESS = 'blocked_address';

    public const UNRESOLVABLE = 'unresolvable';

    public const TOO_MANY_REDIRECTS = 'too_many_redirects';

    public const HTTP_ERROR = 'http_error';

    public const TOO_LARGE = 'too_large';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
