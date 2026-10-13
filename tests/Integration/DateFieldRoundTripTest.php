<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Date fields on a site whose app timezone is not UTC, plus the two shapes
 * the nested-date fix (#64) did not reach on its own.
 *
 * Statamic validates a date in the Control Panel's wire format (ISO 8601
 * Zulu) and stores it in the field's save format as app-timezone wall
 * clock. The test suite runs in UTC, where the two agree on the hour, so a
 * conversion done in the wrong direction is invisible here unless the
 * timezone is changed. The former top-level pass formatted the wall clock
 * with a literal `Z`, so `2026-06-01 10:00` sent to a Copenhagen site was
 * stored as `12:00`. It also mangled a top-level range into today's date.
 */
class DateFieldRoundTripTest extends TestCase
{
    private EntriesRouter $router;

    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Copenhagen']);
        date_default_timezone_set('Europe/Copenhagen');

        $this->router = new EntriesRouter;
        $this->collection = 'tz_' . bin2hex(random_bytes(4));

        CollectionFacade::make($this->collection)->title('Timezones')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'starts_at' => ['type' => 'date', 'display' => 'Starts at', 'time_enabled' => true],
            'period' => ['type' => 'date', 'display' => 'Period', 'mode' => 'range'],
            'stamp' => ['type' => 'date', 'display' => 'Stamp', 'format' => 'U'],
            'prices' => [
                'type' => 'grid',
                'display' => 'Prices',
                'fields' => [
                    ['handle' => 'valid_from', 'field' => ['type' => 'date', 'display' => 'Valid from']],
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

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');

        parent::tearDown();
    }

    public function test_stored_dates_at_every_level_survive_an_unrelated_update(): void
    {
        $entry = EntryFacade::make()->collection($this->collection)->slug('winter')->data([
            'title' => 'Winter',
            'starts_at' => '2026-01-01 00:00',
            'period' => ['start' => '2026-03-29 01:30', 'end' => '2026-03-29 03:30'],
            'stamp' => 1767222000,
            'prices' => [['id' => 'row1', 'valid_from' => '2026-01-01 00:00']],
            'blocks' => [['id' => 'set1', 'type' => 'event', 'happens' => ['start' => '2026-10-25 01:30', 'end' => '2026-10-25 03:30']]],
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
        $this->assertSame('2026-01-01 00:00', $saved->get('starts_at'));
        // The range crosses the DST switch; both ends keep their wall clock.
        $this->assertSame(['start' => '2026-03-29 01:30', 'end' => '2026-03-29 03:30'], $saved->get('period'));
        $this->assertSame(1767222000, $saved->get('stamp'));
        $this->assertSame('2026-01-01 00:00', $saved->get('prices')[0]['valid_from']);
        $this->assertSame(['start' => '2026-10-25 01:30', 'end' => '2026-10-25 03:30'], $saved->get('blocks')[0]['happens']);
    }

    public function test_wall_clock_input_is_stored_as_sent_and_zulu_input_is_converted(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collection,
            'data' => [
                'title' => 'Summer',
                'starts_at' => '2026-06-01 10:00',
                'period' => ['start' => '2026-07-01', 'end' => '2026-07-02 16:00'],
                'stamp' => '2026-01-01 00:00',
                'prices' => [
                    ['valid_from' => '2026-06-01 10:00'],
                    ['valid_from' => '2026-10-01T00:00:00.000Z'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));

        $saved = EntryFacade::find($result['data']['entry']['id']);
        $this->assertSame('2026-06-01 10:00', $saved->get('starts_at'));
        $this->assertSame(['start' => '2026-07-01 00:00', 'end' => '2026-07-02 16:00'], $saved->get('period'));
        // Midnight in Copenhagen on New Year's Day is 23:00 UTC the day before.
        $this->assertSame(1767222000, $saved->get('stamp'));
        $this->assertSame('2026-06-01 10:00', $saved->get('prices')[0]['valid_from']);
        // A Zulu value is an instant, so it lands on the site's clock, as it does from the CP.
        $this->assertSame('2026-10-01 02:00', $saved->get('prices')[1]['valid_from']);
    }
}
