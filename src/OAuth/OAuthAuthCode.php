<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

class OAuthAuthCode
{
    /** @param array<int, string> $scopes */
    public function __construct(
        public readonly string $clientId,
        public readonly string $userId,
        public readonly array $scopes,
        public readonly string $redirectUri,
    ) {}
}
