<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * The Stache builds, records and keeps up to date an index for every key a
 * query uses. A list filter on an unknown key used to answer an empty list and
 * leave a permanent index behind, so `filters` takes known keys only.
 */
class ListFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('lf_posts')->title('Posts')->save();
        Blueprint::make('lf_posts')->setNamespace('collections.lf_posts')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'colour', 'field' => ['type' => 'text']],
        ]])->save();

        Taxonomy::make('lf_tags')->title('Tags')->save();
        Blueprint::make('lf_tags')->setNamespace('taxonomies.lf_tags')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'tone', 'field' => ['type' => 'text']],
        ]])->save();
        Stache::refresh();

        Entry::make()->id('lf-red')->collection('lf_posts')->slug('red')->data(['title' => 'Red', 'colour' => 'red'])->published(true)->save();
        Entry::make()->id('lf-blue')->collection('lf_posts')->slug('blue')->data(['title' => 'Blue', 'colour' => 'blue'])->published(true)->save();
        Term::make()->taxonomy('lf_tags')->slug('calm')->data(['title' => 'Calm', 'tone' => 'soft'])->save();
        Term::make()->taxonomy('lf_tags')->slug('loud')->data(['title' => 'Loud', 'tone' => 'hard'])->save();
    }

    /**
     * @return list<string>
     */
    private function recordedIndexes(string $store): array
    {
        /** @var list<string> */
        return Stache::cacheStore()->get("stache::indexes::{$store}::_indexes", []);
    }

    public function test_an_unknown_entry_filter_is_refused_and_leaves_no_index_behind(): void
    {
        $result = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'lf_posts', 'filters' => ['made_up_key' => 'x']]);

        $this->assertFalse($result['success']);
        $error = implode(' ', (array) ($result['errors'] ?? $result['error'] ?? []));
        $this->assertStringContainsString('made_up_key', $error);
        // The answer names what the caller can filter by instead.
        $this->assertStringContainsString('colour', $error);
        $this->assertStringContainsString('status', $error);
        $this->assertNotContains('made_up_key', $this->recordedIndexes('entries::lf_posts'));
    }

    public function test_blueprint_fields_and_properties_still_filter_entries(): void
    {
        $byField = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'lf_posts', 'filters' => ['colour' => 'blue']]);
        $this->assertTrue($byField['success'], json_encode($byField));
        $this->assertSame(['lf-blue'], array_column($byField['data']['entries'], 'id'));

        $bySlug = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'lf_posts', 'filters' => ['slug' => 'red', 'status' => 'published']]);
        $this->assertTrue($bySlug['success'], json_encode($bySlug));
        $this->assertSame(['lf-red'], array_column($bySlug['data']['entries'], 'id'));
    }

    public function test_a_filter_value_must_be_scalar(): void
    {
        $result = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'lf_posts', 'filters' => ['colour' => ['red', 'blue']]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("Filter 'colour'", implode(' ', (array) ($result['errors'] ?? $result['error'] ?? [])));
    }

    public function test_term_filters_take_known_keys_only(): void
    {
        $unknown = (new TermsRouter)->execute(['action' => 'list', 'taxonomy' => 'lf_tags', 'filters' => ['status' => 'published']]);
        $this->assertFalse($unknown['success']);
        $this->assertStringContainsString('tone', implode(' ', (array) ($unknown['errors'] ?? $unknown['error'] ?? [])));
        $this->assertNotContains('status', $this->recordedIndexes('terms::lf_tags'));

        $byField = (new TermsRouter)->execute(['action' => 'list', 'taxonomy' => 'lf_tags', 'filters' => ['tone' => 'hard']]);
        $this->assertTrue($byField['success'], json_encode($byField));
        $this->assertSame(['loud'], array_column($byField['data']['terms'], 'slug'));
    }
}
