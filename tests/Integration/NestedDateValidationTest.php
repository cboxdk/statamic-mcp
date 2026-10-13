<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Date fields nested in grids, replicators and groups.
 *
 * Statamic validates a date field against the format the Control Panel
 * submits (ISO 8601 Zulu, or Y-m-d for date-only fields), while the stored
 * value uses the field's save format (`Y-m-d H:i` by default). The router
 * re-validates the whole merged entry on update, so a stored nested date
 * failed "Not a valid date" and blocked every update of that entry — a
 * programme with price seasons could not be touched through the MCP at all.
 */
class NestedDateValidationTest extends TestCase
{
    private EntriesRouter $router;

    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $this->collection = 'seasons_' . bin2hex(random_bytes(4));

        CollectionFacade::make($this->collection)->title('Seasons')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'prices' => [
                'type' => 'grid',
                'display' => 'Prices',
                'fields' => [
                    ['handle' => 'valid_from', 'field' => ['type' => 'date', 'display' => 'Valid from']],
                    ['handle' => 'price', 'field' => ['type' => 'text', 'display' => 'Price']],
                ],
            ],
            'details' => [
                'type' => 'group',
                'display' => 'Details',
                'fields' => [
                    ['handle' => 'opens_on', 'field' => ['type' => 'date', 'display' => 'Opens on', 'time_enabled' => false, 'format' => 'Y-m-d']],
                ],
            ],
            'blocks' => [
                'type' => 'replicator',
                'display' => 'Blocks',
                'sets' => [
                    'event' => [
                        'display' => 'Event',
                        'fields' => [
                            ['handle' => 'happens', 'field' => ['type' => 'date', 'display' => 'Happens', 'mode' => 'range']],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->setHandle($this->collection);
        $blueprint->setNamespace("collections.{$this->collection}");
        $blueprint->save();
    }

    public function test_update_passes_with_stored_dates_in_grid_group_and_replicator(): void
    {
        $entry = EntryFacade::make()->collection($this->collection)->slug('winter')->data([
            'title' => 'Winter',
            'prices' => [['id' => 'row1', 'valid_from' => '2026-01-01 00:00', 'price' => '785']],
            'details' => ['opens_on' => '2026-01-01'],
            'blocks' => [['id' => 'set1', 'type' => 'event', 'happens' => ['start' => '2026-02-01 10:00', 'end' => '2026-02-03 18:00']]],
        ]);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collection,
            'id' => $entry->id(),
            'data' => ['title' => 'Winter season'],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));

        $saved = EntryFacade::find($entry->id());
        $this->assertSame('Winter season', $saved->get('title'));
        $this->assertSame('2026-01-01 00:00', $saved->get('prices')[0]['valid_from']);
        $this->assertSame('2026-01-01', $saved->get('details')['opens_on']);
        $this->assertSame(['start' => '2026-02-01 10:00', 'end' => '2026-02-03 18:00'], $saved->get('blocks')[0]['happens']);
    }

    public function test_incoming_nested_dates_are_accepted_in_any_common_format_and_stored_in_the_save_format(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collection,
            'data' => [
                'title' => 'Summer',
                'prices' => [
                    ['valid_from' => '2026-06-01', 'price' => '900'],
                    ['valid_from' => '2026-10-01T00:00:00.000Z', 'price' => '850'],
                    ['valid_from' => ['date' => '2026-12-01', 'time' => '08:30'], 'price' => '950'],
                ],
                'details' => ['opens_on' => '2026-05-31 00:00'],
                'blocks' => [['type' => 'event', 'happens' => ['start' => '2026-07-01', 'end' => '2026-07-02 16:00']]],
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));

        $saved = EntryFacade::find($result['data']['entry']['id']);
        $this->assertSame(['2026-06-01 00:00', '2026-10-01 00:00', '2026-12-01 08:30'], array_column($saved->get('prices'), 'valid_from'));
        $this->assertSame('2026-05-31', $saved->get('details')['opens_on']);
        $this->assertSame(['start' => '2026-07-01 00:00', 'end' => '2026-07-02 16:00'], $saved->get('blocks')[0]['happens']);
    }

    public function test_an_unparseable_nested_date_is_still_reported(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collection,
            'data' => [
                'title' => 'Broken',
                'prices' => [['valid_from' => 'next winter', 'price' => '900']],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('valid_from', implode(' ', $result['errors']));
    }
}
