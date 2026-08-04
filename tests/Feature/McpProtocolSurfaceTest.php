<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Testing\InteractsWithMcp;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;

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
            'blueprints' => [BlueprintsRouter::class, ['action' => 'list']],
            'structures' => [StructuresRouter::class, ['action' => 'list', 'resource_type' => 'collection']],
            'assets' => [AssetsRouter::class, ['action' => 'list', 'resource_type' => 'container']],
            'users' => [UsersRouter::class, ['action' => 'list', 'resource_type' => 'user']],
            'system' => [SystemRouter::class, ['action' => 'info']],
            'content facade' => [ContentFacadeRouter::class, ['action' => 'content_audit']],
            'discovery' => [DiscoveryTool::class, ['intent' => 'list entries']],
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
                ->where('meta.tool', 'statamic-entries')
                ->has('data')
                ->etc()
            );
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
                ->where('meta.tool', 'statamic-content-facade')
                ->has('errors', 1)
                ->etc()
            );
    }

    public function test_tool_names_are_unique(): void
    {
        $names = array_map(
            fn (string $tool): string => app($tool)->name(),
            array_column(self::readOnlyCalls(), 0)
        );

        $this->assertContains('statamic-entries', $names);
        $this->assertSame(count(array_unique($names)), count($names), 'Tool names must be unique.');
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
