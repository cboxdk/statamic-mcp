<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\User;

/**
 * The web endpoint driven the way a real MCP client drives it.
 *
 * Every other test in this suite reaches the tools in-process, which skips the
 * whole HTTP layer: CORS, transport checks, bearer auth, throttling, the
 * permission gate, and — since laravel/mcp 1.0 — the header validation that
 * rejects a request whose headers disagree with its body. That layer had no
 * coverage at all, so a protocol change could break every client while the
 * suite stayed green.
 */
class WebEndpointProtocolTest extends TestCase
{
    private const PROTOCOL_VERSION = '2026-07-28';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::make()->email('protocol@example.com')->makeSuper();
        $user->save();

        $created = app(TokenService::class)->createToken(
            (string) $user->id(),
            'protocol-test',
            [TokenScope::FullAccess],
        );

        $this->token = $created['token'];
    }

    public function test_discover_handshake_succeeds_and_reports_the_server(): void
    {
        $response = $this->rpc('server/discover');

        $response->assertOk();

        $result = $response->json('result');

        $this->assertSame([self::PROTOCOL_VERSION], $result['supportedVersions']);
        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertStringContainsString('Statamic', $result['instructions']);
    }

    public function test_catalog_lists_the_core_tools_and_the_search_pair(): void
    {
        $names = $this->toolNames();

        $this->assertContains('statamic-entries', $names);
        $this->assertContains('statamic-blueprints', $names);
        $this->assertContains('statamic-system-discover', $names);
        $this->assertContains('search_tools', $names);
        $this->assertContains('execute_tools', $names);
    }

    public function test_searchable_tools_stay_out_of_the_catalog(): void
    {
        $names = $this->toolNames();

        foreach (['statamic-assets', 'statamic-users', 'statamic-system', 'statamic-terms'] as $searchable) {
            $this->assertNotContains($searchable, $names, "[{$searchable}] should be reachable only through search_tools.");
        }
    }

    public function test_the_full_catalog_can_be_restored_by_config(): void
    {
        config()->set('statamic.mcp.catalog.searchable', false);

        $names = $this->toolNames();

        $this->assertContains('statamic-assets', $names);
        $this->assertContains('statamic-users', $names);
        $this->assertNotContains('search_tools', $names);

        // The instructions must not keep promising a search_tools route that
        // no longer exists — an agent told to use it would simply fail.
        $instructions = $this->rpc('server/discover')->json('result.instructions');

        $this->assertStringNotContainsString('search_tools', $instructions);
    }

    public function test_a_searchable_tool_is_findable_and_runnable(): void
    {
        $found = $this->rpc('tools/call', [
            'name' => 'search_tools',
            'arguments' => ['query' => 'asset container'],
        ]);

        $found->assertOk();
        $this->assertStringContainsString('statamic-assets', $this->textContent($found));

        $ran = $this->rpc('tools/call', [
            'name' => 'execute_tools',
            'arguments' => [
                'calls' => [[
                    'name' => 'statamic-assets',
                    'arguments' => ['action' => 'list', 'resource_type' => 'container'],
                ]],
            ],
        ]);

        $ran->assertOk();

        // execute_tools yields progress notifications before its result, so the
        // endpoint answers as an event stream rather than a single JSON body.
        $result = $this->lastResult($ran);

        $this->assertNotNull($result, 'execute_tools returned no result frame.');
        $this->assertFalse($result['isError'] ?? false);
    }

    public function test_the_catalog_carries_a_cache_hint_and_tool_calls_do_not(): void
    {
        $catalog = $this->rpc('tools/list');

        $this->assertSame(300_000, $catalog->json('result.ttlMs'));
        $this->assertSame('private', $catalog->json('result.cacheScope'));

        // A tool call reads live content, so it must never be reusable. The
        // protocol forbids it outright; this asserts we did not hint otherwise.
        $call = $this->rpc('tools/call', [
            'name' => 'statamic-system-discover',
            'arguments' => ['intent' => 'manage entries'],
        ]);

        $this->assertNull($call->json('result.ttlMs'));
    }

    public function test_a_blueprint_read_carries_its_own_shorter_hint(): void
    {
        $response = $this->rpc('resources/read', ['uri' => 'statamic://blueprints']);

        $response->assertOk();
        $this->assertSame(60_000, $response->json('result.ttlMs'));
        $this->assertSame('private', $response->json('result.cacheScope'));
    }

    public function test_a_mismatched_method_header_is_rejected(): void
    {
        $response = $this->postJson('/mcp/statamic', $this->body('tools/list'), [
            'Authorization' => 'Bearer ' . $this->token,
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => 'prompts/list',
        ]);

        $response->assertStatus(400);
        $this->assertSame(-32020, $response->json('error.code'));
    }

    public function test_a_missing_method_header_is_rejected(): void
    {
        $response = $this->postJson('/mcp/statamic', $this->body('tools/list'), [
            'Authorization' => 'Bearer ' . $this->token,
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ]);

        $response->assertStatus(400);
        $this->assertSame(-32020, $response->json('error.code'));
    }

    public function test_a_mismatched_name_header_is_rejected(): void
    {
        $response = $this->postJson('/mcp/statamic', $this->body('tools/call', [
            'name' => 'statamic-entries',
            'arguments' => ['action' => 'list', 'collection' => 'pages'],
        ]), [
            'Authorization' => 'Bearer ' . $this->token,
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'statamic-users',
        ]);

        $response->assertStatus(400);
        $this->assertSame(-32020, $response->json('error.code'));
    }

    public function test_a_legacy_initialize_client_still_connects(): void
    {
        $response = $this->postJson('/mcp/statamic', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0'],
            ],
        ], ['Authorization' => 'Bearer ' . $this->token]);

        $response->assertOk();
        $this->assertSame('2025-06-18', $response->json('result.protocolVersion'));
    }

    public function test_the_endpoint_still_refuses_an_unauthenticated_call(): void
    {
        $response = $this->postJson('/mcp/statamic', $this->body('tools/list'), [
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => 'tools/list',
        ]);

        $response->assertStatus(401);
    }

    /**
     * The result payload of the last JSON-RPC frame in an SSE response.
     *
     * @return array<string, mixed>|null
     */
    private function lastResult(TestResponse $response): ?array
    {
        $body = $response->streamedContent();
        $result = null;

        foreach (explode("\n", $body) as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $frame = json_decode(trim(substr($line, 5)), true);

            if (is_array($frame) && isset($frame['result']) && is_array($frame['result'])) {
                $result = $frame['result'];
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function toolNames(): array
    {
        $tools = $this->rpc('tools/list')->json('result.tools');

        return array_column($tools, 'name');
    }

    private function textContent(TestResponse $response): string
    {
        return implode('', array_column($response->json('result.content') ?? [], 'text'));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $method, array $params = []): TestResponse
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => $method,
        ];

        // tools/call, prompts/get and resources/read must also name their
        // target in a header, so a proxy can route without parsing the body.
        $name = match ($method) {
            'tools/call', 'prompts/get' => $params['name'] ?? null,
            'resources/read' => $params['uri'] ?? null,
            default => null,
        };

        if (is_string($name)) {
            $headers['Mcp-Name'] = $name;
        }

        return $this->postJson('/mcp/statamic', $this->body($method, $params), $headers);
    }

    /**
     * A 2026-07-28 request body: the protocol version and client capabilities
     * travel in _meta, which is what replaced the initialize exchange.
     *
     * @param  array<string, mixed>  $params
     *
     * @return array<string, mixed>
     */
    private function body(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => [
                ...$params,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ];
    }
}
