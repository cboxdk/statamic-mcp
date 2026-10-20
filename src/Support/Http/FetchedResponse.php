<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support\Http;

/**
 * What OutboundFetcher brought back.
 */
final class FetchedResponse
{
    public function __construct(
        public readonly string $body,
        public readonly ?string $contentType,
        public readonly string $url,
    ) {}
}
