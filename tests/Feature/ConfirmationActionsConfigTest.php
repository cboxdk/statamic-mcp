<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\ConfirmationActionGate;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    // Mirror the shipped defaults so each test starts from a known baseline.
    Config::set('statamic.mcp.confirmation.actions', [
        'default' => ['delete'],
        'blueprints' => ['create', 'update', 'delete'],
    ]);
    Config::set('statamic.mcp.confirmation.enabled', true);
    Config::set('statamic.mcp.confirmation.ttl', 300);
    Config::set('statamic.mcp.security.force_web_mode', false);
});

// ---------------------------------------------------------------------------
// Defaults (regression guards — must not change without a major version bump)
// ---------------------------------------------------------------------------

it('gates delete on domains using the default list', function (): void {
    expect(ConfirmationActionGate::gates('entries', 'delete'))->toBeTrue();
});

it('does NOT gate update on entries by default', function (): void {
    expect(ConfirmationActionGate::gates('entries', 'update'))->toBeFalse();
});

it('gates create/update/delete on blueprints (backwards-compat regression guard)', function (): void {
    expect(ConfirmationActionGate::gates('blueprints', 'create'))->toBeTrue();
    expect(ConfirmationActionGate::gates('blueprints', 'update'))->toBeTrue();
    expect(ConfirmationActionGate::gates('blueprints', 'delete'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Explicit per-domain configuration
// ---------------------------------------------------------------------------

it('honours explicit per-domain gating for entries.update', function (): void {
    Config::set('statamic.mcp.confirmation.actions.entries', ['update']);

    expect(ConfirmationActionGate::gates('entries', 'update'))->toBeTrue();
    // Not in the list → not gated.
    expect(ConfirmationActionGate::gates('entries', 'delete'))->toBeFalse();
});

it('disables all gates on a domain when set to an empty array', function (): void {
    Config::set('statamic.mcp.confirmation.actions.entries', []);

    expect(ConfirmationActionGate::gates('entries', 'delete'))->toBeFalse();
    expect(ConfirmationActionGate::gates('entries', 'update'))->toBeFalse();
    expect(ConfirmationActionGate::gates('entries', 'create'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Fallback behaviour
// ---------------------------------------------------------------------------

it('falls back to the default list for domains not explicitly listed', function (): void {
    // No 'terms' key in config — should use 'default'.
    expect(ConfirmationActionGate::gates('terms', 'delete'))->toBeTrue();
    expect(ConfirmationActionGate::gates('terms', 'update'))->toBeFalse();
});

it('gates every action when the domain list contains the wildcard', function (): void {
    Config::set('statamic.mcp.confirmation.actions.entries', ['*']);

    expect(ConfirmationActionGate::gates('entries', 'list'))->toBeTrue();
    expect(ConfirmationActionGate::gates('entries', 'create'))->toBeTrue();
    expect(ConfirmationActionGate::gates('entries', 'update'))->toBeTrue();
    expect(ConfirmationActionGate::gates('entries', 'delete'))->toBeTrue();
    expect(ConfirmationActionGate::gates('entries', 'publish'))->toBeTrue();
});

it('accepts a returned confirmation token on the next gated call', function (): void {
    Config::set('statamic.mcp.security.force_web_mode', true);

    $router = new class extends EntriesRouter
    {
        /**
         * @param  array<string, mixed>  $arguments
         *
         * @return array<string, mixed>|null
         */
        public function callHandleConfirmation(string $action, array &$arguments): ?array
        {
            return $this->handleConfirmation($action, $arguments);
        }
    };

    $arguments = [
        'action' => 'delete',
        'collection' => 'blog',
        'id' => 'entry-123',
    ];

    $firstResponse = $router->callHandleConfirmation('delete', $arguments);

    if (! is_array($firstResponse)) {
        throw new RuntimeException('Expected confirmation response array.');
    }

    $responseData = $firstResponse['data'] ?? null;
    if (! is_array($responseData)) {
        throw new RuntimeException('Expected confirmation response data array.');
    }

    $confirmationToken = $responseData['confirmation_token'] ?? null;
    if (! is_string($confirmationToken)) {
        throw new RuntimeException('Expected confirmation token string.');
    }
    if ($confirmationToken === '') {
        throw new RuntimeException('Expected non-empty confirmation token.');
    }

    expect($firstResponse['success'])->toBeFalse()
        ->and($responseData['requires_confirmation'] ?? null)->toBeTrue();

    $confirmedArguments = array_merge($arguments, [
        'confirmation_token' => $confirmationToken,
    ]);

    expect($router->callHandleConfirmation('delete', $confirmedArguments))->toBeNull()
        ->and($confirmedArguments)->toBe($arguments);
});

it('accepts reordered nested confirmation arguments but restores the confirmed payload', function (): void {
    Config::set('statamic.mcp.security.force_web_mode', true);
    Config::set('statamic.mcp.confirmation.actions.entries', ['update']);

    $router = new class extends EntriesRouter
    {
        /**
         * @param  array<string, mixed>  $arguments
         *
         * @return array<string, mixed>|null
         */
        public function callHandleConfirmation(string $action, array &$arguments): ?array
        {
            return $this->handleConfirmation($action, $arguments);
        }
    };

    $arguments = [
        'action' => 'update',
        'collection' => 'blog',
        'id' => 'entry-123',
        'data' => [
            'title' => 'About',
            'seo' => [
                'description' => 'About page',
                'title' => 'About us',
            ],
        ],
    ];

    $firstResponse = $router->callHandleConfirmation('update', $arguments);

    if (! is_array($firstResponse)) {
        throw new RuntimeException('Expected confirmation response array.');
    }

    $responseData = $firstResponse['data'] ?? null;
    if (! is_array($responseData)) {
        throw new RuntimeException('Expected confirmation response data array.');
    }

    $confirmationToken = $responseData['confirmation_token'] ?? null;
    if (! is_string($confirmationToken) || $confirmationToken === '') {
        throw new RuntimeException('Expected non-empty confirmation token string.');
    }

    $confirmedArguments = [
        'data' => [
            'seo' => [
                'title' => 'About us',
                'description' => 'About page',
            ],
            'title' => 'About',
        ],
        'id' => 'entry-123',
        'collection' => 'blog',
        'action' => 'update',
        'confirmation_token' => $confirmationToken,
    ];

    expect($router->callHandleConfirmation('update', $confirmedArguments))->toBeNull()
        ->and($confirmedArguments)->toBe($arguments);
});

// ---------------------------------------------------------------------------
// handleConfirmation() short-circuits (env + CLI)
// ---------------------------------------------------------------------------

it('short-circuits handleConfirmation when confirmation.enabled is false', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', false);

    $router = new class extends EntriesRouter
    {
        /**
         * @param  array<string, mixed>  $arguments
         *
         * @return array<string, mixed>|null
         */
        public function callHandleConfirmation(string $action, array $arguments): ?array
        {
            return $this->handleConfirmation($action, $arguments);
        }
    };

    $response = $router->callHandleConfirmation('delete', ['id' => 'abc']);

    expect($response)->toBeNull();
});

it('short-circuits handleConfirmation in CLI context', function (): void {
    Config::set('statamic.mcp.confirmation.enabled', true);

    $router = new class extends EntriesRouter
    {
        /**
         * @param  array<string, mixed>  $arguments
         *
         * @return array<string, mixed>|null
         */
        public function callHandleConfirmation(string $action, array $arguments): ?array
        {
            return $this->handleConfirmation($action, $arguments);
        }
    };

    // runningInConsole() is true under Pest — gate should still short-circuit.
    $response = $router->callHandleConfirmation('delete', ['id' => 'abc']);

    expect($response)->toBeNull();
});
