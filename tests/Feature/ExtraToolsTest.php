<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Mcp\ExtraTools;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Tests\Fixtures\ExtraTools\SystemScopedExtraRouter;
use Cboxdk\StatamicMcp\Tests\Fixtures\ExtraTools\UnscopedExtraRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    ExtraTools::flush();
    config()->set('statamic.mcp.extra_tools', []);

    $this->app->bind(Transport::class, fn () => Mockery::mock(Transport::class)->shouldIgnoreMissing());
});

afterEach(function () {
    ExtraTools::flush();
    request()->headers->remove('X-MCP-Remote');
    request()->attributes->remove('mcp_token');
});

/**
 * Names of the tools a client is offered at connect time, and of those behind search_tools.
 *
 * @return array{listed: list<string>, searchable: list<string>}
 */
function extraToolsCatalog(): array
{
    /** @var array<int|string, class-string<Tool>|array<int, class-string<Tool>>> $tools */
    $tools = (new ReflectionProperty(ServerContext::class, 'tools'))->getValue(app(StatamicMcpServer::class)->createContext());

    $searchable = $tools[ToolSearch::class] ?? [];
    unset($tools[ToolSearch::class]);

    $name = fn (string $class): string => app($class)->name();

    return [
        'listed' => array_values(array_map($name, $tools)),
        'searchable' => array_values(array_map($name, $searchable)),
    ];
}

/**
 * @param  list<string>  $scopes
 */
function extraToolsToken(array $scopes): McpTokenData
{
    $model = McpToken::create([
        'user_id' => 'test-user-id',
        'name' => 'Extra tools test token',
        'token' => hash('sha256', 'extra-' . bin2hex(random_bytes(8))),
        'scopes' => $scopes,
    ]);

    return new McpTokenData(
        id: $model->id,
        userId: $model->user_id,
        name: $model->name,
        tokenHash: $model->token,
        scopes: $model->scopes,
        lastUsedAt: null,
        expiresAt: null,
        createdAt: Carbon::instance($model->created_at),
        updatedAt: null,
    );
}

/**
 * Call a router as a web client holding a token with the given scopes.
 *
 * @param  class-string<Tool>  $class
 * @param  list<string>  $scopes
 *
 * @return array<string, mixed>
 */
function callAsWebClient(string $class, array $scopes): array
{
    test()->loadMigrationsFrom(__DIR__ . '/../../database/migrations/tokens');
    config()->set('statamic.mcp.web.enabled', true);
    request()->headers->set('X-MCP-Remote', 'true');

    $user = User::make()->email('extra-tools@example.com')->makeSuper();
    $user->save();
    test()->actingAs($user);

    request()->attributes->set('mcp_token', extraToolsToken($scopes));

    return app($class)->execute(['action' => 'get']);
}

it('serves a registered tool through search_tools by default', function () {
    StatamicMcpServer::registerTool(UnscopedExtraRouter::class);

    $catalog = extraToolsCatalog();

    expect($catalog['searchable'])->toContain('fixture-site-health')
        ->and($catalog['listed'])->not->toContain('fixture-site-health');
});

it('lists a registered tool at connect time when it is not searchable', function () {
    StatamicMcpServer::registerTool(UnscopedExtraRouter::class, searchable: false);

    $catalog = extraToolsCatalog();

    expect($catalog['listed'])->toContain('fixture-site-health')
        ->and($catalog['searchable'])->not->toContain('fixture-site-health');
});

it('reads extra tools from config', function () {
    config()->set('statamic.mcp.extra_tools', [
        UnscopedExtraRouter::class,
        ['class' => SystemScopedExtraRouter::class, 'searchable' => false],
    ]);

    $catalog = extraToolsCatalog();

    expect($catalog['searchable'])->toContain('fixture-site-health')
        ->and($catalog['listed'])->toContain('fixture-system-health');
});

it('lists every tool directly when the searchable catalog is off', function () {
    config()->set('statamic.mcp.catalog.searchable', false);
    StatamicMcpServer::registerTool(UnscopedExtraRouter::class);

    expect(extraToolsCatalog()['listed'])->toContain('fixture-site-health');
});

it('keeps the flat tool list complete', function () {
    StatamicMcpServer::registerTool(UnscopedExtraRouter::class);

    $tools = (new ReflectionProperty(StatamicMcpServer::class, 'tools'))->getValue(app(StatamicMcpServer::class));

    expect($tools)->toContain(UnscopedExtraRouter::class)
        ->and($tools)->toContain(...StatamicMcpServer::CORE_TOOLS);
});

it('names searchable extra tools in the instructions', function () {
    StatamicMcpServer::registerTool(UnscopedExtraRouter::class);

    expect(app(StatamicMcpServer::class)->createContext()->instructions)->toContain('fixture-site-health');
});

it('tells the discovery tool about a listed extra tool', function () {
    StatamicMcpServer::registerTool(UnscopedExtraRouter::class, searchable: false);

    $names = (new ReflectionMethod(DiscoveryTool::class, 'catalogToolNames'))->invoke(app(DiscoveryTool::class));

    expect($names)->toContain('fixture-site-health');
});

it('refuses a class that is not a router', function (string $class) {
    StatamicMcpServer::registerTool($class);
})->with([
    'not a class' => 'App\\Mcp\\DoesNotExist',
    'a plain tool' => DiscoveryTool::class,
])->throws(InvalidArgumentException::class);

it('refuses a config entry that is not a router', function () {
    config()->set('statamic.mcp.extra_tools', [DiscoveryTool::class]);

    ExtraTools::all();
})->throws(InvalidArgumentException::class);

it('requires a full-access token for an extra tool whose domain has no scope', function () {
    StatamicMcpServer::registerTool(UnscopedExtraRouter::class);

    $denied = callAsWebClient(UnscopedExtraRouter::class, ['system:read', 'content:read']);

    expect($denied['success'])->toBeFalse()
        ->and(json_encode($denied['errors']))->toContain('Token missing required scope: *');

    $allowed = callAsWebClient(UnscopedExtraRouter::class, ['*']);

    expect($allowed['success'])->toBeTrue();
});

it('gates an extra tool in a known domain by that domain\'s scope', function () {
    StatamicMcpServer::registerTool(SystemScopedExtraRouter::class);

    expect(callAsWebClient(SystemScopedExtraRouter::class, ['content:read'])['success'])->toBeFalse()
        ->and(callAsWebClient(SystemScopedExtraRouter::class, ['system:read'])['success'])->toBeTrue();
});
