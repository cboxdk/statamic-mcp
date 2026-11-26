<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Symfony\Component\Yaml\Yaml;

class StructuresUpdatePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic.editions.pro' => true,
            'statamic.mcp.web.enabled' => true,
            'statamic.mcp.catalog.searchable' => false,
            'statamic.mcp.confirmation.enabled' => false,
            'statamic.mcp.tools.structures.resources.write' => ['demo'],
            'statamic.mcp.tools.structures.denied_fields' => ['title'],
        ]);
    }

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function structures(): array
    {
        return [
            'collection' => ['collection', Collection::class],
            'taxonomy' => ['taxonomy', Taxonomy::class],
            'navigation' => ['navigation', Nav::class],
            'globalset' => ['globalset', GlobalSet::class],
        ];
    }

    /**
     * @return array<string, array{string, class-string, string}>
     */
    public static function structuresAndTransports(): array
    {
        $cases = [];

        foreach (self::structures() as $type => $arguments) {
            foreach (['cli', 'pat', 'oauth'] as $transport) {
                $cases["{$type}-{$transport}"] = [...$arguments, $transport];
            }
        }

        return $cases;
    }

    #[DataProvider('structuresAndTransports')]
    public function test_update_preserves_denied_title_on_disk(string $type, string $facade, string $transport): void
    {
        $structure = $facade::make('demo')->title('Original');
        $structure->save();
        $path = $structure->path();
        Stache::refresh();

        $arguments = [
            'action' => 'update', 'resource_type' => $type, 'handle' => 'demo',
            'data' => ['title' => 'Changed'],
        ];

        $result = $transport === 'cli'
            ? (new StructuresRouter)->execute($arguments)
            : $this->updateOverHttp($arguments, $this->token($transport))->assertOk()->json('result.structuredContent');

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertSame('Original', Yaml::parseFile($path)['title']);
        Stache::refresh();
        $this->assertSame('Original', $facade::find('demo')->title());
    }

    #[DataProvider('structures')]
    public function test_update_changes_title_without_a_denied_field_policy(string $type, string $facade): void
    {
        config(['statamic.mcp.tools.structures.denied_fields' => []]);
        $structure = $facade::make('demo')->title('Original');
        $structure->save();
        Stache::refresh();

        $result = (new StructuresRouter)->execute([
            'action' => 'update', 'resource_type' => $type, 'handle' => 'demo',
            'data' => ['title' => 'Changed'],
        ]);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertSame('Changed', Yaml::parseFile($structure->path())['title']);
    }

    public function test_allowed_collection_settings_are_saved_alongside_denied_fields(): void
    {
        $collection = Collection::make('demo')->title('Original')->template('original');
        $collection->save();
        Stache::refresh();

        $response = $this->updateOverHttp([
            'action' => 'update', 'resource_type' => 'collection', 'handle' => 'demo',
            'data' => ['title' => 'Changed', 'template' => 'updated'],
        ], $this->token('pat'));

        $response->assertOk()->assertJsonPath('result.structuredContent.success', true);
        $saved = Yaml::parseFile($collection->path());
        $this->assertSame('Original', $saved['title']);
        $this->assertSame('updated', $saved['template']);
    }

    public function test_global_set_update_returns_the_saved_title(): void
    {
        config(['statamic.mcp.tools.structures.denied_fields' => []]);
        $globalSet = GlobalSet::make('demo')->title('Original');
        $globalSet->save();
        Stache::refresh();

        $response = $this->updateOverHttp([
            'action' => 'update', 'resource_type' => 'globalset', 'handle' => 'demo',
            'data' => ['title' => 'Changed'],
        ], $this->token('pat'));

        $response->assertOk()
            ->assertJsonPath('result.structuredContent.success', true)
            ->assertJsonPath('result.structuredContent.data.globalset.updated', true)
            ->assertJsonPath('result.structuredContent.data.globalset.handle', 'demo')
            ->assertJsonPath('result.structuredContent.data.globalset.title', Yaml::parseFile($globalSet->path())['title']);
        $this->assertSame('Changed', Yaml::parseFile($globalSet->path())['title']);
    }

    public function test_update_uses_the_recursive_field_policy(): void
    {
        config(['statamic.mcp.tools.structures.denied_fields' => ['secret']]);
        Nav::make('demo')->title('Demo')->save();
        Stache::refresh();

        $result = (new StructuresRouter)->execute([
            'action' => 'update', 'resource_type' => 'navigation', 'handle' => 'demo',
            'data' => ['tree' => [[
                'url' => '/public',
                'data' => ['secret' => 'Denied', 'visible' => 'Kept'],
            ]]],
        ]);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $tree = Nav::find('demo')->in('default');
        $this->assertSame(['visible' => 'Kept'], Yaml::parseFile($tree->path())['tree'][0]['data']);
    }

    public function test_navigation_update_data_remains_filtered(): void
    {
        $navigation = Nav::make('demo')->title('Original');
        $navigation->save();
        Stache::refresh();

        $result = (new StructuresRouter)->execute([
            'action' => 'update', 'resource_type' => 'navigation', 'handle' => 'demo',
            'data' => ['title' => 'Changed', 'max_depth' => 2],
        ]);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $saved = Yaml::parseFile($navigation->path());
        $this->assertSame('Original', $saved['title']);
        $this->assertSame(2, $saved['max_depth']);
    }

    /**
     * @return array<string, array{TokenScope|null, bool, list<string>, string|null}>
     */
    public static function deniedAccess(): array
    {
        return [
            'unauthenticated' => [null, true, ['demo'], null],
            'read-only token' => [TokenScope::StructuresRead, true, ['demo'], 'Token missing required scope: structures:admin'],
            'missing permission' => [TokenScope::StructuresAdmin, false, ['demo'], 'Permission denied: Cannot update structures'],
            'resource policy' => [TokenScope::StructuresAdmin, true, ['other'], "Write access to 'demo' is not permitted by resource policy"],
        ];
    }

    #[DataProvider('deniedAccess')]
    public function test_update_requires_normal_access_checks(?TokenScope $scope, bool $permitted, array $resources, ?string $error): void
    {
        $collection = Collection::make('demo')->title('Original')->template('original');
        $collection->save();
        Stache::refresh();
        config(['statamic.mcp.tools.structures.resources.write' => $resources]);

        $response = $this->updateOverHttp([
            'action' => 'update', 'resource_type' => 'collection', 'handle' => 'demo',
            'data' => ['title' => 'Changed', 'template' => 'updated'],
        ], $scope === null ? null : $this->token('pat', $scope, $permitted));

        if ($scope === null) {
            $response->assertUnauthorized();
        } else {
            $response->assertOk()
                ->assertJsonPath('result.isError', true)
                ->assertJsonPath('result.structuredContent.error.message', $error);
        }

        $saved = Yaml::parseFile($collection->path());
        $this->assertSame('Original', $saved['title']);
        $this->assertSame('original', $saved['template']);
    }

    public function test_update_takes_its_settings_in_data_and_refuses_the_v3_config_argument(): void
    {
        $schema = (new StructuresRouter)->facet('write')->toArray()['inputSchema'];
        $this->assertArrayNotHasKey('config', $schema['properties']);
        $this->assertNotContains('configure', $schema['properties']['action']['enum']);

        Nav::make('demo')->title('Original')->save();
        Stache::refresh();
        $result = (new StructuresRouter)->execute([
            'action' => 'update', 'resource_type' => 'navigation', 'handle' => 'demo',
            'config' => ['title' => 'Changed'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not take config', $result['errors'][0]);
        $this->assertSame('Original', Nav::find('demo')->title());
    }

    private function token(string $transport, TokenScope $scope = TokenScope::StructuresAdmin, bool $permitted = true): string
    {
        $permissions = $permitted
            ? ['configure collections', 'configure taxonomies', 'configure globals', 'edit demo nav']
            : [];
        Role::make()->handle('configurator')->title('Configurator')->permissions(['access cp', ...$permissions])->save();
        $user = User::make()->email('configurator@example.test');
        $user->assignRole('configurator');
        $user->save();

        if ($transport === 'pat') {
            return app(TokenService::class)->createToken((string) $user->id(), 'configure-test', [$scope])['token'];
        }

        $driver = app(OAuthDriver::class);
        $client = $driver->registerClient('Configure test', ['https://example.test/callback']);
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = $driver->createAuthCode(
            clientId: $client->clientId,
            userId: (string) $user->id(),
            scopes: [$scope->value],
            codeChallenge: $challenge,
            codeChallengeMethod: 'S256',
            redirectUri: 'https://example.test/callback',
        );

        return $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.test/callback',
            'client_id' => $client->clientId,
            'code_verifier' => $verifier,
        ])->assertOk()->json('access_token');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function updateOverHttp(array $arguments, ?string $token): TestResponse
    {
        return $this->postJson('/mcp/statamic', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'statamic-structures-admin',
                'arguments' => $arguments,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ], [
            ...($token === null ? [] : ['Authorization' => 'Bearer ' . $token]),
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'statamic-structures-admin',
        ]);
    }
}
