<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Actions;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\ObserverRegistry;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Actions\Transport\ContextFactory;
use Cboxdk\StatamicMcp\Actions\Transport\RegisteredReadTool;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Support\McpContext;
use Cboxdk\StatamicMcp\Testing\FakeLifecycleObserver;
use Cboxdk\StatamicMcp\Testing\InteractsWithMcp;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class RegisteredReadTransportTest extends TestCase
{
    use InteractsWithMcp;

    private string $secret;

    private string $entryId;

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), ReadTransportProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.mcp.catalog.searchable' => false]);
        $actor = User::make()->email('transport@example.test')->makeSuper();
        $actor->save();
        $snapshot = app(CapabilityRegistry::class)->forScopes(['fixture:read']);
        $this->secret = app(TokenService::class)->createToken($actor->id(), 'Read fixture', ['fixture:read'], capabilities: $snapshot)['token'];
        Collection::make('pages')->save();
        $entry = Entry::make()->collection('pages')->slug('draft')->published(false)->data(['title' => 'Private draft']);
        $entry->save();
        $this->entryId = $entry->id();
    }

    private function definition(): ActionDefinition
    {
        return app(CapabilityRegistry::class)->get('fixture/transport/read');
    }

    private function rpc(string $method, array $params = []): TestResponse
    {
        $headers = ['Authorization' => 'Bearer ' . $this->secret, 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if (isset($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }

        return $this->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => [...$params, '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => []]]], $headers);
    }

    public function test_http_catalog_and_call_use_the_registered_schema_native_actor_and_executor_events(): void
    {
        $name = $this->definition()->toolName();
        $catalog = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($catalog)->firstWhere('name', $name);
        $this->assertNotNull($tool);
        $this->assertSame($this->definition()->inputSchema, $tool['inputSchema']['properties']['input']);
        $this->assertTrue($tool['annotations']['readOnlyHint']);
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => ['input' => ['id' => $this->entryId]]])->assertOk();
        $this->assertSame('Private draft', $result->json('result.structuredContent.title'));
        $events = app(FakeLifecycleObserver::class)->events;
        $executionNames = array_values(array_map(fn ($event) => $event->eventName(), array_filter($events,
            fn ($event) => str_starts_with($event->eventName(), 'execution.'))));
        $this->assertSame(['execution.started', 'execution.completed'], $executionNames);
    }

    public function test_legacy_wildcard_tokens_do_not_discover_or_call_registered_actions(): void
    {
        $actor = User::make()->email('legacy@example.test')->makeSuper();
        $actor->save();
        $this->secret = app(TokenService::class)->createToken($actor->id(), 'Legacy', ['*'])['token'];
        $name = $this->definition()->toolName();
        $catalog = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $this->assertNotContains($name, array_column($catalog, 'name'));
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => ['input' => ['id' => $this->entryId]]]);
        $this->assertNotNull($result->json('error'));
        $this->assertStringNotContainsString('Private draft', $result->getContent());
    }

    public function test_actor_spoofing_bad_sites_and_bad_schema_never_reach_the_handler(): void
    {
        ReadTransportAction::$calls = 0;
        foreach ([
            ['input' => ['id' => $this->entryId], 'actor' => 'someone-else'],
            ['input' => ['id' => $this->entryId], 'site' => 'unknown'],
            ['input' => ['id' => 1]],
            ['input' => ['id' => $this->entryId, 'private' => true]],
        ] as $arguments) {
            $result = $this->rpc('tools/call', ['name' => $this->definition()->toolName(), 'arguments' => $arguments])->assertOk();
            $this->assertTrue($result->json('result.isError'));
            $this->assertStringNotContainsString('Private draft', $result->getContent());
        }
        $this->assertSame(0, ReadTransportAction::$calls);
    }

    public function test_search_and_execute_tools_use_the_same_registered_action_boundary(): void
    {
        config(['statamic.mcp.catalog.searchable' => true]);
        $name = $this->definition()->toolName();
        $catalog = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $this->assertNotContains($name, array_column($catalog, 'name'));
        $found = $this->rpc('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'fixture draft title']])->assertOk();
        $this->assertStringContainsString($name, $found->getContent());
        $ran = $this->rpc('tools/call', ['name' => 'execute_tools', 'arguments' => ['calls' => [[
            'name' => $name, 'arguments' => ['input' => ['id' => $this->entryId]],
        ]]]])->assertOk();
        $this->assertStringContainsString('Private draft', $ran->streamedContent());
        $this->assertCount(1, app(FakeLifecycleObserver::class)->named('execution.completed'));
    }

    public function test_a_non_super_user_can_discover_the_action_for_a_non_default_permitted_site(): void
    {
        config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
        Site::setSites([
            'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
            'danish' => ['name' => 'Danish', 'locale' => 'da_DK', 'url' => '/da/'],
        ]);
        Collection::find('pages')->sites(['default', 'danish'])->save();
        $entry = Entry::make()->collection('pages')->locale('danish')->slug('danish-draft')->published(false)->data(['title' => 'Danish draft']);
        $entry->save();
        Role::make()->handle('danish-reader')->permissions(['view pages entries', 'access danish site'])->save();
        $actor = User::make()->email('danish@example.test')->assignRole('danish-reader');
        $actor->save();
        $snapshot = app(CapabilityRegistry::class)->forScopes(['fixture:read']);
        $this->secret = app(TokenService::class)->createToken($actor->id(), 'Danish only', ['fixture:read'], capabilities: $snapshot)['token'];
        $name = $this->definition()->toolName();
        $catalog = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $this->assertContains($name, array_column($catalog, 'name'));
        $this->rpc('tools/call', ['name' => $name, 'arguments' => ['input' => ['id' => $entry->id()], 'site' => 'danish']])
            ->assertOk()->assertJsonPath('result.structuredContent.title', 'Danish draft');
        $this->rpc('tools/call', ['name' => $name, 'arguments' => ['input' => ['id' => $this->entryId], 'site' => 'default']])
            ->assertOk()->assertJsonPath('result.isError', true);
        Role::find('danish-reader')->permissions([])->save();
        $catalog = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $this->assertNotContains($name, array_column($catalog, 'name'));
    }

    public function test_stdio_requires_explicit_current_consent_and_restores_anonymous_identity(): void
    {
        config(['statamic.mcp.stdio.token' => $this->secret]);
        request()->attributes->remove(McpContext::WEB_ATTRIBUTE);
        $tool = new RegisteredReadTool($this->definition());
        $this->assertTrue($tool->shouldRegister());
        $context = app(ContextFactory::class)->create();
        $this->assertSame('stdio', $context->transport);
        $this->mcpTool($tool, ['input' => ['id' => $this->entryId]])
            ->assertOk()->assertHasNoErrors()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('title', 'Private draft'));
        $this->assertNull(User::current());
        app(TokenService::class)->revokePlainTextToken($this->secret);
        $this->assertFalse($tool->shouldRegister());
    }
}

class ReadTransportProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(ScopeRegistry::class)->register('fixture/transport', 'fixture:read', 'Read fixture draft', ['view pages entries']);
        $this->app->make(CapabilityRegistry::class)->register(new ActionDefinition('fixture/transport', 'read', 1,
            'Read the fixture draft title.', ActionEffect::Read, ['fixture:read'],
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id'], 'additionalProperties' => false],
            ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title'], 'additionalProperties' => false],
            ReadTransportAction::class, ReadTransportAction::class));
        $this->app->singleton(FakeLifecycleObserver::class);
        $this->app->make(ObserverRegistry::class)->register(FakeLifecycleObserver::class);
    }
}

class ReadTransportAction implements ActionHandler, ActionPolicy
{
    public static int $calls = 0;

    public function execute(array $input, ExecutionContext $context): array
    {
        if (User::current()?->id() !== $context->actorId) {
            throw new \LogicException('Incorrect native actor');
        }
        self::$calls++;

        return ['title' => Entry::find($input['id'])->get('title')];
    }

    public function authorize(array $input, ExecutionContext $context): void
    {
        $actor = User::find($context->actorId);
        $entry = Entry::find($input['id']);
        if ($actor === null || $entry === null || $entry->locale() !== $context->site || ! $actor->can('view', $entry)) {
            throw new AuthorizationException;
        }
    }

    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return $result;
    }
}
