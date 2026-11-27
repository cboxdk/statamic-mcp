<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Integration;

/** Cache work requested by trusted integration code, performed after the call. */
final readonly class Effects
{
    /** @param list<string> $staticUrls URLs to invalidate, never fetch.
     * @param  list<string>  $searchIndexes  Configured index handles, including every locale.
     */
    public function __construct(
        public bool $stache = true,
        public bool $static = true,
        public bool $glide = false,
        public array $staticUrls = [],
        public array $searchIndexes = [],
    ) {}
}
