<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Cimd;

use Cboxdk\StatamicMcp\Support\Http\FetchRefused;
use Cboxdk\StatamicMcp\Support\Http\OutboundFetcher;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches and resolves CIMD metadata documents with SSRF protection.
 *
 * Provides secure retrieval of Client ID Metadata Documents (CIMD) by:
 * - Fetching through OutboundFetcher, which refuses private and reserved
 *   addresses and pins the connection to the address it checked (SSRF)
 * - Enforcing response size limits
 * - Caching valid results
 * - Disabling redirect following
 */
class CimdResolver
{
    /**
     * Resolve a CIMD client_id URL to its metadata document.
     *
     * @throws CimdFetchException On network errors, SSRF blocks, or size violations
     * @throws CimdValidationException On invalid JSON or metadata validation failures
     */
    public function resolve(CimdClientId $clientId): CimdMetadata
    {
        $url = $clientId->toString();

        /** @var int $cacheTtl */
        $cacheTtl = config('statamic.mcp.oauth.cimd_cache_ttl', 3600);

        if ($cacheTtl > 0) {
            $cacheKey = 'cimd:' . hash('sha256', $url);

            /** @var CimdMetadata|null $cached */
            $cached = Cache::get($cacheKey);

            if ($cached instanceof CimdMetadata) {
                return $cached;
            }

            $metadata = $this->fetchAndParse($clientId);

            Cache::put($cacheKey, $metadata, $cacheTtl);

            return $metadata;
        }

        return $this->fetchAndParse($clientId);
    }

    /**
     * Fetch the URL and parse the response into CimdMetadata.
     *
     * @throws CimdFetchException
     * @throws CimdValidationException
     */
    private function fetchAndParse(CimdClientId $clientId): CimdMetadata
    {
        $url = $clientId->toString();

        /** @var int $timeout */
        $timeout = config('statamic.mcp.oauth.cimd_fetch_timeout', 5);

        /** @var int $maxSize */
        $maxSize = config('statamic.mcp.oauth.cimd_max_response_size', 5120);

        $fetcher = new OutboundFetcher(blockPrivate: (bool) config('statamic.mcp.oauth.cimd_block_private_ips', true));

        try {
            $body = $fetcher->get($url, $maxSize, $timeout, maxRedirects: 0, headers: ['Accept' => 'application/json'])->body;
        } catch (FetchRefused $e) {
            throw match ($e->reason) {
                FetchRefused::BLOCKED_ADDRESS => new CimdFetchException('ssrf_blocked', 'CIMD document URL resolves to a private/reserved IP address.', $e),
                FetchRefused::UNRESOLVABLE => new CimdFetchException('dns_resolution_failed', "Could not resolve the CIMD document host for '{$url}'.", $e),
                FetchRefused::TOO_LARGE => new CimdFetchException('response_too_large', "CIMD document from '{$url}' exceeds maximum size of {$maxSize} bytes.", $e),
                FetchRefused::HTTP_ERROR, FetchRefused::TOO_MANY_REDIRECTS => new CimdFetchException('http_error', "CIMD document fetch failed: {$e->getMessage()}", $e),
                default => new CimdFetchException('fetch_failed', "Failed to fetch CIMD document from '{$url}': {$e->getMessage()}", $e),
            };
        }

        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CimdValidationException(
                'invalid_json',
                "CIMD document from '{$url}' is not valid JSON: {$e->getMessage()}",
            );
        }

        if (! is_array($data)) {
            throw new CimdValidationException(
                'invalid_json',
                "CIMD document from '{$url}' must be a JSON object.",
            );
        }

        /** @var array<string, mixed> $data */
        return CimdMetadata::fromArray($data, $clientId);
    }
}
