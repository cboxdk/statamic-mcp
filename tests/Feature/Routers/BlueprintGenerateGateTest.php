<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Auth\ConfirmationActionGate;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;

/**
 * `generate` writes a blueprint to disk exactly as `create` does, but was in
 * neither write-action list, so it was gated as a read: a token holding only
 * blueprints:read could create blueprints, and a site with
 * resources.write => [] could not stop it (#56).
 *
 * That is also why a Git-managed site could not simply enable the tool with an
 * empty write allowlist to get blueprint reads back — see #54.
 */
class BlueprintGenerateGateTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function generate(string $handle): array
    {
        return (new BlueprintsRouter)->execute([
            'action' => 'generate',
            'namespace' => 'collections',
            'handle' => $handle,
            'fields' => [['handle' => 'title', 'type' => 'text']],
        ]);
    }

    public function test_generate_is_refused_when_the_write_allowlist_excludes_it(): void
    {
        config(['statamic.mcp.tools.blueprints.resources.write' => []]);

        $handle = 'refused_' . bin2hex(random_bytes(4));

        $result = $this->generate($handle);

        $this->assertFalse($result['success'] ?? true, 'generate must be refused by a write-mode resource policy.');

        // Pin the reason too: a refusal for some unrelated reason would
        // otherwise satisfy this test while the gate stayed open.
        $this->assertStringContainsString(
            'resource policy',
            implode(' ', $result['errors'] ?? []),
            'It must be the resource policy that refuses, not something incidental.'
        );
        $this->assertNull(
            collect(Blueprint::in('collections')->all())->firstWhere('handle', $handle),
            'No blueprint may reach disk when the policy refuses the write.'
        );
    }

    public function test_generate_requires_the_write_scope(): void
    {
        // The scope gate and the resource-policy gate read separate lists, so a
        // fix applied to only one would leave the hole half open. This covers
        // the scope side; the test above covers the policy side.
        $scope = (new class extends BlueprintsRouter
        {
            public function scopeFor(string $action): ?TokenScope
            {
                return $this->getRequiredTokenScope($action);
            }
        })->scopeFor('generate');

        $this->assertSame(TokenScope::BlueprintsWrite, $scope, 'A blueprints:read token must not be able to generate a blueprint.');
    }

    public function test_generate_is_covered_by_the_confirmation_gate(): void
    {
        $this->assertTrue(
            ConfirmationActionGate::gates('blueprints', 'generate'),
            'generate writes a blueprint to disk, so it belongs behind the same confirmation gate as create.'
        );
    }
}
