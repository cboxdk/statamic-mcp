<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

use Carbon\Carbon;

class OAuthClient
{
    /** @param array<int, string> $redirectUris */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly array $redirectUris,
        public readonly Carbon $createdAt,
        public readonly ?string $registeredIp = null,
    ) {}
}
