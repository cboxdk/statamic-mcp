<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    // Default config: everything open
    Config::set('statamic.mcp.tools', [
        'entries' => [
            'enabled' => true,
            'resources' => ['read' => ['*'], 'write' => ['*']],
            'denied_fields' => [],
        ],
        'blueprints' => [
            'enabled' => true,
            'resources' => ['read' => ['*'], 'write' => ['*']],
            'denied_fields' => [],
        ],
    ]);
});

// ---------------------------------------------------------------------------
// canAccess — defaults (everything open)
// ---------------------------------------------------------------------------

it('allows read access with wildcard default', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'blog', 'read'))->toBeTrue();
});

it('allows write access with wildcard default', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'blog', 'write'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// canAccess — restricted resources
// ---------------------------------------------------------------------------

it('allows write when handle matches glob pattern', function (): void {
    Config::set('statamic.mcp.tools.entries.resources.write', ['blog*', 'pages']);

    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'blog', 'write'))->toBeTrue();
    expect($policy->canAccess('entries', 'blog-archive', 'write'))->toBeTrue();
    expect($policy->canAccess('entries', 'pages', 'write'))->toBeTrue();
});

it('denies write when handle does not match any glob', function (): void {
    Config::set('statamic.mcp.tools.entries.resources.write', ['blog*', 'pages']);

    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'products', 'write'))->toBeFalse();
    expect($policy->canAccess('entries', 'my-blog', 'write'))->toBeFalse();
});

it('allows read even when write is restricted', function (): void {
    Config::set('statamic.mcp.tools.entries.resources.write', ['blog*']);
    Config::set('statamic.mcp.tools.entries.resources.read', ['*']);

    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'products', 'read'))->toBeTrue();
    expect($policy->canAccess('entries', 'products', 'write'))->toBeFalse();
});

it('denies access when resource list is empty', function (): void {
    Config::set('statamic.mcp.tools.entries.resources.write', []);

    $policy = new ResourcePolicy;

    expect($policy->canAccess('entries', 'blog', 'write'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// canAccess — unconfigured domain falls back to allow all
// ---------------------------------------------------------------------------

it('allows access for unconfigured domain', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->canAccess('terms', 'tags', 'write'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// getDeniedFields
// ---------------------------------------------------------------------------

it('returns empty array when no denied fields configured', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->getDeniedFields('entries'))->toBe([]);
});

it('returns configured denied fields', function (): void {
    Config::set('statamic.mcp.tools.entries.denied_fields', ['internal_notes', 'api_key']);

    $policy = new ResourcePolicy;

    expect($policy->getDeniedFields('entries'))->toBe(['internal_notes', 'api_key']);
});

it('returns empty array for unconfigured domain', function (): void {
    $policy = new ResourcePolicy;

    expect($policy->getDeniedFields('terms'))->toBe([]);
});

// ---------------------------------------------------------------------------
// filterFields — flat data
// ---------------------------------------------------------------------------

it('strips denied fields from flat data', function (): void {
    Config::set('statamic.mcp.tools.entries.denied_fields', ['secret', 'internal']);

    $policy = new ResourcePolicy;

    $data = ['title' => 'Hello', 'secret' => 'hidden', 'internal' => 'notes', 'slug' => 'hello'];
    $filtered = $policy->filterFields('entries', $data);

    expect($filtered)->toBe(['title' => 'Hello', 'slug' => 'hello']);
});

it('returns data unchanged when no denied fields', function (): void {
    $policy = new ResourcePolicy;

    $data = ['title' => 'Hello', 'slug' => 'hello'];
    $filtered = $policy->filterFields('entries', $data);

    expect($filtered)->toBe($data);
});

// ---------------------------------------------------------------------------
// filterFields — nested data (Bard/Replicator/Grid)
// ---------------------------------------------------------------------------

it('strips denied fields from nested arrays recursively', function (): void {
    Config::set('statamic.mcp.tools.entries.denied_fields', ['secret']);

    $policy = new ResourcePolicy;

    $data = [
        'title' => 'Post',
        'content' => [
            ['type' => 'text', 'secret' => 'hidden', 'text' => 'visible'],
            ['type' => 'image', 'url' => '/img.png'],
        ],
        'secret' => 'top-level-hidden',
    ];

    $filtered = $policy->filterFields('entries', $data);

    expect($filtered)->toBe([
        'title' => 'Post',
        'content' => [
            ['type' => 'text', 'text' => 'visible'],
            ['type' => 'image', 'url' => '/img.png'],
        ],
    ]);
});

it('handles deeply nested structures', function (): void {
    Config::set('statamic.mcp.tools.entries.denied_fields', ['price']);

    $policy = new ResourcePolicy;

    $data = [
        'title' => 'Product',
        'sections' => [
            'main' => [
                'fields' => [
                    'name' => 'Widget',
                    'price' => 99.99,
                    'details' => [
                        'price' => 'also hidden',
                        'description' => 'A widget',
                    ],
                ],
            ],
        ],
    ];

    $filtered = $policy->filterFields('entries', $data);

    expect($filtered['sections']['main']['fields'])->not->toHaveKey('price');
    expect($filtered['sections']['main']['fields']['details'])->not->toHaveKey('price');
    expect($filtered['sections']['main']['fields']['details']['description'])->toBe('A widget');
});
