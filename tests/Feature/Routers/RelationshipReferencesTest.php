<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

/**
 * A reference must name a record that exists.
 *
 * Statamic's Terms fieldtype turns any value without `taxonomy::` into a new
 * term, outside this server's scope checks. The format spec told agents to send
 * UUIDs, so an agent created a term named after a UUID and was told it worked.
 */
class RelationshipReferencesTest extends TestCase
{
    private string $collection;

    private string $relatedId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.mcp.cache.stache_after_write' => 'none', 'statamic.mcp.cache.clear_static_after_write' => false]);

        foreach (['tags', 'topics'] as $taxonomy) {
            Taxonomy::make($taxonomy)->save();
        }
        Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();
        Term::make()->taxonomy('topics')->slug('news')->data(['title' => 'News'])->save();

        Collection::make('other')->save();
        $other = Entry::make()->collection('other')->slug('elsewhere')->data(['title' => 'Elsewhere']);
        $other->save();
        $this->relatedId = (string) $other->id();

        $this->collection = 'refs-' . bin2hex(random_bytes(4));
        Collection::make($this->collection)->taxonomies(['tags'])->save();
        Blueprint::make('page')->setNamespace("collections.{$this->collection}")->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'tags', 'field' => ['type' => 'terms', 'taxonomies' => ['tags']]],
            ['handle' => 'subjects', 'field' => ['type' => 'terms', 'taxonomies' => ['tags', 'topics']]],
            ['handle' => 'related', 'field' => ['type' => 'entries', 'collections' => [$this->collection]]],
            ['handle' => 'owners', 'field' => ['type' => 'users']],
        ]])->save();
    }

    /** @param  array<string, mixed>  $data */
    private function create(array $data): array
    {
        return (new EntriesRouter)->execute(['action' => 'create', 'collection' => $this->collection, 'data' => ['title' => 'Page', ...$data]]);
    }

    public function test_a_uuid_in_a_terms_field_is_refused_and_creates_no_term(): void
    {
        $uuid = '3f2b1a44-0c6e-4c3a-9f1e-8b5d6c7a2e10';

        $result = $this->create(['tags' => [$uuid]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("references term '{$uuid}', which does not exist in taxonomy tags", $result['errors'][0]);
        $this->assertStringContainsString('Existing slugs include: php', $result['errors'][0]);
        $this->assertSame(['php'], Term::query()->where('taxonomy', 'tags')->get()->map->slug()->all());
        $this->assertCount(0, Entry::query()->where('collection', $this->collection)->get());
    }

    public function test_an_existing_term_is_stored_as_the_control_panel_stores_it(): void
    {
        // One taxonomy: the bare slug, whether sent as slug or taxonomy::slug.
        // Several: the full id.
        $result = $this->create(['tags' => ['tags::php'], 'subjects' => ['topics::news']]);

        $this->assertTrue($result['success'], json_encode($result));
        $entry = Entry::find($result['data']['entry']['id']);
        $this->assertSame(['php'], $entry->get('tags'));
        $this->assertSame(['topics::news'], $entry->get('subjects'));
    }

    public function test_a_field_with_several_taxonomies_needs_the_taxonomy_named(): void
    {
        $result = $this->create(['subjects' => ['news']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('each value must be taxonomy::slug', $result['errors'][0]);
    }

    public function test_a_term_from_a_taxonomy_the_field_does_not_take_is_refused(): void
    {
        $result = $this->create(['tags' => ['topics::news']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('accepts terms from tags', $result['errors'][0]);
    }

    public function test_an_entry_from_another_collection_is_refused(): void
    {
        $result = $this->create(['related' => [$this->relatedId]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("accepts entries from {$this->collection}; entry '{$this->relatedId}' is in other", $result['errors'][0]);
    }

    public function test_a_missing_entry_or_user_is_refused(): void
    {
        $this->assertStringContainsString("references entry 'nope', which does not exist", $this->create(['related' => ['nope']])['errors'][0]);
        $this->assertStringContainsString("references user 'ghost', which does not exist", $this->create(['owners' => ['ghost']])['errors'][0]);

        $user = User::make()->email('owner@example.test');
        $user->save();
        $this->assertTrue($this->create(['owners' => [(string) $user->id()]])['success']);
    }
}
