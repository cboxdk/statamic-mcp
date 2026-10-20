<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support\Http;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;

/**
 * Fetch a URL a client named, without letting it reach the server's network.
 *
 * Anything that downloads a caller-supplied URL is a server-side request
 * forgery waiting to happen: `http://169.254.169.254/` hands out cloud
 * credentials, `http://localhost:6379/` talks to Redis. So, on every hop:
 *
 * - only http and https on their default ports, with no credentials in the URL;
 * - the host is resolved here, and every address it resolves to must be
 *   public — private, loopback, link-local, CGNAT, documentation, multicast,
 *   reserved, and IPv6 forms that embed one of those (mapped, NAT64, 6to4);
 * - the connection is pinned to the address that was checked, so a second
 *   DNS answer cannot point it somewhere else (DNS rebinding);
 * - redirects are followed by hand, a few at most, each one checked again;
 * - the body is read in chunks and abandoned the moment it passes the limit.
 *
 * `blockPrivate: false` turns all of that off, for local development against
 * a server on the same machine. It is never the default.
 */
final class OutboundFetcher
{
    /**
     * @var list<string>
     */
    private const BLOCKED_RANGES = [
        // IPv4
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // IPv6
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * IPv6 prefixes whose last 32 bits are an IPv4 address that decides it.
     *
     * @var list<string>
     */
    private const EMBEDDED_IPV4 = ['::ffff:0:0/96', '64:ff9b::/96', '::/96'];

    /**
     * @param  Closure(string): list<string>|null  $resolver  host to addresses; the system resolver when null
     */
    public function __construct(
        private readonly bool $blockPrivate = true,
        private readonly ?Closure $resolver = null,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  Closure(string): bool|null  $allowHost  asked about the host of every hop, redirects included
     *
     * @throws FetchRefused
     */
    public function get(string $url, int $maxBytes, int $timeout, int $maxRedirects = 3, array $headers = [], ?Closure $allowHost = null): FetchedResponse
    {
        for ($hop = 0; ; $hop++) {
            [$host, $port, $address] = $this->vet($url);

            if ($allowHost !== null && ! $allowHost($host)) {
                throw new FetchRefused(FetchRefused::BAD_URL, "{$host} is not on this site's list of hosts files may be fetched from.");
            }

            $options = ['allow_redirects' => false, 'stream' => true, 'connect_timeout' => $timeout];
            if ($address !== null) {
                $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:" . (str_contains($address, ':') ? "[{$address}]" : $address)]];
            }

            try {
                $response = Http::withOptions($options)->withHeaders($headers)->timeout($timeout)->get($url);
            } catch (\Throwable $e) {
                throw new FetchRefused(FetchRefused::FAILED, "Could not fetch {$url}: {$e->getMessage()}", $e);
            }

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new FetchRefused(FetchRefused::HTTP_ERROR, "{$url} redirected without a Location header.");
                }

                if ($hop >= $maxRedirects) {
                    throw new FetchRefused(FetchRefused::TOO_MANY_REDIRECTS, "{$url} redirected more than {$maxRedirects} times.");
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                throw new FetchRefused(FetchRefused::HTTP_ERROR, "{$url} returned HTTP {$response->status()}.");
            }

            $declared = $response->header('Content-Length');
            if (is_numeric($declared) && (int) $declared > $maxBytes) {
                throw new FetchRefused(FetchRefused::TOO_LARGE, "{$url} is larger than the {$maxBytes}-byte limit.");
            }

            $stream = $response->toPsrResponse()->getBody();
            $body = '';

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (! $stream->eof()) {
                $body .= $stream->read(65536);

                if (strlen($body) > $maxBytes) {
                    $stream->close();

                    throw new FetchRefused(FetchRefused::TOO_LARGE, "{$url} is larger than the {$maxBytes}-byte limit.");
                }
            }

            $contentType = $response->header('Content-Type');

            return new FetchedResponse($body, $contentType === '' ? null : $contentType, $url);
        }
    }

    /**
     * Check one URL and pick the address to connect to.
     *
     * @return array{0: string, 1: int, 2: string|null} host, port, and the pinned address (null when not blocking)
     */
    private function vet(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new FetchRefused(FetchRefused::BAD_URL, "'{$url}' is not an absolute URL.");
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new FetchRefused(FetchRefused::BAD_URL, "Only http and https URLs can be fetched, not {$scheme}.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchRefused(FetchRefused::BAD_URL, 'URLs with credentials in them cannot be fetched.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! $this->blockPrivate) {
            return [$host, $port, null];
        }

        if ($port !== ($scheme === 'https' ? 443 : 80)) {
            throw new FetchRefused(FetchRefused::BAD_URL, "Only the default {$scheme} port can be fetched.");
        }

        if (! extension_loaded('curl')) {
            throw new FetchRefused(FetchRefused::FAILED, 'Fetching a URL safely needs the curl extension, to pin the checked address.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            throw new FetchRefused(FetchRefused::UNRESOLVABLE, "{$host} does not resolve to a public address.");
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new FetchRefused(FetchRefused::BLOCKED_ADDRESS, "{$host} does not resolve to a public address.");
            }
        }

        return [$host, $port, $addresses[0]];
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        // A records through the system resolver, which reads hosts files the
        // way curl does; AAAA records from DNS, which it has no call for.
        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    public static function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        foreach (self::EMBEDDED_IPV4 as $range) {
            if (strlen($packed) === 16 && self::inRange($packed, $range)) {
                $embedded = inet_ntop(substr($packed, 12));

                // ::/96 also holds :: and ::1, which are not IPv4-compatible forms.
                return $embedded !== false && $embedded !== '0.0.0.0' && $embedded !== '0.0.0.1' && self::isPublic($embedded);
            }
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);

        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($packed, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask);
    }
}
