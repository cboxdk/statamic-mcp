<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

class ContentRouterTest extends TestCase
{
    private ContentRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new ContentRouter;

        // Create test collection
        Collection::make('articles')
            ->title('Articles')
            ->routes('/articles/{slug}')
            ->save();

        // Create test taxonomy
        Taxonomy::make('categories')
            ->title('Categories')
            ->save();

        // Create test global set
        GlobalSet::make('settings')
            ->title('Site Settings')
            ->save();
    }

    public function test_list_entries(): void
    {
        // Clear all existing entries in the articles collection
        Entry::query()
            ->where('collection', 'articles')
            ->get()
            ->each(function ($entry) {
                $entry->delete();
            });

        // Create test entries
        Entry::make()
            ->id('entry1')
            ->collection('articles')
            ->slug('first-article')
            ->data([
                'title' => 'First Article',
                'content' => 'Content of first article',
            ])
            ->save();

        Entry::make()
            ->id('entry2')
            ->collection('articles')
            ->slug('second-article')
            ->data([
                'title' => 'Second Article',
                'content' => 'Content of second article',
            ])
            ->save();

        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'articles',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertArrayHasKey('entries', $data);
        $this->assertCount(2, $data['entries']);

        $slugs = collect($data['entries'])->pluck('slug')->toArray();
        $this->assertContains('first-article', $slugs);
        $this->assertContains('second-article', $slugs);
    }

    public function test_get_entry(): void
    {
        Entry::make()
            ->id('test-entry')
            ->collection('articles')
            ->slug('test-article')
            ->data([
                'title' => 'Test Article',
                'content' => 'Test content here',
                'author' => 'John Doe',
            ])
            ->save();

        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => 'test-entry',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['entry'];
        $this->assertEquals('test-entry', $data['id']);
        $this->assertEquals('test-article', $data['slug']);
        $this->assertEquals('Test Article', $data['title']);
        $this->assertEquals('Test content here', $data['content']);
        $this->assertEquals('John Doe', $data['author']);
    }

    public function test_create_entry(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'type' => 'entry',
            'collection' => 'articles',
            'slug' => 'new-article',
            'data' => [
                'title' => 'New Article',
                'content' => 'Content for new article',
                'published' => true,
            ],
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['entry'];
        $this->assertEquals('new-article', $data['slug']);
        $this->assertEquals('New Article', $data['title']);

        // Verify entry exists
        $entry = Entry::query()->where('collection', 'articles')->where('slug', 'new-article')->first();
        $this->assertNotNull($entry);
        $this->assertEquals('New Article', $entry->get('title'));
    }

    public function test_update_entry(): void
    {
        $entry = Entry::make()
            ->id('update-entry')
            ->collection('articles')
            ->slug('update-article')
            ->data(['title' => 'Original Title'])
            ->save();

        $result = $this->router->execute([
            'action' => 'update',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => 'update-entry',
            'data' => [
                'title' => 'Updated Title',
                'content' => 'Updated content',
            ],
        ]);

        $this->assertTrue($result['success']);

        $updatedEntry = Entry::find('update-entry');
        $this->assertEquals('Updated Title', $updatedEntry->get('title'));
        $this->assertEquals('Updated content', $updatedEntry->get('content'));
    }

    public function test_delete_entry(): void
    {
        Entry::make()
            ->id('delete-entry')
            ->collection('articles')
            ->slug('delete-article')
            ->data(['title' => 'To Delete'])
            ->save();

        $this->assertNotNull(Entry::find('delete-entry'));

        $result = $this->router->execute([
            'action' => 'delete',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => 'delete-entry',
        ]);

        $this->assertTrue($result['success']);
        $this->assertNull(Entry::find('delete-entry'));
    }

    public function test_publish_entry(): void
    {
        $entry = Entry::make()
            ->id('draft-entry')
            ->collection('articles')
            ->slug('draft-article')
            ->data(['title' => 'Draft Article'])
            ->published(false);

        $entry->save();

        $this->assertFalse($entry->published());

        $result = $this->router->execute([
            'action' => 'publish',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => 'draft-entry',
        ]);

        $this->assertTrue($result['success']);

        $publishedEntry = Entry::find('draft-entry');
        $this->assertTrue($publishedEntry->published());
    }

    public function test_unpublish_entry(): void
    {
        $entry = Entry::make()
            ->id('published-entry')
            ->collection('articles')
            ->slug('published-article')
            ->data(['title' => 'Published Article'])
            ->published(true);

        $entry->save();

        $this->assertTrue($entry->published());

        $result = $this->router->execute([
            'action' => 'unpublish',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => 'published-entry',
        ]);

        $this->assertTrue($result['success']);

        $unpublishedEntry = Entry::find('published-entry');
        $this->assertFalse($unpublishedEntry->published());
    }

    public function test_list_terms(): void
    {
        // Create test terms
        Term::make()
            ->taxonomy('categories')
            ->slug('technology')
            ->data(['title' => 'Technology'])
            ->save();

        Term::make()
            ->taxonomy('categories')
            ->slug('business')
            ->data(['title' => 'Business'])
            ->save();

        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'term',
            'taxonomy' => 'categories',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertArrayHasKey('terms', $data);
        $this->assertGreaterThanOrEqual(2, count($data['terms']));

        $slugs = collect($data['terms'])->pluck('slug')->toArray();
        $this->assertContains('technology', $slugs);
        $this->assertContains('business', $slugs);
    }

    public function test_get_term(): void
    {
        Term::make()
            ->taxonomy('categories')
            ->slug('science')
            ->data([
                'title' => 'Science',
                'description' => 'Science related articles',
            ])
            ->save();

        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'term',
            'taxonomy' => 'categories',
            'slug' => 'science',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['term'];
        $this->assertEquals('science', $data['slug']);
        $this->assertEquals('Science', $data['title']);
        $this->assertEquals('Science related articles', $data['description']);
    }

    public function test_create_term(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'type' => 'term',
            'taxonomy' => 'categories',
            'slug' => 'health',
            'data' => [
                'title' => 'Health',
                'description' => 'Health and wellness topics',
            ],
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['term'];
        $this->assertEquals('health', $data['slug']);
        $this->assertEquals('Health', $data['title']);

        // Verify term exists
        $term = Term::query()->where('taxonomy', 'categories')->where('slug', 'health')->first();
        $this->assertNotNull($term);
        $this->assertEquals('Health', $term->get('title'));
    }

    public function test_update_term(): void
    {
        $term = Term::make()
            ->taxonomy('categories')
            ->slug('sports')
            ->data(['title' => 'Sports'])
            ->save();

        $result = $this->router->execute([
            'action' => 'update',
            'type' => 'term',
            'taxonomy' => 'categories',
            'slug' => 'sports',
            'data' => [
                'title' => 'Sports & Recreation',
                'description' => 'Sports and recreational activities',
            ],
        ]);

        $this->assertTrue($result['success']);

        $updatedTerm = Term::query()->where('taxonomy', 'categories')->where('slug', 'sports')->first();
        $this->assertEquals('Sports & Recreation', $updatedTerm->get('title'));
        $this->assertEquals('Sports and recreational activities', $updatedTerm->get('description'));
    }

    public function test_delete_term(): void
    {
        Term::make()
            ->taxonomy('categories')
            ->slug('temp-category')
            ->data(['title' => 'Temporary Category'])
            ->save();

        $term = Term::query()->where('taxonomy', 'categories')->where('slug', 'temp-category')->first();
        $this->assertNotNull($term);

        $result = $this->router->execute([
            'action' => 'delete',
            'type' => 'term',
            'taxonomy' => 'categories',
            'slug' => 'temp-category',
        ]);

        $this->assertTrue($result['success']);

        $deletedTerm = Term::query()->where('taxonomy', 'categories')->where('slug', 'temp-category')->first();
        $this->assertNull($deletedTerm);
    }

    public function test_list_globals(): void
    {
        // Create additional global set
        GlobalSet::make('company')
            ->title('Company Info')
            ->save();

        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'global',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertArrayHasKey('globals', $data);
        $this->assertGreaterThanOrEqual(2, count($data['globals']));

        $handles = collect($data['globals'])->pluck('handle')->toArray();
        $this->assertContains('settings', $handles);
        $this->assertContains('company', $handles);
    }

    public function test_get_global(): void
    {
        // Set some values for the global set
        $globalSet = GlobalSet::find('settings');
        $globalSet->in('default')->data([
            'site_name' => 'My Website',
            'contact_email' => 'contact@example.com',
        ]);
        $globalSet->save();

        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'global',
            'handle' => 'settings',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['global'];
        $this->assertEquals('settings', $data['handle']);
        $this->assertEquals('Site Settings', $data['title']);
        $this->assertArrayHasKey('values', $data);
        $this->assertEquals('My Website', $data['values']['site_name']);
        $this->assertEquals('contact@example.com', $data['values']['contact_email']);
    }

    public function test_update_global(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'type' => 'global',
            'handle' => 'settings',
            'site' => 'default',
            'data' => [
                'site_name' => 'Updated Website Name',
                'footer_text' => 'Copyright 2024',
            ],
        ]);

        $this->assertTrue($result['success']);

        $globalSet = GlobalSet::find('settings');
        $values = $globalSet->in('default')->data();
        $this->assertEquals('Updated Website Name', $values->get('site_name'));
        $this->assertEquals('Copyright 2024', $values->get('footer_text'));
    }

    public function test_invalid_action(): void
    {
        $result = $this->router->execute([
            'action' => 'invalid',
            'type' => 'entry',
            'collection' => 'blog', // Provide collection to pass validation
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Action invalid not supported for type entry', $result['errors'][0]);
    }

    public function test_invalid_type(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'invalid',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Action list not supported for type invalid', $result['errors'][0]);
    }

    public function test_missing_collection_for_entry_list(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'entry',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection handle is required for entry operations', $result['errors'][0]);
    }

    public function test_missing_taxonomy_for_term_list(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'term',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Taxonomy handle is required for term operations', $result['errors'][0]);
    }

    public function test_entry_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => 'nonexistent',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent', $result['errors'][0]);
    }

    public function test_term_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'term',
            'taxonomy' => 'categories',
            'id' => 'nonexistent',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Term not found: nonexistent', $result['errors'][0]);
    }

    public function test_global_not_found(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'global',
            'global_set' => 'nonexistent',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Global set not found: nonexistent', $result['errors'][0]);
    }

    public function test_missing_id_for_entry_get(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'entry',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry ID is required for get action', $result['errors'][0]);
    }

    public function test_missing_data_for_entry_create(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'type' => 'entry',
            'collection' => 'articles',
            'slug' => 'test',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Data is required for create action', $result['errors'][0]);
    }

    public function test_filter_entries_by_status(): void
    {
        // Create published and draft entries
        Entry::make()
            ->id('published-entry')
            ->collection('articles')
            ->slug('published')
            ->published(true)
            ->data(['title' => 'Published Entry'])
            ->save();

        Entry::make()
            ->id('draft-entry')
            ->collection('articles')
            ->slug('draft')
            ->published(false)
            ->data(['title' => 'Draft Entry'])
            ->save();

        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'articles',
            'filter' => ['published' => true],
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertGreaterThanOrEqual(1, count($data['entries']));

        // Check that our published entry is in the results
        $titles = collect($data['entries'])->pluck('title')->toArray();
        $this->assertContains('Published Entry', $titles);
    }

    public function test_search_entries(): void
    {
        Entry::make()
            ->id('search1')
            ->collection('articles')
            ->slug('laravel-tips')
            ->data(['title' => 'Laravel Development Tips'])
            ->save();

        Entry::make()
            ->id('search2')
            ->collection('articles')
            ->slug('vue-guide')
            ->data(['title' => 'Vue.js Complete Guide'])
            ->save();

        $result = $this->router->execute([
            'action' => 'list',
            'type' => 'entry',
            'collection' => 'articles',
            'search' => 'Laravel',
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertGreaterThanOrEqual(1, count($data['entries']));

        // Check that our search result is present
        $titles = collect($data['entries'])->pluck('title')->toArray();
        $this->assertContains('Laravel Development Tips', $titles);
    }
}
