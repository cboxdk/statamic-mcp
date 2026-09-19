<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Statamic\Contracts\Entries\Collection as StatamicCollection;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\StaticCaching\Invalidator;

/**
 * A write must not wipe the site's caches.
 *
 * Statamic's own save() updates the Stache store and its indexes, and
 * StaticCaching\Invalidate invalidates static pages from the saved events — a
 * Control Panel save clears nothing. Doing it here was redundant, and on a live
 * multisite it emptied a structured collection's tree mid-request: Statamic
 * padded the empty tree with every entry at root, flattening nested URLs and
 * making a random entry the homepage (#53).
 */
class WriteCacheBehaviourTest extends TestCase
{
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = 'cache-' . bin2hex(random_bytes(4));

        Collection::make($this->collection)->title('Pages')->save();

        Blueprint::make('page')
            ->setNamespace("collections.{$this->collection}")
            ->setContents([
                'title' => 'Page',
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                ]]]]],
            ])
            ->save();
    }

    /**
     * Run an entry update and report every Artisan command it triggered.
     *
     * @return list<string>
     */
    private function commandsDuringUpdate(): array
    {
        $entry = Entry::make()
            ->collection($this->collection)
            ->slug('a-page')
            ->data(['title' => 'A Page']);
        $entry->save();

        $called = [];

        Artisan::swap(new class($called) extends Kernel
        {
            /** @param list<string> $called */
            public function __construct(public array &$called)
            {
                // Intentionally not calling parent::__construct(): this stands in
                // for the kernel only to record what the write asks it to run.
            }

            public function call($command, array $parameters = [], $outputBuffer = null): int
            {
                $this->called[] = (string) $command;

                return 0;
            }
        });

        (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $this->collection,
            'id' => $entry->id(),
            'data' => ['title' => 'Renamed'],
        ]);

        Artisan::clearResolvedInstances();

        return $called;
    }

    public function test_an_update_does_not_clear_the_stache(): void
    {
        $commands = $this->commandsDuringUpdate();

        $this->assertNotContains('statamic:stache:clear', $commands, 'A write must leave the Stache alone; clearing it mid-request emptied collection trees on live sites.');
    }

    public function test_an_update_still_clears_the_static_cache(): void
    {
        // Kept, unlike the Stache clear, because Statamic's own invalidation
        // has holes — no CollectionSaved or TaxonomySaved subscriber, a new
        // term dispatches TermSaved rather than the LocalizedTermSaved it
        // listens for, and a slug change leaves the old URL cached. A stale
        // page is a bug visitors see; a static clear is a rebuild.
        $commands = $this->commandsDuringUpdate();

        $this->assertContains('statamic:static:clear', $commands);
    }

    public function test_static_clearing_can_be_switched_off(): void
    {
        config(['statamic.mcp.cache.clear_static_after_write' => false]);

        $commands = $this->commandsDuringUpdate();

        $this->assertNotContains('statamic:static:clear', $commands);
        $this->assertNotContains('statamic:stache:clear', $commands);
    }

    public function test_a_collection_write_still_invalidates_its_static_pages(): void
    {
        // Statamic's invalidator subscribes to entry, term, nav, form, asset,
        // blueprint and collection-*tree* saves — but not CollectionSaved. So
        // changing a template or layout invalidates nothing on its own, and
        // dropping the blanket clear (#53) would have left cached pages stale.
        $invalidated = [];

        $this->app->instance(Invalidator::class, new class($invalidated) implements Invalidator
        {
            /** @param list<mixed> $seen */
            public function __construct(public array &$seen) {}

            public function invalidate($item): void
            {
                $this->seen[] = $item;
            }

            public function refresh($item): void {}
        });

        (new StructuresRouter)->execute([
            'action' => 'update',
            'resource_type' => 'collection',
            'handle' => $this->collection,
            'title' => 'Renamed Collection',
        ]);

        $this->assertNotEmpty($invalidated, 'A collection configuration write must invalidate its static pages.');
        $this->assertInstanceOf(StatamicCollection::class, $invalidated[0]);
    }

    public function test_a_site_can_still_opt_back_in(): void
    {
        config(['statamic.mcp.cache.clear_stache_after_write' => true]);

        $commands = $this->commandsDuringUpdate();

        $this->assertContains('statamic:stache:clear', $commands);
    }
}
