<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

/**
 * Asset values must be round-trip safe: a value returned by `get` (a
 * container-relative path) has to be accepted by `create`/`update`.
 *
 * @see https://github.com/cboxdk/statamic-mcp/issues/41
 */
class EntriesAssetRoundTripTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;

        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);

        Storage::fake('assets');
        Storage::disk('assets')->put('icons/heart.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"></svg>');
        Storage::disk('assets')->put('icons/star.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"></svg>');

        AssetContainer::make('assets')->title('Assets')->disk('assets')->save();

        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(4));
        Collection::make($this->collectionHandle)->title('Pages')->save();

        Blueprint::make('pages')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'icon', 'field' => [
                    'type' => 'assets',
                    'container' => 'assets',
                    'max_files' => 1,
                    'validate' => ['required', 'mimes:svg'],
                ]],
                ['handle' => 'gallery', 'field' => [
                    'type' => 'assets',
                    'container' => 'assets',
                    'validate' => ['mimes:svg'],
                ]],
                ['handle' => 'page_builder', 'field' => [
                    'type' => 'replicator',
                    'sets' => [
                        'main' => [
                            'sets' => [
                                'icon_cards' => [
                                    'fields' => [
                                        ['handle' => 'card_icon', 'field' => [
                                            'type' => 'assets',
                                            'container' => 'assets',
                                            'max_files' => 1,
                                            'validate' => ['required', 'mimes:svg'],
                                        ]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ],
        ])->save();

        Stache::refresh();
    }

    private function makeEntry(string $id): void
    {
        Entry::make()
            ->id($id)
            ->collection($this->collectionHandle)
            ->slug($id)
            ->data(['title' => 'Home'])
            ->save();
    }

    public function test_update_accepts_relative_asset_paths(): void
    {
        $this->makeEntry('home');

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'data' => [
                'icon' => 'icons/heart.svg',
                'gallery' => ['icons/heart.svg', 'icons/star.svg'],
                'page_builder' => [
                    ['id' => 'set-1', 'type' => 'icon_cards', 'enabled' => true, 'card_icon' => 'icons/heart.svg'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $entry = Entry::find('home');
        $this->assertNotNull($entry);
        $this->assertSame('icons/heart.svg', $entry->get('icon'));
        $this->assertSame(['icons/heart.svg', 'icons/star.svg'], $entry->get('gallery'));
        $this->assertSame('icons/heart.svg', $entry->get('page_builder')[0]['card_icon']);
    }

    public function test_update_still_accepts_canonical_asset_ids(): void
    {
        $this->makeEntry('canonical');

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'canonical',
            'data' => ['icon' => 'assets::icons/heart.svg'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('icons/heart.svg', Entry::find('canonical')?->get('icon'));
    }

    public function test_create_accepts_relative_asset_paths(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Created',
                'slug' => 'created',
                'icon' => 'icons/star.svg',
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $id = $result['data']['entry']['id'];
        $this->assertSame('icons/star.svg', Entry::find($id)?->get('icon'));
    }

    public function test_update_of_an_unrelated_field_does_not_trip_stored_asset_paths(): void
    {
        $this->makeEntry('stored');

        Entry::find('stored')?->merge([
            'icon' => 'icons/heart.svg',
            'page_builder' => [
                ['id' => 'set-1', 'type' => 'icon_cards', 'enabled' => true, 'card_icon' => 'icons/star.svg'],
            ],
        ])->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'stored',
            'data' => ['title' => 'Renamed'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $entry = Entry::find('stored');
        $this->assertSame('Renamed', $entry?->get('title'));
        $this->assertSame('icons/heart.svg', $entry?->get('icon'));
    }

    public function test_unresolvable_asset_path_still_fails_validation(): void
    {
        $this->makeEntry('missing');

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'missing',
            'data' => ['icon' => 'icons/does-not-exist.svg'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Icon', $result['errors'][0]);
    }
}
