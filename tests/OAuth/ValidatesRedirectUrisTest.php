<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\OAuth\Concerns\ValidatesRedirectUris;

// ---------------------------------------------------------------------------
// Test harness — simple class that exposes the trait's private method
// ---------------------------------------------------------------------------

class RedirectUriValidator
{
    use ValidatesRedirectUris;

    public function validate(string $uri): bool
    {
        return $this->validateRedirectUri($uri);
    }
}

beforeEach(function () {
    $this->validator = new RedirectUriValidator;
});

// ---------------------------------------------------------------------------
// HTTPS URLs
// ---------------------------------------------------------------------------

describe('HTTPS URLs', function () {
    it('accepts valid HTTPS URLs', function (string $uri) {
        expect($this->validator->validate($uri))->toBeTrue();
    })->with([
        'standard' => 'https://example.com/callback',
        'with port' => 'https://example.com:8443/callback',
        'with path' => 'https://app.example.com/oauth/redirect',
        'localhost' => 'https://localhost/callback',
        'localhost with port' => 'https://localhost:3000/callback',
        'loopback' => 'https://127.0.0.1/callback',
    ]);
});

// ---------------------------------------------------------------------------
// HTTP localhost URLs
// ---------------------------------------------------------------------------

describe('HTTP localhost URLs', function () {
    it('accepts HTTP localhost and 127.0.0.1', function (string $uri) {
        expect($this->validator->validate($uri))->toBeTrue();
    })->with([
        'localhost' => 'http://localhost/callback',
        'localhost with port' => 'http://localhost:8080/callback',
        '127.0.0.1' => 'http://127.0.0.1/callback',
        '127.0.0.1 with port' => 'http://127.0.0.1:3000/callback',
    ]);
});

// ---------------------------------------------------------------------------
// Rejected URLs
// ---------------------------------------------------------------------------

describe('rejected URLs', function () {
    it('rejects plain HTTP non-localhost URLs', function (string $uri) {
        expect($this->validator->validate($uri))->toBeFalse();
    })->with([
        'external domain' => 'http://example.com/callback',
        'external IP' => 'http://192.168.1.1/callback',
        'subdomain' => 'http://app.example.com/callback',
    ]);

    it('rejects URLs with fragments', function (string $uri) {
        expect($this->validator->validate($uri))->toBeFalse();
    })->with([
        'HTTPS with fragment' => 'https://example.com/callback#section',
        'HTTP localhost with fragment' => 'http://localhost/callback#token',
    ]);

    it('rejects empty strings', function () {
        expect($this->validator->validate(''))->toBeFalse();
    });

    it('rejects relative URLs', function (string $uri) {
        expect($this->validator->validate($uri))->toBeFalse();
    })->with([
        'relative path' => '/callback',
        'bare path' => 'callback',
        'dot-relative' => './callback',
    ]);

    it('rejects IPv6 loopback over HTTP', function () {
        expect($this->validator->validate('http://[::1]/callback'))->toBeFalse();
    });
});
