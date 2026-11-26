<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Actions\LifecycleObservers;
use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\ServiceProvider;
use Cboxdk\StatamicMcp\Testing\FakeLifecycleObserver;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Psr\Log\NullLogger;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\Role;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Statamic;

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

    public function test_rejected_http_requests_emit_only_safe_transport_metadata(): void
    {
        $observer = new FakeLifecycleObserver;
        $this->app->instance(LifecycleObservers::class,
            new LifecycleObservers(new NullLogger, [$observer]));
        $trace = '00-12345678901234567890123456789012-1234567890123456-01';
        foreach ([$trace, 'Secret launch plan'] as $header) {
            $this->withHeaders(['Authorization' => 'Bearer do-not-leak', 'traceparent' => $header,
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION, 'Mcp-Method' => 'server/discover'])
                ->postJson('/mcp/statamic', $this->body('server/discover'))->assertUnauthorized();
        }
        $this->assertCount(2, $observer->events);
        $first = $observer->events[0]->metadata();
        $this->assertSame('request.rejected', $first['event']);
        $this->assertSame('UNAUTHENTICATED', $first['error_code']);
        $this->assertSame($trace, $first['traceparent']);
        $this->assertNull($observer->events[1]->metadata()['traceparent']);
        $this->assertStringNotContainsString('do-not-leak', json_encode($first));
    }

    public function test_execution_bytes_measure_the_protocol_envelope_without_legacy_errors(): void
    {
        Collection::make('event_pages')->save();
        $observer = new FakeLifecycleObserver;
        $this->app->instance(LifecycleObservers::class,
            new LifecycleObservers(new NullLogger, [$observer]));
        $response = $this->rpc('tools/call', ['name' => 'statamic-entries-write', 'arguments' => [
            'action' => 'update', 'collection' => 'event_pages', 'id' => 'missing', 'data' => ['title' => 'No record'],
        ]])->assertOk();
        $envelope = $response->json('result.structuredContent');
        $this->assertFalse($envelope['success']);
        $this->assertArrayNotHasKey('errors', $envelope);
        $this->assertSame(strlen(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            $observer->events[1]->metadata()['response_bytes']);
    }

    public function test_scope_challenges_cover_whole_batches_prompts_and_resources_before_dispatch(): void
    {
        config(['statamic.mcp.catalog.searchable' => true, 'statamic.mcp.web.allowed_origins' => ['https://client.test']]);
        $collection = Collection::make('challenge_pages')->title('Unchanged');
        $collection->save();
        $user = User::make()->email('challenge@example.test')->makeSuper();
        $user->save();
        $this->token = app(TokenService::class)->createToken((string) $user->id(), 'challenge', ['structures:admin', 'structures:read'])['token'];
        $batch = ['name' => 'execute_tools', 'arguments' => ['calls' => [
            ['name' => 'statamic-structures-admin', 'arguments' => ['action' => 'update', 'resource_type' => 'collection', 'handle' => 'challenge_pages', 'data' => ['title' => 'Changed']]],
            ['name' => 'statamic-structures-read', 'arguments' => ['action' => 'get', 'resource_type' => 'collection', 'handle' => 'challenge_pages', 'include_tree' => true]],
        ]]];
        foreach (['application/json', 'text/plain'] as $contentType) {
            $this->call('POST', '/mcp/statamic', [], [], [], [
                'CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'HTTP_ORIGIN' => 'https://client.test',
                'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION, 'HTTP_MCP_METHOD' => 'tools/call', 'HTTP_MCP_NAME' => 'execute_tools',
            ], json_encode($this->body('tools/call', $batch), JSON_THROW_ON_ERROR))
                ->assertForbidden()->assertJsonPath('scope', 'structures:admin structures:read entries:read')
                ->assertHeader('Access-Control-Expose-Headers', 'WWW-Authenticate');
            $this->assertSame('Unchanged', Collection::find('challenge_pages')->title());
        }
        $this->rpc('prompts/get', ['name' => 'translate_entry', 'arguments' => ['id' => 'private-entry']])
            ->assertForbidden()->assertJsonPath('scope', 'blueprints:read entries:read');
        $this->rpc('prompts/get', ['name' => 'model_content', 'arguments' => []])
            ->assertForbidden()->assertJsonPath('scope', 'blueprints:read');
        $this->rpc('resources/read', ['uri' => 'statamic://blueprints'])
            ->assertForbidden()->assertJsonPath('scope', 'blueprints:read');
    }

    public function test_unauthenticated_scope_challenges_do_not_reveal_entry_state(): void
    {
        Collection::make('private_pages')->save();
        Entry::make()->id('private-published')->collection('private_pages')->slug('page')->published(true)->save();
        $challenges = [];
        foreach (['private-published', 'missing'] as $id) {
            foreach ([true, false] as $published) {
                $response = $this->postJson('/mcp/statamic', $this->body('tools/call', [
                    'name' => 'statamic-entries-write', 'arguments' => ['action' => 'update', 'collection' => 'private_pages', 'id' => $id, 'data' => ['published' => $published]],
                ]), ['MCP-Protocol-Version' => self::PROTOCOL_VERSION, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'statamic-entries-write'])->assertUnauthorized();
                $challenges[] = $response->headers->get('WWW-Authenticate');
            }
        }
        $this->assertCount(1, array_unique($challenges));
        $this->assertStringContainsString('scope="entries:write entries:publish"', $challenges[0]);
    }

    public function test_workflow_prompts_are_registered_and_only_name_real_tools(): void
    {
        Collection::make('prompt_pages')->save();
        Blueprint::make('page')->setNamespace('collections.prompt_pages')->setContents(['fields' => [
            ['handle' => 'sections', 'field' => ['type' => 'replicator']],
        ]])->save();
        Entry::make()->id('prompt-entry')->collection('prompt_pages')->slug('page')->data(['sections' => []])->save();
        $names = ['write_entry', 'translate_entry', 'edit_page_builder', 'model_content'];
        $this->assertEqualsCanonicalizing($names, array_column($this->rpc('prompts/list')->assertOk()->json('result.prompts'), 'name'));
        $tools = $this->toolNames();
        foreach ($names as $name) {
            $arguments = ['collection' => 'prompt_pages', 'blueprint' => 'page', 'task' => 'Improve copy'];
            if ($name !== 'model_content') {
                $arguments['id'] = 'prompt-entry';
            }
            if ($name === 'translate_entry') {
                $arguments['target_site'] = 'danish';
            }
            if ($name === 'edit_page_builder') {
                $arguments['field'] = 'sections';
            }
            $response = $this->rpc('prompts/get', ['name' => $name, 'arguments' => $arguments])->assertOk();
            $text = $response->json('result.messages.0.content.text');
            $this->assertIsString($text, $response->getContent());
            $this->assertStringContainsString('_format_spec', $text);
            preg_match_all('/statamic-[a-z-]+/', $text, $matches);
            foreach (array_unique($matches[0]) as $tool) {
                $this->assertContains($tool, $tools);
            }
        }
    }

    public function test_prompt_context_and_completions_cannot_bypass_scopes_or_resource_policy(): void
    {
        foreach (['prompt_public', 'prompt_private'] as $handle) {
            Collection::make($handle)->save();
            Blueprint::make('page')->setNamespace('collections.' . $handle)->setContents(['fields' => [
                ['handle' => 'body', 'field' => ['type' => 'markdown']],
            ]])->save();
        }
        $complete = fn (string $argument, array $context = []): TestResponse => $this->rpc('completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'write_entry'],
            'argument' => ['name' => $argument, 'value' => $argument === 'collection' ? 'prompt_' : 'pa'],
            'context' => ['arguments' => $context],
        ]);
        $this->assertArrayHasKey('completions', $this->rpc('server/discover')->assertOk()->json('result.capabilities'));
        config(['statamic.mcp.tools.structures.resources.read' => ['prompt_public']]);
        $complete('collection')->assertOk()->assertJsonPath('result.completion.values', ['prompt_public']);
        $complete('blueprint', ['collection' => 'prompt_public'])->assertOk()->assertJsonPath('result.completion.values', ['page']);

        $args = ['collection' => 'prompt_public', 'blueprint' => 'page', 'task' => 'Write a draft'];
        $this->rpc('prompts/get', ['name' => 'write_entry', 'arguments' => $args])->assertOk()->assertJsonStructure(['result' => ['messages']]);
        config(['statamic.mcp.tools.blueprints.resources.read' => []]);
        $complete('blueprint', ['collection' => 'prompt_public'])->assertOk()->assertJsonPath('result.completion.values', []);
        $this->rpc('prompts/get', ['name' => 'write_entry', 'arguments' => $args])->assertStatus(400)->assertJsonStructure(['error'])->assertDontSee('_format_spec');
        config(['statamic.mcp.tools.blueprints.resources.read' => ['*']]);
        $user = User::make()->email('prompt-limited@example.com')->makeSuper();
        $user->save();
        $this->token = app(TokenService::class)->createToken((string) $user->id(), 'entries-only', [TokenScope::EntriesRead])['token'];
        $complete('blueprint', ['collection' => 'prompt_public'])->assertOk()->assertJsonPath('result.completion.values', []);
        $this->rpc('prompts/get', ['name' => 'write_entry', 'arguments' => $args])->assertForbidden()->assertJsonPath('scope', 'blueprints:read')->assertDontSee('_format_spec');
    }

    public function test_framework_versions_are_opt_in_and_only_reported_by_server_discover(): void
    {
        foreach ([false, true] as $expose) {
            config(['statamic.mcp.security.expose_versions' => $expose]);
            $meta = $this->rpc('server/discover')->assertOk()->json('result._meta');
            if ($expose) {
                $this->assertSame([
                    'statamic' => Statamic::version(),
                    'laravel' => app()->version(),
                    'php' => PHP_VERSION,
                ], $meta['statamic-mcp/versions']);
            } else {
                $this->assertArrayNotHasKey('statamic-mcp/versions', $meta);
            }

            foreach (['info', 'unknown'] as $action) {
                $content = $this->rpc('tools/call', [
                    'name' => 'statamic-system-read', 'arguments' => ['action' => $action],
                ])->assertOk()->json('result.structuredContent');
                foreach (['statamic_version', 'laravel_version', 'php_version'] as $key) {
                    $this->assertArrayNotHasKey($key, $content['meta']);
                    $this->assertArrayNotHasKey($key, $content['data']['system_info'] ?? []);
                }
            }
        }
    }

    public function test_get_projection_and_blueprint_result_hint_are_available_over_http(): void
    {
        Blueprint::make('projection')->setNamespace('globals')->setContents([
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ])->save();
        $tools = collect($this->rpc('tools/list')->assertOk()->json('result.tools'))->keyBy('name');
        $this->assertSame(100000, $tools['statamic-blueprints-read']['_meta']['anthropic/maxResultSizeChars']);
        $this->assertSame('string', $tools['statamic-blueprints-read']['inputSchema']['properties']['fields']['items']['type']);
        $this->assertSame('object', $tools['statamic-blueprints-admin']['inputSchema']['properties']['fields']['items']['type']);

        $call = ['name' => 'statamic-blueprints-read', 'arguments' => [
            'action' => 'get', 'namespace' => 'globals', 'handle' => 'projection', 'fields' => ['blueprint.handle'],
        ]];
        $this->rpc('tools/call', $call)->assertOk()
            ->assertJsonPath('result.structuredContent.success', true)
            ->assertJsonPath('result.structuredContent.data', [
                'projection' => true, 'fields' => [['path' => 'blueprint.handle', 'value' => 'projection']],
            ]);
        config(['statamic.mcp.tools.blueprints.resources.read' => []]);
        $this->rpc('tools/call', $call)->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
    }

    public function test_cursor_pages_are_ordered_query_bound_and_authorized_on_every_request(): void
    {
        Collection::make('cursor_posts')->save();
        Collection::make('other_posts')->save();
        foreach (['zebra', 'ant', 'mouse'] as $id) {
            Entry::make()->id($id)->collection('cursor_posts')->slug($id)->published(true)->data(['title' => $id])->save();
        }
        $arguments = ['action' => 'list', 'collection' => 'cursor_posts', 'limit' => 2];
        $call = fn (array $args): TestResponse => $this->rpc('tools/call', ['name' => 'statamic-entries-read', 'arguments' => $args]);
        $first = $call($arguments)->assertOk()->assertJsonPath('result.structuredContent.success', true);
        $this->assertSame(['ant', 'mouse'], array_column($first->json('result.structuredContent.data.items'), 'id'));
        $cursor = $first->json('result.structuredContent.data.next_cursor');
        $this->assertIsString($cursor);
        $last = $call([...$arguments, 'cursor' => $cursor])->assertOk()
            ->assertJsonPath('result.structuredContent.data.total', 3)
            ->assertJsonPath('result.structuredContent.data.next_cursor', null);
        $this->assertSame(['zebra'], array_column($last->json('result.structuredContent.data.items'), 'id'));

        foreach ([
            [...$arguments, 'cursor' => 'not-a-cursor'],
            [...$arguments, 'cursor' => str_repeat('x', 5000)],
            [...$arguments, 'cursor' => $cursor, 'collection' => 'other_posts'],
            [...$arguments, 'cursor' => $cursor, 'filters' => ['slug' => 'ant']],
        ] as $invalid) {
            $call($invalid)->assertOk()->assertJsonPath('result.isError', true)
                ->assertJsonPath('result.structuredContent.error.code', 'VALIDATION')
                ->assertJsonPath('result.structuredContent.error.field', 'cursor');
        }

        config(['statamic.mcp.tools.entries.resources.read' => ['other_posts']]);
        $call([...$arguments, 'cursor' => $cursor])->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
    }

    public function test_not_found_suggestions_only_include_readable_handles(): void
    {
        foreach (['posts', 'posters', 'post', 'news'] as $handle) {
            Collection::make($handle)->save();
        }
        $call = ['name' => 'statamic-entries-read', 'arguments' => ['action' => 'list', 'collection' => 'postz']];
        $this->rpc('tools/call', $call)->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.structuredContent.error.code', 'NOT_FOUND')
            ->assertJsonPath('result.structuredContent.error.field', 'collection')
            ->assertJsonPath('result.structuredContent.error.valid_values', ['post', 'posts']);

        config(['statamic.mcp.tools.entries.resources.read' => ['postz', 'posts']]);
        $this->rpc('tools/call', $call)->assertOk()
            ->assertJsonPath('result.structuredContent.error.valid_values', ['posts']);

        config(['statamic.mcp.tools.entries.resources.read' => null]);
        Role::make()->handle('typo-reader')->permissions([
            'access cp', 'view postz entries', 'view posts entries',
        ])->save();
        $reader = User::make()->email('typo-reader@example.test')->assignRole('typo-reader');
        $reader->save();
        $this->token = app(TokenService::class)->createToken((string) $reader->id(), 'reader', ['entries:read'])['token'];
        $this->rpc('tools/call', $call)->assertOk()
            ->assertJsonPath('result.structuredContent.error.valid_values', ['posts']);

        $writer = User::make()->email('typo-writer@example.test')->makeSuper();
        $writer->save();
        $this->token = app(TokenService::class)->createToken((string) $writer->id(), 'writer', ['entries:write'])['token'];
        $this->rpc('tools/call', ['name' => 'statamic-entries-write', 'arguments' => [
            'action' => 'create', 'collection' => 'postz', 'data' => ['title' => 'Never saved'],
        ]])->assertOk()->assertJsonPath('result.structuredContent.error.code', 'NOT_FOUND')
            ->assertJsonMissingPath('result.structuredContent.error.valid_values')
            ->assertJsonMissingPath('result.structuredContent.error.hint');
    }

    public function test_forbidden_and_missing_scope_are_distinct_tool_errors(): void
    {
        config(['statamic.mcp.tools.entries.resources.read' => []]);
        $call = ['name' => 'statamic-entries-read', 'arguments' => ['action' => 'list', 'collection' => 'pages']];
        $this->rpc('tools/call', $call)->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');

        $user = User::make()->email('wrong-scope@example.test')->makeSuper();
        $user->save();
        $this->token = app(TokenService::class)->createToken((string) $user->id(), 'wrong-scope', ['terms:read'])['token'];
        $this->rpc('tools/call', $call)->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope')
            ->assertJsonPath('scope', 'entries:read');
    }

    public function test_removed_actions_are_not_advertised_or_executable(): void
    {
        $tools = collect($this->rpc('tools/list')->json('result.tools'))->keyBy('name');
        $this->assertSame(['create', 'update', 'move', 'delete'], $tools['statamic-structures-admin']['inputSchema']['properties']['action']['enum']);
        $this->assertSame(['cache_clear', 'cache_warm', 'invalidate_urls', 'reindex'], $tools['statamic-system-admin']['inputSchema']['properties']['action']['enum']);
        $this->assertArrayNotHasKey('config_value', $tools['statamic-system-admin']['inputSchema']['properties']);

        foreach ([
            ['statamic-system-admin', ['action' => 'config_set', 'config_key' => 'statamic.mcp.tools.entries.enabled', 'config_value' => 'false']],
            ['statamic-structures-admin', ['action' => 'configure', 'resource_type' => 'collection', 'handle' => 'pages', 'data' => ['title' => 'Changed']]],
            ['statamic-structures-admin', ['action' => 'move', 'resource_type' => 'site', 'handle' => 'default', 'data' => ['entry' => 'id', 'parent' => null]]],
        ] as [$tool, $arguments]) {
            $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments])
                ->assertOk()->assertJsonPath('result.isError', true);
        }
        $this->assertTrue(config('statamic.mcp.tools.entries.enabled'));
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
        $this->assertContains('statamic-discover', $names);
        $this->assertNotContains('statamic-entries-write', $names);
        $this->assertNotContains('statamic-terms-read', $names);

        // A call to a tool left out is refused with the scope it needs, not
        // \"tool not found\", so the client can ask for more access.
        $refused = $this->rpc('tools/call', [
            'name' => 'statamic-entries-write',
            'arguments' => ['action' => 'create', 'collection' => 'pages', 'data' => ['title' => 'x']],
        ]);
        $refused->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
        $this->assertStringContainsString('entries:write', $refused->headers->get('WWW-Authenticate'));
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

    public function test_discovery_filters_tools_and_actions_and_bounds_instructions(): void
    {
        $user = User::make()->email('discovery-reader@example.com')->makeSuper();
        $user->save();
        foreach ([['entries:read'], ['entries:read', 'entries:write']] as $scopes) {
            $this->token = app(TokenService::class)->createToken($user->id(), 'discovery', $scopes)['token'];
            $call = fn (array $arguments) => $this->rpc('tools/call', ['name' => 'statamic-discover', 'arguments' => $arguments]);
            $catalog = $call(['intent' => 'publish entry'])->assertOk()->json('result.structuredContent.data.discovery');
            $this->assertArrayNotHasKey('system_state', $catalog);
            $this->assertArrayNotHasKey('statamic-assets-read', $catalog['available_tools']);
            $this->assertArrayHasKey('statamic-entries-read', $catalog['available_tools']);
            if (count($scopes) === 1) {
                $this->assertArrayNotHasKey('statamic-entries-write', $catalog['available_tools']);
                $this->assertArrayNotHasKey('write_tool', $catalog['recommended_tools'][0]);
            } else {
                $schema = $call(['tool_name' => 'statamic-entries-write'])->assertOk()->json('result.structuredContent.data.tool');
                $this->assertContains('update', $schema['actions']);
                $this->assertNotContains('delete', $schema['actions']);
                $this->assertNotContains('publish', $schema['actions']);
                $this->assertSame($schema['actions'], $schema['inputSchema']['properties']['action']['enum']);
            }
            foreach ([false, true] as $searchable) {
                config(['statamic.mcp.catalog.searchable' => $searchable]);
                $instructions = $this->rpc('server/discover')->assertOk()->json('result.instructions');
                $this->assertLessThan(4096, strlen($instructions));
                $this->assertStringNotContainsString('statamic-assets', $instructions);
            }
        }
        config(['statamic.mcp.tools.entries.enabled' => false]);
        $catalog = $call([])->assertOk()->json('result.structuredContent.data.discovery.available_tools');
        $this->assertArrayNotHasKey('statamic-entries-read', $catalog);
        $call(['tool_name' => 'statamic-entries-write'])->assertOk()->assertJsonPath('result.structuredContent.error.code', 'NOT_FOUND');
    }

    public function test_discovery_requires_both_scopes_for_tree_moves(): void
    {
        $user = User::make()->email('tree-discovery@example.com')->makeSuper();
        $user->save();
        foreach ([['structures:admin'], ['structures:admin', 'entries:write']] as $scopes) {
            $this->token = app(TokenService::class)->createToken($user->id(), 'tree-discovery', $scopes)['token'];
            $schema = $this->rpc('tools/call', ['name' => 'statamic-discover', 'arguments' => ['tool_name' => 'statamic-structures-admin']])
                ->assertOk()->json('result.structuredContent.data.tool');
            $this->assertSame(count($scopes) === 2, in_array('move', $schema['actions'], true));
            $this->assertSame($schema['actions'], $schema['inputSchema']['properties']['action']['enum']);
        }
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
        $this->assertContains('statamic-discover', $names);
        $this->assertContains('search_tools', $names);
        $this->assertContains('execute_tools', $names);

        foreach (['statamic-assets-read', 'statamic-users-read', 'statamic-system-read', 'statamic-terms-write'] as $searchable) {
            $this->assertNotContains($searchable, $names, "[{$searchable}] should be reachable only through search_tools.");
        }

        $recommended = $this->rpc('tools/call', [
            'name' => 'statamic-discover',
            'arguments' => ['intent' => 'upload image'],
        ])->json('result.structuredContent.data.discovery.recommended_tools');
        $this->assertSame('via_execute_tools', collect($recommended)->firstWhere('tool', 'statamic-assets-read')['access']);
        $this->assertStringContainsString('search_tools', $this->rpc('server/discover')->json('result.instructions'));
    }

    public function test_the_full_catalog_is_the_default_and_discovery_follows_it(): void
    {
        $recommended = $this->rpc('tools/call', [
            'name' => 'statamic-discover',
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
            'name' => 'statamic-discover',
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

    public function test_written_entries_link_to_authorized_resource_reads(): void
    {
        Collection::make('resource_pages')->save();
        Blueprint::make('page')->setNamespace('collections.resource_pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'private_note', 'field' => ['type' => 'text']],
        ]])->save();
        $write = $this->rpc('tools/call', ['name' => 'statamic-entries-write', 'arguments' => [
            'action' => 'create', 'collection' => 'resource_pages', 'data' => ['title' => 'Linked entry', 'private_note' => 'Hidden'],
        ]])->assertOk()->assertJsonPath('result.structuredContent.success', true);
        $link = $write->json('result.content.1');
        $this->assertSame('resource_link', $link['type']);
        $this->assertSame('application/json', $link['mimeType']);
        config(['statamic.mcp.tools.entries.denied_fields' => ['private_note']]);
        $read = $this->rpc('resources/read', ['uri' => $link['uri']])->assertOk();
        $data = json_decode($read->json('result.contents.0.text'), true);
        $this->assertTrue($data['success']);
        $this->assertSame('Linked entry', $data['data']['entry']['data']['title']);
        $this->assertArrayNotHasKey('private_note', $data['data']['entry']['data']);

        config(['statamic.mcp.tools.entries.resources.read' => []]);
        $denied = $this->rpc('resources/read', ['uri' => $link['uri']])->assertOk();
        $this->assertFalse(json_decode($denied->json('result.contents.0.text'), true)['success']);
        $denied->assertDontSee('Linked entry');
        $user = User::make()->email('resource-write-only@example.test')->makeSuper();
        $user->save();
        $this->token = app(TokenService::class)->createToken((string) $user->id(), 'write-only', ['entries:write'])['token'];
        $this->rpc('resources/read', ['uri' => $link['uri']])->assertForbidden()->assertJsonPath('scope', 'entries:read');
    }

    public function test_resource_catalogs_and_handle_completions_use_the_read_gates(): void
    {
        Collection::make('resource_public')->save();
        Collection::make('resource_private')->save();
        Taxonomy::make('resource_topics')->save();
        Form::make('resource_contact')->title('Contact')->save();
        Blueprint::make('page')->setNamespace('collections.resource_public')->setContents(['fields' => []])->save();
        $uris = array_column($this->rpc('resources/list')->assertOk()->json('result.resources'), 'uri');
        foreach (['collections', 'sites', 'fieldtypes'] as $catalog) {
            $this->assertContains('statamic://' . $catalog, $uris);
            $response = $this->rpc('resources/read', ['uri' => 'statamic://' . $catalog])->assertOk();
            $this->assertTrue(json_decode($response->json('result.contents.0.text'), true)['success'], $response->getContent());
        }
        config(['statamic.mcp.tools.structures.resources.read' => ['resource_public', 'resource_topics']]);
        foreach ([
            ['statamic://entries/{collection}/{site}/{id}', 'collection', 'resource_', [], ['resource_public']],
            ['statamic://taxonomies/{taxonomy}', 'taxonomy', 'resource_', [], ['resource_topics']],
            ['statamic://forms/{form}', 'form', 'resource_', [], ['resource_contact']],
            ['statamic://blueprints/{namespace}/{handle}', 'handle', 'pa', ['namespace' => 'collections.resource_public'], ['page']],
        ] as [$uri, $argument, $value, $context, $expected]) {
            $this->rpc('completion/complete', [
                'ref' => ['type' => 'ref/resource', 'uri' => $uri],
                'argument' => ['name' => $argument, 'value' => $value],
                'context' => ['arguments' => $context],
            ])->assertOk()->assertJsonPath('result.completion.values', $expected);
        }
        foreach (['collections/resource_public', 'taxonomies/resource_topics', 'forms/resource_contact'] as $path) {
            $response = $this->rpc('resources/read', ['uri' => 'statamic://' . $path])->assertOk();
            $this->assertTrue(json_decode($response->json('result.contents.0.text'), true)['success'], $response->getContent());
        }
        config(['statamic.mcp.resources.enabled' => false]);
        $this->rpc('resources/read', ['uri' => 'statamic://collections'])->assertOk()->assertDontSee('resource_public');
        $this->rpc('completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'statamic://forms/{form}'],
            'argument' => ['name' => 'form', 'value' => 'resource_'],
        ])->assertOk()->assertJsonPath('result.completion.values', []);
    }

    public function test_resource_links_decode_handles_once_before_authorization_and_lookup(): void
    {
        $write = $this->rpc('tools/call', ['name' => 'statamic-structures-admin', 'arguments' => [
            'action' => 'create', 'resource_type' => 'collection', 'handle' => 'news+events',
            'data' => ['title' => 'News and events'],
        ]])->assertOk()->assertJsonPath('result.structuredContent.success', true);
        $uri = $write->json('result.content.1.uri');
        $this->assertSame('statamic://collections/news%2Bevents', $uri);
        config(['statamic.mcp.tools.structures.resources.read' => ['news+events']]);
        $read = $this->rpc('resources/read', ['uri' => $uri])->assertOk();
        $this->assertTrue(json_decode($read->json('result.contents.0.text'), true)['success']);
        $this->rpc('resources/read', ['uri' => 'statamic://collections/news%252Bevents'])
            ->assertOk()->assertDontSee('News and events');
        config(['statamic.mcp.tools.structures.resources.read' => ['other']]);
        $this->rpc('resources/read', ['uri' => $uri])->assertOk()->assertDontSee('News and events');
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
