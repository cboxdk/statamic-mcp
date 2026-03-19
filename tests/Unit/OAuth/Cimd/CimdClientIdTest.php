<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\OAuth\Cimd\CimdClientId;

// --- Valid CIMD client_id URLs ---

it('accepts a valid HTTPS URL with path', function (): void {
    $cimd = CimdClientId::tryFrom('https://app.example.com/oauth/metadata.json');

    expect($cimd)->not->toBeNull()
        ->and($cimd?->toString())->toBe('https://app.example.com/oauth/metadata.json')
        ->and($cimd?->getHost())->toBe('app.example.com');
});

it('accepts a valid HTTPS URL with a simple path', function (): void {
    $cimd = CimdClientId::tryFrom('https://example.com/client');

    expect($cimd)->not->toBeNull()
        ->and($cimd?->toString())->toBe('https://example.com/client')
        ->and($cimd?->getHost())->toBe('example.com');
});

it('accepts a valid HTTPS URL with query parameters', function (): void {
    $cimd = CimdClientId::tryFrom('https://example.com/path?key=value');

    expect($cimd)->not->toBeNull()
        ->and($cimd?->toString())->toBe('https://example.com/path?key=value');
});

it('accepts a valid HTTPS URL with port', function (): void {
    $cimd = CimdClientId::tryFrom('https://example.com:8443/path');

    expect($cimd)->not->toBeNull()
        ->and($cimd?->getHost())->toBe('example.com');
});

// --- Rejection: not HTTPS ---

it('rejects HTTP URLs', function (): void {
    expect(CimdClientId::tryFrom('http://app.example.com/path'))->toBeNull();
});

it('rejects FTP URLs', function (): void {
    expect(CimdClientId::tryFrom('ftp://app.example.com/path'))->toBeNull();
});

// --- Rejection: no meaningful path ---

it('rejects HTTPS URL with no path', function (): void {
    expect(CimdClientId::tryFrom('https://app.example.com'))->toBeNull();
});

it('rejects HTTPS URL with only slash path', function (): void {
    expect(CimdClientId::tryFrom('https://app.example.com/'))->toBeNull();
});

// --- Rejection: dot segments ---

it('rejects URL with dot-dot path segment', function (): void {
    expect(CimdClientId::tryFrom('https://app.example.com/path/../secret'))->toBeNull();
});

it('rejects URL with single-dot path segment', function (): void {
    expect(CimdClientId::tryFrom('https://app.example.com/./path'))->toBeNull();
});

it('rejects URL with dot-dot at end of path', function (): void {
    expect(CimdClientId::tryFrom('https://app.example.com/path/..'))->toBeNull();
});

// --- Rejection: fragment ---

it('rejects URL with fragment', function (): void {
    expect(CimdClientId::tryFrom('https://app.example.com/path#frag'))->toBeNull();
});

// --- Rejection: credentials ---

it('rejects URL with username and password', function (): void {
    expect(CimdClientId::tryFrom('https://user:pass@app.example.com/path'))->toBeNull();
});

it('rejects URL with username only', function (): void {
    expect(CimdClientId::tryFrom('https://user@app.example.com/path'))->toBeNull();
});

// --- Rejection: not a URL ---

it('rejects a normal DCR client_id string', function (): void {
    expect(CimdClientId::tryFrom('mcp_abc123'))->toBeNull();
});

it('rejects an empty string', function (): void {
    expect(CimdClientId::tryFrom(''))->toBeNull();
});

it('rejects a random non-URL string', function (): void {
    expect(CimdClientId::tryFrom('not-a-url-at-all'))->toBeNull();
});

// --- getHost() ---

it('returns the correct host from a valid URL', function (): void {
    $cimd = CimdClientId::tryFrom('https://myapp.example.org/oauth/meta.json');

    expect($cimd)->not->toBeNull()
        ->and($cimd?->getHost())->toBe('myapp.example.org');
});
