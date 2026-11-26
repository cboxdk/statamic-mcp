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
 * Every Relationship fieldtype validates as `array`, but with `max_items: 1`
 * Statamic stores a bare handle. The Control Panel wraps it before it submits;
 * an update merges the stored record into what it validates, so a relationship
 * the sanitizer did not know about failed "must be an array" on every write,
 * even one that never touched it (#102). `form` is the case that was reported;
 * `collections` stands in for the rest of the subclasses.
 */
class StoredRelationshipShapeTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'form', 'field' => ['type' => 'form', 'max_items' => 1]],
            ['handle' => 'source', 'field' => ['type' => 'collections', 'max_items' => 1]],
            ['handle' => 'page_builder', 'field' => [
                'type' => 'replicator',
                'sets' => ['main' => ['sets' => ['contact_form' => ['fields' => [
                    ['handle' => 'form', 'field' => ['type' => 'form', 'max_items' => 1]],
                ]]]]],
            ]],
        ];
    }

    /**
     * The record as the Control Panel stores it.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return [
            'title' => 'Contact',
            'form' => 'contact',
            'source' => 'pages',
            'page_builder' => [
                ['id' => 'set1', 'type' => 'contact_form', 'enabled' => true, 'form' => 'contact'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertShapeKept(array $data): void
    {
        $this->assertSame('contact', $data['form']);
        $this->assertSame('pages', $data['source']);
        $this->assertSame('contact', $data['page_builder'][0]['form']);
    }

    public function test_entry_update_accepts_stored_single_relationships(): void
    {
        $collection = 'pages-' . bin2hex(random_bytes(4));
        Collection::make($collection)->title('Pages')->save();
        Blueprint::make('page')->setNamespace("collections.{$collection}")
            ->setContents(['fields' => $this->fields()])->save();
        Stache::refresh();

        Entry::make()->id('contact-page')->collection($collection)->slug('contact')
            ->data($this->stored())->save();

        $result = (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $collection,
            'id' => 'contact-page',
            'data' => ['title' => 'Contact us'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $entry = Entry::find('contact-page');
        $this->assertSame('Contact us', $entry->get('title'));
        $this->assertShapeKept($entry->data()->all());
    }

    public function test_entry_update_accepts_a_bare_handle_it_is_sent(): void
    {
        $collection = 'pages-' . bin2hex(random_bytes(4));
        Collection::make($collection)->title('Pages')->save();
        Blueprint::make('page')->setNamespace("collections.{$collection}")
            ->setContents(['fields' => $this->fields()])->save();
        Stache::refresh();

        Entry::make()->id('contact-page')->collection($collection)->slug('contact')
            ->data(['title' => 'Contact'])->save();

        $result = (new EntriesRouter)->execute([
            'action' => 'update',
            'collection' => $collection,
            'id' => 'contact-page',
            'data' => $this->stored(),
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertShapeKept(Entry::find('contact-page')->data()->all());
    }

    public function test_term_update_accepts_stored_single_relationships(): void
    {
        $taxonomy = 'tags-' . bin2hex(random_bytes(4));
        Taxonomy::make($taxonomy)->title('Tags')->save();
        Blueprint::make('tag')->setNamespace("taxonomies.{$taxonomy}")
            ->setContents(['fields' => $this->fields()])->save();
        Stache::refresh();

        Term::make()->taxonomy($taxonomy)->slug('contact')->data($this->stored())->save();

        $result = (new TermsRouter)->execute([
            'action' => 'update',
            'taxonomy' => $taxonomy,
            'slug' => 'contact',
            'data' => ['title' => 'Contact us'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertShapeKept(Term::find("{$taxonomy}::contact")->data()->all());
    }

    public function test_global_update_accepts_stored_single_relationships(): void
    {
        $handle = 'site-' . bin2hex(random_bytes(4));
        $globalSet = GlobalSet::make($handle)->title('Site');
        $globalSet->save();
        Blueprint::make($handle)->setNamespace('globals')
            ->setContents(['fields' => $this->fields()])->save();
        Stache::refresh();

        $variables = $globalSet->makeLocalization('default');
        $variables->data($this->stored());
        $variables->save();

        $result = (new GlobalsRouter)->execute([
            'action' => 'update',
            'handle' => $handle,
            'site' => 'default',
            'data' => ['title' => 'Contact us'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertShapeKept(GlobalSet::find($handle)->in('default')->data()->all());
    }
}
