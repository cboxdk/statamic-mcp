<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;

class DateNormalizationTest extends TestCase
{
    use CreatesTestContent;

    private EntriesRouter $router;

    private string $testId;

    private string $datedCollection;

    private string $collectionWithDateField;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $this->testId = bin2hex(random_bytes(4));

        // Dated collection (entry-level date property)
        $this->datedCollection = "dated_{$this->testId}";
        $collection = CollectionFacade::make($this->datedCollection)
            ->title('Dated Collection')
            ->dated(true);
        $collection->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
        ]);
        $blueprint->setHandle($this->datedCollection);
        $blueprint->setNamespace("collections.{$this->datedCollection}");
        $blueprint->save();

        // Non-dated collection with a date blueprint field
        $this->collectionWithDateField = "withdate_{$this->testId}";
        CollectionFacade::make($this->collectionWithDateField)
            ->title('With Date Field')
            ->save();

        $blueprint2 = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'event_date' => ['type' => 'date', 'display' => 'Event Date', 'time_enabled' => false],
        ]);
        $blueprint2->setHandle($this->collectionWithDateField);
        $blueprint2->setNamespace("collections.{$this->collectionWithDateField}");
        $blueprint2->save();
    }

    // --- Entry-level date extraction for dated collections ---

    public function test_create_entry_with_iso8601_date_on_dated_collection(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->datedCollection,
            'data' => [
                'title' => 'ISO Date Test',
                'date' => '2026-04-10T09:00:00.000000Z',
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));
        $this->assertTrue($result['data']['created']);

        $entry = EntryFacade::find($result['data']['entry']['id']);
        $this->assertNotNull($entry->date());
        $this->assertEquals('2026-04-10', $entry->date()->format('Y-m-d'));
    }

    public function test_create_entry_with_simple_date_string_on_dated_collection(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->datedCollection,
            'data' => [
                'title' => 'Simple Date Test',
                'date' => '2026-04-10',
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));

        $entry = EntryFacade::find($result['data']['entry']['id']);
        $this->assertEquals('2026-04-10', $entry->date()->format('Y-m-d'));
    }

    public function test_create_entry_with_datetime_string_on_dated_collection(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->datedCollection,
            'data' => [
                'title' => 'Datetime String Test',
                'date' => '2026-04-10 14:30',
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));

        $entry = EntryFacade::find($result['data']['entry']['id']);
        $this->assertEquals('2026-04-10', $entry->date()->format('Y-m-d'));
        $this->assertEquals('14:30', $entry->date()->format('H:i'));
    }

    public function test_create_entry_with_date_time_object_on_dated_collection(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->datedCollection,
            'data' => [
                'title' => 'Date Object Test',
                'date' => ['date' => '2026-04-10', 'time' => '09:00'],
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));

        $entry = EntryFacade::find($result['data']['entry']['id']);
        $this->assertEquals('2026-04-10', $entry->date()->format('Y-m-d'));
    }

    // --- Published extraction ---

    public function test_create_entry_with_published_false_in_data(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->datedCollection,
            'data' => [
                'title' => 'Draft Test',
                'date' => '2026-04-10',
                'published' => false,
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));
        $this->assertFalse($result['data']['entry']['published']);
    }

    public function test_create_entry_with_published_true_in_data(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->datedCollection,
            'data' => [
                'title' => 'Published Test',
                'date' => '2026-04-10',
                'published' => true,
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));
        $this->assertTrue($result['data']['entry']['published']);
    }

    // --- Blueprint date field normalization ---

    public function test_create_entry_with_iso8601_date_in_blueprint_field(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionWithDateField,
            'data' => [
                'title' => 'Event ISO Date',
                'event_date' => '2026-06-15T00:00:00.000000Z',
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));
    }

    public function test_create_entry_with_simple_date_in_blueprint_field(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionWithDateField,
            'data' => [
                'title' => 'Event Simple Date',
                'event_date' => '2026-06-15',
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));
    }

    public function test_create_entry_with_date_time_object_in_blueprint_field(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionWithDateField,
            'data' => [
                'title' => 'Event Object Date',
                'event_date' => ['date' => '2026-06-15', 'time' => '10:00'],
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));
    }

    // --- Update with date ---

    public function test_update_entry_date_on_dated_collection(): void
    {
        $entry = EntryFacade::make()
            ->id("update-date-{$this->testId}")
            ->collection($this->datedCollection)
            ->slug("update-date-{$this->testId}")
            ->data(['title' => 'Original'])
            ->date(now());
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->datedCollection,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Updated',
                'date' => '2026-12-25',
            ],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));

        $updated = EntryFacade::find($entry->id());
        $this->assertEquals('2026-12-25', $updated->date()->format('Y-m-d'));
    }

    public function test_update_entry_published_state(): void
    {
        $entry = EntryFacade::make()
            ->id("update-pub-{$this->testId}")
            ->collection($this->datedCollection)
            ->slug("update-pub-{$this->testId}")
            ->data(['title' => 'Published Entry'])
            ->published(true);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->datedCollection,
            'id' => $entry->id(),
            'data' => ['published' => false],
        ]);

        $this->assertTrue($result['success'], 'Failed: ' . json_encode($result['errors'] ?? []));
        $this->assertFalse($result['data']['entry']['published']);
    }

    // --- Error handling ---

    public function test_invalid_date_value_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->datedCollection,
            'data' => [
                'title' => 'Bad Date Test',
                'date' => 'not-a-date-at-all',
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('date', strtolower($result['errors'][0]));
    }

    public function test_invalid_blueprint_date_field_returns_validation_error(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionWithDateField,
            'data' => [
                'title' => 'Invalid Date Field Test',
                'event_date' => 'not-a-date',
            ],
        ]);

        // parseDateValue will throw, normalizeDateFields catches it,
        // then the FieldsValidator rejects the unparseable string
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }
}
