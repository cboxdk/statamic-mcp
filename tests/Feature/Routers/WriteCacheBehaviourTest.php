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
 * What a write is allowed to do to the site's caches.
 *
 * A write used to end in a full Stache and static wipe. The Stache half was
 * both unnecessary — save() maintains the store, which is why a Control Panel
 * save clears nothing — and actively harmful: run through Artisan inside the
 * request, it reset the in-memory stores mid-call, emptying a structured
 * collection's tree on a live multisite. Statamic then padded the empty tree
 * with every entry at root, flattening nested URLs and making a random entry
 * the homepage (#53).
 *
 * The static half stays, because Statamic's own invalidation has holes this
 * addon would otherwise fall into. A stale page is a bug visitors see; a static
 * clear is a rebuild.
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
     * Record every Artisan command the code under test asks for.
     *
     * @param  list<string>  $called
     */
    private function spyArtisan(array &$called): void
    {
        Artisan::swap(new class($called) extends Kernel
        {
            /** @param list<string> $called */
            public function __construct(public array &$called)
            {
                // Stands in for the kernel only to record what it is asked to run.
            }

            public function call($command, array $parameters = [], $outputBuffer = null): int
            {
                $this->called[] = (string) $command;

                return 0;
            }
        });
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
        $this->spyArtisan($called);

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
        $this->assertNotContains(
            'statamic:stache:clear',
            $this->commandsDuringUpdate(),
            'A write must leave the Stache alone; clearing it mid-request emptied collection trees on live sites.'
        );
    }

    public function test_an_update_still_clears_the_static_cache(): void
    {
        // Kept, unlike the Stache clear, because Statamic's own invalidation
        // has holes: no CollectionSaved or TaxonomySaved subscriber, a new term
        // dispatches TermSaved rather than the LocalizedTermSaved it listens
        // for, and a slug change leaves the old URL cached.
        $this->assertContains('statamic:static:clear', $this->commandsDuringUpdate());
    }

    public function test_static_clearing_can_be_switched_off(): void
    {
        config(['statamic.mcp.cache.clear_static_after_write' => false]);

        $commands = $this->commandsDuringUpdate();

        $this->assertNotContains('statamic:static:clear', $commands);
        $this->assertNotContains('statamic:stache:clear', $commands);
    }

    public function test_stache_clearing_can_be_switched_back_on(): void
    {
        config(['statamic.mcp.cache.clear_stache_after_write' => true]);

        $this->assertContains('statamic:stache:clear', $this->commandsDuringUpdate());
    }

    public function test_a_structural_write_still_rebuilds_the_stache(): void
    {
        // The line is content vs structure, not "never clear". Statamic keeps
        // its stores current on save but does not rebuild the indexes that
        // *depend* on a schema or configuration change — a relationship's
        // max_items, a collection's taxonomies, its mount, its dated flag, a
        // group's roles. Five review passes each found another one, which is
        // why this is handled by category rather than case by case.
        $called = [];
        $this->spyArtisan($called);

        (new StructuresRouter)->execute([
            'action' => 'update',
            'resource_type' => 'collection',
            'handle' => $this->collection,
            'data' => ['taxonomies' => []],
        ]);

        Artisan::clearResolvedInstances();

        $this->assertContains('statamic:stache:clear', $called);
    }

    public function test_a_structural_write_rebuilds_even_with_clearing_switched_off(): void
    {
        // The content-write switches must not be able to turn off reindexing
        // that correctness depends on; switching them off is a performance
        // choice about content saves, not a licence to serve 404s.
        config([
            'statamic.mcp.cache.clear_stache_after_write' => false,
            'statamic.mcp.cache.clear_static_after_write' => false,
        ]);

        $called = [];
        $this->spyArtisan($called);

        (new StructuresRouter)->execute([
            'action' => 'configure',
            'resource_type' => 'collection',
            'handle' => $this->collection,
            'config' => ['mount' => 'somewhere'],
        ]);

        Artisan::clearResolvedInstances();

        $this->assertContains('statamic:stache:clear', $called);
    }

    public function test_deleting_an_entry_rebuilds_dependent_indexes(): void
    {
        // A delete invalidates what points AT the record, which an update does
        // not: Entry::delete() leaves term associations behind and
        // TaxonomyTermsStore::sync() does not drop a virtual term whose last
        // use just went away, so entriesCount() keeps counting the deleted
        // entry and orphaned terms linger in listings.
        $entry = Entry::make()
            ->collection($this->collection)
            ->slug('doomed')
            ->data(['title' => 'Doomed']);
        $entry->save();

        $called = [];
        $this->spyArtisan($called);

        (new EntriesRouter)->execute([
            'action' => 'delete',
            'collection' => $this->collection,
            'id' => $entry->id(),
        ]);

        Artisan::clearResolvedInstances();

        $this->assertContains('statamic:stache:clear', $called);
    }

    public function test_a_collection_write_still_invalidates_its_static_pages(): void
    {
        // Statamic's invalidator subscribes to entry, term, nav, form, asset,
        // blueprint and collection-*tree* saves — but not CollectionSaved.
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
            'data' => ['title' => 'Renamed Collection'],
        ]);

        $this->assertNotEmpty($invalidated, 'A collection configuration write must invalidate its static pages.');
        $this->assertInstanceOf(StatamicCollection::class, $invalidated[0]);
    }
}
