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
 * When a write clears the cache, and what it must not do while the call runs.
 *
 * Clearing after a write is necessary: Statamic does not rebuild the indexes
 * that depend on one, so without it a changed max_items leaves an index that
 * later throws, a changed mount leaves every entry 404ing, a removed taxonomy
 * leaves whereTaxonomy() returning entries.
 *
 * Clearing *during* the call is what broke a live site (#53): Artisan resets
 * the in-memory stores there and then, so the rest of the call read emptied
 * stores, Statamic padded a structured collection's tree with every entry at
 * root, and a random entry became the homepage. The clear now waits until the
 * call is finished.
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
     * Swap in a kernel that records commands, and optionally reports whether
     * the tool call had already finished when each one arrived.
     *
     * @param  list<string>  $called
     */
    private function spyArtisan(array &$called, ?\Closure $onCall = null): void
    {
        Artisan::swap(new class($called, $onCall) extends Kernel
        {
            /** @param list<string> $called */
            public function __construct(public array &$called, private ?\Closure $onCall = null)
            {
                // Stands in for the kernel only to record what it is asked to run.
            }

            public function call($command, array $parameters = [], $outputBuffer = null): int
            {
                $this->called[] = (string) $command;

                if ($this->onCall !== null) {
                    ($this->onCall)((string) $command);
                }

                return 0;
            }
        });
    }

    private function makeEntry(string $slug = 'a-page'): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()
            ->collection($this->collection)
            ->slug($slug)
            ->data(['title' => 'A Page']);
        $entry->save();

        return $entry;
    }

    public function test_a_write_still_clears_the_caches(): void
    {
        $entry = $this->makeEntry();

        $called = [];
        $this->spyArtisan($called);

        (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $this->collection,
            'id' => $entry->id(),
            'data' => ['title' => 'Renamed'],
        ]);

        Artisan::clearResolvedInstances();

        $this->assertContains('statamic:stache:clear', $called);
        $this->assertContains('statamic:static:clear', $called);
    }

    public function test_the_clear_happens_only_after_the_call_has_finished(): void
    {
        // The whole of #53 in one assertion. A clear that lands while the call
        // is still running resets the stores underneath it, which is how a
        // structured collection's tree came back empty and got padded.
        $entry = $this->makeEntry();

        $finished = false;
        $clearedEarly = false;

        $called = [];
        $this->spyArtisan($called, function () use (&$finished, &$clearedEarly): void {
            if (! $finished) {
                $clearedEarly = true;
            }
        });

        $router = new class extends EntriesRouter
        {
            public ?\Closure $after = null;

            protected function executeInternal(array $arguments): array
            {
                $result = parent::executeInternal($arguments);

                if ($this->after !== null) {
                    ($this->after)();
                }

                return $result;
            }
        };
        $router->after = function () use (&$finished): void {
            $finished = true;
        };

        $router->execute([
            'action' => 'update',
            'collection' => $this->collection,
            'id' => $entry->id(),
            'data' => ['title' => 'Renamed'],
        ]);

        Artisan::clearResolvedInstances();

        $this->assertNotEmpty($called, 'The write should still have asked for a clear.');
        $this->assertFalse($clearedEarly, 'A cache clear ran while the tool call was still in progress.');
    }

    public function test_clearing_can_be_switched_off(): void
    {
        config([
            'statamic.mcp.cache.clear_stache_after_write' => false,
            'statamic.mcp.cache.clear_static_after_write' => false,
        ]);

        $entry = $this->makeEntry();

        $called = [];
        $this->spyArtisan($called);

        (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $this->collection,
            'id' => $entry->id(),
            'data' => ['title' => 'Renamed'],
        ]);

        Artisan::clearResolvedInstances();

        $this->assertNotContains('statamic:stache:clear', $called);
        $this->assertNotContains('statamic:static:clear', $called);
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
