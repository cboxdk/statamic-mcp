<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Every write handler in statamic-assets re-checked a permission Statamic does
 * not have ("create assets", "edit assets", "create asset_containers"), so a
 * non-super editor with the real per-container permissions passed the outer
 * check and was refused by the inner one. Container discovery also demanded
 * "configure asset containers" and, once allowed, listed every container (#78).
 */
class AssetPermissionBoundaryTest extends TestCase
{
    private AssetsRouter $router;

    private string $allowed;

    private string $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        config(['statamic.mcp.security.force_web_mode' => true]);
        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);
        Storage::fake('assets');

        $this->router = new AssetsRouter;
        $this->allowed = 'media-' . bin2hex(random_bytes(4));
        $this->other = 'docs-' . bin2hex(random_bytes(4));

        AssetContainer::make($this->allowed)->title('Media')->disk('assets')->save();
        AssetContainer::make($this->other)->title('Docs')->disk('assets')->save();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actAs(array $permissions): void
    {
        $handle = 'role-' . bin2hex(random_bytes(4));
        Role::make()->handle($handle)->title('Role')->permissions(['access cp', ...$permissions])->save();
        $user = User::make()->id('u-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        $user->assignRole($handle);
        $user->save();
        $this->actingAs($user);
    }

    /**
     * @return array<int, string>
     */
    private function listedHandles(): array
    {
        $result = $this->router->execute(['action' => 'list', 'resource_type' => 'container', 'include_details' => false]);
        $this->assertTrue($result['success'], json_encode($result) ?: '');

        return collect($result['data']['containers'])->pluck('handle')->all();
    }

    public function test_editor_lists_only_the_containers_they_can_view(): void
    {
        $this->actAs(["view {$this->allowed} assets"]);

        $handles = $this->listedHandles();

        $this->assertContains($this->allowed, $handles);
        $this->assertNotContains($this->other, $handles);
    }

    public function test_container_list_honours_the_resource_policy_for_privileged_callers(): void
    {
        config(['statamic.mcp.tools.assets.resources.read' => [$this->allowed]]);
        $this->actAs(['configure asset containers']);

        $handles = $this->listedHandles();

        $this->assertContains($this->allowed, $handles);
        $this->assertNotContains($this->other, $handles);
    }

    public function test_get_container_checks_the_container_it_returns(): void
    {
        $this->actAs(["view {$this->allowed} assets"]);

        // `container` names an allowed container, `handle` the one returned.
        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'container',
            'container' => $this->allowed,
            'handle' => $this->other,
        ]);

        $this->assertFalse($result['success']);

        $result = $this->router->execute(['action' => 'get', 'resource_type' => 'container', 'handle' => $this->allowed]);
        $this->assertTrue($result['success'], json_encode($result) ?: '');
    }

    public function test_editor_with_upload_permission_can_upload(): void
    {
        $this->actAs(["view {$this->allowed} assets", "upload {$this->allowed} assets"]);

        $result = $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => $this->allowed,
            'filename' => 'note.txt',
            'content' => base64_encode('hello'),
        ]);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertNotNull(Asset::find("{$this->allowed}::note.txt"));
    }

    public function test_upload_permission_on_one_container_does_not_reach_another(): void
    {
        $this->actAs(["upload {$this->allowed} assets"]);

        $result = $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => $this->other,
            'filename' => 'note.txt',
            'content' => base64_encode('hello'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertNull(Asset::find("{$this->other}::note.txt"));
    }

    public function test_editor_with_edit_and_delete_permissions_can_update_and_delete(): void
    {
        $this->actAs(["upload {$this->allowed} assets", "edit {$this->allowed} assets", "delete {$this->allowed} assets"]);

        $upload = $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => $this->allowed,
            'filename' => 'note.txt',
            'content' => base64_encode('hello'),
        ]);
        $this->assertTrue($upload['success'], json_encode($upload) ?: '');

        $update = $this->router->execute([
            'action' => 'update',
            'resource_type' => 'asset',
            'container' => $this->allowed,
            'path' => 'note.txt',
            'data' => ['alt' => 'A note'],
        ]);
        $this->assertTrue($update['success'], json_encode($update) ?: '');

        $delete = $this->router->execute([
            'action' => 'delete',
            'resource_type' => 'asset',
            'container' => $this->allowed,
            'path' => 'note.txt',
        ]);
        $this->assertTrue($delete['success'], json_encode($delete) ?: '');
    }

    public function test_configure_asset_containers_can_create_a_container(): void
    {
        $this->actAs(['configure asset containers']);
        $handle = 'new-' . bin2hex(random_bytes(4));

        $result = $this->router->execute([
            'action' => 'create',
            'resource_type' => 'container',
            'data' => ['handle' => $handle, 'title' => 'New', 'disk' => 'assets'],
        ]);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertNotNull(AssetContainer::find($handle));
    }
}
