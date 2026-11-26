<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Search;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Search\Index;

class SystemSearchTest extends TestCase
{
    private string $token;

    private string $entryId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true, 'statamic.revisions.enabled' => false]);
        Site::setSites(['en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
            'da' => ['name' => 'Danish', 'url' => '/da', 'locale' => 'da_DK']]);
        foreach (['public', 'private'] as $handle) {
            Collection::make($handle)->sites(['en', 'da'])->save();
            Blueprint::make('post')->setNamespace('collections.' . $handle)->setContents(['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'secret', 'field' => ['type' => 'text']],
            ]])->save();
            foreach (['en', 'da'] as $site) {
                $entry = Entry::make()->collection($handle)->locale($site)->slug('search-' . $handle . '-' . $site)
                    ->published(true)->data(['title' => 'Findable ' . $handle . ' ' . $site, 'secret' => 'classified']);
                $entry->save();
                if ($handle === 'public' && $site === 'en') {
                    $this->entryId = $entry->id();
                }
            }
        }
        config(['statamic.search.indexes.mcp' => ['driver' => 'local', 'searchables' => ['collection:public', 'collection:private'],
            'fields' => ['title', 'secret'], 'path' => config('statamic.forms.forms') . '/search']]);
        Search::index('mcp')->update();
        Role::make()->handle('searcher')->permissions(['access cp', 'access utilities', 'access en site', 'view public entries', 'edit public entries'])->save();
        $user = User::make()->email('search@example.test')->assignRole('searcher');
        $user->save();
        $this->token = app(TokenService::class)->createToken($user->id(), 'Search', ['system:read', 'entries:read', 'entries:write'])['token'];
    }

    private function callTool(string $name, array $arguments)
    {
        return $this->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments,
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []]]],
            ['Authorization' => 'Bearer ' . $this->token, 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name]);
    }

    private function search(string $query = 'Findable')
    {
        return $this->callTool('statamic-system-read', ['action' => 'search', 'handle' => 'mcp', 'query' => $query]);
    }

    public function test_search_applies_content_scope_permissions_site_resource_and_field_policies(): void
    {
        $this->assertCount(4, Search::index('mcp')->lookup('Findable'));
        $this->search()->assertJsonPath('result.structuredContent.data.total', 1)
            ->assertJsonPath('result.structuredContent.data.items.0.id', $this->entryId);
        config(['statamic.mcp.tools.entries.resources.read' => ['private']]);
        $this->search()->assertJsonPath('result.structuredContent.data.total', 0);
        config(['statamic.mcp.tools.entries.resources.read' => null, 'statamic.mcp.tools.entries.denied_fields' => ['secret']]);
        $this->search('classified')->assertJsonPath('result.structuredContent.data.items', []);
        config(['statamic.mcp.tools.entries.denied_fields' => [], 'statamic.mcp.tools.entries.enabled' => false]);
        $this->search()->assertJsonPath('result.structuredContent.data.items', []);
        config(['statamic.mcp.tools.entries.enabled' => true]);
        $user = User::findByEmail('search@example.test');
        $this->token = app(TokenService::class)->createToken($user->id(), 'System only', ['system:read'])['token'];
        $this->search()->assertJsonPath('result.structuredContent.data.items', []);
    }

    public function test_mcp_writes_update_native_search_and_reindex_requires_approval(): void
    {
        $this->callTool('statamic-entries-write', ['action' => 'update', 'collection' => 'public', 'id' => $this->entryId,
            'data' => ['title' => 'Replacement']])->assertJsonPath('result.structuredContent.success', true);
        $this->search('Replacement')->assertJsonPath('result.structuredContent.data.items.0.id', $this->entryId);
        $this->search('Findable')->assertJsonPath('result.structuredContent.data.total', 0);
        Search::index('mcp')->deleteIndex();
        $this->search()->assertJsonPath('result.structuredContent.error.code', 'NOT_FOUND');
        $this->assertFalse(Search::index('mcp')->exists());
        $user = User::findByEmail('search@example.test')->makeSuper();
        $user->save();
        $this->token = app(TokenService::class)->createToken($user->id(), 'Reindex', ['system:admin'])['token'];
        config(['statamic.mcp.confirmation.enabled' => true]);
        $args = ['action' => 'reindex', 'handle' => 'mcp', 'idempotency_key' => 'rebuild-once'];
        $this->callTool('statamic-system-admin', $args)->assertJsonPath('result.structuredContent.error.code', 'APPROVAL_REQUIRED');
        $this->assertFalse(Search::index('mcp')->exists());
        config(['statamic.mcp.confirmation.enabled' => false]);
        $this->callTool('statamic-system-admin', $args)->assertJsonPath('result.structuredContent.data.status', 'submitted');
        $this->assertCount(1, Search::index('mcp')->lookup('Replacement'));
        $this->callTool('statamic-system-admin', $args)->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
    }

    public function test_search_includes_assets_an_editor_may_view_but_not_other_containers(): void
    {
        Storage::fake('assets');
        foreach (['images', 'private_images'] as $handle) {
            $container = AssetContainer::make($handle)->disk('assets');
            $container->save();
            $asset = $container->makeAsset('findable.txt');
            Storage::disk('assets')->put('findable.txt', 'content');
            $asset->save();
        }
        config(['statamic.search.indexes.assets' => ['driver' => 'local', 'searchables' => ['assets:images', 'assets:private_images'],
            'fields' => ['basename'], 'path' => config('statamic.forms.forms') . '/asset-search']]);
        Search::index('assets')->update();
        Role::find('searcher')->addPermission('view images assets')->save();
        $user = User::findByEmail('search@example.test');
        $this->token = app(TokenService::class)->createToken($user->id(), 'Assets', ['system:read', 'assets:read'])['token'];
        $this->assertCount(2, Search::index('assets')->lookup('findable'));
        $this->callTool('statamic-system-read', ['action' => 'search', 'handle' => 'assets', 'query' => 'findable'])
            ->assertJsonPath('result.structuredContent.data.total', 1)
            ->assertJsonPath('result.structuredContent.data.items.0.container', 'images');
    }

    public function test_search_refuses_a_provider_without_complete_result_pagination(): void
    {
        $index = \Mockery::mock(Index::class);
        $index->shouldReceive('handle')->andReturn('hosted');
        $index->shouldReceive('search')->never();
        Search::shouldReceive('indexes')->andReturn(collect([$index]));
        $this->callTool('statamic-system-read', ['action' => 'search', 'handle' => 'hosted', 'query' => 'needle'])
            ->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
    }
}
