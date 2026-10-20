<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

/**
 * `destination` is documented as the asset's new path, filename included.
 * move handed it to Statamic as a *folder*, so "archive/hero.jpg" became
 * "archive/hero.jpg/hero.jpg"; copy called a method Statamic's Asset does
 * not have, so it never succeeded. The old tests asserted only that a
 * result came back.
 */
class AssetMoveCopyTest extends TestCase
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
        Storage::disk('assets')->put('hero.txt', 'hero');
        Asset::make()->container('images')->path('hero.txt')->set('alt', 'A hero')->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function relocate(string $action, string $destination): array
    {
        return $this->router->execute([
            'action' => $action,
            'resource_type' => 'asset',
            'container' => 'images',
            'path' => 'hero.txt',
            'destination' => $destination,
        ]);
    }

    public function test_move_puts_the_asset_at_the_destination_path(): void
    {
        $result = $this->relocate('move', 'archive/old-hero.txt');

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('archive/old-hero.txt', $result['data']['asset']['new_path']);
        $this->assertTrue(Storage::disk('assets')->exists('archive/old-hero.txt'));
        $this->assertFalse(Storage::disk('assets')->exists('hero.txt'));
    }

    public function test_move_to_a_folder_keeps_the_filename(): void
    {
        $result = $this->relocate('move', 'archive/');

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertTrue(Storage::disk('assets')->exists('archive/hero.txt'));
    }

    public function test_copy_leaves_the_original_and_copies_its_data(): void
    {
        $result = $this->relocate('copy', 'copies/hero-copy.txt');

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('copies/hero-copy.txt', $result['data']['copy']['path']);
        $this->assertTrue(Storage::disk('assets')->exists('hero.txt'));
        $this->assertSame('hero', Storage::disk('assets')->get('copies/hero-copy.txt'));
        $this->assertSame('A hero', Asset::find('images::copies/hero-copy.txt')?->get('alt'));
    }

    public function test_copy_refuses_to_overwrite(): void
    {
        Storage::disk('assets')->put('taken.txt', 'other');

        $result = $this->relocate('copy', 'taken.txt');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exists', $result['errors'][0]);
        $this->assertSame('other', Storage::disk('assets')->get('taken.txt'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function refusedDestinations(): array
    {
        return [
            'move to php' => ['move', 'hero.php', "keep the extension '.txt'"],
            'copy to php' => ['copy', 'hero.php', "keep the extension '.txt'"],
            'move out' => ['move', '../hero.txt', "'.' or '..'"],
            'copy out' => ['copy', 'a/../../hero.txt', "'.' or '..'"],
        ];
    }

    #[DataProvider('refusedDestinations')]
    public function test_a_destination_cannot_change_the_extension_or_leave_the_container(string $action, string $destination, string $message): void
    {
        $result = $this->relocate($action, $destination);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($message, $result['errors'][0]);
        $this->assertSame(['hero.txt'], array_values(array_filter(
            Storage::disk('assets')->allFiles(),
            fn (string $file): bool => ! str_contains($file, '.meta'),
        )));
    }
}
