<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;

class BlueprintAndFilePathSafetyTest extends TestCase
{
    private const FIELDS = [['handle' => 'title', 'type' => 'text']];

    public function test_blueprint_handle_cannot_escape_its_namespace_directory(): void
    {
        $result = (new BlueprintsRouter)->execute([
            'action' => 'create', 'namespace' => 'globals', 'handle' => '../evil', 'fields' => self::FIELDS,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid handle', (string) ($result['errors'][0] ?? ''));
    }

    public function test_blueprint_namespace_must_be_known(): void
    {
        $result = (new BlueprintsRouter)->execute([
            'action' => 'create', 'namespace' => 'not_a_namespace', 'handle' => 'ok_handle', 'fields' => self::FIELDS,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid namespace', (string) ($result['errors'][0] ?? ''));
    }

    public function test_a_valid_blueprint_handle_passes_the_location_check(): void
    {
        // The validator must not reject a legitimate handle/namespace. Whether
        // the create ultimately succeeds depends on field shape etc.; here we
        // only assert it is not stopped by the location guard.
        $result = (new BlueprintsRouter)->execute([
            'action' => 'create', 'namespace' => 'collections', 'collection_handle' => 'blog', 'handle' => 'legit_handle', 'fields' => self::FIELDS,
        ]);

        $error = (string) ($result['errors'][0] ?? '');
        $this->assertStringNotContainsString('Invalid handle', $error);
        $this->assertStringNotContainsString('Invalid namespace', $error);
        $this->assertStringNotContainsString('Invalid collection_handle', $error);
    }

    public function test_file_path_upload_is_refused_for_remote_clients(): void
    {
        config(['statamic.mcp.security.force_web_mode' => true]);
        config(['filesystems.disks.assets' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/assets')]]);
        AssetContainer::make('images')->title('Images')->disk('assets')->save();

        $user = User::make()->id('super-1')->email('super@example.test')->makeSuper();
        $user->save();
        $this->actingAs($user);

        @mkdir(storage_path('app'), 0777, true);
        file_put_contents(storage_path('app/leak.txt'), 'private bytes');

        $result = (new AssetsRouter)->execute([
            'action' => 'upload', 'resource_type' => 'asset', 'container' => 'images',
            'file_path' => storage_path('app/leak.txt'), 'filename' => 'copied.txt',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('local CLI', (string) ($result['errors'][0] ?? ''));

        @unlink(storage_path('app/leak.txt'));
    }
}
