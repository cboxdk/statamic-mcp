<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Mcp\ExtraTools;
use Cboxdk\StatamicMcp\Mcp\Prompts\ContentWorkflowPrompt;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Illuminate\Http\Request;

/** Transport challenges describe the whole request before any handler writes. */
final class ScopeChallenge
{
    /** @return list<string> */
    public static function initialScopes(): array
    {
        return array_values(array_filter(['blueprints:read', 'entries:read'],
            fn (string $scope): bool => (bool) config('statamic.mcp.tools.' . explode(':', $scope)[0] . '.enabled', true)));
    }

    /** @return list<string> */
    public function forRequest(Request $request): array
    {
        // Match HttpTransport's raw JSON parsing, including clients that send
        // JSON with a non-JSON Content-Type. Query parameters are not MCP calls.
        $envelope = json_decode($request->getContent(), true);
        if (! is_array($envelope) || ! is_array($envelope['params'] ?? null)) {
            return [];
        }
        $params = $envelope['params'];
        if (($envelope['method'] ?? '') === 'resources/read') {
            $uri = $params['uri'] ?? null;

            return is_string($uri) && ($uri === 'statamic://blueprints' || str_starts_with($uri, 'statamic://blueprints/')) ? ['blueprints:read'] : [];
        }
        if (($envelope['method'] ?? '') === 'prompts/get' && in_array($params['name'] ?? null, ContentWorkflowPrompt::NAMES, true)) {
            return (is_array($params['arguments'] ?? null) && isset($params['arguments']['id'])) || in_array($params['name'], ['translate_entry', 'edit_page_builder'], true)
                ? ['blueprints:read', 'entries:read'] : ['blueprints:read'];
        }
        if (($envelope['method'] ?? '') !== 'tools/call') {
            return [];
        }
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (! is_string($name) || ! is_array($arguments)) {
            return [];
        }
        $calls = [['name' => $name, 'arguments' => $arguments]];
        if ($name === 'execute_tools' && config('statamic.mcp.catalog.searchable', false)) {
            $calls = $arguments['calls'] ?? null;
            if (! is_array($calls) || ! array_is_list($calls) || count($calls) > max(1, config()->integer('mcp.tool_search.max_tool_calls', 25))) {
                return [];
            }
        }
        $required = [];
        foreach ($calls as $call) {
            if (! is_array($call) || ! is_string($call['name'] ?? null) || ! is_array($call['arguments'] ?? [])) {
                return []; // Malformed calls remain the protocol validator's job.
            }
            $args = array_filter($call['arguments'] ?? [], is_string(...), ARRAY_FILTER_USE_KEY);
            $required = [...$required, ...$this->forTool($call['name'], $args)];
        }

        return array_values(array_unique($required));
    }

    /** @param array<string, mixed> $arguments
     * @return list<string>
     */
    private function forTool(string $name, array $arguments): array
    {
        foreach (app(CapabilityRegistry::class)->definitions() as $definition) {
            if ($definition->effect === ActionEffect::Read && $definition->toolName() === $name) {
                return $definition->scopes;
            }
        }
        foreach ([...StatamicMcpServer::CORE_TOOLS, ...StatamicMcpServer::SEARCHABLE_TOOLS, ...array_keys(ExtraTools::all())] as $class) {
            try {
                foreach (StatamicMcpServer::presented([$class]) as $tool) {
                    if (! $tool instanceof BaseRouter || $tool->name() !== $name || ! $tool->eligibleForRegistration()) {
                        continue;
                    }
                    $action = is_string($arguments['action'] ?? null) ? $tool->declaredAction($arguments['action']) : null;
                    $type = is_string($arguments['resource_type'] ?? null) ? $arguments['resource_type'] : null;
                    if ($action === null || $tool->presentedFacet() !== ($action->writes() ? 'write' : 'read')
                        || ($type !== null && ! $action->appliesTo($type))) {
                        return [];
                    }

                    return array_map(fn (TokenScope $scope): string => $scope->value, $tool->describeCall($action->name, $arguments)->scopes);
                }
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return [];
    }

    /** @param list<string> $scopes */
    public function header(Request $request, array $scopes, bool $insufficient = false): string
    {
        $header = 'Bearer realm="Statamic MCP"';
        if (config('statamic.mcp.oauth.enabled', true)) {
            $header = 'Bearer resource_metadata="' . $request->getSchemeAndHttpHost() . '/.well-known/oauth-protected-resource' . config()->string('statamic.mcp.web.path', '/mcp/statamic') . '"';
        }
        if ($insufficient) {
            $header .= ', error="insufficient_scope"';
        }
        if ($scopes !== []) {
            $header .= ', scope="' . implode(' ', $scopes) . '"';
        }

        return $header;
    }
}
