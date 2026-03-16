<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;
use Statamic\Globals\GlobalSet as GlobalSetModel;

/**
 * Edge case and validation tests for GlobalsRouter.
 * Core get/update operations are also tested in ContentRouterTest.
 */
class GlobalsRouterTest extends TestCase
{
    private GlobalsRouter $router;

    private string $testId;

    private string $globalHandle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new GlobalsRouter;
        $this->testId = bin2hex(random_bytes(8));

        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);

        Storage::fake('assets');

        $this->globalHandle = "config-{$this->testId}";
        GlobalSet::make($this->globalHandle)
            ->title('Config')
            ->save();

        Blueprint::make($this->globalHandle)
            ->setNamespace('globals')
            ->setContents([
                'title' => 'Config',
                'sections' => [
                    'main' => [
                        'fields' => [
                            ['handle' => 'site_title', 'field' => ['type' => 'text', 'display' => 'Site Title']],
                            ['handle' => 'tagline', 'field' => ['type' => 'text', 'display' => 'Tagline']],
                        ],
                    ],
                ],
            ])
            ->save();

        Stache::refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function setGlobalValues(GlobalSetModel $globalSet, string $site, array $data): void
    {
        $localization = $globalSet->in($site);
        if ($localization === null) {
            $localization = $globalSet->makeLocalization($site);
        }
        $localization->data($data);
        $localization->save();
    }

    public function test_list_globals(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('globals', $result['data']);
        $this->assertArrayHasKey('pagination', $result['data']);

        $handles = collect($result['data']['globals'])->pluck('handle')->toArray();
        $this->assertContains($this->globalHandle, $handles);
    }

    public function test_list_globals_with_pagination(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'limit' => 1,
            'offset' => 0,
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['globals']);
        $this->assertArrayHasKey('total', $result['data']['pagination']);
    }

    public function test_get_global_includes_blueprint_info(): void
    {
        $globalSet = GlobalSet::find($this->globalHandle);
        $this->setGlobalValues($globalSet, 'default', ['site_title' => 'Test Site']);

        $result = $this->router->execute([
            'action' => 'get',
            'handle' => $this->globalHandle,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('blueprint', $result['data']['global']);
        $this->assertEquals($this->globalHandle, $result['data']['global']['blueprint']['handle']);
    }

    public function test_get_global_with_global_set_param(): void
    {
        $globalSet = GlobalSet::find($this->globalHandle);
        $this->setGlobalValues($globalSet, 'default', ['site_title' => 'Via Global Set Param']);

        $result = $this->router->execute([
            'action' => 'get',
            'global_set' => $this->globalHandle,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals($this->globalHandle, $result['data']['global']['handle']);
        $this->assertEquals('Via Global Set Param', $result['data']['global']['data']['site_title']);
    }

    public function test_missing_handle_for_get_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Global set handle is required', $result['errors'][0]);
    }

    public function test_missing_handle_for_update_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'data' => ['site_title' => 'Test'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Global set handle is required', $result['errors'][0]);
    }

    public function test_missing_data_for_update_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'handle' => $this->globalHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Data is required for update action', $result['errors'][0]);
    }

    public function test_nonexistent_global_set_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'handle' => 'nonexistent-global',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Global set not found: nonexistent-global', $result['errors'][0]);
    }

    public function test_invalid_action_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('delete', $result['errors'][0]);
    }

    public function test_update_returns_updated_fields_list(): void
    {
        $globalSet = GlobalSet::find($this->globalHandle);
        $this->setGlobalValues($globalSet, 'default', ['site_title' => 'Original']);

        $result = $this->router->execute([
            'action' => 'update',
            'handle' => $this->globalHandle,
            'site' => 'default',
            'data' => [
                'site_title' => 'Updated',
                'tagline' => 'New Tagline',
            ],
        ]);

        if (! $result['success']) {
            dump('Update global failed:', $result);
        }

        $this->assertTrue($result['success']);
        $this->assertContains('site_title', $result['data']['global']['updated_fields']);
        $this->assertContains('tagline', $result['data']['global']['updated_fields']);
    }

    public function test_list_globals_shows_localization_info(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
        ]);

        $this->assertTrue($result['success']);

        $global = collect($result['data']['globals'])->firstWhere('handle', $this->globalHandle);
        $this->assertNotNull($global);
        $this->assertArrayHasKey('localized', $global);
        $this->assertArrayHasKey('sites', $global);
        $this->assertArrayHasKey('has_values', $global);
    }
}
