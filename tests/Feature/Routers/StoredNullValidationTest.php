<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * An update validates the stored record merged with the incoming payload, so
 * required rules keep passing for fields the caller did not resend. Stored
 * nulls must not take part in that: Statamic only validates a field whose key
 * is present, and the Control Panel never submits a field its conditions hide.
 *
 * Advanced SEO's canonical fields are the case that surfaced it. Both carry a
 * `required_if:seo_canonical_type,…` rule, which stops Statamic from adding
 * `nullable`, next to `array` (entries) and `active_url` (text). A record
 * holding `seo_canonical_entry: null` and `seo_canonical_custom: null` could
 * not be updated at all without sending a value for both: "The Entry field
 * must be an array", "The URL field must be a valid URL".
 *
 * Such records are the norm, not the exception. A Control Panel save merges
 * every blueprint field into the entry, hidden ones as null, and Statamic
 * caches that object in the Stache as-is; only the file on disk has the nulls
 * stripped. So an entry published from the CP hands `Entry::find()` nulls
 * until the next Stache refresh. `create` wrote them to disk outright. The
 * seeds below save without refreshing the Stache, which is exactly the state
 * a CP publish leaves behind.
 */
class StoredNullValidationTest extends TestCase
{
    /**
     * The canonical trio as Advanced SEO declares it, with `url` standing in
     * for `active_url` so the test does not resolve DNS. Both rules reject null
     * the same way and share the same message.
     *
     * @return list<array<string, mixed>>
     */
    private function canonicalFields(): array
    {
        return [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'seo_canonical_type', 'field' => [
                'type' => 'button_group',
                'options' => ['current' => 'Current', 'entry' => 'Entry', 'custom' => 'Custom'],
                'default' => 'current',
            ]],
            ['handle' => 'seo_canonical_entry', 'field' => [
                'type' => 'entries',
                'display' => 'Entry',
                'max_items' => 1,
                'if' => ['seo_canonical_type' => 'equals entry'],
                'validate' => ['required_if:seo_canonical_type,entry'],
            ]],
            ['handle' => 'seo_canonical_custom', 'field' => [
                'type' => 'text',
                'display' => 'URL',
                'input_type' => 'url',
                'if' => ['seo_canonical_type' => 'equals custom'],
                'validate' => ['required_if:seo_canonical_type,custom', 'url'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedWithNulls(): array
    {
        return [
            'title' => 'Vergaderen',
            'seo_canonical_type' => 'current',
            'seo_canonical_entry' => null,
            'seo_canonical_custom' => null,
        ];
    }

    // ─── Entries ──────────────────────────────────────────────────────

    private function makeCollectionWithCanonicalFields(): string
    {
        $handle = 'pages-' . bin2hex(random_bytes(4));

        Collection::make($handle)->title('Pages')->save();

        Blueprint::make('pages')->setNamespace("collections.{$handle}")->setContents([
            'fields' => $this->canonicalFields(),
        ])->save();

        Stache::refresh();

        return $handle;
    }

    public function test_entry_update_ignores_stored_nulls_on_fields_it_does_not_touch(): void
    {
        $collection = $this->makeCollectionWithCanonicalFields();

        Entry::make()->id('meeting')->collection($collection)->slug('vergaderen')
            ->data($this->storedWithNulls())->save();

        $result = (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $collection,
            'id' => 'meeting',
            'data' => ['title' => 'Meerdaags vergaderen'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $entry = Entry::find('meeting');
        $this->assertSame('Meerdaags vergaderen', $entry->get('title'));
        $this->assertSame('current', $entry->get('seo_canonical_type'));
    }

    public function test_entry_update_still_validates_a_value_the_caller_sends(): void
    {
        $collection = $this->makeCollectionWithCanonicalFields();

        Entry::make()->id('meeting')->collection($collection)->slug('vergaderen')
            ->data($this->storedWithNulls())->save();

        $result = (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $collection,
            'id' => 'meeting',
            'data' => ['seo_canonical_custom' => 'not a url'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('seo_canonical_custom: The URL field must be a valid URL', $result['errors'][0]);
    }

    public function test_entry_update_still_enforces_required_if_against_the_stored_null(): void
    {
        $collection = $this->makeCollectionWithCanonicalFields();

        Entry::make()->id('meeting')->collection($collection)->slug('vergaderen')
            ->data($this->storedWithNulls())->save();

        // Switching the canonical type to "custom" makes the (still null) URL
        // required. Dropping the null from validation must not hide that.
        $result = (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $collection,
            'id' => 'meeting',
            'data' => ['seo_canonical_type' => 'custom'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('seo_canonical_custom', $result['errors'][0]);
        $this->assertStringContainsString('required', $result['errors'][0]);
    }

    public function test_entry_create_stores_only_the_fields_it_was_given(): void
    {
        $collection = $this->makeCollectionWithCanonicalFields();

        $created = (new EntriesRouter)->execute([
            'action' => 'create',
            'collection' => $collection,
            'slug' => 'feesten',
            'data' => ['title' => 'Feesten'],
        ]);

        $this->assertTrue($created['success'], json_encode($created['errors'] ?? []));

        $stored = Entry::find($created['data']['entry']['id'])->data()->all();
        $this->assertSame('Feesten', $stored['title']);
        $this->assertArrayNotHasKey('seo_canonical_type', $stored);
        $this->assertArrayNotHasKey('seo_canonical_entry', $stored);
        $this->assertArrayNotHasKey('seo_canonical_custom', $stored);

        // The round trip that failed in production: edit a created entry.
        $updated = (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $collection,
            'id' => $created['data']['entry']['id'],
            'data' => ['title' => 'Bedrijfsfeesten'],
        ]);

        $this->assertTrue($updated['success'], json_encode($updated['errors'] ?? []));
    }

    // ─── Terms ────────────────────────────────────────────────────────

    public function test_term_update_ignores_stored_nulls_on_fields_it_does_not_touch(): void
    {
        $taxonomy = 'tags-' . bin2hex(random_bytes(4));

        Taxonomy::make($taxonomy)->title('Tags')->save();

        Blueprint::make('tag')->setNamespace("taxonomies.{$taxonomy}")->setContents([
            'fields' => $this->canonicalFields(),
        ])->save();

        Stache::refresh();

        Term::make()->taxonomy($taxonomy)->slug('zakelijk')->data($this->storedWithNulls())->save();

        $result = (new TermsRouter)->execute([
            'action' => 'update',
            'taxonomy' => $taxonomy,
            'slug' => 'zakelijk',
            'data' => ['title' => 'Zakelijk'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('Zakelijk', Term::find("{$taxonomy}::zakelijk")->get('title'));
    }

    // ─── Globals ──────────────────────────────────────────────────────

    public function test_global_update_ignores_stored_nulls_on_fields_it_does_not_touch(): void
    {
        $handle = 'seo-' . bin2hex(random_bytes(4));

        $globalSet = GlobalSet::make($handle)->title('SEO');
        $globalSet->save();

        Blueprint::make($handle)->setNamespace('globals')->setContents([
            'fields' => $this->canonicalFields(),
        ])->save();

        Stache::refresh();

        $variables = $globalSet->makeLocalization('default');
        $variables->data($this->storedWithNulls());
        $variables->save();

        $result = (new GlobalsRouter)->execute([
            'action' => 'update',
            'handle' => $handle,
            'site' => 'default',
            'data' => ['title' => 'Landgoed'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('Landgoed', GlobalSet::find($handle)->in('default')->get('title'));
    }
}
