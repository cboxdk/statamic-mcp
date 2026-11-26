<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Actions;

use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleEvent;
use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\OperationIntent;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Integration\Effects;
use Cboxdk\StatamicMcp\Integration\IntegrationRegistry;
use Cboxdk\StatamicMcp\Mcp\Support\FieldtypeExtensions;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Cboxdk\StatamicMcp\StatamicMcp;
use Cboxdk\StatamicMcp\Support\McpContext;
use Cboxdk\StatamicMcp\Testing\FakeLifecycleObserver;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Search;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Fields\Field;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\ApplicationCacher;

class IntegrationRouterTest extends TestCase
{
    private string $secret;

    private string $entryId;

    private string $actorId;

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), IntegrationFixtureProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true, 'statamic.mcp.catalog.searchable' => false]);
        Collection::make('pages')->save();
        $entry = Entry::make()->collection('pages')->slug('draft')->published(false)->data(['title' => 'Before', 'secret' => 'Hidden']);
        $entry->save();
        $this->entryId = $entry->id();
        Role::make()->handle('integration-editor')->permissions([
            'access cp', 'view mcp dashboard', 'create mcp tokens', 'view pages entries', 'edit pages entries',
        ])->save();
        $actor = User::make()->email('integration@example.test')->assignRole('integration-editor');
        $actor->save();
        $this->actorId = $actor->id();
        $this->assertFalse($actor->isSuper());
        $this->issue(['fixture:read', 'fixture:write']);
    }

    private function issue(array $scopes): void
    {
        $this->secret = app(TokenService::class)->createToken($this->actorId, 'Integration', $scopes,
            capabilities: app(CapabilityRegistry::class)->forScopes($scopes))['token'];
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

    private function callAction(string $action, array $extra = []): TestResponse
    {
        return $this->rpc('tools/call', ['name' => 'fixture-notes-' . ($action === 'get' ? 'read' : 'write'),
            'arguments' => ['action' => $action, 'collection' => 'pages', 'id' => $this->entryId, ...$extra]]);
    }

    public function test_non_super_editor_gets_facets_and_writes_once_with_native_actor_and_events(): void
    {
        $catalog = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $read = collect($catalog)->firstWhere('name', 'fixture-notes-read');
        $write = collect($catalog)->firstWhere('name', 'fixture-notes-write');
        $this->assertNotNull($read);
        $this->assertNotNull($write);
        $this->assertTrue($read['annotations']['readOnlyHint']);
        $this->assertSame(['get'], $read['inputSchema']['properties']['action']['enum']);
        $this->assertFalse($write['annotations']['readOnlyHint']);
        $this->assertArrayNotHasKey('data', $read['inputSchema']['properties']);
        $input = ['data' => ['title' => 'After'], 'idempotency_key' => 'integration-write'];
        $this->callAction('update', $input)->assertOk()->assertJsonPath('result.structuredContent.data.title', 'After');
        $this->callAction('update', $input)->assertOk()->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        $this->assertSame('After', Entry::find($this->entryId)->get('title'));
        $events = app(FakeLifecycleObserver::class);
        $this->assertCount(1, $events->named('execution.started'));
        $this->assertCount(1, $events->named('execution.completed'));
        $this->assertCount(1, $events->named('execution.replayed'));
        $this->assertSame('fixture/notes/notes_update', $events->named('execution.completed')[0]->metadata()['action_id']);
        $operation = app(OperationStore::class)->find($events->named('execution.completed')[0]->metadata()['operation_id']);
        $intent = OperationIntent::fromJson(app(Encrypter::class)->decrypt($operation->encryptedIntent, false));
        $this->assertSame('fixture/notes/notes_update', $intent->actionId);
        $this->assertSame(3, $intent->actionVersion);
        $this->callAction('update', ['data' => ['title' => 'Conflict'], 'idempotency_key' => 'integration-write'])
            ->assertJsonPath('result.isError', true);
        $this->assertSame('After', Entry::find($this->entryId)->get('title'));
    }

    public function test_read_scope_cannot_discover_or_execute_writes_and_resource_binding_is_enforced(): void
    {
        $this->issue(['fixture:read']);
        $names = array_column($this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');
        $this->assertContains('fixture-notes-read', $names);
        $this->assertNotContains('fixture-notes-write', $names);
        $this->callAction('update', ['data' => ['title' => 'Forbidden']])->assertForbidden()->assertJsonPath('scope', 'fixture:write');
        Collection::make('private')->save();
        $entry = Entry::make()->collection('private')->slug('hidden')->data(['title' => 'Never expose this']);
        $entry->save();
        $this->callAction('get', ['id' => $entry->id()])->assertJsonPath('result.isError', true);
        $this->assertSame('Before', Entry::find($this->entryId)->get('title'));
    }

    public function test_integration_approval_default_requires_elevation_and_rechecks_permissions_before_replay(): void
    {
        config(['statamic.mcp.confirmation.enabled' => true, 'statamic.users.elevated_sessions_enabled' => true]);
        $input = ['data' => ['title' => 'Approved']];
        $asked = $this->callAction('replace', $input)->assertOk()->assertJsonPath('result.structuredContent.error.code', 'APPROVAL_REQUIRED');
        $approval = $asked->json('result.structuredContent.data.approval_id');
        $operation = app(OperationStore::class)->find($approval);
        $intent = OperationIntent::fromJson(app(Encrypter::class)->decrypt($operation->encryptedIntent, false));
        $this->assertSame('fixture/notes/notes_replace', $intent->actionId);
        $this->assertSame(3, $intent->actionVersion);
        $this->assertSame(app(IntegrationRegistry::class)->binding(IntegrationFixtureRouter::class, 'replace')['fingerprint'], $intent->actionFingerprint);
        $this->assertSame($intent->actionId, app(FakeLifecycleObserver::class)->named('approval.requested')[0]->metadata()['action_id']);
        $this->assertSame('Before', Entry::find($this->entryId)->get('title'));
        $this->actingAs(User::find($this->actorId), 'web');
        $this->postJson(cp_route('statamic-mcp.approvals.approve', ['approval' => $approval]))->assertForbidden();
        $this->withSession(['statamic_elevated_session' => now()->timestamp])
            ->postJson(cp_route('statamic-mcp.approvals.approve', ['approval' => $approval]))->assertOk();
        $this->callAction('replace', $input + ['approval_id' => $approval])->assertJsonPath('result.structuredContent.data.title', 'Approved');
        Role::find('integration-editor')->permissions(['access cp', 'view mcp dashboard', 'view pages entries'])->save();
        $this->callAction('replace', $input + ['approval_id' => $approval])->assertJsonPath('result.isError', true);
        $this->assertCount(1, app(FakeLifecycleObserver::class)->named('execution.completed'));
    }

    public function test_revocation_after_execution_started_prevents_persistence(): void
    {
        $path = Entry::find($this->entryId)->path();
        $before = file_get_contents($path);
        app(IntegrationRevoker::class)->secret = $this->secret;
        $this->callAction('update', ['data' => ['title' => 'Revoked'], 'idempotency_key' => 'revoke-before-save'])
            ->assertJsonPath('result.isError', true);
        $this->assertSame($before, file_get_contents($path));
        $this->assertCount(1, app(FakeLifecycleObserver::class)->named('execution.failed'));
        $this->assertCount(0, app(FakeLifecycleObserver::class)->named('execution.completed'));
    }

    public function test_fields_are_filtered_before_storage_and_before_a_stored_result_is_replayed(): void
    {
        config(['statamic.mcp.tools.notes.denied_fields' => ['secret']]);
        $input = ['data' => ['title' => 'After', 'secret' => 'Replacement'], 'idempotency_key' => 'filtered-fields'];
        $result = $this->callAction('update', $input)->assertJsonPath('result.structuredContent.data.title', 'After');
        $this->assertStringNotContainsString('Hidden', $result->getContent());
        $this->assertStringNotContainsString('Replacement', $result->getContent());
        $this->assertSame('Hidden', Entry::find($this->entryId)->get('secret'));
        $replay = $this->callAction('update', $input)->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        $this->assertStringNotContainsString('Hidden', $replay->getContent());
    }

    public function test_stdio_uses_the_same_grant_and_restores_native_identity(): void
    {
        config(['statamic.mcp.stdio.token' => $this->secret]);
        request()->attributes->remove(McpContext::WEB_ATTRIBUTE);
        $router = new IntegrationFixtureRouter;
        $result = $router->execute(['action' => 'update', 'collection' => 'pages', 'id' => $this->entryId, 'data' => ['title' => 'From stdio']]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('From stdio', Entry::find($this->entryId)->get('title'));
        $this->assertNull(User::current());
        app(TokenService::class)->revokePlainTextToken($this->secret);
        $result = $router->execute(['action' => 'get', 'collection' => 'pages', 'id' => $this->entryId]);
        $this->assertFalse($result['success']);
    }

    public function test_cp_consent_does_not_grant_a_changed_router_contract(): void
    {
        $this->actingAs(User::find($this->actorId), 'web');
        $page = $this->get(cp_route('statamic-mcp.dashboard'), ['X-Inertia' => 'true'])->assertOk();
        $offer = $page->json('props.capabilityOffer');
        $this->assertIsString($offer);
        $this->withCookie(config('session.cookie'), app('session')->getId())->withCredentials();
        $body = ['name' => 'Consented integration', 'scopes' => ['fixture:write'], 'capability_offer' => $offer];
        $created = $this->postJson(cp_route('statamic-mcp.tokens.store'), $body)->assertCreated();
        $this->secret = $created->json('token');
        $this->callAction('update', ['data' => ['title' => 'Consented']])->assertJsonPath('result.structuredContent.data.title', 'Consented');

        // A deployment changes the contract without changing the scope string.
        $this->app->instance(IntegrationRegistry::class, new IntegrationRegistry);
        $this->app->instance(CapabilityRegistry::class, new CapabilityRegistry(app(ScopeRegistry::class)));
        StatamicMcp::integration('fixture/notes')->router(IntegrationFixtureRouter::class, version: 2, approvals: ['replace']);
        app(IntegrationRegistry::class)->freeze();
        app(CapabilityRegistry::class)->freeze();
        $this->callAction('update', ['data' => ['title' => 'Undisplayed']])->assertJsonPath('result.isError', true);
        $this->assertSame('Consented', Entry::find($this->entryId)->get('title'));
        $this->assertNotContains('fixture-notes-write', array_column($this->rpc('tools/list')->json('result.tools'), 'name'));
    }

    public function test_stdio_idempotency_is_bound_to_the_actor_and_survives_credential_rotation(): void
    {
        config(['statamic.mcp.stdio.token' => $this->secret]);
        request()->attributes->remove(McpContext::WEB_ATTRIBUTE);
        $arguments = ['action' => 'update', 'collection' => 'pages', 'id' => $this->entryId,
            'data' => ['title' => 'First'], 'idempotency_key' => 'same-local-key'];
        $router = new IntegrationFixtureRouter;
        $this->assertTrue($router->execute($arguments)['success']);
        $old = app(TokenService::class)->validateToken($this->secret);
        app(TokenService::class)->revokePlainTextToken($this->secret);
        $rotated = app(TokenService::class)->createToken($this->actorId, 'Rotated', $old->scopes, capabilities: $old->capabilities);
        config(['statamic.mcp.stdio.token' => $rotated['token']]);
        $this->assertTrue($router->execute($arguments)['data']['idempotent_replay']);

        $other = User::make()->email('other@example.test')->assignRole('integration-editor');
        $other->save();
        $this->actorId = $other->id();
        $this->issue(['fixture:write']);
        config(['statamic.mcp.stdio.token' => $this->secret]);
        $arguments['data']['title'] = 'Second actor';
        $this->assertTrue($router->execute($arguments)['success']);
        $this->assertSame('Second actor', Entry::find($this->entryId)->get('title'));
        $this->assertCount(2, app(FakeLifecycleObserver::class)->named('execution.completed'));
    }

    public function test_non_default_site_and_resource_policy_apply_to_read_write_and_replay(): void
    {
        Entry::find($this->entryId)->delete();
        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'danish' => ['name' => 'Danish', 'locale' => 'da_DK', 'url' => '/da/'],
        ]);
        Collection::find('pages')->sites(['default', 'danish'])->save();
        $entry = Entry::make()->collection('pages')->locale('danish')->slug('danish')->data(['title' => 'Danish']);
        $entry->save();
        $this->entryId = $entry->id();
        Role::find('integration-editor')->permissions(['view pages entries', 'edit pages entries', 'access danish site'])->save();
        $this->assertContains('fixture-notes-read', array_column($this->rpc('tools/list')->json('result.tools'), 'name'));
        $this->callAction('get', ['site' => 'default'])->assertJsonPath('result.isError', true);
        $this->callAction('get', ['site' => 'danish'])->assertJsonPath('result.structuredContent.data.title', 'Danish');
        $input = ['site' => 'danish', 'data' => ['title' => 'Updated'], 'idempotency_key' => 'site-bound-write'];
        $this->callAction('update', $input)->assertJsonPath('result.structuredContent.data.title', 'Updated');
        config(['statamic.mcp.tools.notes.resources' => ['read' => ['other'], 'write' => ['other']]]);
        $this->callAction('get', ['site' => 'danish'])->assertJsonPath('result.isError', true);
        $this->callAction('update', $input)->assertJsonPath('result.isError', true);
    }

    public static function writeOutcomes(): array
    {
        return ['success' => [false], 'throw after saving' => [true]];
    }

    #[DataProvider('writeOutcomes')]
    public function test_effects_wait_until_the_handler_finishes_even_when_it_throws(bool $throws): void
    {
        $cacher = new ApplicationCacher(Cache::store('array'), []);
        $this->app->instance(Cacher::class, $cacher);
        $target = Request::create('http://localhost/notes-list');
        $other = Request::create('http://localhost/unrelated');
        foreach ([$target, $other] as $request) {
            $cacher->cachePage($request, 'Previously rendered');
            Event::dispatch(new ResponsePrepared($request, new Response('Previously rendered')));
        }
        $directory = storage_path('framework/testing/integration-search-' . bin2hex(random_bytes(8)));
        Entry::find($this->entryId)->published(true)->save();
        config(['statamic.search.indexes.notes' => ['driver' => 'local', 'searchables' => 'collection:pages', 'fields' => ['title'], 'path' => $directory]]);
        $index = Search::index('notes');
        $index->update();
        $this->assertCount(1, $index->lookup('Before'));
        $images = Storage::fake('integration-glide');
        config(['statamic.assets.image_manipulation.cache' => 'integration-glide', 'cache.stores.glide' => ['driver' => 'array']]);
        $images->put('cached/example.jpg', 'Generated image');
        // Simulate an addon write whose native event does not update search.
        Event::forget(EntrySaved::class);
        $this->app->instance(Effects::class, new Effects(static: false, glide: true, staticUrls: ['http://localhost/notes-list'], searchIndexes: ['notes']));
        config(['fixture.after_write' => function () use ($cacher, $target, $index, $images, $throws): void {
            $this->assertTrue($cacher->hasCachedPage($target));
            $this->assertCount(1, $index->lookup('Before'));
            $this->assertTrue($images->exists('cached/example.jpg'));
            if ($throws) {
                throw new \RuntimeException('Failure after native save');
            }
        }]);
        try {
            $response = $this->callAction('update', ['data' => ['title' => 'Reindexed'], 'idempotency_key' => 'deferred-effects']);
            $response->assertJsonPath('result.structuredContent.success', ! $throws);
            $this->assertFalse($cacher->hasCachedPage($target));
            $this->assertTrue($cacher->hasCachedPage($other));
            $this->assertCount(1, $index->lookup('Reindexed'));
            $this->assertCount(0, $index->lookup('Before'));
            $this->assertFalse($images->exists('cached/example.jpg'));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_schema_rejects_wrong_inputs_before_execution_and_wrong_results_before_completion(): void
    {
        $this->callAction('get', ['id' => 42])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        $this->assertCount(0, app(FakeLifecycleObserver::class)->named('execution.started'));
        Entry::find($this->entryId)->set('title', ['bad' => 'shape'])->save();
        $this->callAction('get')->assertJsonPath('result.isError', true);
        $this->assertCount(0, app(FakeLifecycleObserver::class)->named('execution.completed'));
        $this->assertCount(1, app(FakeLifecycleObserver::class)->named('execution.failed'));
    }

    public function test_a_replay_cannot_return_data_outside_its_current_output_contract(): void
    {
        $input = ['data' => ['secret' => 'Changed'], 'idempotency_key' => 'replay-output-contract'];
        $this->callAction('update', $input)->assertJsonPath('result.structuredContent.success', true);
        config(['statamic.mcp.tools.notes.denied_fields' => ['title']]);
        $response = $this->callAction('update', $input)->assertJsonPath('result.isError', true);
        $this->assertStringNotContainsString('Before', $response->getContent());
        $this->assertCount(1, app(FakeLifecycleObserver::class)->named('execution.started'));
        $this->assertCount(0, app(FakeLifecycleObserver::class)->named('execution.replayed'));
    }

    public function test_integrations_cannot_claim_the_guidance_profile_exemption(): void
    {
        $this->app->instance(IntegrationRegistry::class, new IntegrationRegistry);
        $this->app->instance(CapabilityRegistry::class, new CapabilityRegistry(app(ScopeRegistry::class)));
        config(['fixture.domain' => 'guidance']);
        $this->expectException(\LogicException::class);
        StatamicMcp::integration('fixture/notes')->router(IntegrationFixtureRouter::class);
    }

    public function test_fieldtype_callbacks_and_ownership_are_local_to_each_application(): void
    {
        $original = app();
        $other = new Container;
        $other->singleton(IntegrationRegistry::class);
        $field = new Field('note', ['type' => 'fixture_note']);
        $this->assertSame(['owner' => 'original'], FieldtypeExtensions::resolveSpec($field));
        try {
            Container::setInstance($other);
            $this->assertNull(FieldtypeExtensions::resolveSpec($field));
            $this->assertFalse(FieldtypeExtensions::hasSanitizer($field));
            StatamicMcp::integration('fixture/notes')->fieldtype('fixture_note',
                fn (): array => ['owner' => 'other'], fn (mixed $value): mixed => ['other' => $value]);
            $this->assertSame(['owner' => 'other'], FieldtypeExtensions::resolveSpec($field));
            $this->assertSame(['other' => 'value'], FieldtypeExtensions::applySanitizer($field, 'value', 'note'));
        } finally {
            Container::setInstance($original);
        }
        $this->assertSame(['owner' => 'original'], FieldtypeExtensions::resolveSpec($field));
        $this->assertSame('value', FieldtypeExtensions::applySanitizer($field, 'value', 'note'));
    }

    public function test_rejected_router_registration_does_not_leave_partial_consent(): void
    {
        $this->app->instance(IntegrationRegistry::class, new IntegrationRegistry);
        $registry = new CapabilityRegistry(app(ScopeRegistry::class));
        $this->app->instance(CapabilityRegistry::class, $registry);
        config(['fixture.write_scope' => 'another:write']);
        try {
            StatamicMcp::integration('fixture/notes')->router(IntegrationFixtureRouter::class);
            $this->fail('A router cannot borrow another package scope.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('owning package', $error->getMessage());
        }
        $registry->freeze();
        $this->assertSame([], $registry->forScopes(['fixture:read', 'fixture:write'])->actions);
        $this->assertSame([], app(IntegrationRegistry::class)->tools());
    }

    public function test_a_read_handler_cannot_persist_a_change(): void
    {
        $path = Entry::find($this->entryId)->path();
        $before = file_get_contents($path);
        config(['fixture.persist_read' => true]);
        $this->callAction('get')->assertJsonPath('result.isError', true);
        $this->assertSame($before, file_get_contents($path));
        $this->assertCount(1, app(FakeLifecycleObserver::class)->named('execution.failed'));
    }
}

class IntegrationFixtureProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->singleton(FakeLifecycleObserver::class);
        $this->app->singleton(IntegrationRevoker::class);
        StatamicMcp::integration('fixture/notes')
            ->scope('fixture:read', 'Read notes', ['view pages entries'])
            ->scope('fixture:write', 'Edit notes', ['edit pages entries'])
            ->router(IntegrationFixtureRouter::class, version: 3, approvals: ['replace'])
            ->fieldtype('fixture_note', fn (): array => ['owner' => 'original'], fn (mixed $value): mixed => $value)
            ->observer(FakeLifecycleObserver::class)
            ->observer(IntegrationRevoker::class);
    }
}

class IntegrationRevoker implements LifecycleObserver
{
    public ?string $secret = null;

    public function observe(LifecycleEvent $event): void
    {
        if ($this->secret !== null && $event->eventName() === 'execution.started') {
            app(TokenService::class)->revokePlainTextToken($this->secret);
            $this->secret = null;
        }
    }
}

#[Name('fixture-notes')]
#[Description('Read and edit fixture notes.')]
class IntegrationFixtureRouter extends BaseRouter
{
    protected function getDomain(): string
    {
        return config('fixture.domain', 'notes');
    }

    protected function getTypes(): array
    {
        return [];
    }

    protected function actions(): array
    {
        $output = ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'secret' => ['type' => 'string']], 'required' => ['title'], 'additionalProperties' => false];

        return [
            RouterAction::read('get', 'Read a note.', needs: ['collection', 'id'], optional: ['site'])->withContract(['fixture:read'], $output),
            RouterAction::edit('update', 'Edit a note.', needs: ['collection', 'id', 'data'], optional: ['site'])->withContract([config('fixture.write_scope', 'fixture:write')], $output),
            RouterAction::edit('replace', 'Replace a note after approval.', needs: ['collection', 'id', 'data'], optional: ['site'])->withContract(['fixture:write'], $output),
        ];
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return parent::defineSchema($schema) + [
            'collection' => JsonSchema::string(), 'id' => JsonSchema::string(), 'site' => JsonSchema::string(), 'data' => JsonSchema::object(),
        ];
    }

    protected function authorizeAction(array $arguments): void
    {
        $entry = Entry::find($arguments['id']);
        if ($entry === null || $entry->collectionHandle() !== $arguments['collection']
            || $entry->locale() !== ($arguments['site'] ?? Site::default()->handle())
            || ! User::current()?->can($arguments['action'] === 'get' ? 'view' : 'edit', $entry)) {
            throw new AuthorizationException('Note is not accessible.');
        }
    }

    protected function executeAction(array $arguments): array
    {
        $entry = Entry::find($arguments['id']);
        if ($arguments['action'] === 'get' && config('fixture.persist_read')) {
            $entry->set('title', 'Read must not write');
            $this->persistStatamic(fn () => $entry->save());
        }
        if ($arguments['action'] !== 'get') {
            $entry->merge($arguments['data']);
            $this->persistStatamic(fn () => $entry->save(), app()->bound(Effects::class) ? app(Effects::class) : ['stache', 'static']);
            $afterWrite = config('fixture.after_write');
            if ($afterWrite instanceof \Closure) {
                $afterWrite();
            }
        }

        $data = $entry->data()->only(['title', 'secret'])->all();

        return $arguments['action'] === 'get' ? $data : $this->createSuccessResponse($data)->toArray();
    }
}
