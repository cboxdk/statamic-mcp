<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;

class OAuthAuthCode
{
    /** @param array<int, string> $scopes */
    public function __construct(
        public readonly string $clientId,
        public readonly string $userId,
        public readonly array $scopes,
        public readonly string $redirectUri,
        public readonly ?string $refreshToken = null,
        public readonly ?CapabilitySnapshot $capabilities = null,
    ) {}
}
