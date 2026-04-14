<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdMetadata;

class OAuthClient
{
    /** @param array<int, string> $redirectUris */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientName,
        public readonly array $redirectUris,
        public readonly Carbon $createdAt,
        public readonly ?string $registeredIp = null,
        public readonly ?string $clientUri = null,
        public readonly ?string $logoUri = null,
        public readonly bool $isCimd = false,
    ) {}

    /**
     * Create an OAuthClient from validated CIMD metadata.
     */
    public static function fromCimdMetadata(CimdMetadata $metadata): self
    {
        return new self(
            clientId: $metadata->clientId,
            clientName: $metadata->clientName,
            redirectUris: $metadata->redirectUris,
            createdAt: Carbon::now(),
            registeredIp: null,
            clientUri: $metadata->clientUri,
            logoUri: $metadata->logoUri,
            isCimd: true,
        );
    }
}
