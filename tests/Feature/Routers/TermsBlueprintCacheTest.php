<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * The counterpart of the entry blueprint cache collision (#52).
 *
 * Term::blueprint() memoizes under "term-{$this->id()}-blueprint" like entries
 * do, but Term::id() is "{taxonomy}::{slug}" rather than null for an unsaved
 * term, so the key already differs per taxonomy. This proves that rather than
 * assuming it: the report flagged terms as a likely second instance and left
 * it unverified.
 */
class TermsBlueprintCacheTest extends TestCase
{
    private TermsRouter $router;

    private string $alpha;

    private string $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new TermsRouter;
        $suffix = bin2hex(random_bytes(4));
        $this->alpha = "topics-{$suffix}";
        $this->beta = "regions-{$suffix}";

        Taxonomy::make($this->alpha)->title('Topics')->save();
        Taxonomy::make($this->beta)->title('Regions')->save();

        Blueprint::make('topics')->setNamespace("taxonomies.{$this->alpha}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ],
        ])->save();

        Blueprint::make('regions')->setNamespace("taxonomies.{$this->beta}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'country_code', 'field' => ['type' => 'text']],
                ['handle' => 'population', 'field' => ['type' => 'integer']],
            ],
        ])->save();
    }

    public function test_a_second_create_in_another_taxonomy_keeps_its_own_fields(): void
    {
        $first = $this->router->execute([
            'action' => 'create',
            'taxonomy' => $this->alpha,
            'slug' => 'php',
            'data' => ['title' => 'PHP'],
        ]);
        $this->assertTrue($first['success'], json_encode($first['errors'] ?? []));

        $second = $this->router->execute([
            'action' => 'create',
            'taxonomy' => $this->beta,
            'slug' => 'denmark',
            'data' => ['title' => 'Denmark', 'country_code' => 'DK', 'population' => 5900000],
        ]);
        $this->assertTrue($second['success'], json_encode($second['errors'] ?? []));

        $term = Term::find("{$this->beta}::denmark");
        $this->assertNotNull($term);
        $this->assertSame('DK', $term->get('country_code'), 'country_code was dropped');
        $this->assertSame(5900000, $term->get('population'), 'population was dropped');
    }
}
