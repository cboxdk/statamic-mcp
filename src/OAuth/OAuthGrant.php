<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

/** Consent metadata only: never a credential or its hash. */
readonly class OAuthGrant
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $userId,
        public string $clientId,
        public array $scopes,
    ) {}
}
