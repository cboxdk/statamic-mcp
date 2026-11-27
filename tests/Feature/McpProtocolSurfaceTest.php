<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\FormsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Testing\InteractsWithMcp;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Testing\Fluent\AssertableJson;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * Exercises tools through the real MCP protocol rather than calling execute()
 * directly, so JSON-RPC argument delivery, response serialization, and the
 * isError flag are covered by something.
 */
class McpProtocolSurfaceTest extends TestCase
{
    use InteractsWithMcp;

    private const COLLECTION = 'protocol_pages';

    private const TAXONOMY = 'protocol_tags';

    protected function setUp(): void
    {
        parent::setUp();

        // This test is about what each tool does on the wire, not about how the
        // catalog is shaped, so it addresses every tool by name. The default
        // catalog hides most of them behind search_tools; WebEndpointProtocolTest
        // covers that split, and here it would only get in the way.
        config()->set('statamic.mcp.catalog.searchable', false);

        Collection::make(self::COLLECTION)->title('Protocol Pages')->save();
        Taxonomy::make(self::TAXONOMY)->title('Protocol Tags')->save();

        // Tests that resolve the server directly need a transport; the tool
        // helper supplies its own.
        $this->fakeMcpTransport();
    }

    /**
     * A read-only call per tool that should succeed, so every registered tool is
     * smoke-tested through the protocol rather than only through execute().
     *
     * @return array<string, array{class-string, array<string, mixed>}>
     */
    public static function readOnlyCalls(): array
    {
        return [
            'entries' => [EntriesRouter::class, ['action' => 'list', 'collection' => self::COLLECTION]],
            'terms' => [TermsRouter::class, ['action' => 'list', 'taxonomy' => self::TAXONOMY]],
            'globals' => [GlobalsRouter::class, ['action' => 'list']],
            'forms' => [FormsRouter::class, ['action' => 'list']],
            'blueprints' => [BlueprintsRouter::class, ['action' => 'list']],
            'structures' => [StructuresRouter::class, ['action' => 'list', 'resource_type' => 'collection']],
            'assets' => [AssetsRouter::class, ['action' => 'list', 'resource_type' => 'container']],
            'users' => [UsersRouter::class, ['action' => 'list', 'resource_type' => 'user']],
            'system' => [SystemRouter::class, ['action' => 'info']],
            'content facade' => [ContentFacadeRouter::class, ['action' => 'content_audit']],
            'discovery' => [DiscoveryTool::class, ['intent' => 'list entries']],
            'schema' => [DiscoveryTool::class, ['tool_name' => 'statamic-entries-read']],
        ];
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('readOnlyCalls')]
    public function test_read_only_calls_succeed_through_the_protocol(string $tool, array $arguments): void
    {
        $this->mcpTool($tool, $arguments)
            ->assertOk()
            ->assertHasNoErrors();
    }

    public function test_arguments_survive_the_json_rpc_round_trip(): void
    {
        // If the action argument were dropped in transit, the router would
        // answer "Unknown action" instead of listing entries.
        $this->mcpTool(EntriesRouter::class, [
            'action' => 'list',
            'collection' => self::COLLECTION,
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('success', true)
                ->where('meta.tool', 'statamic-entries-read')
                ->has('data')
                ->etc()
            );
    }

    public function test_fieldset_writes_and_reads_use_the_blueprint_facets(): void
    {
        $this->mcpTool(BlueprintsRouter::class, ['action' => 'create_fieldset', 'handle' => 'protocol_fields',
            'fields' => [['handle' => 'summary', 'field' => ['type' => 'textarea']]],
        ])->assertOk()->assertHasNoErrors()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('success', true)->where('meta.tool', 'statamic-blueprints-admin')
            ->where('data.fieldset.handle', 'protocol_fields')->etc());
        $this->mcpTool(BlueprintsRouter::class, ['action' => 'get_fieldset', 'handle' => 'protocol_fields'])
            ->assertOk()->assertHasNoErrors()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('success', true)->where('meta.tool', 'statamic-blueprints-read')
            ->where('data.fieldset.fields.summary.type', 'textarea')->etc());
    }

    /**
     * The protocol's isError flag is what clients read to decide a call failed;
     * a false flag on a failed call reads as success to everything except a
     * model that happens to parse the JSON body.
     */
    public function test_failed_calls_set_the_protocol_error_flag(): void
    {
        $this->mcpTool(ContentFacadeRouter::class, ['action' => 'nope'])
            ->assertHasErrors(['Unknown action']);
    }

    public function test_failed_calls_still_carry_their_structured_envelope(): void
    {
        $this->mcpTool(ContentFacadeRouter::class, ['action' => 'nope'])
            ->assertHasErrors()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('success', false)
                ->where('meta.tool', 'statamic-content-facade-read')
                ->where('error.code', 'VALIDATION')
                ->where('error.field', 'action')
                ->has('error.message')
                ->missing('errors')
                ->etc()
            );
    }

    /**
     * Official SDK clients validate structuredContent against the tool's
     * advertised outputSchema, failures included, and throw away a response
     * that does not match. So every envelope a tool sends, success or
     * failure, has to satisfy the schema it publishes.
     *
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('readOnlyCalls')]
    public function test_success_and_failure_envelopes_match_the_advertised_output_schema(string $tool, array $arguments): void
    {
        $instance = app($tool);
        if ($instance instanceof BaseRouter) {
            $instance = $instance->facet('read');
        }
        $schema = json_decode((string) json_encode($instance->toArray()['outputSchema']));
        $validator = new Validator;

        foreach ([$arguments, ['action' => 'no_such_action'] + $arguments] as $call) {
            $content = null;
            $this->mcpTool($tool, $call)->assertStructuredContent(function (AssertableJson $json) use (&$content): void {
                $content = $json->toArray();
                $json->etc();
            });

            $result = $validator->validate(json_decode((string) json_encode($content)), $schema);
            $error = $result->error();
            $this->assertTrue($result->isValid(), $error === null ? ''
                : json_encode((new ErrorFormatter)->format($error)) . ' for ' . json_encode($call));
        }

        // The former envelope-only schema accepted missing or wrong-domain data.
        $invalid = (object) ['success' => true, 'data' => (object) ['unrelated' => true], 'meta' => (object) []];
        $this->assertFalse($validator->validate($invalid, $schema)->isValid());
        $invalid->success = false;
        $this->assertFalse($validator->validate($invalid, $schema)->isValid(), 'A failed call must carry its error.');
    }

    /** @return array<string, array{class-string<BaseRouter>, array<string, mixed>}> */
    public static function writeCalls(): array
    {
        return [
            'entry' => [EntriesRouter::class, ['action' => 'create', 'collection' => self::COLLECTION, 'data' => ['title' => 'Protocol entry']]],
            'term' => [TermsRouter::class, ['action' => 'create', 'taxonomy' => self::TAXONOMY, 'data' => ['title' => 'Protocol term']]],
            'global' => [GlobalsRouter::class, ['action' => 'update', 'handle' => 'protocol_globals', 'data' => ['footer' => 'Changed footer']]],
            'blueprint' => [BlueprintsRouter::class, ['action' => 'create', 'namespace' => 'globals', 'handle' => 'protocol_schema']],
            'structure' => [StructuresRouter::class, ['action' => 'create', 'resource_type' => 'collection', 'handle' => 'protocol_new']],
            'asset container' => [AssetsRouter::class, ['action' => 'create', 'resource_type' => 'container', 'container' => 'protocol_assets', 'data' => ['disk' => 'local']]],
            'user' => [UsersRouter::class, ['action' => 'create', 'resource_type' => 'user', 'data' => ['email' => 'protocol@example.com', 'name' => 'Protocol user']]],
            'system' => [SystemRouter::class, ['action' => 'cache_clear', 'cache_type' => 'views']],
        ];
    }

    /**
     * @param  class-string<BaseRouter>  $tool
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('writeCalls')]
    public function test_write_tools_validate_full_records_errors_and_truncated_successes(string $tool, array $arguments): void
    {
        Blueprint::make('protocol_globals')->setNamespace('globals')->setContents([
            'fields' => [['handle' => 'footer', 'field' => ['type' => 'text']]],
        ])->save();
        $set = GlobalSet::make('protocol_globals')->title('Protocol globals');
        $set->save();
        $set->makeLocalization('default')->save();
        $instance = app($tool)->facet('write');
        $schema = json_decode(json_encode($instance->toArray()['outputSchema'], JSON_THROW_ON_ERROR));
        foreach ([$arguments, ['action' => 'absent']] as $index => $call) {
            $content = null;
            $response = $this->mcpTool($instance, $call)->assertStructuredContent(function (AssertableJson $json) use (&$content): void {
                $content = $json->toArray();
                $json->etc();
            });
            if ($index === 0) {
                $response->assertHasNoErrors();
                if ($tool === EntriesRouter::class) {
                    foreach (['id', 'data', 'version'] as $required) {
                        $invalid = $content;
                        unset($invalid['data']['entry'][$required]);
                        $this->assertFalse((new Validator)->validate(json_decode(json_encode($invalid)), $schema)->isValid());
                    }
                    foreach (['published' => 'yes', 'collection' => 42] as $field => $wrongType) {
                        $invalid = $content;
                        $invalid['data']['entry'][$field] = $wrongType;
                        $this->assertFalse((new Validator)->validate(json_decode(json_encode($invalid)), $schema)->isValid());
                    }
                }
            } else {
                $response->assertHasErrors();
            }
            $result = (new Validator)->validate(json_decode(json_encode($content, JSON_THROW_ON_ERROR)), $schema);
            $error = $result->error();
            $this->assertTrue($result->isValid(), $error === null ? '' : json_encode((new ErrorFormatter)->format($error)));
        }

        if ($tool === EntriesRouter::class) {
            config()->set('statamic.mcp.security.max_response_size', 800);
            $this->mcpTool($instance, ['action' => 'create', 'collection' => self::COLLECTION, 'slug' => 'large-record', 'data' => ['title' => str_repeat('Large title ', 200)]])
                ->assertHasNoErrors()
                ->assertStructuredContent(function (AssertableJson $json) use ($schema): void {
                    $json->where('data.response_truncated', true)->etc();
                    $this->assertTrue((new Validator)->validate(json_decode(json_encode($json->toArray(), JSON_THROW_ON_ERROR)), $schema)->isValid());
                });
        }
    }

    public function test_eloquent_user_ids_are_strings_on_the_wire(): void
    {
        Role::make()->handle('editor')->title('Editor')->permissions(['access cp'])->save();
        UserGroup::make()->handle('editors')->title('Editors')->roles(['editor'])->save();
        $user = new \Statamic\Auth\Eloquent\User;
        $user->model((new \Illuminate\Foundation\Auth\User)->setRawAttributes([
            'id' => 42, 'email' => 'integer@example.test', 'name' => 'Integer id', 'super' => true,
        ]));
        $user->explicitRoles(['editor'])->groups(['editors']);
        User::partialMock()->shouldReceive('find')->with('42')->andReturn($user);
        $tool = (new UsersRouter)->facet('read');
        $schema = json_decode(json_encode($tool->toArray()['outputSchema'], JSON_THROW_ON_ERROR));
        $this->mcpTool($tool, ['action' => 'get', 'resource_type' => 'user', 'id' => '42', 'include_details' => false])
            ->assertHasNoErrors()
            ->assertStructuredContent(function (AssertableJson $json) use ($schema): void {
                $json->where('data.user.id', '42')->where('data.user.roles', ['editor'])->where('data.user.groups', ['editors'])->etc();
                $this->assertTrue((new Validator)->validate(json_decode(json_encode($json->toArray())), $schema)->isValid());
            });
    }

    public function test_group_membership_lists_and_fully_filtered_snapshots_match_the_schema(): void
    {
        Role::make()->handle('editor')->title('Editor')->permissions(['access cp'])->save();
        $tool = (new UsersRouter)->facet('write');
        $schema = json_decode(json_encode($tool->toArray()['outputSchema'], JSON_THROW_ON_ERROR));
        $this->mcpTool($tool, [
            'action' => 'create', 'resource_type' => 'group', 'handle' => 'editors',
            'data' => ['title' => 'Editors', 'roles' => ['editor']],
        ])->assertHasNoErrors()->assertStructuredContent(function (AssertableJson $json) use ($schema): void {
            $json->where('data.group.roles', ['editor'])->etc();
            $this->assertTrue((new Validator)->validate(json_decode(json_encode($json->toArray())), $schema)->isValid());
        });
        config(['statamic.mcp.tools.users.denied_fields' => ['handle', 'title', 'roles', 'version', 'deleted']]);
        $schema = json_decode(json_encode($tool->toArray()['outputSchema'], JSON_THROW_ON_ERROR));
        $this->mcpTool($tool, ['action' => 'delete', 'resource_type' => 'group', 'handle' => 'editors'])
            ->assertHasNoErrors()->assertStructuredContent(function (AssertableJson $json) use ($schema): void {
                $json->where('data.group', [])->etc();
                $this->assertTrue((new Validator)->validate(json_decode(json_encode($json->toArray())), $schema)->isValid());
            });
    }

    public function test_filtered_blueprint_metadata_matches_its_output_schema(): void
    {
        Blueprint::make('page')->setNamespace('collections.' . self::COLLECTION)->setContents([
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ])->save();
        config(['statamic.mcp.tools.blueprints.denied_fields' => ['version']]);
        $tool = (new BlueprintsRouter)->facet('read');
        $schema = json_decode(json_encode($tool->toArray()['outputSchema'], JSON_THROW_ON_ERROR));
        $this->mcpTool($tool, ['action' => 'get', 'namespace' => 'collections', 'collection' => self::COLLECTION, 'handle' => 'page'])
            ->assertHasNoErrors()->assertStructuredContent(function (AssertableJson $json) use ($schema): void {
                $json->missing('data.blueprint.version')->where('data.blueprint.handle', 'page')->etc();
                $this->assertTrue((new Validator)->validate(json_decode(json_encode($json->toArray())), $schema)->isValid());
            });
    }

    public function test_tool_names_are_unique(): void
    {
        $names = array_map(
            fn (string $tool): string => app($tool)->name(),
            [...StatamicMcpServer::CORE_TOOLS, ...StatamicMcpServer::SEARCHABLE_TOOLS]
        );

        $this->assertContains('statamic-entries', $names);
        $this->assertSame(count(array_unique($names)), count($names), 'Tool names must be unique.');
    }

    /** @return array<string, array{class-string, array<string, mixed>}> */
    public static function missingRecords(): array
    {
        return [
            'entry' => [EntriesRouter::class, ['collection' => self::COLLECTION, 'id' => 'absent']],
            'term' => [TermsRouter::class, ['taxonomy' => self::TAXONOMY, 'slug' => 'absent']],
            'global' => [GlobalsRouter::class, ['handle' => 'absent']],
            'blueprint' => [BlueprintsRouter::class, ['handle' => 'absent', 'namespace' => 'collections']],
            'collection' => [StructuresRouter::class, ['resource_type' => 'collection', 'handle' => 'absent']],
            'taxonomy' => [StructuresRouter::class, ['resource_type' => 'taxonomy', 'handle' => 'absent']],
            'navigation' => [StructuresRouter::class, ['resource_type' => 'navigation', 'handle' => 'absent']],
            'site' => [StructuresRouter::class, ['resource_type' => 'site', 'handle' => 'absent']],
            'container' => [AssetsRouter::class, ['resource_type' => 'container', 'container' => 'absent']],
            'user' => [UsersRouter::class, ['resource_type' => 'user', 'id' => 'absent']],
            'role' => [UsersRouter::class, ['resource_type' => 'role', 'handle' => 'absent']],
            'group' => [UsersRouter::class, ['resource_type' => 'group', 'handle' => 'absent']],
        ];
    }

    #[DataProvider('missingRecords')]
    public function test_missing_records_are_not_validation_failures(string $tool, array $arguments): void
    {
        $this->mcpTool($tool, ['action' => 'get'] + $arguments)
            ->assertHasErrors()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('success', false)
                ->where('error.code', 'NOT_FOUND')
                ->has('error.message')->has('error.field')
                ->missing('errors')->etc());
    }

    public function test_unexpected_repository_failures_are_internal_and_do_not_expose_details(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        Entry::shouldReceive('find')->with('broken')->andThrow(new \RuntimeException('private database diagnostic'));

        $this->mcpTool(EntriesRouter::class, ['action' => 'get', 'collection' => self::COLLECTION, 'id' => 'broken'])
            ->assertHasErrors()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('success', false)
                ->where('error.code', 'INTERNAL')
                ->where('error.message', fn (string $message): bool => ! str_contains($message, 'private database diagnostic'))
                ->has('meta.correlation_id')->missing('errors')->etc());
    }

    public function test_missing_localizations_are_not_found_with_or_without_a_version(): void
    {
        config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
        Site::setSites([
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'da' => ['name' => 'Dansk', 'locale' => 'da_DK', 'url' => '/da/'],
        ]);
        Collection::find(self::COLLECTION)->sites(['en', 'da'])->save();
        Entry::make()->id('english-only')->collection(self::COLLECTION)->locale('en')->slug('one')->save();
        $set = GlobalSet::make('english-only')->sites(['en']);
        $set->save();
        $set->makeLocalization('en')->data(['title' => 'English'])->save();

        foreach ([
            [EntriesRouter::class, ['action' => 'get', 'collection' => self::COLLECTION, 'id' => 'english-only']],
            [EntriesRouter::class, ['action' => 'update', 'collection' => self::COLLECTION, 'id' => 'english-only', 'data' => ['title' => 'Dansk']]],
            [GlobalsRouter::class, ['action' => 'update', 'handle' => 'english-only', 'data' => ['title' => 'Dansk']]],
            [GlobalsRouter::class, ['action' => 'update', 'handle' => 'english-only', 'data' => ['title' => 'Dansk'], 'expected_version' => 'old']],
        ] as [$tool, $arguments]) {
            $this->mcpTool($tool, $arguments + ['site' => 'da'])
                ->assertHasErrors()
                ->assertStructuredContent(fn (AssertableJson $json) => $json
                    ->where('error.code', 'NOT_FOUND')->where('error.field', 'site')->etc());
        }
    }

    public function test_server_reports_its_installed_package_version(): void
    {
        $server = app(StatamicMcpServer::class);
        $server->boot();

        $version = (new \ReflectionProperty($server, 'version'))->getValue($server);

        $this->assertIsString($version);
        $this->assertNotSame('0.0.0', $version, 'Version should resolve from the installed package.');
    }
}
