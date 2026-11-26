<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Authorization;

use Cboxdk\StatamicMcp\Mcp\Resources\Concerns\AuthorizesResourceAccess;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The V08 coverage inventory: every action every built-in tool offers, and
 * what a caller must hold to run it over HTTP.
 *
 * The read actions are listed here by hand, not derived from the routers, so
 * a new action that writes but is not classified as a write fails this test
 * instead of running on a :read scope.
 */
class ActionCoverageInventoryTest extends TestCase
{
    private const READS = [
        'assets' => ['list', 'get'],
        'blueprints' => ['list', 'get', 'scan', 'types', 'validate'],
        'content-facade' => ['content_audit', 'content_validate', 'cross_reference'],
        'entries' => ['list', 'get', 'list_revisions', 'get_revision'],
        'forms' => ['list', 'get', 'list_submissions', 'get_submission', 'export', 'summary'],
        'globals' => ['list', 'get'],
        'structures' => ['list', 'get'],
        'system' => ['info', 'health', 'cache_status', 'config_get', 'search'],
        'terms' => ['list', 'get'],
        'users' => ['list', 'get', 'search'],
    ];

    /** Actions that need a scope on top of the domain's write scope. */
    private const EXTRA_SCOPES = [
        'entries' => ['delete' => 'entries:delete', 'publish' => 'entries:publish', 'unpublish' => 'entries:publish', 'publish_working_copy' => 'entries:publish'],
        'terms' => ['delete' => 'terms:delete'],
        'assets' => ['delete' => 'assets:delete'],
    ];

    /** Domains whose writes are administration, so they need {domain}:admin. */
    private const ADMIN_DOMAINS = ['blueprints', 'structures', 'users', 'system'];

    /**
     * Unscoped listings with no handle to check: they filter per item instead
     * (see the listing handlers), so they carry no permission of their own.
     */
    private const FILTERED_PER_ITEM = ['assets list container', 'structures list collection', 'forms list'];

    public function test_every_built_in_action_is_classified_scoped_and_permissioned(): void
    {
        config(['statamic.mcp.security.force_web_mode' => true]);
        $seen = [];

        foreach ($this->routers() as $router) {
            $domain = $this->invoke($router, 'getDomain');
            $this->assertArrayHasKey($domain, self::READS, "No inventory row for the '{$domain}' tool.");
            $types = array_keys($this->invoke($router, 'getTypes')) ?: [null];

            foreach (array_keys($this->invoke($router, 'getActions')) as $action) {
                $seen[$domain][] = $action;
                $write = ! in_array($action, self::READS[$domain], true);

                foreach ($types as $type) {
                    $label = trim("{$domain} {$action} {$type}");
                    $call = $this->invoke($router, 'describeCall', $action, [
                        'action' => $action, 'resource_type' => $type, 'type' => $type, 'handle' => 'h',
                        'collection' => 'c', 'taxonomy' => 't', 'container' => 'k', 'namespace' => 'collections', 'form' => 'f',
                    ]);
                    $scopes = array_map(fn ($scope) => $scope->value, $call->scopes);

                    $this->assertSame($write ? 'write' : 'read', $call->mode, "{$label}: resource policy mode");
                    $expected = match (true) {
                        $domain === 'forms' && $action === 'delete_submission' => ['submissions:delete'],
                        $domain === 'forms' && $action === 'mark_spam' => ['forms:write', 'submissions:read'],
                        $domain === 'forms' && in_array($action, ['list_submissions', 'get_submission', 'export', 'summary'], true) => ['submissions:read'],
                        ! $write => ["{$domain}:read"],
                        $domain === 'structures' && $action === 'move' => ['structures:admin', 'entries:write'],
                        in_array($domain, self::ADMIN_DOMAINS, true) => ["{$domain}:admin"],
                        default => array_values(array_filter(["{$domain}:write", self::EXTRA_SCOPES[$domain][$action] ?? null])),
                    };
                    $this->assertSame($expected, $scopes, "{$label}: token scopes");

                    if (! in_array($label, self::FILTERED_PER_ITEM, true)) {
                        $this->assertNotSame([], $call->permissions, "{$label}: no Statamic permission is checked");
                    }
                }
            }
        }

        // Every inventory row names a real action, so the list cannot rot.
        foreach (self::READS as $domain => $reads) {
            $this->assertSame([], array_diff($reads, $seen[$domain] ?? []), "{$domain}: inventory names actions the tool does not have");
        }
    }

    /**
     * A declared action is a promise: the schema offers it. Every declared
     * action must reach a real handler for every resource_type it applies
     * to, never a "not supported" or "unknown action" answer.
     */
    public function test_every_declared_action_reaches_a_handler(): void
    {
        foreach ($this->routers() as $router) {
            $types = array_keys($this->invoke($router, 'getTypes')) ?: [null];

            foreach ($router->declaredActions() as $declared) {
                foreach ($types as $type) {
                    if (! $declared->appliesTo($type)) {
                        continue;
                    }
                    $label = trim(get_class($router) . " {$declared->name} {$type}");
                    try {
                        $result = $this->invoke($router, 'executeAction', array_filter([
                            'action' => $declared->name, 'resource_type' => $type, 'handle' => 'mcp_probe_missing',
                        ], fn ($value) => $value !== null));
                    } catch (\Throwable) {
                        continue; // Reached a handler that refused the probe's arguments.
                    }
                    $error = implode(' ', array_map('strval', (array) ($result['errors'] ?? [])));
                    $this->assertDoesNotMatchRegularExpression('/^Unknown [a-z ]*(action|type)\b|not supported via MCP/i', $error, $label);
                }
            }
        }
    }

    public function test_an_undeclared_action_or_type_is_refused_before_anything_runs(): void
    {
        $router = new UsersRouter;

        $unknown = $this->invoke($router, 'executeInternal', ['action' => 'impersonate', 'resource_type' => 'user']);
        $this->assertFalse($unknown['success']);
        $this->assertStringContainsString("Unknown action 'impersonate'. Use one of: list, get", implode(' ', $unknown['errors']));

        $wrongType = $this->invoke($router, 'executeInternal', ['action' => 'activate', 'resource_type' => 'role', 'handle' => 'editor']);
        $this->assertFalse($wrongType['success']);
        $this->assertStringContainsString("does not apply to resource_type 'role'. It applies to: user", implode(' ', $wrongType['errors']));
    }

    public function test_every_tool_outside_the_routers_is_read_only(): void
    {
        $others = array_values(array_filter(
            $this->serverList('tools'),
            fn (string $tool): bool => ! is_subclass_of($tool, BaseRouter::class),
        ));

        $this->assertSame([DiscoveryTool::class], $others);
        foreach ($others as $tool) {
            $this->assertNotSame([], (new \ReflectionClass($tool))->getAttributes(IsReadOnly::class), "{$tool} must be read-only");
        }
    }

    public function test_every_resource_runs_the_tool_gates(): void
    {
        $resources = $this->serverList('resources');
        $this->assertNotSame([], $resources);
        foreach ($resources as $resource) {
            $this->assertContains(AuthorizesResourceAccess::class, class_uses_recursive($resource), "{$resource} hands out data without the tool gates");
        }
    }

    /** @return list<BaseRouter> */
    private function routers(): array
    {
        $routers = array_values(array_filter($this->serverList('tools'), fn (string $tool): bool => is_subclass_of($tool, BaseRouter::class)));
        $this->assertCount(10, $routers, 'A built-in router was added or removed: give it an inventory row.');

        return array_map(fn (string $class): BaseRouter => new $class, $routers);
    }

    /** @return list<class-string> */
    private function serverList(string $property): array
    {
        $defaults = (new \ReflectionClass(StatamicMcpServer::class))->getDefaultProperties();

        return array_values(array_filter($defaults[$property], 'is_string'));
    }

    private function invoke(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }
}
