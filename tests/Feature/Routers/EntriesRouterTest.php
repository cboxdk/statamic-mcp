<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

/**
 * Edge case and validation tests for EntriesRouter.
 * Core CRUD operations are tested in ContentRouterTest.
 */
class EntriesRouterTest extends TestCase
{
    private EntriesRouter $router;

    private string $testId;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new EntriesRouter;
        $this->testId = bin2hex(random_bytes(8));

        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);

        Storage::fake('assets');

        $this->collectionHandle = "posts-{$this->testId}";
        Collection::make($this->collectionHandle)
            ->title('Posts')
            ->routes("/posts-{$this->testId}/{slug}")
            ->save();

        Stache::refresh();
    }

    public function test_sort_is_stable_across_pages_and_refuses_hidden_or_unknown_fields(): void
    {
        foreach (['c' => 'Alpha', 'b' => 'Zulu', 'a' => 'Zulu'] as $id => $title) {
            Entry::make()->id($id)->collection($this->collectionHandle)->slug($id)->published(true)->data(['title' => $title])->save();
        }
        $call = ['action' => 'list', 'collection' => $this->collectionHandle, 'sort' => 'title:desc', 'limit' => 1];
        $ids = [];
        do {
            $result = $this->router->execute($call);
            $this->assertTrue($result['success'], json_encode($result));
            $ids[] = $result['data']['items'][0]['id'];
            $call['cursor'] = $result['data']['next_cursor'];
        } while ($call['cursor'] !== null);
        $this->assertSame(['a', 'b', 'c'], $ids);

        config(['statamic.mcp.tools.entries.denied_fields' => ['title']]);
        foreach (['title:asc', 'unknown:desc', 'id:sideways', 'title desc'] as $sort) {
            $result = $this->router->execute(['action' => 'list', 'collection' => $this->collectionHandle, 'sort' => $sort]);
            $this->assertFalse($result['success'], $sort);
        }
    }

    public function test_create_selects_only_a_blueprint_assigned_to_the_collection(): void
    {
        foreach (['short', 'long', 'unassigned'] as $handle) {
            Blueprint::make($handle)->setNamespace('collections.' . ($handle === 'unassigned' ? 'other' : $this->collectionHandle))->setContents([
                'fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => $handle . '_value', 'field' => ['type' => 'text']],
                ],
            ])->save();
        }

        foreach (['long', 'short'] as $handle) {
            $result = $this->router->execute([
                'action' => 'create', 'collection' => $this->collectionHandle, 'blueprint' => $handle,
                'data' => ['title' => $handle, $handle . '_value' => 'Kept'],
            ]);
            $this->assertTrue($result['success'], json_encode($result));
            $stored = Entry::find($result['data']['entry']['id']);
            $this->assertSame($handle, $stored->blueprint()->handle());
            $this->assertSame('Kept', $stored->get($handle . '_value'));
        }

        $before = Entry::query()->where('collection', $this->collectionHandle)->count();
        foreach (['unassigned', '../long', 'missing'] as $handle) {
            $result = $this->router->execute([
                'action' => 'create', 'collection' => $this->collectionHandle, 'blueprint' => $handle,
                'data' => ['title' => 'Refused'],
            ]);
            $this->assertFalse($result['success'], json_encode($result));
        }
        $this->assertSame($before, Entry::query()->where('collection', $this->collectionHandle)->count());
    }

    public function test_nonexistent_collection_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'list',
            'collection' => 'nonexistent-collection',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Collection not found: nonexistent-collection', $result['errors'][0]);
    }

    public function test_get_resolves_exactly_one_identifier_within_the_named_collection(): void
    {
        $entry = Entry::make()->collection($this->collectionHandle)->slug('same-slug')->data(['title' => 'Allowed']);
        $entry->save();
        Collection::make('elsewhere')->routes('/elsewhere/{slug}')->save();
        Entry::make()->collection('elsewhere')->slug('same-slug')->data(['title' => 'Not ours'])->save();

        foreach ([['id' => $entry->id()], ['slug' => 'same-slug'], ['uri' => '/posts-' . $this->testId . '/same-slug']] as $identifier) {
            $result = $this->router->execute(['action' => 'get', 'collection' => $this->collectionHandle, ...$identifier]);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertSame($entry->id(), $result['data']['entry']['id']);
        }
        foreach ([['uri' => '/elsewhere/same-slug'], ['id' => $entry->id(), 'slug' => 'same-slug'], ['slug' => ['same-slug']]] as $identifier) {
            $result = $this->router->execute(['action' => 'get', 'collection' => $this->collectionHandle, ...$identifier]);
            $this->assertFalse($result['success'], json_encode($result));
        }
    }

    public function test_unknown_fields_warn_on_write_and_replay_but_not_the_next_read(): void
    {
        Blueprint::make('pages')->setNamespace('collections.' . $this->collectionHandle)->setContents([
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ])->save();
        config(['statamic.mcp.tools.structures.denied_fields' => ['parent']]);
        $arguments = ['action' => 'create', 'collection' => $this->collectionHandle, 'idempotency_key' => 'unknown-fields-example',
            'data' => ['title' => 'Saved', 'typo' => 'Not saved']];
        $created = $this->router->execute($arguments);
        $this->assertTrue($created['success'], json_encode($created));
        $this->assertStringContainsString('typo', implode(' ', $created['warnings']));
        $this->assertArrayNotHasKey('typo', $created['data']['entry']['data']);
        $this->assertArrayNotHasKey('parent', $created['data']['entry']);
        $replayed = $this->router->execute($arguments);
        $this->assertSame($created['warnings'], $replayed['warnings']);
        $this->assertArrayNotHasKey('parent', $replayed['data']['entry']);
        $this->assertSame(1, Entry::query()->where('collection', $this->collectionHandle)->count());
        $read = $this->router->execute(['action' => 'get', 'collection' => $this->collectionHandle, 'id' => $created['data']['entry']['id']]);
        $this->assertEmpty($read['warnings'] ?? []);
    }

    public function test_list_entries_with_pagination(): void
    {
        // Create 5 entries
        for ($i = 1; $i <= 5; $i++) {
            Entry::make()
                ->id("paginated-{$i}-{$this->testId}")
                ->collection($this->collectionHandle)
                ->slug("entry-{$i}-{$this->testId}")
                ->data(['title' => "Entry {$i}"])
                ->save();
        }

        $result = $this->router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
            'limit' => 2,
            'include_unpublished' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['items']);
        $this->assertEquals(5, $result['data']['total']);
        $this->assertIsString($result['data']['next_cursor']);
    }

    public function test_list_entries_resumes_with_a_cursor_and_allows_a_different_page_size(): void
    {
        foreach ([5, 2, 4, 1, 3] as $i) {
            Entry::make()
                ->id("offset-{$i}-{$this->testId}")
                ->collection($this->collectionHandle)
                ->slug("offset-entry-{$i}-{$this->testId}")
                ->data(['title' => "Offset Entry {$i}"])
                ->save();
        }

        $arguments = [
            'action' => 'list',
            'collection' => $this->collectionHandle,
            'limit' => 3,
            'include_unpublished' => true,
        ];
        $first = $this->router->execute($arguments);
        $this->assertTrue($first['success'], json_encode($first));
        $this->assertSame(['Offset Entry 1', 'Offset Entry 2', 'Offset Entry 3'], array_column($first['data']['items'], 'title'));
        $result = $this->router->execute([...$arguments, 'limit' => 2, 'cursor' => $first['data']['next_cursor']]);

        $this->assertTrue($result['success']);
        $this->assertSame(['Offset Entry 4', 'Offset Entry 5'], array_column($result['data']['items'], 'title'));
        $this->assertNull($result['data']['next_cursor']);
    }

    public function test_list_entries_excludes_unpublished_by_default(): void
    {
        Entry::make()
            ->id("pub-{$this->testId}")
            ->collection($this->collectionHandle)
            ->slug("published-{$this->testId}")
            ->data(['title' => 'Published'])
            ->published(true)
            ->save();

        Entry::make()
            ->id("draft-{$this->testId}")
            ->collection($this->collectionHandle)
            ->slug("draft-{$this->testId}")
            ->data(['title' => 'Draft'])
            ->published(false)
            ->save();

        // Default: no include_unpublished
        $result = $this->router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertTrue($result['success']);
        $titles = collect($result['data']['items'])->pluck('title')->toArray();
        $this->assertContains('Published', $titles);
        $this->assertNotContains('Draft', $titles);
    }

    public function test_list_entries_includes_unpublished_when_requested(): void
    {
        Entry::make()
            ->id("pub2-{$this->testId}")
            ->collection($this->collectionHandle)
            ->slug("published2-{$this->testId}")
            ->data(['title' => 'Published2'])
            ->published(true)
            ->save();

        Entry::make()
            ->id("draft2-{$this->testId}")
            ->collection($this->collectionHandle)
            ->slug("draft2-{$this->testId}")
            ->data(['title' => 'Draft2'])
            ->published(false)
            ->save();

        $result = $this->router->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
            'include_unpublished' => true,
        ]);

        $this->assertTrue($result['success']);
        $titles = collect($result['data']['items'])->pluck('title')->toArray();
        $this->assertContains('Published2', $titles);
        $this->assertContains('Draft2', $titles);
    }

    public function test_create_entry_without_slug_generates_from_title(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Auto Slug Generation Test',
            ],
        ]);

        if (! $result['success']) {
            dump('Create entry failed:', $result);
        }

        $this->assertTrue($result['success']);
        $this->assertEquals('auto-slug-generation-test', $result['data']['entry']['slug']);
    }

    /**
     * Regression for https://github.com/cboxdk/statamic-mcp/issues/27.
     *
     * The previous updateEntry() flow injected the entry's current
     * slug into the FieldsValidator payload, which made
     * UniqueEntryValue compare the slug against the entry being
     * updated and reject it as "already taken" — even when the caller
     * never passed a slug at all.
     */
    public function test_update_entry_without_slug_in_data_succeeds(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("update-no-slug-{$this->testId}")
            ->data(['title' => 'Original Title']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Updated Title',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'updating an entry without changing the slug must succeed; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame('Updated Title', $reloaded->get('title'));
        $this->assertSame("update-no-slug-{$this->testId}", $reloaded->slug());
    }

    /**
     * Resending the entry's *current* slug as part of `data` is
     * idempotent and must not trigger UniqueEntryValue against the
     * entry itself.
     */
    public function test_update_entry_with_unchanged_slug_succeeds(): void
    {
        $slug = "update-same-slug-{$this->testId}";
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug($slug)
            ->data(['title' => 'Original']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Updated',
                'slug' => $slug,
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'resending the existing slug must be idempotent; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame($slug, $reloaded->slug());
        $this->assertSame('Updated', $reloaded->get('title'));
    }

    /**
     * Renaming an entry to a slug that does not collide with any
     * other entry in the collection succeeds.
     */
    public function test_update_entry_with_new_unique_slug_succeeds(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("update-rename-from-{$this->testId}")
            ->data(['title' => 'Will Be Renamed']);
        $entry->save();

        $newSlug = "update-rename-to-{$this->testId}";
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'slug' => $newSlug,
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'updating to a unique new slug must succeed; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame($newSlug, $reloaded->slug());
    }

    /**
     * Renaming an entry to a slug that another entry already owns
     * must surface the validation error.
     */
    public function test_update_entry_to_existing_slug_returns_error(): void
    {
        $other = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("update-collision-{$this->testId}")
            ->data(['title' => 'Other']);
        $other->save();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("update-target-{$this->testId}")
            ->data(['title' => 'Target']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'slug' => $other->slug(),
            ],
        ]);

        $this->assertFalse(
            $result['success'],
            'updating to a slug already owned by another entry must fail',
        );
    }

    /**
     * Give the test collection the blueprint Statamic scaffolds by default:
     * a slug field that is `required` and unique via placeholder-based
     * UniqueEntryValue. Issue #39 only reproduces against this blueprint.
     */
    private function createBlueprintWithRequiredSlug(): void
    {
        Blueprint::make($this->collectionHandle)
            ->setNamespace("collections.{$this->collectionHandle}")
            ->setContents([
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
                                    ['handle' => 'slug', 'field' => [
                                        'type' => 'slug',
                                        'localizable' => true,
                                        'validate' => [
                                            'required',
                                            'new \Statamic\Rules\UniqueEntryValue({collection}, {id}, {site})',
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->save();
    }

    /**
     * Regression for https://github.com/cboxdk/statamic-mcp/issues/39.
     *
     * The #27 fix removed the slug from the validated payload entirely,
     * so a blueprint whose slug field is `required` (Statamic's default)
     * could never be satisfied — every update failed with
     * "The Slug field is required" no matter what the caller sent.
     */
    public function test_update_with_required_slug_blueprint_succeeds_without_slug(): void
    {
        $this->createBlueprintWithRequiredSlug();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("required-slug-omitted-{$this->testId}")
            ->data(['title' => 'Original Title']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Updated Title',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'update must satisfy a required slug rule from the entry\'s current slug; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame('Updated Title', $reloaded->get('title'));
        $this->assertSame("required-slug-omitted-{$this->testId}", $reloaded->slug());
    }

    /**
     * Companion regression for issue #39: resending the current slug must
     * not re-trigger the #27 false positive. The blueprint-level
     * UniqueEntryValue({collection}, {id}, {site}) placeholders must be
     * resolved so the rule excludes the entry being updated.
     */
    public function test_update_with_required_slug_blueprint_succeeds_with_unchanged_slug(): void
    {
        $this->createBlueprintWithRequiredSlug();

        $slug = "required-slug-resent-{$this->testId}";
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug($slug)
            ->data(['title' => 'Original']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Updated',
                'slug' => $slug,
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'resending the current slug must pass the blueprint uniqueness rule; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame($slug, $reloaded->slug());
        $this->assertSame('Updated', $reloaded->get('title'));
    }

    /**
     * Resolving the uniqueness placeholders must not weaken the rule:
     * a slug owned by another entry is still rejected.
     */
    public function test_update_with_required_slug_blueprint_rejects_colliding_slug(): void
    {
        $this->createBlueprintWithRequiredSlug();

        $other = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("required-slug-owner-{$this->testId}")
            ->data(['title' => 'Other']);
        $other->save();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug("required-slug-victim-{$this->testId}")
            ->data(['title' => 'Target']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'slug' => $other->slug(),
            ],
        ]);

        $this->assertFalse(
            $result['success'],
            'a slug owned by another entry must still be rejected',
        );
    }

    /**
     * Issue #39, date facet: on dated collections the date is an entry
     * property, so it is absent from the merged data payload. A blueprint
     * with a required date field failed every update that did not resend
     * the date the caller never meant to change.
     */
    public function test_update_dated_collection_without_date_succeeds(): void
    {
        $datedHandle = "dated-{$this->testId}";
        Collection::make($datedHandle)
            ->title('Dated Posts')
            ->dated(true)
            ->save();

        Blueprint::make($datedHandle)
            ->setNamespace("collections.{$datedHandle}")
            ->setContents([
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
                                    ['handle' => 'date', 'field' => ['type' => 'date', 'validate' => ['required']]],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->save();

        $entry = Entry::make()
            ->collection($datedHandle)
            ->slug("dated-entry-{$this->testId}")
            ->date(Carbon::parse('2025-01-15'))
            ->data(['title' => 'Dated Original']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $datedHandle,
            'id' => $entry->id(),
            'data' => [
                'title' => 'Dated Updated',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'update must satisfy a required date rule from the entry\'s current date; got: '
            . json_encode($result['errors'] ?? []),
        );

        $reloaded = Entry::find($entry->id());
        $this->assertSame('Dated Updated', $reloaded->get('title'));
        $this->assertSame('2025-01-15', $reloaded->date()->format('Y-m-d'));
    }

    /**
     * Issue #39, create facet: the slug argument was read by createEntry()
     * but never declared in the tool schema, so an explicit slug on create
     * was impossible.
     */
    public function test_create_entry_with_explicit_slug_argument(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'slug' => "explicit-slug-{$this->testId}",
            'data' => [
                'title' => 'Explicit Slug Test',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'create with an explicit slug must succeed; got: '
            . json_encode($result['errors'] ?? []),
        );
        $this->assertSame("explicit-slug-{$this->testId}", $result['data']['entry']['slug']);
    }

    /**
     * Callers that send the slug as a data field (the shape update uses)
     * get the same result on create — and the slug is stored as an entry
     * property, never as a data key.
     */
    public function test_create_entry_with_slug_in_data(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Data Slug Test',
                'slug' => "data-slug-{$this->testId}",
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'create with slug inside data must succeed; got: '
            . json_encode($result['errors'] ?? []),
        );
        $this->assertSame("data-slug-{$this->testId}", $result['data']['entry']['slug']);

        $reloaded = Entry::find($result['data']['entry']['id']);
        $this->assertArrayNotHasKey('slug', $reloaded->data()->all());
    }

    /**
     * Creating against the default required-slug blueprint exercises the
     * blueprint-level UniqueEntryValue placeholders on the create path.
     */
    public function test_create_with_required_slug_blueprint_succeeds(): void
    {
        $this->createBlueprintWithRequiredSlug();

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'slug' => "required-create-{$this->testId}",
            'data' => [
                'title' => 'Required Blueprint Create',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'create must pass the default required+unique slug rules; got: '
            . json_encode($result['errors'] ?? []),
        );
        $this->assertSame("required-create-{$this->testId}", $result['data']['entry']['slug']);
    }

    public function test_update_nonexistent_entry_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id',
            'data' => ['title' => 'Updated'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent-id', $result['errors'][0]);
    }

    public function test_publish_nonexistent_entry_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'publish',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent-id', $result['errors'][0]);
    }

    public function test_unpublish_nonexistent_entry_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'unpublish',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent-id', $result['errors'][0]);
    }

    public function test_delete_nonexistent_entry_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found: nonexistent-id', $result['errors'][0]);
    }

    public function test_missing_id_for_update(): void
    {
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Test'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The update action needs id', $result['errors'][0]);
    }

    public function test_missing_id_for_delete(): void
    {
        $result = $this->router->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The delete action needs id', $result['errors'][0]);
    }

    public function test_missing_id_for_publish(): void
    {
        $result = $this->router->execute([
            'action' => 'publish',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The publish action needs id', $result['errors'][0]);
    }

    public function test_missing_id_for_unpublish(): void
    {
        $result = $this->router->execute([
            'action' => 'unpublish',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('The unpublish action needs id', $result['errors'][0]);
    }
}
