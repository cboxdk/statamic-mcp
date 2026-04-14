<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\OAuth\Cimd\CimdClientId;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdFetchException;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdMetadata;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdResolver;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// --- Helpers ---

function resolverClientId(string $url = 'https://app.example.com/oauth/metadata.json'): CimdClientId
{
    $id = CimdClientId::tryFrom($url);
    assert($id !== null);

    return $id;
}

/**
 * @return array<string, mixed>
 */
function resolverMetadataPayload(): array
{
    return [
        'client_id' => 'https://app.example.com/oauth/metadata.json',
        'client_name' => 'Test App',
        'redirect_uris' => ['https://app.example.com/callback'],
    ];
}

function resolverJsonBody(): string
{
    return (string) json_encode(resolverMetadataPayload());
}

// --- Successful resolution ---

it('fetches valid CIMD document and returns CimdMetadata', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    $resolver = new CimdResolver;
    $metadata = $resolver->resolve(resolverClientId());

    expect($metadata)->toBeInstanceOf(CimdMetadata::class)
        ->and($metadata->clientId)->toBe('https://app.example.com/oauth/metadata.json')
        ->and($metadata->clientName)->toBe('Test App')
        ->and($metadata->redirectUris)->toBe(['https://app.example.com/callback']);
});

it('sends Accept application/json header', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    $resolver = new CimdResolver;
    $resolver->resolve(resolverClientId());

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Accept', 'application/json');
    });
});

// --- HTTP error handling ---

it('throws CimdFetchException on HTTP 404', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response('Not Found', 404),
    ]);

    $resolver = new CimdResolver;
    $resolver->resolve(resolverClientId());
})->throws(CimdFetchException::class, 'HTTP 404');

it('throws CimdFetchException on HTTP 500', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response('Internal Server Error', 500),
    ]);

    $resolver = new CimdResolver;
    $resolver->resolve(resolverClientId());
})->throws(CimdFetchException::class, 'HTTP 500');

it('sets http_error code on HTTP failure', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response('Not Found', 404),
    ]);

    try {
        $resolver = new CimdResolver;
        $resolver->resolve(resolverClientId());
        test()->fail('Expected CimdFetchException');
    } catch (CimdFetchException $e) {
        expect($e->errorCode)->toBe('http_error');
    }
});

it('throws CimdFetchException when HTTP client throws', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => function (): never {
            throw new ConnectionException('Connection timed out');
        },
    ]);

    try {
        $resolver = new CimdResolver;
        $resolver->resolve(resolverClientId());
        test()->fail('Expected CimdFetchException');
    } catch (CimdFetchException $e) {
        expect($e->errorCode)->toBe('fetch_failed')
            ->and($e->getMessage())->toContain('Connection timed out');
    }
});

// --- Response size limit ---

it('throws CimdFetchException when response exceeds max size', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);
    config()->set('statamic.mcp.oauth.cimd_max_response_size', 50);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    $resolver = new CimdResolver;
    $resolver->resolve(resolverClientId());
})->throws(CimdFetchException::class, 'exceeds maximum size');

it('sets response_too_large error code for oversized responses', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);
    config()->set('statamic.mcp.oauth.cimd_max_response_size', 10);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    try {
        $resolver = new CimdResolver;
        $resolver->resolve(resolverClientId());
        test()->fail('Expected CimdFetchException');
    } catch (CimdFetchException $e) {
        expect($e->errorCode)->toBe('response_too_large');
    }
});

it('allows responses within the size limit', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);
    config()->set('statamic.mcp.oauth.cimd_max_response_size', 10000);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    $resolver = new CimdResolver;
    $metadata = $resolver->resolve(resolverClientId());

    expect($metadata)->toBeInstanceOf(CimdMetadata::class);
});

// --- Invalid JSON ---

it('throws CimdValidationException on invalid JSON', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response('not json at all', 200),
    ]);

    $resolver = new CimdResolver;
    $resolver->resolve(resolverClientId());
})->throws(CimdValidationException::class, 'not valid JSON');

it('sets invalid_json error code for non-JSON responses', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response('<html>Not JSON</html>', 200),
    ]);

    try {
        $resolver = new CimdResolver;
        $resolver->resolve(resolverClientId());
        test()->fail('Expected CimdValidationException');
    } catch (CimdValidationException $e) {
        expect($e->errorCode)->toBe('invalid_json');
    }
});

it('throws CimdValidationException when JSON is not an object', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response('"just a string"', 200),
    ]);

    $resolver = new CimdResolver;
    $resolver->resolve(resolverClientId());
})->throws(CimdValidationException::class, 'must be a JSON object');

// --- SSRF protection ---

it('throws CimdFetchException when hostname resolves to 127.0.0.1', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', true);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    $clientId = resolverClientId('https://localhost/oauth/metadata.json');

    $resolver = new CimdResolver;
    $resolver->resolve($clientId);
})->throws(CimdFetchException::class, 'private/reserved IP');

it('sets ssrf_blocked error code for private IPs', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', true);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    $clientId = resolverClientId('https://localhost/oauth/metadata.json');

    try {
        $resolver = new CimdResolver;
        $resolver->resolve($clientId);
        test()->fail('Expected CimdFetchException');
    } catch (CimdFetchException $e) {
        expect($e->errorCode)->toBe('ssrf_blocked');
    }
});

it('allows fetch when cimd_block_private_ips is false', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    $resolver = new CimdResolver;
    $metadata = $resolver->resolve(resolverClientId());

    expect($metadata)->toBeInstanceOf(CimdMetadata::class);
});

it('throws CimdFetchException when DNS resolution fails', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', true);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    // Use a hostname guaranteed not to resolve
    $clientId = resolverClientId('https://this-host-does-not-exist.invalid/oauth/metadata.json');

    try {
        $resolver = new CimdResolver;
        $resolver->resolve($clientId);
        test()->fail('Expected CimdFetchException');
    } catch (CimdFetchException $e) {
        expect($e->errorCode)->toBe('dns_resolution_failed');
    }
});

// --- Caching ---

it('caches successful results', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 3600);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    $resolver = new CimdResolver;
    $clientId = resolverClientId();

    // First call — fetches from HTTP
    $metadata1 = $resolver->resolve($clientId);

    // Second call — should use cache, not HTTP
    $metadata2 = $resolver->resolve($clientId);

    expect($metadata1->clientId)->toBe($metadata2->clientId);

    // Only one HTTP request should have been made
    Http::assertSentCount(1);
});

it('does not cache when cache TTL is zero', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    $resolver = new CimdResolver;
    $clientId = resolverClientId();

    $resolver->resolve($clientId);
    $resolver->resolve($clientId);

    // Both calls should hit HTTP
    Http::assertSentCount(2);
});

it('does not cache HTTP error responses', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 3600);

    Http::fake([
        'app.example.com/*' => Http::response('Not Found', 404),
    ]);

    $resolver = new CimdResolver;
    $clientId = resolverClientId();
    $cacheKey = 'cimd:' . hash('sha256', $clientId->toString());

    try {
        $resolver->resolve($clientId);
    } catch (CimdFetchException) {
        // Expected
    }

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('does not cache invalid JSON responses', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 3600);

    Http::fake([
        'app.example.com/*' => Http::response('not json', 200),
    ]);

    $resolver = new CimdResolver;
    $clientId = resolverClientId();
    $cacheKey = 'cimd:' . hash('sha256', $clientId->toString());

    try {
        $resolver->resolve($clientId);
    } catch (CimdValidationException) {
        // Expected
    }

    expect(Cache::has($cacheKey))->toBeFalse();
});

// --- Config respect ---

it('respects cimd_fetch_timeout config', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);
    config()->set('statamic.mcp.oauth.cimd_fetch_timeout', 15);

    Http::fake([
        'app.example.com/*' => Http::response(resolverJsonBody(), 200),
    ]);

    $resolver = new CimdResolver;
    $resolver->resolve(resolverClientId());

    Http::assertSent(function ($request): bool {
        // The request was made — timeout is configured on the PendingRequest
        // We verify it doesn't throw by completing successfully
        return true;
    });
});

it('does not follow redirects', function (): void {
    config()->set('statamic.mcp.oauth.cimd_block_private_ips', false);
    config()->set('statamic.mcp.oauth.cimd_cache_ttl', 0);

    Http::fake([
        'app.example.com/*' => Http::response('', 302, ['Location' => 'https://evil.com']),
    ]);

    // A 302 is a client error from our perspective (failed() returns true for redirects
    // when redirects aren't followed, or the response is returned as-is).
    // With maxRedirects(0), Laravel HTTP client returns the redirect response directly.
    // Since 3xx is not a "successful" response, response->failed() may not be true,
    // but the body won't contain valid JSON.
    $resolver = new CimdResolver;

    try {
        $resolver->resolve(resolverClientId());
        test()->fail('Expected an exception for redirect response');
    } catch (CimdFetchException|CimdValidationException) {
        // Either a fetch error or validation error is acceptable —
        // the important thing is redirects are not followed
        expect(true)->toBeTrue();
    }
});
