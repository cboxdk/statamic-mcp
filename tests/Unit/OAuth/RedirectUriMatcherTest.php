<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\OAuth\RedirectUriMatcher;

describe('exact matches', function () {
    it('accepts a registered URI as given', function (string $uri) {
        expect(RedirectUriMatcher::matches($uri, [$uri]))->toBeTrue();
    })->with([
        'https' => 'https://app.example.com/callback',
        'https with port' => 'https://app.example.com:8443/callback',
        'loopback with port' => 'http://localhost:3000/callback',
    ]);

    it('refuses an empty redirect_uri', function () {
        expect(RedirectUriMatcher::matches('', ['']))->toBeFalse();
    });

    it('refuses a URI that is not registered', function () {
        expect(RedirectUriMatcher::matches('https://evil.example/callback', ['https://app.example.com/callback']))->toBeFalse();
    });
});

describe('loopback redirect URIs (RFC 8252 §7.3)', function () {
    it('accepts any port on a registered loopback URI', function (string $registered, string $requested) {
        expect(RedirectUriMatcher::matches($requested, [$registered]))->toBeTrue();
    })->with([
        'localhost, no port registered' => ['http://localhost/callback', 'http://localhost:53682/callback'],
        'localhost, other port registered' => ['http://localhost:3000/callback', 'http://localhost:53682/callback'],
        '127.0.0.1, no port registered' => ['http://127.0.0.1/callback', 'http://127.0.0.1:61000/callback'],
        'port removed' => ['http://localhost:3000/callback', 'http://localhost/callback'],
        'same query' => ['http://localhost/callback?app=cli', 'http://localhost:53682/callback?app=cli'],
    ]);

    it('still requires the rest of the URI to match', function (string $registered, string $requested) {
        expect(RedirectUriMatcher::matches($requested, [$registered]))->toBeFalse();
    })->with([
        'other path' => ['http://localhost/callback', 'http://localhost:53682/other'],
        'path prefix' => ['http://localhost/callback', 'http://localhost:53682/callback/extra'],
        'other host' => ['http://localhost/callback', 'http://127.0.0.1:53682/callback'],
        'other query' => ['http://localhost/callback', 'http://localhost:53682/callback?next=/admin'],
        'https requested' => ['http://localhost/callback', 'https://localhost:53682/callback'],
        'user info' => ['http://localhost/callback', 'http://user@localhost:53682/callback'],
        'fragment' => ['http://localhost/callback', 'http://localhost:53682/callback#x'],
        'uppercase host' => ['http://localhost/callback', 'http://LOCALHOST:53682/callback'],
    ]);

    it('never relaxes the port for non-loopback URIs', function (string $registered, string $requested) {
        expect(RedirectUriMatcher::matches($requested, [$registered]))->toBeFalse();
    })->with([
        'https host' => ['https://app.example.com/callback', 'https://app.example.com:8443/callback'],
        'https localhost' => ['https://localhost/callback', 'https://localhost:8443/callback'],
        'lookalike host' => ['http://localhost/callback', 'http://localhost.evil.example:53682/callback'],
        'other private address' => ['http://127.0.0.1/callback', 'http://127.0.0.2:53682/callback'],
    ]);

    it('checks every registered URI', function () {
        $registered = ['https://app.example.com/callback', 'http://localhost/callback'];

        expect(RedirectUriMatcher::matches('http://localhost:53682/callback', $registered))->toBeTrue();
    });
});
