<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Revisions;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Events\RevisionSaving;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Live entry, slug and working copy after revision writes, on success and on
 * failure, read again in the same worker the way the next call would.
 *
 * The update path changes slug and data on the entry before it decides to
 * write a working copy instead; if that ever reached the instance Statamic
 * holds in memory, a refused write would still show up live.
 */
class WorkingCopyStateTest extends TestCase
{
    private string $collection;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true, 'statamic.revisions.enabled' => true]);
        $this->collection = 'wc-' . bin2hex(random_bytes(4));
        Collection::make($this->collection)->routes('/' . $this->collection . '/{slug}')->revisionsEnabled(true)->save();
        Blueprint::make('post')->setNamespace("collections.{$this->collection}")->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]])->save();
        $entry = Entry::make()->collection($this->collection)->slug('live')->published(true)->data(['title' => 'Live']);
        $entry->save();
        $this->id = (string) $entry->id();
    }

    /** @return array<string, mixed> */
    private function entryCall(array $arguments): array
    {
        return (new EntriesRouter)->execute(['collection' => $this->collection, 'id' => $this->id, ...$arguments]);
    }

    /** The stored entry, as a fresh call would find it. */
    private function stored(): \Statamic\Entries\Entry
    {
        Blink::swap(new \Statamic\Support\Blink);
        $entry = Entry::find($this->id);
        $this->assertInstanceOf(\Statamic\Entries\Entry::class, $entry);

        return $entry;
    }

    private function assertLiveUnchanged(): void
    {
        $entry = $this->stored();
        $this->assertSame(['Live', 'live', true], [$entry->get('title'), $entry->slug(), $entry->published()]);
        $read = $this->entryCall(['action' => 'get']);
        $this->assertSame(['Live', 'live'], [$read['data']['entry']['data']['title'] ?? null, $read['data']['entry']['slug'] ?? null], json_encode($read) ?: '');
    }

    public function test_an_edit_to_a_published_entry_goes_to_the_working_copy_only(): void
    {
        $result = $this->entryCall(['action' => 'update', 'data' => ['title' => 'Draft', 'slug' => 'renamed']]);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertTrue($result['data']['working_copy']);
        $this->assertLiveUnchanged();
        $copy = $this->stored()->workingCopy();
        $this->assertSame(['Draft', 'renamed'], [$copy?->attribute('data')['title'] ?? null, $copy?->attribute('slug')]);
    }

    public function test_a_refused_working_copy_leaves_nothing_behind(): void
    {
        Event::listen(RevisionSaving::class, fn () => false);

        $result = $this->entryCall(['action' => 'update', 'data' => ['title' => 'Draft', 'slug' => 'renamed']]);

        $this->assertFalse($result['success'], json_encode($result) ?: '');
        $this->assertTrue($result['meta']['retry_requires_reconciliation']);
        $this->assertLiveUnchanged();
        $this->assertFalse($this->stored()->hasWorkingCopy());
    }

    public function test_a_refused_publish_keeps_the_live_entry_and_the_working_copy(): void
    {
        $this->assertTrue($this->entryCall(['action' => 'update', 'data' => ['title' => 'Draft', 'slug' => 'renamed']])['success']);
        Event::listen(EntrySaving::class, fn () => false);

        $result = $this->entryCall(['action' => 'publish_working_copy']);

        $this->assertFalse($result['success'], json_encode($result) ?: '');
        $this->assertLiveUnchanged();
        $this->assertTrue($this->stored()->hasWorkingCopy());
    }

    public function test_publishing_moves_the_working_copy_live(): void
    {
        $this->assertTrue($this->entryCall(['action' => 'update', 'data' => ['title' => 'Draft', 'slug' => 'renamed']])['success']);

        $result = $this->entryCall(['action' => 'publish_working_copy']);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $entry = $this->stored();
        $this->assertSame(['Draft', 'renamed', false], [$entry->get('title'), $entry->slug(), $entry->hasWorkingCopy()]);
        $this->assertSame('/' . $this->collection . '/renamed', $entry->uri());
    }

    public function test_a_publish_that_fails_after_saving_is_not_reported_as_nothing_saved(): void
    {
        $this->assertTrue($this->entryCall(['action' => 'update', 'data' => ['title' => 'Draft']])['success']);
        // A listener throws after Statamic stored the entry, before it removed the working copy.
        Event::listen(EntrySaved::class, function (): void {
            throw new \RuntimeException('Search index unavailable');
        });

        $result = $this->entryCall(['action' => 'publish_working_copy']);

        $this->assertFalse($result['success'], json_encode($result) ?: '');
        // The throw came from inside Statamic's save, so the call cannot know it landed.
        $this->assertSame('unknown', $result['meta']['write_effects'] ?? null, json_encode($result) ?: '');
        $this->assertTrue($result['meta']['retry_requires_reconciliation']);
        Event::forget(EntrySaved::class);
        $this->assertSame('Draft', $this->stored()->get('title'), 'the live entry changed, and a read must say so');
    }

    public function test_restoring_a_revision_of_a_published_entry_leaves_it_live(): void
    {
        $this->assertTrue($this->entryCall(['action' => 'update', 'data' => ['title' => 'First draft']])['success']);
        $this->assertTrue($this->entryCall(['action' => 'publish_working_copy'])['success']);
        $revision = $this->stored()->revisions()->first(fn ($r) => ($r->attribute('data')['title'] ?? null) === 'First draft');
        $this->assertNotNull($revision);
        // A revision's id is its timestamp: the next one needs another second.
        $this->travel(2)->seconds();
        $this->assertTrue($this->entryCall(['action' => 'update', 'data' => ['title' => 'Second draft']])['success']);
        $this->assertTrue($this->entryCall(['action' => 'publish_working_copy'])['success']);

        $result = $this->entryCall(['action' => 'restore_revision', 'revision_id' => (string) $revision->date()->timestamp]);

        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $entry = $this->stored();
        $this->assertSame('Second draft', $entry->get('title'));
        $this->assertSame('First draft', $entry->workingCopy()?->attribute('data')['title'] ?? null);
    }
}
