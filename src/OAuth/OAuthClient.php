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
            logoUri: self::sameHostHttps($metadata->logoUri, $metadata->clientId),
            isCimd: true,
        );
    }

    /**
     * The logo is shown on the consent screen, so the browser of whoever
     * approves fetches it. Only an https address on the client_id's own host
     * is used: anything else would let a client track or mislead the person
     * from a server of its choosing.
     */
    private static function sameHostHttps(?string $url, string $clientId): ?string
    {
        if ($url === null) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https' && is_string($host)
            && strcasecmp($host, (string) parse_url($clientId, PHP_URL_HOST)) === 0 ? $url : null;
    }
}
