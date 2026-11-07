<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Cboxdk\StatamicMcp\Actions\Contracts\ActorScope;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Container\Container;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\Blink;
use Statamic\Facades\User;

/** A private, non-session guard: no login/logout events or changes to CP sessions. */
final class ExecutionActorScope implements ActorScope
{
    public const GUARD = '_statamic_mcp_execution';

    public function __construct(private readonly AuthManager $auth, private readonly Container $container) {}

    public function run(ExecutionContext $context, Closure $callback): mixed
    {
        Blink::flush();
        $this->container->make(PermissionCache::class)->clear();
        $actor = User::find($context->actorId);
        if ($actor === null || $actor->get('status') === 'inactive') {
            throw new AuthorizationException('Execution actor is unavailable.');
        }
        $guard = $this->auth->guard(self::GUARD);
        if (! $guard instanceof RequestGuard) {
            throw new \LogicException('Invalid private MCP execution guard.');
        }
        $previousActor = $guard->user();
        $previousDriver = $this->auth->getDefaultDriver();
        $previousResolver = $this->auth->userResolver();
        $request = $this->container->make('request');
        $previousRequestResolver = $request->getUserResolver();
        $guard->setUser($actor);
        try {
            $this->auth->shouldUse(self::GUARD);
            $request->setUserResolver(fn ($guard = null) => $this->auth->guard($guard)->user());

            return $callback();
        } finally {
            if ($previousActor === null) {
                $guard->forgetUser();
            } else {
                $guard->setUser($previousActor);
            }
            $this->auth->shouldUse($previousDriver);
            $this->auth->resolveUsersUsing($previousResolver);
            $request->setUserResolver($previousRequestResolver);
            Blink::flush();
            $this->container->make(PermissionCache::class)->clear();
        }
    }
}
