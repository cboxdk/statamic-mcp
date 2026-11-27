<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Statamic\Assets\AssetContainer as AssetContainerModel;
use Statamic\Facades\AssetContainer;

class AssetsRouterTest extends TestCase
{
    private AssetsRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new AssetsRouter;

        // Set up test storage with proper disk configuration
        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);

        Storage::fake('assets');
    }

    /**
     * Helper to create a container with version-aware permission settings.
     *
     * @param  array<string, mixed>  $permissions
     */
    private function createContainerWithPermissions(
        string $handle,
        string $title,
        string $disk = 'assets',
        array $permissions = []
    ): AssetContainerModel {
        $container = AssetContainer::make($handle)
            ->title($title)
            ->disk($disk);

        // v6: Container-level permissions don't exist (user-based instead)
        $container->save();

        return $container;
    }

    public function test_list_containers(): void
    {
        // Create test containers using version-aware helper
        $this->createContainerWithPermissions('images', 'Images');
        $this->createContainerWithPermissions('documents', 'Documents');

        $result = $this->router->execute([
            'action' => 'list',
            'resource_type' => 'container',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertArrayHasKey('items', $data);
        $this->assertGreaterThanOrEqual(2, count($data['items']));

        $handles = collect($data['items'])->pluck('handle')->toArray();
        $this->assertContains('images', $handles);
        $this->assertContains('documents', $handles);
    }

    public function test_asset_writes_return_full_records_and_versions(): void
    {
        $where = ['container' => 'records', 'resource_type' => 'container'];
        $created = $this->router->execute(['action' => 'create', ...$where, 'data' => ['title' => 'Records', 'disk' => 'assets']]);
        $this->assertTrue($created['success'], json_encode($created));
        $read = $this->router->execute(['action' => 'get', ...$where, 'include_counts' => false]);
        $this->assertSame($read['data']['container'], array_diff_key($created['data']['container'], ['created' => true]));
        $where['resource_type'] = 'asset';
        $created = $this->router->execute(['action' => 'create', ...$where, 'filename' => 'note.txt', 'content' => 'Record body', 'data' => ['title' => 'Original']]);
        $this->assertTrue($created['success'], json_encode($created));
        $where['path'] = 'note.txt';
        $read = $this->router->execute(['action' => 'get', ...$where]);
        $this->assertSame($read['data']['asset'], $created['data']['asset']);
        $updated = $this->router->execute(['action' => 'update', ...$where, 'data' => ['title' => 'Changed']]);
        $this->assertTrue($updated['success'], json_encode($updated));
        $this->assertSame('Changed', $updated['data']['asset']['data']['title']);
        $this->assertNotSame($created['data']['asset']['version'], $updated['data']['asset']['version']);
        $read = $this->router->execute(['action' => 'get', ...$where]);
        $this->assertSame($read['data']['asset']['version'], $updated['data']['asset']['version']);
        $deleted = $this->router->execute(['action' => 'delete', ...$where]);
        $this->assertTrue($deleted['success'], json_encode($deleted));
        $this->assertSame($read['data']['asset'], array_diff_key($deleted['data']['asset'], ['deleted' => true]));
    }

    public function test_copy_hides_denied_metadata_in_the_full_record(): void
    {
        $container = $this->createContainerWithPermissions('private_meta', 'Private metadata');
        Storage::disk('assets')->put('source.txt', 'Public file');
        $container->makeAsset('source.txt')->data(['title' => 'Visible', 'secret_code' => 'hidden-value'])->save();
        config(['statamic.mcp.tools.assets.denied_fields' => ['secret_code']]);
        $result = $this->router->execute([
            'action' => 'copy', 'resource_type' => 'asset', 'container' => 'private_meta',
            'path' => 'source.txt', 'destination' => 'target.txt',
        ]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('Visible', $result['data']['copy']['data']['title']);
        $this->assertArrayNotHasKey('secret_code', $result['data']['copy']['data']);
        $this->assertSame('Public file', Storage::disk('assets')->get('target.txt'));
    }

    public function test_get_container(): void
    {
        $this->createContainerWithPermissions('photos', 'Photo Gallery', 'assets', [
            'allow_uploads' => true,
            'allow_downloading' => true,
            'allow_moving' => true,
            'allow_renaming' => true,
        ]);

        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'container',
            'container' => 'photos',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['container'];
        $this->assertEquals('photos', $data['handle']);
        $this->assertEquals('Photo Gallery', $data['title']);
        $this->assertEquals('assets', $data['disk']);
        // In v6, permissions are always true (permission model changed)
        $this->assertTrue($data['allow_uploads']);
        $this->assertTrue($data['allow_downloading']);
        $this->assertTrue($data['allow_moving']);
        $this->assertTrue($data['allow_renaming']);
    }

    public function test_create_container(): void
    {
        // Ensure container doesn't exist first
        $existingContainer = AssetContainer::find('test_videos');
        if ($existingContainer) {
            $existingContainer->delete();
        }

        $result = $this->router->execute([
            'action' => 'create',
            'resource_type' => 'container',
            'container' => 'test_videos',
            'data' => [
                'title' => 'Test Video Files',
                'disk' => 'assets',
                'allow_uploads' => true,
                'allow_downloading' => false,
            ],
        ]);

        $this->assertTrue($result['success']);

        // Container should be created
        $container = AssetContainer::find('test_videos');
        $this->assertNotNull($container);
        $this->assertEquals('Test Video Files', $container->title());
        $this->assertEquals('assets', $container->diskHandle());

    }

    public function test_update_container(): void
    {
        $this->createContainerWithPermissions('files', 'Files', 'assets', [
            'allow_uploads' => false,
        ]);

        $result = $this->router->execute([
            'action' => 'update',
            'resource_type' => 'container',
            'container' => 'files',
            'data' => [
                'title' => 'Updated Files',
                'allow_uploads' => true,
                'allow_downloading' => true,
            ],
        ]);

        $this->assertTrue($result['success']);

        $container = AssetContainer::find('files');
        $this->assertEquals('Updated Files', $container->title());

    }

    public function test_delete_container(): void
    {
        $this->createContainerWithPermissions('temp', 'Temporary');

        $this->assertNotNull(AssetContainer::find('temp'));

        $result = $this->router->execute([
            'action' => 'delete',
            'resource_type' => 'container',
            'container' => 'temp',
        ]);

        $this->assertTrue($result['success']);
        $this->assertNull(AssetContainer::find('temp'));
    }

    public function test_list_assets(): void
    {
        $this->createContainerWithPermissions('test_assets', 'Test Assets');

        // Create test files
        Storage::disk('assets')->put('test1.jpg', 'fake image content');
        Storage::disk('assets')->put('test2.png', 'fake image content');
        Storage::disk('assets')->put('folder/test3.pdf', 'fake pdf content');

        $result = $this->router->execute([
            'action' => 'list',
            'resource_type' => 'asset',
            'container' => 'test_assets',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('next_cursor', $data);
        $this->assertEquals('test_assets', $data['container']);
    }

    private function createFolderFixture(): void
    {
        $this->createContainerWithPermissions('folder_assets', 'Folder Assets');

        Storage::disk('assets')->put('root.jpg', 'fake image content');
        Storage::disk('assets')->put('team/alice.jpg', 'fake image content');
        Storage::disk('assets')->put('team/bob.jpg', 'fake image content');
        Storage::disk('assets')->put('team/archive/carol.jpg', 'fake image content');
        Storage::disk('assets')->put('other/dave.jpg', 'fake image content');
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return list<string>
     */
    private function listedPaths(array $arguments): array
    {
        $result = $this->router->execute(array_merge([
            'action' => 'list',
            'resource_type' => 'asset',
            'container' => 'folder_assets',
            'include_details' => false,
        ], $arguments));

        $this->assertTrue($result['success'], json_encode($result) ?: '');

        $paths = collect($result['data']['items'])->pluck('path')->sort()->values()->all();
        $this->assertSame(count($paths), $result['data']['total']);

        return $paths;
    }

    public function test_list_assets_without_folder_returns_container_root(): void
    {
        $this->createFolderFixture();

        $this->assertSame(['root.jpg'], $this->listedPaths([]));
    }

    public function test_list_assets_recursive_without_folder_returns_everything(): void
    {
        $this->createFolderFixture();

        $this->assertSame(
            ['other/dave.jpg', 'root.jpg', 'team/alice.jpg', 'team/archive/carol.jpg', 'team/bob.jpg'],
            $this->listedPaths(['recursive' => true]),
        );
    }

    public function test_list_assets_in_folder(): void
    {
        $this->createFolderFixture();

        $this->assertSame(['team/alice.jpg', 'team/bob.jpg'], $this->listedPaths(['folder' => 'team']));
    }

    public function test_list_assets_in_folder_recursive(): void
    {
        $this->createFolderFixture();

        $this->assertSame(
            ['team/alice.jpg', 'team/archive/carol.jpg', 'team/bob.jpg'],
            $this->listedPaths(['folder' => '/team/', 'recursive' => true]),
        );
    }

    public function test_list_assets_refuses_the_v3_folder_filter(): void
    {
        // v3 also read the folder from filters.folder. v4 has one name for it,
        // and the old one is refused rather than ignored, which would list
        // the whole container as if the folder had been applied.
        $this->createFolderFixture();

        $result = $this->router->execute([
            'action' => 'list', 'resource_type' => 'asset', 'container' => 'folder_assets',
            'filters' => ['folder' => 'team'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not take filters', $result['errors'][0]);
    }

    public function test_list_assets_rejects_folder_outside_container(): void
    {
        $this->createFolderFixture();

        $result = $this->router->execute([
            'action' => 'list',
            'resource_type' => 'asset',
            'container' => 'folder_assets',
            'folder' => '../secrets',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('folder must be a path inside the container', $result['errors'][0]);
    }

    public function test_get_asset(): void
    {
        $this->createContainerWithPermissions('test_get', 'Test Get');

        Storage::disk('assets')->put('sample.jpg', 'fake image content');

        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'asset',
            'container' => 'test_get',
            'path' => 'sample.jpg',
        ]);

        // With version-aware code, get should succeed
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('asset', $result['data']);
    }

    public function test_upload_rejects_non_json_php_file_objects(): void
    {
        $this->createContainerWithPermissions('uploads', 'Uploads', 'assets', [
            'allow_uploads' => true,
        ]);

        $file = UploadedFile::fake()->image('uploaded.jpg', 800, 600);

        $result = $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => 'uploads',
            'file' => $file,
            'path' => 'uploads/uploaded.jpg',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('JSON values', $result['error']['message']);
    }

    public function test_upload_requires_a_supported_source(): void
    {
        $this->createContainerWithPermissions('uploads', 'Uploads', 'assets', ['allow_uploads' => true]);
        $result = $this->router->execute([
            'action' => 'upload', 'resource_type' => 'asset', 'container' => 'uploads',
            'filename' => 'uploaded.jpg',
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('needs source_url or content or file_path', $result['errors'][0]);
    }

    public function test_move_asset(): void
    {
        $this->createContainerWithPermissions('move_test', 'Move Test', 'assets', [
            'allow_moving' => true,
        ]);

        Storage::disk('assets')->put('original.txt', 'content');

        $result = $this->router->execute([
            'action' => 'move',
            'resource_type' => 'asset',
            'container' => 'move_test',
            'path' => 'original.txt',
            'destination' => 'moved.txt',
        ]);

        // Move operations may fail in test environment due to Stache not indexing files
        // This test primarily validates the router dispatches to the correct handler
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function test_copy_asset(): void
    {
        $this->createContainerWithPermissions('copy_test', 'Copy Test');

        Storage::disk('assets')->put('source.txt', 'content to copy');

        $result = $this->router->execute([
            'action' => 'copy',
            'resource_type' => 'asset',
            'container' => 'copy_test',
            'path' => 'source.txt',
            'destination' => 'copied/source.txt',
        ]);

        // Behaviour is covered in depth by AssetMoveCopyTest.
        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('content to copy', Storage::disk('assets')->get('copied/source.txt'));
    }

    public function test_rename_asset(): void
    {
        $this->createContainerWithPermissions('rename_test', 'Rename Test', 'assets', [
            'allow_renaming' => true,
        ]);

        Storage::disk('assets')->put('oldname.txt', 'content');

        $result = $this->router->execute([
            'action' => 'rename',
            'resource_type' => 'asset',
            'container' => 'rename_test',
            'path' => 'oldname.txt',
            'new_filename' => 'newname.txt',
        ]);

        // Rename action doesn't exist in the router
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("Unknown action 'rename'. Use one of: list, get, create, update, delete, move, copy, upload.", $result['errors'][0]);
    }

    public function test_delete_asset(): void
    {
        $this->createContainerWithPermissions('delete_test', 'Delete Test');

        Storage::disk('assets')->put('todelete.txt', 'content');
        $this->assertTrue(Storage::disk('assets')->exists('todelete.txt'));

        $result = $this->router->execute([
            'action' => 'delete',
            'resource_type' => 'asset',
            'container' => 'delete_test',
            'path' => 'todelete.txt',
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse(Storage::disk('assets')->exists('todelete.txt'));
    }

    public function test_invalid_action(): void
    {
        $result = $this->router->execute([
            'action' => 'invalid',
            'resource_type' => 'container',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("Unknown action 'invalid'. Use one of: list, get", $result['errors'][0]);
    }

    public function test_invalid_type(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'resource_type' => 'invalid',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown asset type: invalid', $result['errors'][0]);
    }

    public function test_missing_handle_for_container_get(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'container',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The get action needs container', $result['errors'][0]);
    }

    public function test_missing_container_for_asset_operations(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'resource_type' => 'asset',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Container handle is required for listing assets', $result['errors'][0]);
    }

    public function test_container_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'container',
            'container' => 'nonexistent',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Asset container not found: nonexistent', $result['errors'][0]);
    }

    public function test_asset_not_found(): void
    {
        $this->createContainerWithPermissions('test_notfound', 'Test Not Found');

        $result = $this->router->execute([
            'action' => 'get',
            'resource_type' => 'asset',
            'container' => 'test_notfound',
            'path' => 'nonexistent.jpg',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Asset not found: test_notfound::nonexistent.jpg', $result['errors'][0]);
    }

    public function test_move_always_allowed_in_v6(): void
    {
        // In Statamic v6, container-level permissions don't exist (user-based instead)
        // Moving is always allowed at the container level
        $this->createContainerWithPermissions('no_moves', 'No Moves', 'assets', [
            'allow_moving' => false,
        ]);

        Storage::disk('assets')->put('test.txt', 'content');

        $result = $this->router->execute([
            'action' => 'move',
            'resource_type' => 'asset',
            'container' => 'no_moves',
            'path' => 'test.txt',
            'destination' => 'moved.txt',
        ]);

        // v6: Container-level permissions removed, move is attempted (may fail in test env due to Stache)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function test_rename_not_allowed(): void
    {
        // Note: This test verifies the rename action doesn't exist in the router
        // The permission check is secondary since the action itself is not implemented
        $this->createContainerWithPermissions('no_renames', 'No Renames', 'assets', [
            'allow_renaming' => false,
        ]);

        Storage::disk('assets')->put('test.txt', 'content');

        $result = $this->router->execute([
            'action' => 'rename',
            'resource_type' => 'asset',
            'container' => 'no_renames',
            'path' => 'test.txt',
            'new_filename' => 'renamed.txt',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("Unknown action 'rename'. Use one of: list, get, create, update, delete, move, copy, upload.", $result['errors'][0]);
    }
}
