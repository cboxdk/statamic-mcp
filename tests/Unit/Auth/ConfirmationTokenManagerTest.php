<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\ConfirmationTokenManager;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    Config::set('statamic.mcp.confirmation.ttl', 300);
});

// ---------------------------------------------------------------------------
// Generation
// ---------------------------------------------------------------------------

it('generates a non-empty encrypted token', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);

    expect($token)->toBeString()->not->toBeEmpty();
});

it('generates different tokens for different arguments', function (): void {
    $manager = new ConfirmationTokenManager;

    $token1 = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);
    $token2 = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'contact']);

    expect($token1)->not->toBe($token2);
});

it('generates different tokens for different tools', function (): void {
    $manager = new ConfirmationTokenManager;

    $token1 = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);
    $token2 = $manager->generate('statamic-blueprints', ['action' => 'delete', 'handle' => 'about']);

    expect($token1)->not->toBe($token2);
});

it('generates different tokens for the same canonical arguments', function (): void {
    $manager = new ConfirmationTokenManager;

    $args1 = [
        'action' => 'update',
        'handle' => 'article',
        'data' => [
            'title' => 'About',
            'seo' => [
                'description' => 'About page',
                'title' => 'About us',
            ],
        ],
    ];
    $args2 = [
        'data' => [
            'seo' => [
                'title' => 'About us',
                'description' => 'About page',
            ],
            'title' => 'About',
        ],
        'handle' => 'article',
        'action' => 'update',
    ];

    $token1 = $manager->generate('statamic-entries', $args1);
    $token2 = $manager->generate('statamic-entries', $args2);

    expect($token1)->not->toBe($token2)
        ->and($manager->validatedArguments($token1, 'statamic-entries', array_merge($args1, [
            'confirmation_token' => $token1,
        ])))->toBe($args1)
        ->and($manager->validatedArguments($token2, 'statamic-entries', array_merge($args2, [
            'confirmation_token' => $token2,
        ])))->toBe($args2);
});

it('keeps large confirmation tokens compact enough for the response envelope', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', [
        'action' => 'update',
        'handle' => 'article',
        'data' => [
            'body' => str_repeat('Long repeated content. ', 3000),
        ],
    ]);

    expect(strlen($token))->toBeLessThan(100000);
});

// ---------------------------------------------------------------------------
// Validation — success
// ---------------------------------------------------------------------------

it('validates a token that was just generated', function (): void {
    $manager = new ConfirmationTokenManager;

    $args = ['action' => 'delete', 'handle' => 'about'];
    $token = $manager->generate('statamic-entries', $args);

    expect($manager->validate($token, 'statamic-entries', $args))->toBeTrue();
});

it('strips confirmation_token key before validating', function (): void {
    $manager = new ConfirmationTokenManager;

    $args = ['action' => 'delete', 'handle' => 'about'];
    $token = $manager->generate('statamic-entries', $args);

    // Agent sends back arguments including the token itself
    $argsWithToken = array_merge($args, ['confirmation_token' => $token]);

    expect($manager->validate($token, 'statamic-entries', $argsWithToken))->toBeTrue();
});

it('validates with arguments in different key order', function (): void {
    $manager = new ConfirmationTokenManager;

    $args1 = ['action' => 'delete', 'handle' => 'about'];
    $token = $manager->generate('statamic-entries', $args1);

    // Same arguments, different key order
    $args2 = ['handle' => 'about', 'action' => 'delete'];

    expect($manager->validate($token, 'statamic-entries', $args2))->toBeTrue();
});

it('validates with nested associative arguments in different key order', function (): void {
    $manager = new ConfirmationTokenManager;

    $args1 = [
        'action' => 'update',
        'handle' => 'article',
        'data' => [
            'title' => 'About',
            'seo' => [
                'description' => 'About page',
                'title' => 'About us',
            ],
        ],
    ];
    $token = $manager->generate('statamic-entries', $args1);

    $args2 = [
        'data' => [
            'seo' => [
                'title' => 'About us',
                'description' => 'About page',
            ],
            'title' => 'About',
        ],
        'handle' => 'article',
        'action' => 'update',
    ];

    expect($manager->validate($token, 'statamic-entries', $args2))->toBeTrue();
});

it('returns the originally confirmed arguments after nested associative reordering', function (): void {
    $manager = new ConfirmationTokenManager;

    $args1 = [
        'action' => 'update',
        'handle' => 'article',
        'data' => [
            'title' => 'About',
            'seo' => [
                'description' => 'About page',
                'title' => 'About us',
            ],
        ],
    ];
    $token = $manager->generate('statamic-entries', $args1);

    $args2 = [
        'data' => [
            'seo' => [
                'title' => 'About us',
                'description' => 'About page',
            ],
            'title' => 'About',
        ],
        'handle' => 'article',
        'action' => 'update',
        'confirmation_token' => $token,
    ];

    expect($manager->validatedArguments($token, 'statamic-entries', $args2))->toBe($args1);
});

it('rejects reordered list arguments', function (): void {
    $manager = new ConfirmationTokenManager;

    $args = [
        'action' => 'update',
        'handle' => 'article',
        'data' => [
            'sections' => [
                ['type' => 'text', 'content' => 'First'],
                ['type' => 'text', 'content' => 'Second'],
            ],
        ],
    ];
    $token = $manager->generate('statamic-entries', $args);

    $reordered = [
        'action' => 'update',
        'handle' => 'article',
        'data' => [
            'sections' => [
                ['type' => 'text', 'content' => 'Second'],
                ['type' => 'text', 'content' => 'First'],
            ],
        ],
    ];

    expect($manager->validate($token, 'statamic-entries', $reordered))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Validation — failure
// ---------------------------------------------------------------------------

it('rejects a tampered token', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);
    $tampered = $token . 'x';

    expect($manager->validate($tampered, 'statamic-entries', ['action' => 'delete', 'handle' => 'about']))->toBeFalse();
});

it('rejects a token with modified arguments', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);

    expect($manager->validate($token, 'statamic-entries', ['action' => 'delete', 'handle' => 'contact']))->toBeFalse();
});

it('rejects a token with wrong tool name', function (): void {
    $manager = new ConfirmationTokenManager;

    $token = $manager->generate('statamic-entries', ['action' => 'delete', 'handle' => 'about']);

    expect($manager->validate($token, 'statamic-blueprints', ['action' => 'delete', 'handle' => 'about']))->toBeFalse();
});

it('rejects an expired token', function (): void {
    Config::set('statamic.mcp.confirmation.ttl', 1); // 1 second TTL

    $manager = new ConfirmationTokenManager;

    $args = ['action' => 'delete', 'handle' => 'about'];
    $token = $manager->generate('statamic-entries', $args);

    // Wait for expiry
    sleep(2);

    expect($manager->validate($token, 'statamic-entries', $args))->toBeFalse();
});

it('rejects an empty string token', function (): void {
    $manager = new ConfirmationTokenManager;

    expect($manager->validate('', 'statamic-entries', ['action' => 'delete']))->toBeFalse();
});

it('rejects a malformed token without separator', function (): void {
    $manager = new ConfirmationTokenManager;

    expect($manager->validate('notavalidtoken', 'statamic-entries', ['action' => 'delete']))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Environment-aware enabled check
// ---------------------------------------------------------------------------

it('reports enabled in production when config is null', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', null);
    app()['env'] = 'production';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeTrue();
});

it('reports disabled in local when config is null', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', null);
    app()['env'] = 'local';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeFalse();
});

it('reports disabled in testing when config is null', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', null);
    app()['env'] = 'testing';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeFalse();
});

it('respects explicit true override regardless of environment', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', true);
    app()['env'] = 'local';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeTrue();
});

it('respects explicit false override regardless of environment', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', false);
    app()['env'] = 'production';

    $manager = new ConfirmationTokenManager;

    expect($manager->isEnabled())->toBeFalse();
});
