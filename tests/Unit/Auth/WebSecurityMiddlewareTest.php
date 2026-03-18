<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Http\Middleware\EnsureSecureTransport;
use Cboxdk\StatamicMcp\Http\Middleware\HandleMcpCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->passThrough = fn (Request $request): Response => response()->json(['ok' => true]);
});

/*
|--------------------------------------------------------------------------
| EnsureSecureTransport
|--------------------------------------------------------------------------
*/

describe('EnsureSecureTransport', function () {

    it('passes through when require_https is disabled', function () {
        config()->set('statamic.mcp.web.require_https', false);

        $middleware = new EnsureSecureTransport;
        $request = Request::create('http://example.com/mcp/statamic', 'GET');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
    });

    it('passes through HTTPS requests when require_https is enabled', function () {
        config()->set('statamic.mcp.web.require_https', true);
        // Force non-local environment
        app()->detectEnvironment(fn () => 'production');

        $middleware = new EnsureSecureTransport;
        $request = Request::create('https://example.com/mcp/statamic', 'GET');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
    });

    it('rejects HTTP requests when require_https is enabled in production', function () {
        config()->set('statamic.mcp.web.require_https', true);
        app()->detectEnvironment(fn () => 'production');

        $middleware = new EnsureSecureTransport;
        $request = Request::create('http://example.com/mcp/statamic', 'GET');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(403);

        /** @var string $content */
        $content = $response->getContent();
        $body = json_decode($content, true);
        expect($body['error'])->toBe('HTTPS required');
    });

    it('allows HTTP in local environment even when require_https is enabled', function () {
        config()->set('statamic.mcp.web.require_https', true);
        app()->detectEnvironment(fn () => 'local');

        $middleware = new EnsureSecureTransport;
        $request = Request::create('http://example.com/mcp/statamic', 'GET');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
    });

    it('allows HTTP in testing environment even when require_https is enabled', function () {
        config()->set('statamic.mcp.web.require_https', true);
        app()->detectEnvironment(fn () => 'testing');

        $middleware = new EnsureSecureTransport;
        $request = Request::create('http://example.com/mcp/statamic', 'GET');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
    });
});

/*
|--------------------------------------------------------------------------
| HandleMcpCors
|--------------------------------------------------------------------------
*/

describe('HandleMcpCors', function () {

    it('adds no CORS headers when allowed_origins is empty', function () {
        config()->set('statamic.mcp.web.allowed_origins', []);

        $middleware = new HandleMcpCors;
        $request = Request::create('https://example.com/mcp/statamic', 'GET');
        $request->headers->set('Origin', 'https://client.example.com');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    });

    it('adds CORS headers for allowed origin', function () {
        config()->set('statamic.mcp.web.allowed_origins', ['https://client.example.com']);

        $middleware = new HandleMcpCors;
        $request = Request::create('https://example.com/mcp/statamic', 'GET');
        $request->headers->set('Origin', 'https://client.example.com');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://client.example.com');
        expect($response->headers->get('Vary'))->toBe('Origin');
    });

    it('does not add CORS headers for disallowed origin', function () {
        config()->set('statamic.mcp.web.allowed_origins', ['https://allowed.example.com']);

        $middleware = new HandleMcpCors;
        $request = Request::create('https://example.com/mcp/statamic', 'GET');
        $request->headers->set('Origin', 'https://evil.example.com');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    });

    it('supports wildcard origin', function () {
        config()->set('statamic.mcp.web.allowed_origins', ['*']);

        $middleware = new HandleMcpCors;
        $request = Request::create('https://example.com/mcp/statamic', 'GET');
        $request->headers->set('Origin', 'https://any-site.com');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://any-site.com');
    });

    it('handles preflight OPTIONS request with allowed origin', function () {
        config()->set('statamic.mcp.web.allowed_origins', ['https://client.example.com']);

        $middleware = new HandleMcpCors;
        $request = Request::create('https://example.com/mcp/statamic', 'OPTIONS');
        $request->headers->set('Origin', 'https://client.example.com');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(204);
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://client.example.com');
        expect($response->headers->get('Access-Control-Allow-Methods'))->toBe('GET, POST, OPTIONS');
        expect($response->headers->get('Access-Control-Allow-Headers'))->toBe('Authorization, Content-Type, Accept');
        expect($response->headers->get('Access-Control-Max-Age'))->toBe('86400');
    });

    it('returns empty preflight response for disallowed origin', function () {
        config()->set('statamic.mcp.web.allowed_origins', ['https://allowed.example.com']);

        $middleware = new HandleMcpCors;
        $request = Request::create('https://example.com/mcp/statamic', 'OPTIONS');
        $request->headers->set('Origin', 'https://evil.example.com');

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(204);
        expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    });

    it('does not add CORS headers when no Origin header is present', function () {
        config()->set('statamic.mcp.web.allowed_origins', ['https://client.example.com']);

        $middleware = new HandleMcpCors;
        $request = Request::create('https://example.com/mcp/statamic', 'GET');
        // No Origin header — typical for desktop MCP clients

        $response = $middleware->handle($request, $this->passThrough);

        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
    });
});
