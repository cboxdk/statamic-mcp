<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;

/**
 * An entry's status is what visitors get. `published` is true for an entry waiting for its date as
 * well as for a live one, so a client that only reads `published` reports a scheduled entry as live.
 */
class EntryStatusTest extends TestCase
{
    private EntriesRouter $router;

    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $this->collection = 'news_' . bin2hex(random_bytes(4));

        CollectionFacade::make($this->collection)
            ->title('News')
            ->dated(true)
            ->futureDateBehavior('private')
            ->pastDateBehavior('public')
            ->save();

        $this->entry('live', now()->subDay(), true);
        $this->entry('later', now()->addYear(), true);
        $this->entry('unfinished', now()->subDay(), false);
    }

    private function entry(string $slug, mixed $date, bool $published): void
    {
        EntryFacade::make()
            ->collection($this->collection)
            ->slug($slug)
            ->date($date)
            ->published($published)
            ->data(['title' => ucfirst($slug)])
            ->save();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function entries(array $arguments): array
    {
        $response = $this->router->execute(['collection' => $this->collection] + $arguments);
        $this->assertTrue($response['success'], json_encode($response['errors'] ?? null) ?: '');

        return $response['data'];
    }

    /**
     * @param  array<string, mixed>  $filters
     *
     * @return list<string>
     */
    private function slugs(array $filters): array
    {
        $slugs = array_column($this->entries(['action' => 'list', 'filters' => $filters])['entries'], 'slug');
        sort($slugs);

        return $slugs;
    }

    public function test_list_and_get_report_the_status(): void
    {
        $entries = collect($this->entries(['action' => 'list', 'include_unpublished' => true])['entries'])->keyBy('slug');

        $this->assertSame('published', $entries['live']['status']);
        $this->assertSame('scheduled', $entries['later']['status']);
        $this->assertSame('draft', $entries['unfinished']['status']);
        $this->assertTrue($entries['later']['published']);

        $this->assertSame('scheduled', $this->entries(['action' => 'get', 'id' => $entries['later']['id']])['entry']['status']);
    }

    public function test_the_status_filter_from_the_tools_own_example_works(): void
    {
        $this->assertSame(['live'], $this->slugs(['status' => 'published']));
        $this->assertSame(['later'], $this->slugs(['status' => 'scheduled']));
    }

    public function test_asking_for_drafts_is_not_narrowed_by_include_unpublished(): void
    {
        $this->assertSame(['unfinished'], $this->slugs(['status' => 'draft']));
    }

    public function test_an_unknown_status_is_refused_with_the_allowed_values(): void
    {
        $response = $this->router->execute([
            'action' => 'list', 'collection' => $this->collection, 'filters' => ['status' => 'live'],
        ]);

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('published, scheduled, expired, draft', (string) json_encode($response['errors']));
    }

    public function test_without_a_status_filter_the_list_is_unchanged(): void
    {
        $this->assertSame(['later', 'live'], $this->slugs([]));
    }

    public function test_publishing_a_future_dated_entry_says_it_is_scheduled(): void
    {
        $this->entry('queued', now()->addYear(), false);
        $id = EntryFacade::query()->where('collection', $this->collection)->where('slug', 'queued')->first()->id();

        $entry = $this->entries(['action' => 'publish', 'id' => $id])['entry'];

        $this->assertTrue($entry['published']);
        $this->assertSame('scheduled', $entry['status']);
        $this->assertNotNull($entry['date']);

        $this->assertSame('draft', $this->entries(['action' => 'unpublish', 'id' => $id])['entry']['status']);
    }

    public function test_an_unlisted_collection_reports_a_future_entry_as_published(): void
    {
        CollectionFacade::findByHandle($this->collection)->futureDateBehavior('unlisted')->save();

        $this->assertContains('later', $this->slugs(['status' => 'published']));
    }

    public function test_getting_a_collection_returns_its_date_behavior(): void
    {
        $response = (new StructuresRouter)->execute([
            'action' => 'get', 'resource_type' => 'collection', 'handle' => $this->collection,
        ]);

        $this->assertTrue($response['success']);
        $this->assertSame('private', $response['data']['collection']['future_date_behavior']);
        $this->assertSame('public', $response['data']['collection']['past_date_behavior']);
    }
}
