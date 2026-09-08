<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

/**
 * merge_sets lets a caller change one section of a page builder without
 * reading and resending every other section.
 */
class EntriesMergeSetsTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(4));
        Collection::make($this->collectionHandle)->title('Pages')->save();

        Blueprint::make('pages')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'page_builder', 'field' => [
                    'type' => 'replicator',
                    'sets' => [
                        'main' => [
                            'sets' => [
                                'hero' => ['fields' => [['handle' => 'body', 'field' => ['type' => 'textarea']]]],
                                'cta' => ['fields' => [['handle' => 'body', 'field' => ['type' => 'textarea']]]],
                            ],
                        ],
                    ],
                ]],
            ],
        ])->save();

        Stache::refresh();
    }

    private function makeEntry(): void
    {
        Entry::make()
            ->id('home')
            ->collection($this->collectionHandle)
            ->slug('home')
            ->data([
                'title' => 'Home',
                'page_builder' => [
                    ['id' => 's1', 'type' => 'hero', 'enabled' => true, 'body' => 'First'],
                    ['id' => 's2', 'type' => 'cta', 'enabled' => true, 'body' => 'Second'],
                    ['id' => 's3', 'type' => 'cta', 'enabled' => true, 'body' => 'Third'],
                ],
            ])
            ->save();
    }

    public function test_replaces_one_set_in_place_and_leaves_the_rest_alone(): void
    {
        $this->makeEntry();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'merge_sets' => true,
            'data' => [
                'page_builder' => [
                    ['id' => 's2', 'type' => 'cta', 'enabled' => true, 'body' => 'Second, rewritten'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $sets = Entry::find('home')->get('page_builder');
        $this->assertCount(3, $sets);
        $this->assertSame(['s1', 's2', 's3'], array_column($sets, 'id'));
        $this->assertSame('First', $sets[0]['body']);
        $this->assertSame('Second, rewritten', $sets[1]['body']);
        $this->assertSame('Third', $sets[2]['body']);
    }

    public function test_appends_a_set_with_an_unseen_id(): void
    {
        $this->makeEntry();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'merge_sets' => true,
            'data' => [
                'page_builder' => [
                    ['id' => 's4', 'type' => 'cta', 'enabled' => true, 'body' => 'Fourth'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame(['s1', 's2', 's3', 's4'], array_column(Entry::find('home')->get('page_builder'), 'id'));
    }

    public function test_without_the_flag_the_array_is_still_replaced_wholesale(): void
    {
        $this->makeEntry();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'data' => [
                'page_builder' => [
                    ['id' => 's2', 'type' => 'cta', 'enabled' => true, 'body' => 'Only survivor'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame(['s2'], array_column(Entry::find('home')->get('page_builder'), 'id'));
    }

    public function test_requires_an_id_on_every_item(): void
    {
        $this->makeEntry();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'merge_sets' => true,
            'data' => ['page_builder' => [['type' => 'cta', 'enabled' => true, 'body' => 'No id']]],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('needs an "id"', implode(' ', $result['errors']));
        $this->assertCount(3, Entry::find('home')->get('page_builder'));
    }

    public function test_rejects_a_duplicate_id_within_one_payload(): void
    {
        $this->makeEntry();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'merge_sets' => true,
            'data' => [
                'page_builder' => [
                    ['id' => 's1', 'type' => 'hero', 'enabled' => true, 'body' => 'One'],
                    ['id' => 's1', 'type' => 'hero', 'enabled' => true, 'body' => 'Two'],
                ],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('more than once', implode(' ', $result['errors']));
    }

    public function test_leaves_non_replicator_fields_replacing_as_usual(): void
    {
        $this->makeEntry();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'merge_sets' => true,
            'data' => ['title' => 'Renamed'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $entry = Entry::find('home');
        $this->assertSame('Renamed', $entry->get('title'));
        $this->assertCount(3, $entry->get('page_builder'));
    }
}
