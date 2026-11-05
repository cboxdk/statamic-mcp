<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class CollectionViewerPermissionTest extends TestCase
{
    private StructuresRouter $router;

    private string $handle;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic.editions.pro' => true,
            'statamic.system.multisite' => true,
            'statamic.mcp.security.force_web_mode' => true,
            'statamic.mcp.web.enabled' => true,
            'statamic.mcp.confirmation.enabled' => false,
        ]);
        Site::setSites([
            'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
            'private' => ['name' => 'Private', 'locale' => 'da_DK', 'url' => '/private/'],
        ]);
        $this->router = new StructuresRouter;
        $this->handle = 'viewer_' . bin2hex(random_bytes(4));
        Collection::make($this->handle)
            ->title('Articles')
            ->sites(['default', 'private'])
            ->dated(true)
            ->futureDateBehavior('private')
            ->pastDateBehavior('public')
            ->template('articles/show')
            ->layout('private-layout')
            ->save();
        Blueprint::make('article')->setNamespace('collections.' . $this->handle)
            ->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $scopes
     */
    private function actAs(array $permissions, array $scopes = ['structures:read'], bool $super = false, bool $siteAccess = true): void
    {
        $role = 'viewer-role-' . bin2hex(random_bytes(4));
        if ($siteAccess) {
            $permissions[] = 'access default site';
        }
        Role::make()->handle($role)->title('Role')->permissions(['access cp', ...$permissions])->save();
        $id = 'viewer-' . bin2hex(random_bytes(4));
        $user = User::make()->id($id)->email($id . '@example.test');
        $user->assignRole($role);
        if ($super) {
            $user->makeSuper();
        }
        $user->save();
        $this->actingAs($user);
        request()->attributes->set('mcp_token', new McpTokenData(
            'viewer-token', $id, 'Viewer', '', $scopes, null, null, now(),
        ));
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @return array<string, mixed>
     */
    private function getCollection(array $extra = []): array
    {
        return $this->router->execute($extra + [
            'action' => 'get', 'resource_type' => 'collection', 'handle' => $this->handle,
        ]);
    }

    public function test_entry_viewer_gets_editing_metadata_without_admin_configuration_or_site_totals(): void
    {
        $this->actAs(["view {$this->handle} entries"]);

        $result = $this->getCollection(['include_counts' => true, 'include_details' => true]);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertSame([
            'handle' => $this->handle,
            'title' => 'Articles',
            'blueprints' => ['article'],
            'dated' => true,
            'orderable' => false,
            'past_date_behavior' => 'public',
            'future_date_behavior' => 'private',
            'default_status' => true,
        ], $result['data']['collection']);
    }

    public function test_collection_admin_and_super_keep_the_full_response(): void
    {
        foreach ([false, true] as $super) {
            $this->actAs($super ? [] : ['configure collections'], super: $super, siteAccess: false);

            $result = $this->getCollection();

            $this->assertTrue($result['success'], json_encode($result) ?: '');
            $this->assertSame(['default', 'private'], $result['data']['collection']['sites']);
            $this->assertSame('articles/show', $result['data']['collection']['template']);
            $this->assertSame('private-layout', $result['data']['collection']['layout']);
            $this->assertSame(0, $result['data']['collection']['entry_count']);
        }
    }

    public function test_local_cli_keeps_the_full_response(): void
    {
        config(['statamic.mcp.security.force_web_mode' => false]);

        $result = $this->getCollection();

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertSame(['default', 'private'], $result['data']['collection']['sites']);
        $this->assertSame('articles/show', $result['data']['collection']['template']);
        $this->assertArrayHasKey('entry_count', $result['data']['collection']);
    }

    public function test_entry_viewer_cannot_read_a_collection_without_access_to_any_of_its_sites(): void
    {
        $this->actAs(["view {$this->handle} entries"], siteAccess: false);

        $result = $this->getCollection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('site restriction', (string) ($result['errors'][0] ?? ''));
        $this->assertArrayNotHasKey('collection', $result['data'] ?? []);
    }

    public function test_entry_viewer_can_list_only_accessible_collection_metadata(): void
    {
        $this->actAs(["view {$this->handle} entries"]);
        Collection::make('hidden_collection')->title('Hidden')->sites(['default'])->save();
        $result = $this->router->execute(['action' => 'list', 'resource_type' => 'collection', 'limit' => 1]);
        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame($this->handle, $result['data']['collections'][0]['handle']);
        $this->assertSame(['handle', 'title', 'blueprint'], array_keys($result['data']['collections'][0]));
        config(['statamic.mcp.tools.structures.resources.read' => ['hidden_collection']]);
        $result = $this->router->execute(['action' => 'list', 'resource_type' => 'collection']);
        $this->assertSame(0, $result['data']['total']);
        $this->assertSame([], $result['data']['collections']);
    }

    public function test_entry_viewer_still_cannot_write_collection_settings(): void
    {
        $this->actAs(["view {$this->handle} entries"], ['structures:read', 'structures:write']);

        foreach (['create', 'update', 'configure', 'delete'] as $action) {
            $result = $this->getCollection(['action' => $action, 'data' => ['title' => 'Changed']]);

            $this->assertFalse($result['success'], $action . ' must require configure collections');
            $this->assertStringContainsString('Permission denied', (string) ($result['errors'][0] ?? ''));
        }
        $this->assertSame('Articles', Collection::find($this->handle)?->title());
    }

    public function test_entry_viewer_needs_the_scope_and_permission_on_the_requested_collection(): void
    {
        $this->actAs(["view {$this->handle} entries"], ['entries:read']);
        $result = $this->getCollection();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Token missing required scope', (string) ($result['errors'][0] ?? ''));

        $this->actAs(['view other_collection entries']);
        $result = $this->getCollection();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Permission denied', (string) ($result['errors'][0] ?? ''));
    }

    public function test_entry_viewer_remains_subject_to_resource_and_field_policies(): void
    {
        $this->actAs(["view {$this->handle} entries"]);
        config(['statamic.mcp.tools.structures.resources.read' => ['some_other_collection']]);
        $result = $this->getCollection(['data' => ['handle' => 'some_other_collection']]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('resource policy', (string) ($result['errors'][0] ?? ''));

        config([
            'statamic.mcp.tools.structures.resources.read' => [$this->handle],
            'statamic.mcp.tools.structures.denied_fields' => ['blueprints'],
        ]);
        $result = $this->getCollection();
        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertArrayNotHasKey('blueprints', $result['data']['collection']);
    }
}
