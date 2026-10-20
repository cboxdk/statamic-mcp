<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

/**
 * A field in tools.{domain}.denied_fields must not leak through any surface.
 * It was stripped from `get` output but still reachable as a `list` filter key
 * (an equality oracle) and returned in full inside a `get_revision` snapshot.
 */
class DeniedFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        config(['statamic.revisions.enabled' => true]);

        Collection::make('vault')->title('Vault')->revisionsEnabled(true)->save();
        Blueprint::make('vault')->setNamespace('collections.vault')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'secret_code', 'field' => ['type' => 'text']],
        ]])->save();
        Stache::refresh();

        config(['statamic.mcp.tools.entries.denied_fields' => ['secret_code']]);
    }

    private function total(array $result): int
    {
        return (int) ($result['data']['pagination']['total'] ?? count($result['data']['entries'] ?? []));
    }

    public function test_a_denied_field_filter_no_longer_works_as_an_oracle(): void
    {
        Entry::make()->id('v1')->collection('vault')->slug('v1')
            ->data(['title' => 'Public', 'secret_code' => 'alpha'])->published(true)->save();

        $get = (new EntriesRouter)->execute(['action' => 'get', 'collection' => 'vault', 'id' => 'v1']);
        $this->assertTrue($get['success'], json_encode($get['errors'] ?? []));
        $this->assertArrayNotHasKey('secret_code', $get['data']['entry']['data'] ?? []);

        // The denied filter is dropped, so the count no longer depends on the
        // guessed value: a right and a wrong guess return the same result.
        $hit = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'vault', 'filters' => ['secret_code' => 'alpha']]);
        $miss = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'vault', 'filters' => ['secret_code' => 'nope']]);
        $this->assertSame($this->total($hit), $this->total($miss));
        $this->assertSame(1, $this->total($hit));
    }

    public function test_a_visible_field_filter_still_discriminates(): void
    {
        Entry::make()->id('a')->collection('vault')->slug('a')->data(['title' => 'Keep'])->published(true)->save();
        Entry::make()->id('b')->collection('vault')->slug('b')->data(['title' => 'Other'])->published(true)->save();

        $match = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'vault', 'filters' => ['title' => 'Keep']]);
        $this->assertSame(1, $this->total($match));
    }

    public function test_get_revision_strips_denied_fields(): void
    {
        // Seed via the Entry API so the stored revision really contains the
        // denied field (the router's input filter would strip it on create).
        $entry = Entry::make()->id('rv1')->collection('vault')->slug('rv1')
            ->data(['title' => 'Public', 'secret_code' => 'alpha'])->published(true);
        $entry->save();
        $entry->makeRevision()->save();
        $id = 'rv1';

        $revisions = (new EntriesRouter)->execute(['action' => 'list_revisions', 'collection' => 'vault', 'id' => $id]);
        $this->assertTrue($revisions['success'], json_encode($revisions['errors'] ?? []));
        $revisionId = $revisions['data']['revisions'][0]['id'] ?? $revisions['data']['revisions'][0]['date'] ?? null;
        $this->assertNotNull($revisionId, 'expected a revision to exist: ' . json_encode($revisions['data']));

        $revision = (new EntriesRouter)->execute([
            'action' => 'get_revision', 'collection' => 'vault', 'id' => $id, 'revision_id' => (string) $revisionId,
        ]);
        $this->assertTrue($revision['success'], json_encode($revision['errors'] ?? []));
        $data = $revision['data']['revision']['attributes']['data'] ?? null;
        $this->assertIsArray($data, 'revision snapshot must expose its data: ' . json_encode($revision['data']['revision'] ?? []));
        $this->assertArrayHasKey('title', $data, 'sanity: the non-denied field is present');
        $this->assertArrayNotHasKey('secret_code', $data);
    }
}
