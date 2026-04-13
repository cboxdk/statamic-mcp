<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;

/**
 * Reproduction test for ENG-697: Entry update fails on `terms` field type.
 *
 * Updating an entry with a `terms` type field would throw:
 * "Cannot access offset of type string on string"
 */
class TermsFieldUpdateTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle = 'pages';

    private string $taxonomyHandle = 'page_tags';

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;

        // Create the taxonomy with terms (matching ENG-697 scenario)
        $taxonomy = TaxonomyFacade::make($this->taxonomyHandle)
            ->title('Page Tags');
        $taxonomy->save();

        // Create terms in the taxonomy
        foreach (['product', 'tutorial', 'legal', 'troubleshooting'] as $slug) {
            $term = TermFacade::make($slug)
                ->taxonomy($this->taxonomyHandle)
                ->data(['title' => ucfirst($slug)]);
            $term->save();
        }

        // Create collection
        $collection = CollectionFacade::make($this->collectionHandle)
            ->title('Pages');
        $collection->save();

        // Create blueprint matching the ENG-697 description
        $blueprint = BlueprintFacade::make($this->collectionHandle);
        $blueprint->setNamespace("collections.{$this->collectionHandle}");
        $blueprint->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => 'Title',
                                    ],
                                ],
                                [
                                    'handle' => 'content',
                                    'field' => [
                                        'type' => 'bard',
                                        'display' => 'Content',
                                    ],
                                ],
                                [
                                    'handle' => 'page_tags',
                                    'field' => [
                                        'type' => 'terms',
                                        'display' => 'Page Tags',
                                        'taxonomies' => [$this->taxonomyHandle],
                                        'mode' => 'select',
                                        'create' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->save();
    }

    /**
     * ENG-697 exact reproduction: update entry with terms as array of slugs.
     */
    public function test_update_entry_with_terms_array_of_slugs(): void
    {
        $entry = EntryFacade::make()
            ->collection($this->collectionHandle)
            ->slug('test-page')
            ->data(['title' => 'Test Page']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['page_tags' => ['troubleshooting']],
        ]);

        $this->assertTrue($result['success'], 'Entry update with terms should succeed: ' . json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        $this->assertTrue($data['updated'] ?? false);
    }

    /**
     * ENG-697 variant: update entry with terms as single string.
     */
    public function test_update_entry_with_terms_single_string(): void
    {
        $entry = EntryFacade::make()
            ->collection($this->collectionHandle)
            ->slug('test-page-string')
            ->data(['title' => 'Test Page String']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['page_tags' => 'troubleshooting'],
        ]);

        $this->assertTrue($result['success'], 'Entry update with terms string should succeed: ' . json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        $this->assertTrue($data['updated'] ?? false);
    }

    /**
     * ENG-697 variant: update entry with terms as prefixed IDs.
     */
    public function test_update_entry_with_terms_prefixed_ids(): void
    {
        $entry = EntryFacade::make()
            ->collection($this->collectionHandle)
            ->slug('test-page-prefixed')
            ->data(['title' => 'Test Page Prefixed']);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['page_tags' => ['page_tags::troubleshooting']],
        ]);

        $this->assertTrue($result['success'], 'Entry update with prefixed terms should succeed: ' . json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        $this->assertTrue($data['updated'] ?? false);
    }

    /**
     * Verify that an entry with existing terms can be updated with other fields
     * without crashing on the stored terms data.
     */
    public function test_update_other_fields_when_entry_has_terms(): void
    {
        $entry = EntryFacade::make()
            ->collection($this->collectionHandle)
            ->slug('tagged-page')
            ->data([
                'title' => 'Tagged Page',
                'page_tags' => ['product', 'tutorial'],
            ]);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Updated Tagged Page'],
        ]);

        $this->assertTrue($result['success'], 'Updating other fields should not crash on stored terms: ' . json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        $this->assertTrue($data['updated'] ?? false);
    }

    /**
     * ENG-697 scenario: entry has a bard field with legacy string data AND terms.
     * This tests the combined scenario where both issues interact.
     */
    public function test_update_terms_when_entry_has_legacy_bard_string(): void
    {
        $entry = EntryFacade::make()
            ->collection($this->collectionHandle)
            ->slug('legacy-bard-page')
            ->data([
                'title' => 'Legacy Bard Page',
                'content' => 'This is plain string content in a bard field',
            ]);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['page_tags' => ['troubleshooting']],
        ]);

        $this->assertTrue($result['success'], 'Terms update should not crash on legacy bard data: ' . json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        $this->assertTrue($data['updated'] ?? false);
    }

    /**
     * Create entry with terms field.
     */
    public function test_create_entry_with_terms(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'New Tagged Page',
                'page_tags' => ['troubleshooting', 'product'],
            ],
        ]);

        $this->assertTrue($result['success'], 'Creating entry with terms should succeed: ' . json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        $this->assertTrue($data['created'] ?? false);
    }

    /**
     * Checkboxes field should accept a bare string and normalize to array.
     */
    public function test_update_entry_with_checkboxes_string(): void
    {
        // Add a checkboxes field to blueprint
        $blueprint = Blueprint::find("collections.{$this->collectionHandle}.{$this->collectionHandle}");
        $this->assertNotNull($blueprint);

        $contents = $blueprint->contents();
        $contents['tabs']['main']['sections'][0]['fields'][] = [
            'handle' => 'features',
            'field' => [
                'type' => 'checkboxes',
                'display' => 'Features',
                'options' => [
                    'fast' => 'Fast',
                    'secure' => 'Secure',
                    'reliable' => 'Reliable',
                ],
            ],
        ];
        $blueprint->setContents($contents);
        $blueprint->save();

        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug('checkbox-test')
            ->data(['title' => 'Checkbox Test']);
        $entry->save();

        // Send checkboxes as a bare string (common LLM mistake)
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['features' => 'fast'],
        ]);

        $this->assertTrue($result['success'], 'Checkboxes string should be normalized to array: ' . json_encode($result));
        /** @var array<string, mixed> $data */
        $data = $result['data'];
        $this->assertTrue($data['updated'] ?? false);
    }
}
