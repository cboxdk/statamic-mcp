<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * Edge case and validation tests for TermsRouter.
 */
class TermsRouterTest extends TestCase
{
    private TermsRouter $router;

    private string $testId;

    private string $taxonomyHandle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new TermsRouter;
        $this->testId = bin2hex(random_bytes(8));
        $this->taxonomyHandle = "tags-{$this->testId}";

        Taxonomy::make($this->taxonomyHandle)
            ->title('Tags')
            ->save();

        Stache::refresh();
    }

    // ─── Taxonomy validation ───────────────────────────────────────────

    public function test_taxonomy_is_required(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The list action needs taxonomy', $result['errors'][0]);
    }

    public function test_nonexistent_taxonomy_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'taxonomy' => 'nonexistent-taxonomy',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Taxonomy not found: nonexistent-taxonomy', $result['errors'][0]);
    }

    // ─── List action ───────────────────────────────────────────────────

    public function test_list_terms_empty(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'taxonomy' => $this->taxonomyHandle,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['data']['items']);
        $this->assertEquals(0, $result['data']['total']);
        $this->assertNull($result['data']['next_cursor']);
    }

    public function test_list_terms_with_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Term::make()
                ->taxonomy($this->taxonomyHandle)
                ->slug("tag-{$i}-{$this->testId}")
                ->data(['title' => "Tag {$i}"])
                ->save();
        }

        $result = $this->router->execute([
            'action' => 'list',
            'taxonomy' => $this->taxonomyHandle,
            'limit' => 2,
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['items']);
        $this->assertEquals(5, $result['data']['total']);
        $this->assertIsString($result['data']['next_cursor']);
    }

    public function test_list_terms_with_cursor(): void
    {
        foreach ([5, 2, 4, 1, 3] as $i) {
            Term::make()
                ->taxonomy($this->taxonomyHandle)
                ->slug("offset-{$i}-{$this->testId}")
                ->data(['title' => "Offset Term {$i}"])
                ->save();
        }

        $arguments = [
            'action' => 'list',
            'taxonomy' => $this->taxonomyHandle,
            'limit' => 2,
        ];
        $titles = [];
        do {
            $result = $this->router->execute($arguments);
            $this->assertTrue($result['success'], json_encode($result));
            $titles = [...$titles, ...array_column($result['data']['items'], 'title')];
            $arguments['cursor'] = $result['data']['next_cursor'];
            $this->assertLessThanOrEqual(5, count($titles));
        } while ($arguments['cursor'] !== null);

        $this->assertSame(['Offset Term 1', 'Offset Term 2', 'Offset Term 3', 'Offset Term 4', 'Offset Term 5'], $titles);
        $this->assertCount(1, $result['data']['items']);
    }

    public function test_list_terms_returns_correct_structure(): void
    {
        Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug("structure-test-{$this->testId}")
            ->data(['title' => 'Structure Test'])
            ->save();

        $result = $this->router->execute([
            'action' => 'list',
            'taxonomy' => $this->taxonomyHandle,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('items', $result['data']);
        $this->assertArrayHasKey('next_cursor', $result['data']);
        $this->assertArrayHasKey('taxonomy', $result['data']);
        $this->assertArrayHasKey('site', $result['data']);

        $term = $result['data']['items'][0];
        $this->assertArrayHasKey('id', $term);
        $this->assertArrayHasKey('slug', $term);
        $this->assertArrayHasKey('title', $term);
        $this->assertArrayHasKey('taxonomy', $term);
        $this->assertArrayHasKey('site', $term);
        $this->assertArrayHasKey('url', $term);
    }

    // ─── Get action ────────────────────────────────────────────────────

    public function test_get_term_by_id(): void
    {
        $term = Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug("id-lookup-{$this->testId}")
            ->data(['title' => 'ID Lookup Test']);
        $term->save();

        $result = $this->router->execute([
            'action' => 'get',
            'taxonomy' => $this->taxonomyHandle,
            'id' => $term->id(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('ID Lookup Test', $result['data']['term']['data']['title']);
    }

    public function test_get_term_by_slug(): void
    {
        $slug = "slug-lookup-{$this->testId}";
        Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug($slug)
            ->data(['title' => 'Slug Lookup Test'])
            ->save();

        $result = $this->router->execute([
            'action' => 'get',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => $slug,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals($slug, $result['data']['term']['slug']);
        $this->assertEquals($this->taxonomyHandle, $result['data']['term']['taxonomy']);
    }

    public function test_get_term_requires_id_or_slug(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'taxonomy' => $this->taxonomyHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('needs id or slug', $result['errors'][0]);
    }

    public function test_get_nonexistent_term_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'get',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => 'nonexistent-term',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Term not found', $result['errors'][0]);
    }

    public function test_get_term_returns_full_data(): void
    {
        $slug = "full-data-{$this->testId}";
        Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug($slug)
            ->data(['title' => 'Full Data Test', 'description' => 'A description'])
            ->save();

        $result = $this->router->execute([
            'action' => 'get',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => $slug,
        ]);

        $this->assertTrue($result['success']);
        $term = $result['data']['term'];
        $this->assertArrayHasKey('id', $term);
        $this->assertArrayHasKey('taxonomy', $term);
        $this->assertArrayHasKey('site', $term);
        $this->assertArrayHasKey('slug', $term);
        $this->assertArrayHasKey('url', $term);
        $this->assertArrayHasKey('data', $term);
        $this->assertArrayHasKey('entries_count', $term);
    }

    public function test_get_term_entries_count(): void
    {
        Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug("count-test-{$this->testId}")
            ->data(['title' => 'Count Test'])
            ->save();

        $result = $this->router->execute([
            'action' => 'get',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => "count-test-{$this->testId}",
            'include_counts' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('entries_count', $result['data']['term']);
        $this->assertEquals(0, $result['data']['term']['entries_count']);
    }

    // ─── Create action ─────────────────────────────────────────────────

    public function test_create_term(): void
    {
        $slug = "created-{$this->testId}";
        $result = $this->router->execute([
            'action' => 'create',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => $slug,
            'data' => ['title' => 'Created Term'],
        ]);

        if (! $result['success']) {
            dump('Create term failed:', $result);
        }

        $this->assertTrue($result['success']);
        $this->assertEquals($slug, $result['data']['term']['slug']);
        $this->assertTrue($result['data']['created']);
    }

    public function test_create_term_requires_data(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'taxonomy' => $this->taxonomyHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The create action needs data', $result['errors'][0]);
    }

    public function test_create_term_generates_slug_from_title(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'taxonomy' => $this->taxonomyHandle,
            'data' => ['title' => 'Auto Generated Slug'],
        ]);

        if (! $result['success']) {
            dump('Create term failed:', $result);
        }

        $this->assertTrue($result['success']);
        $this->assertEquals('auto-generated-slug', $result['data']['term']['slug']);
    }

    // ─── Update action ─────────────────────────────────────────────────

    public function test_update_term_by_slug(): void
    {
        $slug = "update-slug-{$this->testId}";
        Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug($slug)
            ->data(['title' => 'Original Title'])
            ->save();

        $result = $this->router->execute([
            'action' => 'update',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => $slug,
            'data' => ['title' => 'Updated Title'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);
        $this->assertEquals($slug, $result['data']['term']['slug']);
    }

    public function test_update_term_by_id(): void
    {
        $term = Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug("update-by-id-{$this->testId}")
            ->data(['title' => 'Original Title']);
        $term->save();

        $result = $this->router->execute([
            'action' => 'update',
            'taxonomy' => $this->taxonomyHandle,
            'id' => $term->id(),
            'data' => ['title' => 'Updated Via ID'],
        ]);

        $this->assertTrue($result['success']);

        $updatedTerm = Term::find($term->id());
        $this->assertEquals('Updated Via ID', $updatedTerm->get('title'));
    }

    public function test_update_nonexistent_term_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => 'nonexistent-slug',
            'data' => ['title' => 'Test'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Term not found: nonexistent-slug', $result['errors'][0]);
    }

    public function test_update_requires_id_or_slug(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'taxonomy' => $this->taxonomyHandle,
            'data' => ['title' => 'Updated'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('needs id or slug', $result['errors'][0]);
    }

    // ─── Delete action ─────────────────────────────────────────────────

    public function test_delete_term(): void
    {
        $slug = "delete-me-{$this->testId}";
        Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug($slug)
            ->data(['title' => 'Delete Me'])
            ->save();

        $result = $this->router->execute([
            'action' => 'delete',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => $slug,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['deleted']);
        $this->assertEquals($slug, $result['data']['term']['slug']);
    }

    public function test_delete_requires_id_or_slug(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
            'taxonomy' => $this->taxonomyHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('needs id or slug', $result['errors'][0]);
    }

    public function test_delete_nonexistent_term_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => 'nonexistent-slug',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Term not found: nonexistent-slug', $result['errors'][0]);
    }

    public function test_delete_term_with_entries_returns_error(): void
    {
        // Create a collection that uses this taxonomy
        $collectionHandle = "posts-{$this->testId}";
        Collection::make($collectionHandle)
            ->title('Posts')
            ->taxonomies([$this->taxonomyHandle])
            ->save();

        // Create a term
        $slug = "in-use-{$this->testId}";
        Term::make()
            ->taxonomy($this->taxonomyHandle)
            ->slug($slug)
            ->data(['title' => 'In Use Term'])
            ->save();

        // Create an entry referencing this term
        Entry::make()
            ->id("entry-ref-{$this->testId}")
            ->collection($collectionHandle)
            ->slug("referencing-entry-{$this->testId}")
            ->data([
                'title' => 'Referencing Entry',
                $this->taxonomyHandle => [$slug],
            ])
            ->save();

        Stache::refresh();

        $result = $this->router->execute([
            'action' => 'delete',
            'taxonomy' => $this->taxonomyHandle,
            'slug' => $slug,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('entries are using this term', $result['errors'][0]);
    }

    // ─── Invalid action ────────────────────────────────────────────────

    public function test_invalid_action_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'nonexistent_action',
            'taxonomy' => $this->taxonomyHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("Unknown action 'nonexistent_action'. Use one of: list, get, create, update, delete.", $result['errors'][0]);
    }

    // ─── Site validation ───────────────────────────────────────────────

    public function test_invalid_site_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'taxonomy' => $this->taxonomyHandle,
            'site' => 'nonexistent-site',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid site handle', $result['errors'][0]);
    }
}
