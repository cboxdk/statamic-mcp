<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Each tool call must start from the state a fresh web request would.
 *
 * Statamic's Blink cache is scoped to one request and filled freely on that
 * assumption. This server is long-lived, so without flushing it between calls
 * every call inherits what the last one memoized — which is how a blueprint
 * from one collection reached another (#52), and how the entries below ended up
 * saved with no URI at all.
 */
class StaleRequestStateTest extends TestCase
{
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = 'struct-' . bin2hex(random_bytes(4));

        Collection::make($this->collection)
            ->title('Structured')
            ->routes('{parent_uri}/{slug}')
            ->structureContents(['root' => true])
            ->save();

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

    public function test_every_entry_created_in_a_session_is_reachable_by_its_url(): void
    {
        $router = new EntriesRouter;

        foreach (['first', 'second', 'third'] as $slug) {
            $router->execute([
                'action' => 'create',
                'collection' => $this->collection,
                'slug' => $slug,
                'data' => ['title' => ucfirst($slug)],
            ]);
        }

        // The tree is blinked. Without a flush between calls the second and
        // third saves see a tree that predates the first entry, so Statamic
        // indexes their URI as null — the entries exist, findByUri() misses
        // them, and the pages 404.
        foreach (Entry::query()->where('collection', $this->collection)->get() as $entry) {
            $uri = $entry->uri();

            $this->assertNotNull($uri, "Entry [{$entry->slug()}] was saved without a URI.");
            $this->assertNotNull(
                Entry::findByUri($uri),
                "Entry [{$entry->slug()}] is not reachable at its own URI [{$uri}]."
            );
        }
    }
}
