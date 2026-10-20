<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\AssetContainer;

/**
 * Every way of putting a file into a container goes through Statamic's
 * AllowedFile rule, as a Control Panel upload does. Without it a token with
 * assets:write could write `shell.php` into a public container — on a
 * typical PHP host, code execution.
 */
class AssetUploadSafetyTest extends TestCase
{
    private AssetsRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new AssetsRouter;

        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);

        Storage::fake('assets');

        AssetContainer::make('images')->title('Images')->disk('assets')->save();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function writeActions(): array
    {
        return ['upload' => ['upload'], 'create' => ['create']];
    }

    #[DataProvider('writeActions')]
    public function test_a_php_file_is_refused(string $action): void
    {
        $result = $this->router->execute([
            'action' => $action,
            'resource_type' => 'asset',
            'container' => 'images',
            'filename' => 'shell.php',
            'content' => '<?php echo "owned";',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("'.php' cannot be uploaded", $result['errors'][0]);
        $this->assertSame([], Storage::disk('assets')->allFiles());
    }

    #[DataProvider('writeActions')]
    public function test_a_file_without_an_extension_is_refused(string $action): void
    {
        $result = $this->router->execute([
            'action' => $action,
            'resource_type' => 'asset',
            'container' => 'images',
            'filename' => 'htaccess',
            'content' => 'AddType application/x-httpd-php .jpg',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame([], Storage::disk('assets')->allFiles());
    }

    public function test_a_site_can_allow_an_extra_extension(): void
    {
        config(['statamic.assets.additional_uploadable_extensions' => ['ics']]);

        $result = $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => 'images',
            'filename' => 'event.ics',
            'content' => 'BEGIN:VCALENDAR',
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
    }

    public function test_an_ordinary_image_still_uploads(): void
    {
        $png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $result = $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => 'images',
            'filename' => 'pixel.png',
            'content' => $png,
            'encoding' => 'base64',
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertTrue(Storage::disk('assets')->exists('pixel.png'));
    }

    public function test_upload_writes_into_a_folder(): void
    {
        $result = $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => 'images',
            'folder' => 'uploads/cases',
            'filename' => 'notes.txt',
            'content' => 'hello',
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('uploads/cases/notes.txt', $result['data']['asset']['path']);
        $this->assertTrue(Storage::disk('assets')->exists('uploads/cases/notes.txt'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badFolders(): array
    {
        return [
            'parent' => ['../outside'],
            'nested parent' => ['uploads/../../outside'],
            'current' => ['./uploads'],
            'backslash' => ['uploads\\..\\x'],
        ];
    }

    #[DataProvider('badFolders')]
    public function test_a_folder_cannot_climb_out_of_the_container(string $folder): void
    {
        $result = $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => 'images',
            'folder' => $folder,
            'filename' => 'notes.txt',
            'content' => 'hello',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('folder', $result['errors'][0]);
        $this->assertSame([], Storage::disk('assets')->allFiles());
    }
}
