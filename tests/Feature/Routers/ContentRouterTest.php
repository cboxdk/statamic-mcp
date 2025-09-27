<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

class ContentRouterTest extends TestCase
{
    private ContentRouter $router;

    private string $testId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new ContentRouter;

        // Generate unique test ID for this test run to avoid parallel test conflicts
        $this->testId = time() . '-' . getmypid() . '-' . rand(1000, 9999);

        // Set up test storage with proper disk configuration
        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);

        Storage::fake('assets');

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

        // Create test entries with unique IDs
        Entry::make()
            ->id("entry1-{$this->testId}")
            ->collection('articles')
            ->slug("first-article-{$this->testId}")
            ->data([
                'title' => 'First Article',
                'content' => 'Content of first article',
            ])
            ->save();

        Entry::make()
            ->id("entry2-{$this->testId}")
            ->collection('articles')
            ->slug("second-article-{$this->testId}")
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
        $this->assertContains("first-article-{$this->testId}", $slugs);
        $this->assertContains("second-article-{$this->testId}", $slugs);
    }

    public function test_get_entry(): void
    {
        $uniqueId = "test-entry-{$this->testId}";
        $uniqueSlug = "test-article-{$this->testId}";

        Entry::make()
            ->id($uniqueId)
            ->collection('articles')
            ->slug($uniqueSlug)
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
            'id' => $uniqueId,
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['entry'];
        $this->assertEquals($uniqueId, $data['id']);
        $this->assertEquals($uniqueSlug, $data['slug']);
        $this->assertEquals('Test Article', $data['title']);
        $this->assertEquals('Test content here', $data['content']);
        $this->assertEquals('John Doe', $data['author']);
    }

    public function test_create_entry(): void
    {
        // Use unique slug to avoid conflicts in parallel tests
        $uniqueSlug = "new-article-{$this->testId}";

        $result = $this->router->execute([
            'action' => 'create',
            'type' => 'entry',
            'collection' => 'articles',
            'slug' => $uniqueSlug,
            'data' => [
                'title' => 'New Article',
                'content' => 'Content for new article',
                'published' => true,
            ],
        ]);

        // Debug output if test fails
        if (! $result['success']) {
            dump('Create entry failed:', $result);
        }

        $this->assertTrue($result['success']);
        $data = $result['data']['entry'];
        $this->assertEquals($uniqueSlug, $data['slug']);
        $this->assertEquals('New Article', $data['title']);

        // Verify entry exists
        $entry = Entry::query()->where('collection', 'articles')->where('slug', $uniqueSlug)->first();
        $this->assertNotNull($entry);
        $this->assertEquals('New Article', $entry->get('title'));
    }

    public function test_update_entry(): void
    {
        $uniqueId = "update-entry-{$this->testId}";
        $uniqueSlug = "update-article-{$this->testId}";

        $entry = Entry::make()
            ->id($uniqueId)
            ->collection('articles')
            ->slug($uniqueSlug)
            ->data(['title' => 'Original Title'])
            ->save();

        $result = $this->router->execute([
            'action' => 'update',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => $uniqueId,
            'data' => [
                'title' => 'Updated Title',
                'content' => 'Updated content',
            ],
        ]);

        $this->assertTrue($result['success']);

        $updatedEntry = Entry::find($uniqueId);
        $this->assertEquals('Updated Title', $updatedEntry->get('title'));
        $this->assertEquals('Updated content', $updatedEntry->get('content'));
    }

    public function test_delete_entry(): void
    {
        $uniqueId = "delete-entry-{$this->testId}";
        $uniqueSlug = "delete-article-{$this->testId}";

        Entry::make()
            ->id($uniqueId)
            ->collection('articles')
            ->slug($uniqueSlug)
            ->data(['title' => 'To Delete'])
            ->save();

        $this->assertNotNull(Entry::find($uniqueId));

        $result = $this->router->execute([
            'action' => 'delete',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => $uniqueId,
        ]);

        $this->assertTrue($result['success']);
        $this->assertNull(Entry::find($uniqueId));
    }

    public function test_publish_entry(): void
    {
        $uniqueId = "draft-entry-{$this->testId}";
        $uniqueSlug = "draft-article-{$this->testId}";

        $entry = Entry::make()
            ->id($uniqueId)
            ->collection('articles')
            ->slug($uniqueSlug)
            ->data(['title' => 'Draft Article'])
            ->published(false);

        $entry->save();

        $this->assertFalse($entry->published());

        $result = $this->router->execute([
            'action' => 'publish',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => $uniqueId,
        ]);

        $this->assertTrue($result['success']);

        $publishedEntry = Entry::find($uniqueId);
        $this->assertTrue($publishedEntry->published());
    }

    public function test_unpublish_entry(): void
    {
        $uniqueId = "published-entry-{$this->testId}";
        $uniqueSlug = "published-article-{$this->testId}";

        $entry = Entry::make()
            ->id($uniqueId)
            ->collection('articles')
            ->slug($uniqueSlug)
            ->data(['title' => 'Published Article'])
            ->published(true);

        $entry->save();

        $this->assertTrue($entry->published());

        $result = $this->router->execute([
            'action' => 'unpublish',
            'type' => 'entry',
            'collection' => 'articles',
            'id' => $uniqueId,
        ]);

        $this->assertTrue($result['success']);

        $unpublishedEntry = Entry::find($uniqueId);
        $this->assertFalse($unpublishedEntry->published());
    }

    public function test_list_terms(): void
    {
        // Create test terms with unique slugs
        $techSlug = "technology-{$this->testId}";
        $businessSlug = "business-{$this->testId}";

        Term::make()
            ->taxonomy('categories')
            ->slug($techSlug)
            ->data(['title' => 'Technology'])
            ->save();

        Term::make()
            ->taxonomy('categories')
            ->slug($businessSlug)
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
        $this->assertContains($techSlug, $slugs);
        $this->assertContains($businessSlug, $slugs);
    }

    public function test_get_term(): void
    {
        $uniqueSlug = "science-{$this->testId}";

        Term::make()
            ->taxonomy('categories')
            ->slug($uniqueSlug)
            ->data([
                'title' => 'Science',
                'description' => 'Science related articles',
            ])
            ->save();

        $result = $this->router->execute([
            'action' => 'get',
            'type' => 'term',
            'taxonomy' => 'categories',
            'slug' => $uniqueSlug,
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['term'];
        $this->assertEquals($uniqueSlug, $data['slug']);
        $this->assertEquals('Science', $data['title']);
        $this->assertEquals('Science related articles', $data['description']);
    }

    public function test_create_term(): void
    {
        $uniqueSlug = "health-{$this->testId}";

        $result = $this->router->execute([
            'action' => 'create',
            'type' => 'term',
            'taxonomy' => 'categories',
            'slug' => $uniqueSlug,
            'data' => [
                'title' => 'Health',
                'description' => 'Health and wellness topics',
            ],
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data']['term'];
        $this->assertEquals($uniqueSlug, $data['slug']);
        $this->assertEquals('Health', $data['title']);

        // Verify term exists
        $term = Term::query()->where('taxonomy', 'categories')->where('slug', $uniqueSlug)->first();
        $this->assertNotNull($term);
        $this->assertEquals('Health', $term->get('title'));
    }

    public function test_update_term(): void
    {
        $uniqueSlug = "sports-{$this->testId}";

        $term = Term::make()
            ->taxonomy('categories')
            ->slug($uniqueSlug)
            ->data(['title' => 'Sports'])
            ->save();

        $result = $this->router->execute([
            'action' => 'update',
            'type' => 'term',
            'taxonomy' => 'categories',
            'slug' => $uniqueSlug,
            'data' => [
                'title' => 'Sports & Recreation',
                'description' => 'Sports and recreational activities',
            ],
        ]);

        $this->assertTrue($result['success']);

        $updatedTerm = Term::query()->where('taxonomy', 'categories')->where('slug', $uniqueSlug)->first();
        $this->assertEquals('Sports & Recreation', $updatedTerm->get('title'));
        $this->assertEquals('Sports and recreational activities', $updatedTerm->get('description'));
    }

    public function test_delete_term(): void
    {
        $uniqueSlug = "temp-category-{$this->testId}";

        Term::make()
            ->taxonomy('categories')
            ->slug($uniqueSlug)
            ->data(['title' => 'Temporary Category'])
            ->save();

        $term = Term::query()->where('taxonomy', 'categories')->where('slug', $uniqueSlug)->first();
        $this->assertNotNull($term);

        $result = $this->router->execute([
            'action' => 'delete',
            'type' => 'term',
            'taxonomy' => 'categories',
            'slug' => $uniqueSlug,
        ]);

        $this->assertTrue($result['success']);

        $deletedTerm = Term::query()->where('taxonomy', 'categories')->where('slug', $uniqueSlug)->first();
        $this->assertNull($deletedTerm);
    }

    public function test_list_globals(): void
    {
        // Create additional global set with unique handle
        $uniqueHandle = "company-{$this->testId}";

        GlobalSet::make($uniqueHandle)
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
        $this->assertContains($uniqueHandle, $handles);
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
        // Action validation happens first, so expect invalid action error
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
        // Create published and draft entries with unique IDs
        $publishedId = "published-entry-{$this->testId}";
        $draftId = "draft-entry-{$this->testId}";

        Entry::make()
            ->id($publishedId)
            ->collection('articles')
            ->slug("published-{$this->testId}")
            ->published(true)
            ->data(['title' => 'Published Entry'])
            ->save();

        Entry::make()
            ->id($draftId)
            ->collection('articles')
            ->slug("draft-{$this->testId}")
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
        $searchId1 = "search1-{$this->testId}";
        $searchId2 = "search2-{$this->testId}";

        Entry::make()
            ->id($searchId1)
            ->collection('articles')
            ->slug("laravel-tips-{$this->testId}")
            ->data(['title' => 'Laravel Development Tips'])
            ->save();

        Entry::make()
            ->id($searchId2)
            ->collection('articles')
            ->slug("vue-guide-{$this->testId}")
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
