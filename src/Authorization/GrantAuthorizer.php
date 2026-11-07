<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Contracts\Authorizer;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Illuminate\Auth\Access\AuthorizationException;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\Blink;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/** Required outer boundary. An addon policy may narrow this decision, never replace it. */
final class GrantAuthorizer implements Authorizer
{
    public function __construct(private readonly TokenStore $tokens, private readonly ScopeAvailability $scopes, private readonly ScopeRegistry $registry, private readonly AuthoritativeAuthReads $reads) {}

    public function authorize(ActionDefinition $action, ExecutionContext $context): void
    {
        $this->reads->run(fn () => $this->authorizeCurrent($action, $context));
    }

    private function authorizeCurrent(ActionDefinition $action, ExecutionContext $context): void
    {
        Blink::flush();
        app(PermissionCache::class)->clear();
        $profile = config("statamic.mcp.profiles.{$context->transport}");
        if (! in_array($profile, ['read_only', 'editor', 'operator'], true)
            || $context->profile !== $profile || ! in_array($profile, $action->profiles, true)
            || ($profile === 'read_only' && $action->effect !== ActionEffect::Read)
            || ($profile === 'editor' && ! in_array($action->effect, [ActionEffect::Read, ActionEffect::Edit], true))) {
            throw new AuthorizationException('Action is not allowed by the installation profile.');
        }

        // Always reload: the request's token DTO is not proof that a grant still exists.
        $grant = $context->credentialId === null ? null : $this->tokens->find($context->credentialId);
        if ($grant === null || $grant->isExpired() || $grant->userId !== $context->actorId
            || $context->credentialRevision === null || ! hash_equals($grant->credentialRevision(), $context->credentialRevision)
            || $grant->capabilities === null || ! hash_equals($grant->capabilities->grantId, $context->grantId)
            || ! $grant->capabilities->permits($action)
            || array_diff($action->scopes, $grant->scopes) !== []) {
            throw new AuthorizationException('Action requires a current, explicitly approved grant.');
        }

        $actor = User::find($context->actorId);
        $site = Site::get($context->site);
        if ($actor === null || $actor->get('status') === 'inactive' || $site === null || ! $actor->can('view', $site)) {
            throw new AuthorizationException('Actor or site is unavailable.');
        }
        foreach ($action->scopes as $scope) {
            if (! isset($grant->capabilities->scopes[$scope])
                || $grant->capabilities->scopes[$scope] !== $this->registry->fingerprint($scope)
                || ! $this->scopes->allowsValue($actor, $scope)) {
                throw new AuthorizationException('Actor no longer holds the required permissions.');
            }
        }
    }
}
