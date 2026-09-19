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
                    ['handle' => 'rating', 'field' => ['type' => 'integer']],
                    ['handle' => 'tickbox', 'field' => [
                        'type' => 'checkboxes',
                        'options' => ['a' => 'A', 'b' => 'B'],
                        'default' => ['a'],
                        'required' => true,
                    ]],
                    ['handle' => 'author', 'field' => [
                        'type' => 'entries',
                        'collections' => [$this->authors],
                        'max_items' => 1,
                        'required' => true,
                    ]],
                ]]]]],
            ])
            ->save();
    }

    /**
     * An author entry to satisfy the required relationship.
     */
    private function anAuthorId(): string
    {
        $author = Entry::make()
            ->collection($this->authors)
            ->slug('author-' . bin2hex(random_bytes(3)))
            ->data(['title' => 'An Author']);
        $author->save();

        return (string) $author->id();
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
     * The findings of a sweep that actually ran.
     *
     * The success assertion is the point of this helper, not decoration. An
     * earlier version read `$result['data']['findings'] ?? []`, which turned a
     * failed sweep — data null, an exception in errors — into an empty list,
     * so both tests below passed green while content_validate was aborting on
     * the first entry with "Call to undefined method". A test that cannot tell
     * "nothing was wrong" from "nothing was checked" is not a test.
     *
     * @return list<array<string, mixed>>
     */
    private function findings(int $expectedRecords): array
    {
        $result = $this->validate();

        $this->assertTrue(
            $result['success'] ?? false,
            'The sweep must succeed: ' . json_encode($result['errors'] ?? [])
        );

        $data = $result['data'] ?? null;

        $this->assertIsArray($data, 'A successful sweep must carry data.');
        $this->assertSame(
            $expectedRecords,
            $data['summary']['records_scanned'] ?? null,
            'The sweep must actually visit the entries, not stop early.'
        );

        return $data['findings'];
    }

    public function test_a_dated_entry_does_not_report_a_missing_date(): void
    {
        Entry::make()
            ->collection($this->articles)
            ->slug('dated-post')
            ->date('2026-09-19')
            ->data(['title' => 'A Dated Post', 'tickbox' => ['a'], 'author' => $this->anAuthorId()])
            ->save();

        $messages = array_column($this->findings(expectedRecords: 1), 'message');

        $this->assertSame([], $messages, 'A dated entry with a date must not be reported as missing one.');
    }

    public function test_a_corrupt_numeric_value_is_still_reported(): void
    {
        // Pre-processing a value into the shape the rules expect must not
        // launder it: Integer::preProcess casts "not-a-number" to 0, so taking
        // the pre-processed output wholesale would have reported this file as
        // clean — hiding exactly the corruption the sweep exists to find.
        Entry::make()
            ->collection($this->articles)
            ->slug('bad-number')
            ->date('2026-09-19')
            ->data(['title' => 'Bad Number', 'tickbox' => ['a'], 'author' => $this->anAuthorId(), 'rating' => 'not-a-number'])
            ->save();

        $messages = array_column($this->findings(expectedRecords: 1), 'message');

        $this->assertNotEmpty($messages, 'A non-numeric value in an integer field must still be reported.');
    }

    public function test_a_required_field_that_is_missing_is_still_reported(): void
    {
        // Reshaping a stored value must not invent one. Field::preProcess falls
        // back to defaultValue(), so putting a whole record through it made a
        // required-but-absent field validate clean — a false negative in the
        // one check that exists to catch drift.
        Entry::make()
            ->collection($this->articles)
            ->slug('missing-required')
            ->date('2026-09-19')
            ->data(['title' => 'Missing Required'])
            ->save();

        $messages = array_column($this->findings(expectedRecords: 1), 'message');

        $this->assertNotEmpty($messages, 'A required field with no stored value must be reported, not filled in from its default.');
    }

    public function test_a_required_relationship_stored_empty_is_still_reported(): void
    {
        // Wrapping '' would produce [''], which satisfies required, array and
        // max:1 at once — turning the violation into a pass.
        Entry::make()
            ->collection($this->articles)
            ->slug('empty-author')
            ->date('2026-09-19')
            ->data(['title' => 'Empty Author', 'tickbox' => ['a'], 'author' => ''])
            ->save();

        $messages = array_column($this->findings(expectedRecords: 1), 'message');

        $this->assertNotEmpty($messages, 'An empty value in a required relationship must still be reported.');
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
            ->data(['title' => 'With Author', 'tickbox' => ['a'], 'author' => (string) $author->id()])
            ->save();

        $messages = array_column($this->findings(expectedRecords: 1), 'message');

        $this->assertSame([], $messages, 'A correctly stored single-item relationship must not report violations.');
    }
}
