<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth\Cimd;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches and resolves CIMD metadata documents with SSRF protection.
 *
 * Provides secure retrieval of Client ID Metadata Documents (CIMD) by:
 * - Blocking requests to private/reserved IP ranges (SSRF protection)
 * - Enforcing response size limits
 * - Caching valid results
 * - Disabling redirect following
 */
final class CimdResolver
{
    /**
     * Private and reserved IPv4 CIDR ranges to block.
     *
     * @var list<array{string, string}>
     */
    private const BLOCKED_IPV4_RANGES = [
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
        ['127.0.0.0', '127.255.255.255'],
        ['169.254.0.0', '169.254.255.255'],
        ['0.0.0.0', '0.255.255.255'],
    ];

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

        $this->guardAgainstSsrf($clientId);

        /** @var int $timeout */
        $timeout = config('statamic.mcp.oauth.cimd_fetch_timeout', 5);

        /** @var int $maxSize */
        $maxSize = config('statamic.mcp.oauth.cimd_max_response_size', 5120);

        try {
            $response = Http::timeout($timeout)
                ->maxRedirects(0)
                ->accept('application/json')
                ->get($url);
        } catch (\Throwable $e) {
            throw new CimdFetchException(
                'fetch_failed',
                "Failed to fetch CIMD document from '{$url}': {$e->getMessage()}",
                $e,
            );
        }

        if ($response->failed()) {
            throw new CimdFetchException(
                'http_error',
                "CIMD document fetch returned HTTP {$response->status()} for '{$url}'.",
            );
        }

        $body = $response->body();

        if (strlen($body) > $maxSize) {
            throw new CimdFetchException(
                'response_too_large',
                "CIMD document from '{$url}' exceeds maximum size of {$maxSize} bytes.",
            );
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

    /**
     * Check that the hostname does not resolve to a private/reserved IP.
     *
     * @throws CimdFetchException If the IP is in a blocked range
     */
    private function guardAgainstSsrf(CimdClientId $clientId): void
    {
        /** @var bool $blockPrivateIps */
        $blockPrivateIps = config('statamic.mcp.oauth.cimd_block_private_ips', true);

        if (! $blockPrivateIps) {
            return;
        }

        $host = $clientId->getHost();
        $ip = gethostbyname($host);

        // gethostbyname returns the original hostname if resolution fails
        if ($ip === $host) {
            throw new CimdFetchException(
                'dns_resolution_failed',
                "Could not resolve hostname '{$host}' for CIMD document.",
            );
        }

        if ($this->isBlockedIp($ip)) {
            throw new CimdFetchException(
                'ssrf_blocked',
                "CIMD document URL resolves to a private/reserved IP address ({$ip}).",
            );
        }
    }

    /**
     * Check whether an IP address falls within blocked private/reserved ranges.
     */
    private function isBlockedIp(string $ip): bool
    {
        // Check IPv6 loopback and private ranges
        if ($ip === '::1' || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) {
            return true;
        }

        $long = ip2long($ip);

        if ($long === false) {
            // Non-IPv4 address that isn't a known IPv6 private — allow it
            return false;
        }

        foreach (self::BLOCKED_IPV4_RANGES as [$rangeStart, $rangeEnd]) {
            $start = ip2long($rangeStart);
            $end = ip2long($rangeEnd);

            if ($start !== false && $end !== false && $long >= $start && $long <= $end) {
                return true;
            }
        }

        return false;
    }
}
