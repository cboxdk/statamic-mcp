<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\ServiceProvider;
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
            app(ScopeAvailability::class)->expandFullAccess($user, [TokenScope::FullAccess->value]),
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

    public function test_the_catalog_lists_each_area_as_a_read_and_a_write_tool(): void
    {
        $tools = collect($this->rpc('tools/list')->json('result.tools'))->keyBy('name');

        foreach (['entries', 'terms', 'globals', 'assets'] as $area) {
            $this->assertTrue($tools->has("statamic-{$area}-read"), "statamic-{$area}-read");
            $this->assertTrue($tools->has("statamic-{$area}-write"), "statamic-{$area}-write");
        }
        foreach (['blueprints', 'structures', 'users', 'system'] as $area) {
            $this->assertTrue($tools->has("statamic-{$area}-read"), "statamic-{$area}-read");
            $this->assertTrue($tools->has("statamic-{$area}-admin"), "statamic-{$area}-admin");
        }
        $this->assertTrue($tools->has('statamic-content-facade-read'));
        $this->assertFalse($tools->has('statamic-content-facade-write'), 'A tool with no write action gets no write tool.');
        $this->assertFalse($tools->has('search_tools'));
        $this->assertFalse($tools->has('statamic-entries'), 'The mixed v3 tool is not offered.');

        // Every tool carries all four hints explicitly, read tools as reads.
        foreach ($tools as $name => $tool) {
            foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint) {
                $this->assertIsBool($tool['annotations'][$hint] ?? null, "{$name}: {$hint}");
            }
            $this->assertNotSame('', $tool['title'] ?? '', "{$name}: title");
        }
        $this->assertTrue($tools['statamic-entries-read']['annotations']['readOnlyHint']);
        $this->assertFalse($tools['statamic-entries-read']['annotations']['destructiveHint']);
        $this->assertFalse($tools['statamic-entries-write']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['statamic-entries-write']['annotations']['destructiveHint']);
        // An upload fetches the URL a client names.
        $this->assertTrue($tools['statamic-assets-write']['annotations']['openWorldHint']);
        $this->assertFalse($tools['statamic-terms-write']['annotations']['openWorldHint']);

        // A read tool offers only reads and none of the write arguments.
        $read = $tools['statamic-entries-read']['inputSchema']['properties'];
        $this->assertSame(['list', 'get', 'list_revisions', 'get_revision'], $read['action']['enum']);
        $this->assertArrayNotHasKey('data', $read);
        $this->assertArrayNotHasKey('idempotency_key', $read);
        $this->assertArrayNotHasKey('approval_id', $read);
        $this->assertNotContains('list', $tools['statamic-entries-write']['inputSchema']['properties']['action']['enum']);
    }

    public function test_the_catalog_shows_only_the_tools_the_token_can_use(): void
    {
        $user = User::make()->email('reader@example.com')->makeSuper();
        $user->save();
        $this->token = app(TokenService::class)->createToken((string) $user->id(), 'reader', ['entries:read'])['token'];

        $names = $this->toolNames();

        $this->assertContains('statamic-entries-read', $names);
        $this->assertContains('statamic-system-discover', $names);
        $this->assertNotContains('statamic-entries-write', $names);
        $this->assertNotContains('statamic-terms-read', $names);

        // A call to a tool left out is refused with the scope it needs, not
        // \"tool not found\", so the client can ask for more access.
        $refused = $this->rpc('tools/call', [
            'name' => 'statamic-entries-write',
            'arguments' => ['action' => 'create', 'collection' => 'pages', 'data' => ['title' => 'x']],
        ]);
        $refused->assertOk();
        $this->assertTrue($refused->json('result.isError'));
        $this->assertStringContainsString('entries:write', $this->textContent($refused));
    }

    public function test_a_read_only_installation_lists_no_write_tool_and_runs_every_read(): void
    {
        config()->set('statamic.mcp.profiles.http', 'read_only');

        $names = $this->toolNames();

        $this->assertContains('statamic-blueprints-read', $names);
        $this->assertSame([], array_values(array_filter($names, fn (string $name): bool => str_ends_with($name, '-write') || str_ends_with($name, '-admin'))));

        // Every declared read runs under read_only; the profile once kept its
        // own list and refused blueprint scan, types and validate.
        $scan = $this->rpc('tools/call', ['name' => 'statamic-blueprints-read', 'arguments' => ['action' => 'scan']]);
        $scan->assertOk();
        $this->assertFalse($scan->json('result.isError'), $this->textContent($scan));
    }

    public function test_a_read_tool_refuses_a_write_action_and_names_the_write_tool(): void
    {
        $response = $this->rpc('tools/call', [
            'name' => 'statamic-entries-read',
            'arguments' => ['action' => 'delete', 'collection' => 'pages', 'id' => 'x'],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('The delete action is on the statamic-entries-write tool.', $this->textContent($response));
    }

    public function test_the_split_catalog_lists_the_core_tools_and_the_search_pair(): void
    {
        config()->set('statamic.mcp.catalog.searchable', true);

        $names = $this->toolNames();

        $this->assertContains('statamic-entries-read', $names);
        $this->assertContains('statamic-entries-write', $names);
        $this->assertContains('statamic-blueprints-read', $names);
        $this->assertContains('statamic-system-discover', $names);
        $this->assertContains('search_tools', $names);
        $this->assertContains('execute_tools', $names);

        foreach (['statamic-assets-read', 'statamic-users-read', 'statamic-system-read', 'statamic-terms-write'] as $searchable) {
            $this->assertNotContains($searchable, $names, "[{$searchable}] should be reachable only through search_tools.");
        }

        $recommended = $this->rpc('tools/call', [
            'name' => 'statamic-system-discover',
            'arguments' => ['intent' => 'upload image'],
        ])->json('result.structuredContent.data.discovery.recommended_tools');
        $this->assertSame('via_execute_tools', collect($recommended)->firstWhere('tool', 'statamic-assets-read')['access']);
        $this->assertStringContainsString('search_tools', $this->rpc('server/discover')->json('result.instructions'));
    }

    public function test_the_full_catalog_is_the_default_and_discovery_follows_it(): void
    {
        $recommended = $this->rpc('tools/call', [
            'name' => 'statamic-system-discover',
            'arguments' => ['intent' => 'upload image'],
        ])->json('result.structuredContent.data.discovery.recommended_tools');

        $assets = collect($recommended)->firstWhere('tool', 'statamic-assets-read');

        $this->assertSame('statamic-assets-write', $assets['write_tool']);
        $this->assertSame('listed_in_catalog', $assets['access']);

        // The instructions must not promise a search_tools route that does
        // not exist; an agent told to use it would simply fail.
        $instructions = $this->rpc('server/discover')->json('result.instructions');

        $this->assertStringNotContainsString('search_tools', $instructions);
        $this->assertStringContainsString('-read tool', $instructions);
    }

    public function test_a_searchable_tool_is_findable_and_runnable(): void
    {
        config()->set('statamic.mcp.catalog.searchable', true);

        $found = $this->rpc('tools/call', [
            'name' => 'search_tools',
            'arguments' => ['query' => 'asset container'],
        ]);

        $found->assertOk();
        $this->assertStringContainsString('statamic-assets-read', $this->textContent($found));

        $ran = $this->rpc('tools/call', [
            'name' => 'execute_tools',
            'arguments' => [
                'calls' => [[
                    'name' => 'statamic-assets-read',
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

    public function test_execute_tools_gets_the_same_response_budget_as_a_direct_call(): void
    {
        // ToolSearch caps a batch at its own limit and counts our envelope
        // twice (content plus structuredContent), so without alignment a
        // response the addon returns happily on a direct call comes back as
        // OutputLimitExceeded once the tool sits behind the catalog.
        $ceiling = (int) config('statamic.mcp.security.max_response_size');

        $this->assertSame($ceiling * 3 + 4096, (int) config('mcp.tool_search.max_output_bytes'));
    }

    public function test_an_operator_set_output_budget_is_left_alone(): void
    {
        // Only the library's untouched default is replaced; a value the
        // operator chose stays theirs.
        $this->assertNull(ServiceProvider::toolSearchOutputBudget(1234, 100000));
        $this->assertSame(304_096, ServiceProvider::toolSearchOutputBudget(65_536, 100000));
        $this->assertSame(304_096, ServiceProvider::toolSearchOutputBudget(null, 100000));

        // A disabled ceiling must not reintroduce one through the back door.
        $this->assertSame(PHP_INT_MAX, ServiceProvider::toolSearchOutputBudget(null, 0));
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

    public function test_a_refused_resource_read_is_not_a_500(): void
    {
        // laravel/mcp's ReadResource is not Errable, so anything returned via
        // Response::error() becomes -32603 and HTTP 500 — which a hosted client
        // behind a proxy shows as a bare 502 with the message gone, making a
        // permission refusal look identical to an outage (#55).
        $response = $this->rpc('resources/read', ['uri' => 'statamic://blueprints/collections/nope']);

        $response->assertOk();
        $this->assertStringContainsString('NOT_FOUND', (string) $response->getContent());
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
            'name' => 'statamic-entries-read',
            'arguments' => ['action' => 'list', 'collection' => 'pages'],
        ]), [
            'Authorization' => 'Bearer ' . $this->token,
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'statamic-users-read',
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

    public function test_a_header_rejection_still_carries_cors_headers(): void
    {
        config()->set('statamic.mcp.web.allowed_origins', ['https://client.example']);

        // ValidateMcpHeaders is attached inside Mcp::web(), so CORS has to wrap
        // it. Otherwise a browser gets the -32020 with no
        // Access-Control-Allow-Origin and shows a generic network error
        // instead of the reason its request was refused.
        $response = $this->postJson('/mcp/statamic', $this->body('tools/list'), [
            'Authorization' => 'Bearer ' . $this->token,
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => 'prompts/list',
            'Origin' => 'https://client.example',
        ]);

        $response->assertStatus(400);
        $this->assertSame(-32020, $response->json('error.code'));
        $this->assertSame('https://client.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_the_origin_check_admits_configured_origins_and_still_refuses_others(): void
    {
        config()->set('statamic.mcp.web.allowed_origins', ['https://client.example']);

        $headers = fn (string $origin): array => [
            'Authorization' => 'Bearer ' . $this->token,
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => 'tools/list',
            'Origin' => $origin,
        ];

        // laravel/mcp's ValidateOrigin knows only mcp.allowed_origins; the
        // addon's own allowlist must reach it, on POST and on the GET that
        // clients probe for a stream (405 there, not 403).
        $this->postJson('/mcp/statamic', $this->body('tools/list'), $headers('https://client.example'))->assertOk();
        $this->getJson('/mcp/statamic', $headers('https://client.example'))->assertStatus(405);

        $this->postJson('/mcp/statamic', $this->body('tools/list'), $headers('https://evil.example'))->assertStatus(403);
        $this->getJson('/mcp/statamic', $headers('https://evil.example'))->assertStatus(403);
    }

    public function test_a_preflight_allows_the_headers_the_protocol_requires(): void
    {
        config()->set('statamic.mcp.web.allowed_origins', ['https://client.example']);

        $response = $this->call('OPTIONS', '/mcp/statamic', [], [], [], [
            'HTTP_ORIGIN' => 'https://client.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertStatus(204);

        $allowed = (string) $response->headers->get('Access-Control-Allow-Headers');

        // Without these a browser client cannot connect at all: it is blocked
        // if it sends them, and answered -32020 if it does not.
        foreach (['MCP-Protocol-Version', 'Mcp-Method', 'Mcp-Name', 'Authorization'] as $header) {
            $this->assertStringContainsString($header, $allowed);
        }
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
