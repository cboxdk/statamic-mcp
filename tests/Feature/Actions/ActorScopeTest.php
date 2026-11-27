<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Contracts\ActorScope;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\User;

function actorScopeContext(string $id): ExecutionContext
{
    return new ExecutionContext($id, 'grant', 'default', 'operator', 'stdio', 'call', hrtime(true) + 60_000_000_000);
}

test('nested execution restores the CP guard and custom request resolver without login events', function () {
    $cp = User::make()->email('cp@example.test')->makeSuper();
    $cp->save();
    $first = User::make()->email('first@example.test');
    $first->save();
    $second = User::make()->email('second@example.test');
    $second->save();
    $this->actingAs($cp);
    $originalGuard = Auth::guard();
    $originalDriver = Auth::getDefaultDriver();
    $originalResolver = Auth::userResolver();
    $requestResolver = fn () => $cp;
    request()->setUserResolver($requestResolver);
    $authEvents = 0;
    Event::listen(Authenticated::class, function () use (&$authEvents): void {
        $authEvents++;
    });
    $scope = app(ActorScope::class);
    $scope->run(actorScopeContext($first->id()), function () use ($scope, $first, $second, $originalGuard, $cp): void {
        expect(Auth::user()->id())->toBe($first->id());
        expect(User::current()->id())->toBe($first->id());
        expect(request()->user()->id())->toBe($first->id());
        expect($originalGuard->user())->toBe($cp);
        try {
            $scope->run(actorScopeContext($second->id()), function () use ($second): void {
                expect(User::current()->id())->toBe($second->id());
                throw new RuntimeException('Nested failure');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('Nested failure');
        }
        expect(User::current()->id())->toBe($first->id());
        expect(request()->user()->id())->toBe($first->id());
    });
    expect(Auth::guard())->toBe($originalGuard);
    expect(Auth::getDefaultDriver())->toBe($originalDriver);
    expect(Auth::userResolver())->toBe($originalResolver);
    expect(request()->getUserResolver())->toBe($requestResolver);
    expect(User::current()->id())->toBe($cp->id());
    expect($authEvents)->toBe(0);
});

test('sequential calls restore anonymous state even after a handler failure', function () {
    $first = User::make()->email('first@example.test');
    $first->save();
    $second = User::make()->email('second@example.test');
    $second->save();
    $scope = app(ActorScope::class);
    foreach ([$first, $second] as $actor) {
        try {
            $scope->run(actorScopeContext($actor->id()), function () use ($actor): void {
                expect(User::current()->id())->toBe($actor->id());
                throw new RuntimeException('Handler failed');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('Handler failed');
        }
        expect(Auth::user())->toBeNull();
        expect(request()->user())->toBeNull();
        expect(Auth::guard('_statamic_mcp_execution')->user())->toBeNull();
    }
});

test('an unavailable execution actor cannot inherit the ambient super user', function () {
    $cp = User::make()->email('cp@example.test')->makeSuper();
    $cp->save();
    $inactive = User::make()->email('inactive@example.test')->set('status', 'inactive');
    $inactive->save();
    $this->actingAs($cp);
    $scope = app(ActorScope::class);
    foreach (['missing', $inactive->id()] as $id) {
        expect(fn () => $scope->run(actorScopeContext($id), fn () => throw new LogicException('Must not execute')))
            ->toThrow(AuthorizationException::class);
        expect(User::current()->id())->toBe($cp->id());
    }
});

test('a new worker lifecycle resolves a fresh actor scope without retaining authentication', function () {
    $actor = User::make()->email('worker@example.test');
    $actor->save();
    $first = app(ActorScope::class);
    $first->run(actorScopeContext($actor->id()), fn () => expect(User::current()->id())->toBe($actor->id()));
    app()->forgetScopedInstances();
    Auth::forgetGuards();
    $second = app(ActorScope::class);
    expect($second)->not->toBe($first);
    expect(Auth::user())->toBeNull();
    $second->run(actorScopeContext($actor->id()), fn () => expect(request()->user()->id())->toBe($actor->id()));
    expect(Auth::user())->toBeNull();
});

test('actor refresh cannot escape its active scope or replace a nested actor', function () {
    $first = User::make()->email('refresh-first@example.test');
    $first->save();
    $second = User::make()->email('refresh-second@example.test');
    $second->save();
    $scope = app(ActorScope::class);
    $outer = actorScopeContext($first->id());
    $inner = actorScopeContext($second->id());
    expect(fn () => $scope->refresh($outer))->toThrow(LogicException::class);
    $scope->run($outer, function () use ($scope, $outer, $inner, $first, $second) {
        $scope->refresh($outer);
        expect(User::current()->id())->toBe($first->id());
        $scope->run($inner, function () use ($scope, $outer, $inner, $second) {
            expect(fn () => $scope->refresh($outer))->toThrow(LogicException::class);
            $scope->refresh($inner);
            expect(User::current()->id())->toBe($second->id());
        });
        $scope->refresh($outer);
        expect(User::current()->id())->toBe($first->id());
    });
    expect(fn () => $scope->refresh($outer))->toThrow(LogicException::class);
    expect(Auth::user())->toBeNull();
});
