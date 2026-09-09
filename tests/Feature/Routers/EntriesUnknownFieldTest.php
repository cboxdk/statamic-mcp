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
 * A key that is not a field handle must fail loudly.
 *
 * Statamic swallows them in two different ways, both invisible to the caller:
 * Fields::addValues() only reads handles it knows, so a stray key at the top
 * level is discarded, while Replicator::processRow() and Grid::processRow()
 * merge the raw row back over the processed one, so a stray key inside a set
 * is written to the content file as inert data. Either way the write reports
 * success and the content does not match the request — undiagnosable for a
 * client that cannot read the blueprint from the repository.
 */
class EntriesUnknownFieldTest extends TestCase
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
                ['handle' => 'intro', 'field' => ['type' => 'textarea']],
                ['handle' => 'rows', 'field' => [
                    'type' => 'grid',
                    'fields' => [
                        ['handle' => 'caption', 'field' => ['type' => 'text']],
                    ],
                ]],
                ['handle' => 'page_builder', 'field' => [
                    'type' => 'replicator',
                    'sets' => [
                        'main' => [
                            'sets' => [
                                'content_section' => [
                                    'fields' => [
                                        ['handle' => 'body', 'field' => ['type' => 'textarea']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
                ['handle' => 'story', 'field' => [
                    'type' => 'bard',
                    'sets' => [
                        'main' => [
                            'sets' => [
                                'quote' => [
                                    'fields' => [
                                        ['handle' => 'cite', 'field' => ['type' => 'text']],
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

    public function test_rejects_an_unknown_top_level_handle(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'slug' => 'about',
            'data' => ['title' => 'About', 'subtitle' => 'silently dropped today'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('[subtitle]', implode(' ', $result['errors']));
        $this->assertNull(Entry::query()->where('slug', 'about')->first());
    }

    public function test_rejects_an_unknown_handle_inside_a_replicator_set(): void
    {
        $this->makeEntry('home');

        // The real-world case: a `cards` array sent to a set that has no such
        // field. Statamic stores it as junk that no template ever reads.
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'home',
            'data' => [
                'page_builder' => [[
                    'id' => 'set-1',
                    'type' => 'content_section',
                    'enabled' => true,
                    'body' => 'Kept',
                    'cards' => [['id' => 'c1', 'label' => 'Never rendered']],
                ]],
            ],
        ]);

        $this->assertFalse($result['success']);
        $errors = implode(' ', $result['errors']);
        $this->assertStringContainsString('[cards]', $errors);
        $this->assertStringContainsString('page_builder.0', $errors);
        // The valid handles are named so the client can correct itself.
        $this->assertStringContainsString('body', $errors);

        $this->assertNull(Entry::find('home')->get('page_builder'));
    }

    public function test_rejects_an_unknown_handle_inside_a_grid_row(): void
    {
        $this->makeEntry('grid');

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'grid',
            'data' => ['rows' => [['id' => 'r1', 'caption' => 'ok', 'headline' => 'not a field']]],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('[headline]', implode(' ', $result['errors']));
    }

    public function test_rejects_an_unknown_handle_inside_a_bard_set(): void
    {
        $this->makeEntry('bard');

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'bard',
            'data' => [
                'story' => [[
                    'type' => 'set',
                    'attrs' => [
                        'id' => 'node-1',
                        'values' => ['type' => 'quote', 'cite' => 'Ada', 'attribution' => 'not a field'],
                    ],
                ]],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('[attribution]', implode(' ', $result['errors']));
    }

    public function test_accepts_structural_keys_on_sets_and_rows(): void
    {
        $this->makeEntry('structural');

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'structural',
            'data' => [
                'page_builder' => [['id' => 's1', 'type' => 'content_section', 'enabled' => true, 'body' => 'ok']],
                'rows' => [['id' => 'r1', 'caption' => 'ok']],
                'story' => [[
                    'type' => 'set',
                    'attrs' => ['id' => 'n1', 'values' => ['type' => 'quote', 'cite' => 'Ada']],
                ]],
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
    }

    public function test_accepts_record_properties_a_read_hands_back(): void
    {
        $this->makeEntry('roundtrip');

        // get() emits these alongside field data; a naive round-trip sends the
        // whole object back and must not be rejected for it.
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'roundtrip',
            'data' => [
                'id' => 'roundtrip',
                'blueprint' => 'pages',
                'slug' => 'roundtrip',
                'updated_at' => 1788862250,
                'updated_by' => null,
                'title' => 'Round trip',
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('Round trip', Entry::find('roundtrip')->get('title'));
    }

    public function test_the_check_can_be_disabled(): void
    {
        config(['statamic.mcp.security.reject_unknown_fields' => false]);
        $this->makeEntry('lenient');

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'lenient',
            'data' => ['title' => 'Lenient', 'subtitle' => 'tolerated'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
    }
}
