<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;

/**
 * upload with source_url: the server fetches the file, so a remote client
 * can add an image without sending megabytes of base64 (#67).
 *
 * The URLs use a public IP literal, so no test depends on DNS.
 */
class AssetUrlImportTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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
     * @param  array<string, mixed>  $extra
     *
     * @return array<string, mixed>
     */
    private function import(string $url, array $extra = []): array
    {
        return $this->router->execute([
            'action' => 'upload',
            'resource_type' => 'asset',
            'container' => 'images',
            'source_url' => $url,
            ...$extra,
        ]);
    }

    public function test_it_imports_an_image_into_a_folder(): void
    {
        Http::fake(['https://93.184.216.34/photos/client-banner.png' => Http::response((string) base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);

        $result = $this->import('https://93.184.216.34/photos/client-banner.png', ['folder' => 'uploads/cases', 'data' => ['alt' => 'Banner']]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('uploads/cases/client-banner.png', $result['data']['asset']['path']);
        $this->assertSame('Banner', $result['data']['asset']['data']['alt']);
        $this->assertSame(base64_decode(self::PNG), Storage::disk('assets')->get('uploads/cases/client-banner.png'));
    }

    public function test_a_filename_overrides_the_url(): void
    {
        Http::fake(['*' => Http::response((string) base64_decode(self::PNG))]);

        $result = $this->import('https://93.184.216.34/image?id=42', ['filename' => 'hero.png']);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertTrue(Storage::disk('assets')->exists('hero.png'));
    }

    public function test_a_url_without_a_filename_needs_one(): void
    {
        Http::fake();

        $result = $this->import('https://93.184.216.34/image?id=42');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('pass filename', $result['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_the_extension_allow_list_applies(): void
    {
        Http::fake(['*' => Http::response('<?php echo 1;')]);

        $result = $this->import('https://93.184.216.34/shell.php');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("'.php' cannot be uploaded", $result['errors'][0]);
        $this->assertSame([], Storage::disk('assets')->allFiles());
        Http::assertNothingSent();
    }

    public function test_the_server_network_is_off_limits(): void
    {
        Http::fake();

        $result = $this->import('http://169.254.169.254/latest/meta-data/iam.png');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not resolve to a public address', $result['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_the_upload_size_limit_applies(): void
    {
        config(['statamic.mcp.security.max_upload_size' => 10]);
        Http::fake(['*' => Http::response(str_repeat('x', 100))]);

        $result = $this->import('https://93.184.216.34/big.png');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('larger than the 10-byte limit', $result['errors'][0]);
    }

    public function test_a_site_can_limit_the_hosts(): void
    {
        config(['statamic.mcp.security.url_import.allowed_hosts' => ['*.example-cdn.com']]);
        Http::fake();

        $result = $this->import('https://93.184.216.34/a.png');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('list of hosts files may be fetched from', $result['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_a_site_can_switch_it_off(): void
    {
        config(['statamic.mcp.security.url_import.enabled' => false]);
        Http::fake();

        $result = $this->import('https://93.184.216.34/a.png');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('switched off', $result['errors'][0]);
        Http::assertNothingSent();
    }

    public function test_one_source_at_a_time(): void
    {
        $result = $this->import('https://93.184.216.34/a.png', ['content' => 'x', 'filename' => 'a.txt']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not several', $result['errors'][0]);
    }
}
