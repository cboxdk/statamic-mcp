<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * content_validate against values Statamic stores in a shape the raw rules do
 * not expect.
 *
 * Both cases here made the sweep useless on an ordinary blog: every row failed
 * on its date, and every row with an author failed twice more (#57, #58). The
 * findings were entirely false — the same entries validate fine in the Control
 * Panel — which is worse than no check at all, because a caller cannot tell the
 * noise from a real drift.
 */
class ContentValidateStoredShapesTest extends TestCase
{
    private ContentFacadeRouter $router;

    private string $articles;

    private string $authors;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new ContentFacadeRouter;
        $this->articles = 'articles-' . bin2hex(random_bytes(4));
        $this->authors = 'authors-' . bin2hex(random_bytes(4));

        Collection::make($this->authors)->title('Authors')->save();

        // dated: true makes Statamic inject a required date field into the
        // blueprint at runtime, while the value itself lives outside data().
        Collection::make($this->articles)->title('Articles')->dated(true)->save();

        Blueprint::make('article')
            ->setNamespace("collections.{$this->articles}")
            ->setContents([
                'title' => 'Article',
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
                    ['handle' => 'author', 'field' => [
                        'type' => 'entries',
                        'collections' => [$this->authors],
                        'max_items' => 1,
                    ]],
                ]]]]],
            ])
            ->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(): array
    {
        return $this->router->execute([
            'action' => 'content_validate',
            'scope' => 'entries',
            'collection' => $this->articles,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findings(): array
    {
        $result = $this->validate();

        return $result['data']['findings'] ?? [];
    }

    public function test_a_dated_entry_does_not_report_a_missing_date(): void
    {
        Entry::make()
            ->collection($this->articles)
            ->slug('dated-post')
            ->date('2026-09-19')
            ->data(['title' => 'A Dated Post'])
            ->save();

        $messages = array_column($this->findings(), 'message');

        $this->assertSame([], $messages, 'A dated entry with a date must not be reported as missing one.');
    }

    public function test_a_single_item_relationship_stored_as_a_string_is_accepted(): void
    {
        $author = Entry::make()
            ->collection($this->authors)
            ->slug('some-author')
            ->data(['title' => 'Some Author']);
        $author->save();

        // max_items: 1 makes Statamic store a bare id, not a one-element array.
        // The generated array and max:1 rules only make sense after the
        // fieldtype has pre-processed it, exactly as the CP does before it
        // validates.
        Entry::make()
            ->collection($this->articles)
            ->slug('with-author')
            ->date('2026-09-19')
            ->data(['title' => 'With Author', 'author' => (string) $author->id()])
            ->save();

        $messages = array_column($this->findings(), 'message');

        $this->assertSame([], $messages, 'A correctly stored single-item relationship must not report violations.');
    }
}
