<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\Actions\LifecycleObservers;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Events\McpTokenDeleted;
use Cboxdk\StatamicMcp\Events\McpTokenSaved;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Testing\FakeLifecycleObserver;
use Illuminate\Support\Facades\Event;
use Psr\Log\NullLogger;
use Statamic\Facades\User;

it('reports actual grant creation and replay without treating rotation as revocation', function () {
    $fake = new FakeLifecycleObserver;
    $this->app->instance(LifecycleObservers::class, new LifecycleObservers(new NullLogger, [$fake]));
    $user = User::make()->email('events@example.test')->makeSuper();
    $user->save();
    $driver = app(OAuthDriver::class);
    $client = $driver->registerClient('Private client name', ['https://private.example/callback']);
    $snapshot = new CapabilitySnapshot([]);
    $created = app(TokenService::class)->createToken($user->id(), 'Private token name', ['entries:read'],
        oauthClientId: $client->clientId, capabilities: $snapshot);
    $refresh = $driver->createRefreshToken($user->id(), $client->clientId, ['entries:read'], $snapshot);
    $body = ['grant_type' => 'refresh_token', 'client_id' => $client->clientId, 'refresh_token' => $refresh];
    $rotated = $this->postJson('/mcp/oauth/token', $body)->assertOk();
    expect($fake->events)->toHaveCount(1);
    expect($fake->events[0]->eventName())->toBe('grant.issued');
    $this->postJson('/mcp/oauth/token', $body)->assertStatus(400);
    expect($fake->named('grant.revoked'))->toHaveCount(1);
    expect($fake->named('grant.refresh_replay_detected'))->toHaveCount(1);
    foreach ($fake->events as $event) {
        expect($event->metadata()['grant_id'])->toBe($snapshot->grantId);
        $json = json_encode($event->metadata(), JSON_THROW_ON_ERROR);
        expect($json)->not->toContain($created['token'], $refresh, $rotated->json('access_token'), 'Private', 'private.example');
    }
    expect(app(TokenService::class)->validateToken($rotated->json('access_token')))->toBeNull();
});

it('does not report credential failure when legacy Git listeners throw after persistence', function () {
    Event::listen(McpTokenSaved::class, fn () => throw new RuntimeException('Exporter secret'));
    Event::listen(McpTokenDeleted::class, fn () => throw new RuntimeException('Exporter secret'));
    $service = app(TokenService::class);
    $created = $service->createToken('user', 'Private', ['entries:read']);
    expect($service->validateToken($created['token']))->not->toBeNull();
    expect($service->revokeToken($created['model']->id))->toBeTrue();
    expect($service->validateToken($created['token']))->toBeNull();
});
