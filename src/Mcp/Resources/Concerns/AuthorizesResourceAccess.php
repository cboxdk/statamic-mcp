<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Resources\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;
use Statamic\Contracts\Auth\User;

/**
 * Authorization for MCP resources.
 *
 * Resources are a second read surface alongside tools, and the route middleware
 * only authenticates — RequireMcpPermission defers scope checks to "the tool
 * level". Without this trait a resource would hand out data that the equivalent
 * tool call would refuse, so every resource read runs the same four gates a
 * router read does: tool enablement, token scope, resource policy, and the
 * underlying Statamic permission.
 */
trait AuthorizesResourceAccess
{
    /**
     * The config/scope domain this resource reads from (e.g. 'blueprints').
     */
    abstract protected function domain(): string;

    /**
     * Determine whether the current caller may read this resource.
     *
     * @param  string|null  $handle  The specific resource being read, when known,
     *                               so the resource policy's allowlist applies.
     *
     * @return string|null Denial reason, or null when access is allowed
     */
    protected function denyReason(?string $handle = null): ?string
    {
        // CLI runs as the site operator; the resource policy still applies below.
        $isCli = app()->runningInConsole() && ! config('statamic.mcp.security.force_web_mode', false);

        if (! $isCli) {
            $domain = $this->domain();

            // Deliberately NOT gated on tools.{domain}.enabled. That switch turns
            // off a tool that can create, update and delete; sites keeping their
            // content model in Git switch it off for exactly that reason, and
            // doing so also removed the only read-only way to learn a
            // blueprint's fields — while the server's own instructions tell
            // agents to read the blueprint before every write (#54). Reads have
            // their own switch.
            if (! config('statamic.mcp.resources.enabled', true)) {
                return 'MCP resources are disabled';
            }

            /** @var User|null $user */
            $user = auth()->user();

            if (! $user) {
                return 'Authentication required';
            }

            /** @var McpTokenData|null $token */
            $token = request()->attributes->get('mcp_token');

            if ($token !== null) {
                $scope = TokenScope::tryFrom("{$domain}:read");

                /** @var TokenService $tokenService */
                $tokenService = app(TokenService::class);

                if ($scope !== null && ! $tokenService->hasScope($token, $scope)) {
                    Log::warning('MCP resource denied: token missing scope', [
                        'domain' => $domain,
                        'required_scope' => $scope->value,
                        'token_id' => $token->id,
                        'ip' => request()->ip(),
                    ]);

                    return "Token missing required scope: {$scope->value}";
                }
            }

            if (
                config('statamic.mcp.resources.require_statamic_permission', true)
                && ! $user->isSuper()
                && ! $this->hasStatamicPermission($user)
            ) {
                return 'Insufficient Statamic permissions';
            }
        }

        // The resource policy is a site-wide admin policy and applies in every
        // context, CLI included.
        if ($handle !== null) {
            /** @var ResourcePolicy $policy */
            $policy = app(ResourcePolicy::class);

            if (! $policy->canAccess($this->domain(), $handle, 'read')) {
                return "Read access to '{$handle}' is not permitted by resource policy";
            }
        }

        return null;
    }

    /**
     * The Statamic permission backing this resource's domain.
     *
     * The same three BlueprintsRouter accepts on its read actions: the two
     * surfaces serve the same data, so they must not disagree about who may
     * see it. Change one, change the other.
     */
    protected function hasStatamicPermission(User $user): bool
    {
        return $user->hasPermission('configure fields')
            || $user->hasPermission('configure collections')
            || $user->hasPermission('configure taxonomies');
    }

    /**
     * An expected refusal, shaped as a normal result rather than an error.
     *
     * laravel/mcp's ReadResource is not Errable, so anything returned through
     * Response::error() becomes JSON-RPC -32603 and HTTP 500 — which a hosted
     * client behind a proxy shows as a bare 502 with the message gone, making
     * "you lack permission" indistinguishable from an outage (#55). Tools avoid
     * this by carrying their errors inside a normal result; resources now do
     * the same, so the reason survives the round trip.
     */
    protected function refusal(string $message, string $code): Response
    {
        return Response::json([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ]);
    }
}
