<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Support\FieldtypeExtensions;
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
 * A text field accepted a list: Statamic's `required` passes for a non-empty
 * array and the field had no sanitizer, so `title: ["a", "b"]` was saved and
 * the entry broke when it was rendered (#77). The string-valued fieldtypes now
 * take a string or a number and refuse anything else, at every level.
 */
class StringFieldValueTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();
        FieldtypeExtensions::flush();

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'blog-' . bin2hex(random_bytes(4));
        Collection::make($this->collectionHandle)->title('Blog')->save();

        Blueprint::make('post')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
                ['handle' => 'summary', 'field' => ['type' => 'textarea']],
                ['handle' => 'body', 'field' => ['type' => 'markdown']],
                ['handle' => 'cta', 'field' => ['type' => 'link']],
                ['handle' => 'page_builder', 'field' => [
                    'type' => 'replicator',
                    'sets' => [
                        'main' => [
                            'sets' => [
                                'quote' => [
                                    'fields' => [
                                        ['handle' => 'text', 'field' => ['type' => 'text']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]],
            ],
        ])->save();

        Stache::refresh();
    }

    protected function tearDown(): void
    {
        FieldtypeExtensions::flush();

        parent::tearDown();
    }

    private function makeEntry(string $id, string $title = 'Original'): void
    {
        Entry::make()->id($id)->collection($this->collectionHandle)->slug($id)->data(['title' => $title])->save();
        Stache::refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function update(string $id, array $data): array
    {
        return $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $id,
            'data' => $data,
        ]);
    }

    public function test_update_refuses_a_list_for_a_text_field(): void
    {
        $this->makeEntry('post-1');

        $result = $this->update('post-1', ['title' => ['a', 'b']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('[title]', implode(' ', $result['errors']));
        $this->assertSame('Original', Entry::find('post-1')->get('title'));
    }

    public function test_create_refuses_non_string_values_for_string_fieldtypes(): void
    {
        foreach (['summary' => true, 'body' => ['nodes' => []], 'cta' => ['url' => '/x']] as $handle => $value) {
            $result = $this->router->execute([
                'action' => 'create',
                'collection' => $this->collectionHandle,
                'slug' => "bad-{$handle}",
                'data' => ['title' => 'Bad', $handle => $value],
            ]);

            $this->assertFalse($result['success'], "{$handle} accepted " . get_debug_type($value));
            $this->assertStringContainsString("[{$handle}]", implode(' ', $result['errors']));
            $this->assertNull(Entry::query()->where('slug', "bad-{$handle}")->first());
        }
    }

    public function test_a_number_is_stored_as_a_string(): void
    {
        $this->makeEntry('post-2');

        $result = $this->update('post-2', ['title' => 2024]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('2024', Entry::find('post-2')->get('title'));
    }

    public function test_a_text_field_inside_a_replicator_set_refuses_a_list(): void
    {
        $this->makeEntry('post-3');

        $result = $this->update('post-3', ['page_builder' => [[
            'id' => 'set-1',
            'type' => 'quote',
            'enabled' => true,
            'text' => ['a', 'b'],
        ]]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('page_builder.0.text', implode(' ', $result['errors']));
    }

    public function test_an_entry_already_holding_a_bad_value_can_still_be_updated_and_repaired(): void
    {
        Entry::make()->id('post-4')->collection($this->collectionHandle)->slug('post-4')
            ->data(['title' => ['a', 'b']])->save();
        Stache::refresh();

        $other = $this->update('post-4', ['summary' => 'Unrelated']);
        $this->assertTrue($other['success'], json_encode($other['errors'] ?? []));

        $fixed = $this->update('post-4', ['title' => 'Repaired']);
        $this->assertTrue($fixed['success'], json_encode($fixed['errors'] ?? []));
        $this->assertSame('Repaired', Entry::find('post-4')->get('title'));
    }

    public function test_a_registered_sanitizer_replaces_the_built_in_check(): void
    {
        FieldtypeExtensions::sanitizer('text', fn (mixed $value): mixed => is_array($value) ? implode(', ', $value) : $value);
        $this->makeEntry('post-5');

        $result = $this->update('post-5', ['title' => ['a', 'b']]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('a, b', Entry::find('post-5')->get('title'));
    }

    public function test_term_update_refuses_a_list_for_a_text_field(): void
    {
        $taxonomy = 'tags-' . bin2hex(random_bytes(4));
        Taxonomy::make($taxonomy)->title('Tags')->save();
        Blueprint::make('tag')->setNamespace("taxonomies.{$taxonomy}")->setContents([
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ])->save();
        Stache::refresh();
        Term::make()->taxonomy($taxonomy)->slug('news')->data(['title' => 'News'])->save();

        $result = (new TermsRouter)->execute([
            'action' => 'update',
            'taxonomy' => $taxonomy,
            'slug' => 'news',
            'data' => ['title' => ['a', 'b']],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('News', Term::find("{$taxonomy}::news")->get('title'));
    }
}
