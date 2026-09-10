<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Entry::blueprint() memoizes into Blink under "entry-{$this->id()}-blueprint",
 * and an unsaved entry has no id — so every new entry shares the key
 * "entry--blueprint" regardless of its collection.
 *
 * In a web request that never shows: one request creates at most one entry. The
 * MCP stdio server is a long-running process, so the second create in a
 * different collection resolved the first collection's blueprint, and every
 * field the two blueprints did not share was silently dropped from a write that
 * reported success.
 *
 * @see https://github.com/cboxdk/statamic-mcp/issues/52
 */
class EntriesBlueprintCacheTest extends TestCase
{
    private EntriesRouter $router;

    private string $alpha;

    private string $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $suffix = bin2hex(random_bytes(4));
        $this->alpha = "alpha-{$suffix}";
        $this->beta = "beta-{$suffix}";

        Collection::make($this->alpha)->title('Alpha')->save();
        Collection::make($this->beta)->title('Beta')->save();

        Blueprint::make('alpha')->setNamespace("collections.{$this->alpha}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'summary', 'field' => ['type' => 'textarea']],
            ],
        ])->save();

        Blueprint::make('beta')->setNamespace("collections.{$this->beta}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'summary', 'field' => ['type' => 'textarea']],
                ['handle' => 'label', 'field' => ['type' => 'text']],
                ['handle' => 'link_url', 'field' => ['type' => 'text']],
                ['handle' => 'custom_id', 'field' => ['type' => 'integer']],
            ],
        ])->save();

    }

    public function test_a_second_create_in_another_collection_keeps_its_own_fields(): void
    {
        $first = $this->router->execute([
            'action' => 'create',
            'collection' => $this->alpha,
            'slug' => 'probe-alpha',
            'data' => ['title' => 'Probe Alpha', 'summary' => 'Alpha summary'],
        ]);
        $this->assertTrue($first['success'], json_encode($first['errors'] ?? []));

        // Same process, different collection, fields only beta declares.
        $second = $this->router->execute([
            'action' => 'create',
            'collection' => $this->beta,
            'slug' => 'probe-beta',
            'data' => [
                'title' => 'Probe Beta',
                'summary' => 'Beta summary',
                'label' => 'Label',
                'link_url' => '/probe',
                'custom_id' => 777,
            ],
        ]);
        $this->assertTrue($second['success'], json_encode($second['errors'] ?? []));

        $entry = Entry::find($second['data']['entry']['id']);
        $this->assertNotNull($entry);
        $this->assertSame('Label', $entry->get('label'), 'label was dropped');
        $this->assertSame('/probe', $entry->get('link_url'), 'link_url was dropped');
        $this->assertSame(777, $entry->get('custom_id'), 'custom_id was dropped');
        $this->assertSame('beta', $entry->blueprint()->handle());
    }

    public function test_the_order_of_collections_does_not_matter(): void
    {
        $this->router->execute([
            'action' => 'create',
            'collection' => $this->beta,
            'slug' => 'first-beta',
            'data' => ['title' => 'First Beta', 'label' => 'L'],
        ]);

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->alpha,
            'slug' => 'then-alpha',
            'data' => ['title' => 'Then Alpha', 'summary' => 'Alpha summary'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $entry = Entry::find($result['data']['entry']['id']);
        $this->assertSame('Alpha summary', $entry->get('summary'));
        $this->assertSame('alpha', $entry->blueprint()->handle());
    }
}
