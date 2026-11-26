<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Resources\Concerns;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Authorization\McpAuthorizer;
use Cboxdk\StatamicMcp\Authorization\McpCall;
use Cboxdk\StatamicMcp\Support\McpContext;
use Laravel\Mcp\Response;

/**
 * Authorization for MCP resources.
 *
 * Resources are a second read surface alongside tools, and the route middleware
 * only authenticates — RequireMcpPermission defers scope checks to "the tool
 * level". Without this trait a resource would hand out data that the equivalent
 * tool call would refuse, so every resource read asks McpAuthorizer, the same
 * decision a router read asks: token scope, Statamic permission and resource
 * policy, after the installation profile and the resources switch.
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
        $denial = app(InstallationProfile::class)->denial($this->domain(), 'get', writes: false);
        if ($denial !== null) {
            return $denial;
        }
        // Deliberately NOT gated on tools.{domain}.enabled. That switch turns
        // off a tool that can create, update and delete; sites keeping their
        // content model in Git switch it off for exactly that reason, and
        // doing so also removed the only read-only way to learn a blueprint's
        // fields — while the server's own instructions tell agents to read the
        // blueprint before every write (#54). Reads have their own switch.
        if (! McpContext::isCli() && ! config('statamic.mcp.resources.enabled', true)) {
            return 'MCP resources are disabled';
        }

        $scope = TokenScope::tryFrom("{$this->domain()}:read");

        return app(McpAuthorizer::class)->denial(new McpCall(
            domain: $this->domain(),
            action: 'get',
            scopes: $scope === null ? [] : [$scope],
            permissions: config('statamic.mcp.resources.require_statamic_permission', true) ? [$this->requiredPermissions()] : [],
            handle: $handle,
            mode: 'read',
        ))['message'] ?? null;
    }

    /**
     * The Statamic permissions backing this resource's domain: any one of them.
     *
     * The same three BlueprintsRouter accepts on its read actions: the two
     * surfaces serve the same data, so they must not disagree about who may
     * see it. Change one, change the other.
     *
     * @return list<string>
     */
    protected function requiredPermissions(): array
    {
        return ['configure fields', 'configure collections', 'configure taxonomies'];
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
