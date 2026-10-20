<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Support\Http\FetchRefused;
use Cboxdk\StatamicMcp\Support\Http\OutboundFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, list<string>>  $dns
 */
function fetcherWithDns(array $dns): OutboundFetcher
{
    return new OutboundFetcher(resolver: fn (string $host): array => $dns[$host] ?? []);
}

function refusalOf(Closure $call): FetchRefused
{
    try {
        $call();
    } catch (FetchRefused $e) {
        return $e;
    }

    throw new RuntimeException('Expected the fetch to be refused');
}

it('tells public addresses from everything else', function (string $address, bool $public) {
    expect(OutboundFetcher::isPublic($address))->toBe($public);
})->with([
    ['93.184.216.34', true],
    ['8.8.8.8', true],
    ['2606:4700:4700::1111', true],
    ['127.0.0.1', false],
    ['10.1.2.3', false],
    ['172.20.0.1', false],
    ['192.168.1.1', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['224.0.0.1', false],
    ['255.255.255.255', false],
    ['198.51.100.7', false],
    ['::1', false],
    ['::', false],
    ['fe80::1', false],
    ['fd00::1', false],
    ['ff02::1', false],
    ['::ffff:127.0.0.1', false],
    ['::ffff:169.254.169.254', false],
    ['::ffff:93.184.216.34', true],
    ['64:ff9b::a00:1', false],
    ['2002:7f00:1::', false],
    ['not-an-ip', false],
]);

it('refuses what is not a plain http(s) URL', function (string $url, string $reason) {
    $e = refusalOf(fn () => fetcherWithDns(['example.com' => ['93.184.216.34']])->get($url, 1000, 5));

    expect($e->reason)->toBe($reason);
})->with([
    ['file:///etc/passwd', FetchRefused::BAD_URL],
    ['gopher://example.com/', FetchRefused::BAD_URL],
    ['https://user:pass@example.com/a.jpg', FetchRefused::BAD_URL],
    ['https://example.com:8443/a.jpg', FetchRefused::BAD_URL],
    ['/relative.jpg', FetchRefused::BAD_URL],
]);

it('refuses a host that resolves to a private address, and does not say which', function () {
    Http::fake();

    $e = refusalOf(fn () => fetcherWithDns(['metadata.internal' => ['169.254.169.254']])->get('http://metadata.internal/latest', 1000, 5));

    expect($e->reason)->toBe(FetchRefused::BLOCKED_ADDRESS)
        ->and($e->getMessage())->not->toContain('169.254');
    Http::assertNothingSent();
});

it('refuses a host when any of its addresses is private', function () {
    Http::fake();

    $e = refusalOf(fn () => fetcherWithDns(['mixed.example' => ['93.184.216.34', '10.0.0.5']])->get('https://mixed.example/a.jpg', 1000, 5));

    expect($e->reason)->toBe(FetchRefused::BLOCKED_ADDRESS);
    Http::assertNothingSent();
});

it('refuses an IP literal in a private range', function () {
    Http::fake();

    expect(refusalOf(fn () => (new OutboundFetcher)->get('http://127.0.0.1/', 1000, 5))->reason)->toBe(FetchRefused::BLOCKED_ADDRESS)
        ->and(refusalOf(fn () => (new OutboundFetcher)->get('http://[::1]/', 1000, 5))->reason)->toBe(FetchRefused::BLOCKED_ADDRESS);
    Http::assertNothingSent();
});

it('refuses a host that does not resolve', function () {
    expect(refusalOf(fn () => fetcherWithDns([])->get('https://nowhere.example/a.jpg', 1000, 5))->reason)
        ->toBe(FetchRefused::UNRESOLVABLE);
});

it('checks every redirect again', function () {
    Http::fake([
        'https://cdn.example/a.jpg' => Http::response('', 302, ['Location' => 'http://internal.example/secret']),
        '*' => Http::response('secret'),
    ]);

    $e = refusalOf(fn () => fetcherWithDns([
        'cdn.example' => ['93.184.216.34'],
        'internal.example' => ['10.0.0.8'],
    ])->get('https://cdn.example/a.jpg', 1000, 5));

    expect($e->reason)->toBe(FetchRefused::BLOCKED_ADDRESS);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'internal.example'));
});

it('follows a redirect to another public host', function () {
    Http::fake([
        'https://cdn.example/a.jpg' => Http::response('', 301, ['Location' => '/images/a.jpg']),
        'https://cdn.example/images/a.jpg' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $fetched = fetcherWithDns(['cdn.example' => ['93.184.216.34']])->get('https://cdn.example/a.jpg', 1000, 5);

    expect($fetched->body)->toBe('jpeg-bytes')
        ->and($fetched->contentType)->toBe('image/jpeg')
        ->and($fetched->url)->toBe('https://cdn.example/images/a.jpg');
});

it('stops after too many redirects', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://cdn.example/again'])]);

    expect(refusalOf(fn () => fetcherWithDns(['cdn.example' => ['93.184.216.34']])->get('https://cdn.example/a', 1000, 5, maxRedirects: 2))->reason)
        ->toBe(FetchRefused::TOO_MANY_REDIRECTS);
});

it('refuses a body over the limit, declared or not', function (array $headers) {
    Http::fake(['*' => Http::response(str_repeat('x', 2000), 200, $headers)]);

    expect(refusalOf(fn () => fetcherWithDns(['cdn.example' => ['93.184.216.34']])->get('https://cdn.example/big', 1000, 5))->reason)
        ->toBe(FetchRefused::TOO_LARGE);
})->with([
    'declared' => [['Content-Length' => '2000']],
    'streamed' => [[]],
]);

it('reports an HTTP error', function () {
    Http::fake(['*' => Http::response('nope', 404)]);

    expect(refusalOf(fn () => fetcherWithDns(['cdn.example' => ['93.184.216.34']])->get('https://cdn.example/missing', 1000, 5))->reason)
        ->toBe(FetchRefused::HTTP_ERROR);
});

it('reaches a private address only when blocking is switched off', function () {
    Http::fake(['*' => Http::response('local')]);

    expect((new OutboundFetcher(blockPrivate: false))->get('http://localhost:8080/a', 1000, 5)->body)->toBe('local');
});
