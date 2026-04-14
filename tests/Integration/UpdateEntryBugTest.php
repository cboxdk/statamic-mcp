<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Fields\Fieldtype as StatamicFieldtype;

/**
 * Reproduce: update action crashes with
 * "Cannot access offset of type string on string"
 * on entries with replicator + bard fields.
 */
class UpdateEntryBugTest extends TestCase
{
    private EntriesRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new EntriesRouter;
    }

    /**
     * Minimal reproduction: replicator with a bard sub-field that has sets.
     */
    public function test_update_entry_with_replicator_containing_bard_sets(): void
    {
        $handle = 'repro_pages';

        Collection::make($handle)->title('Repro Pages')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'page_builder' => [
                'type' => 'replicator',
                'display' => 'Page Builder',
                'sets' => [
                    'main' => [
                        'display' => 'Main',
                        'sets' => [
                            'content_block' => [
                                'display' => 'Content Block',
                                'fields' => [
                                    ['handle' => 'content', 'field' => [
                                        'type' => 'bard',
                                        'display' => 'Content',
                                        'sets' => [
                                            'text' => [
                                                'display' => 'Text',
                                                'sets' => [
                                                    'code_block' => [
                                                        'display' => 'Code Block',
                                                        'fields' => [
                                                            ['handle' => 'code', 'field' => ['type' => 'code', 'display' => 'Code']],
                                                            ['handle' => 'language', 'field' => ['type' => 'text', 'display' => 'Language']],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ]],
                                ],
                            ],
                            'hero_simple' => [
                                'display' => 'Hero Simple',
                                'fields' => [
                                    ['handle' => 'headline', 'field' => ['type' => 'text', 'display' => 'Headline']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        // Create entry with realistic bard+replicator data
        $entry = Entry::make()
            ->id('repro-entry-001')
            ->collection($handle)
            ->slug('test-page')
            ->data([
                'title' => 'Test Page',
                'page_builder' => [
                    [
                        'id' => 'set-1',
                        'type' => 'hero_simple',
                        'enabled' => true,
                        'headline' => 'Welcome',
                    ],
                    [
                        'id' => 'set-2',
                        'type' => 'content_block',
                        'enabled' => true,
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => 'Hello world'],
                                ],
                            ],
                            [
                                'type' => 'set',
                                'attrs' => [
                                    'id' => 'bard-set-1',
                                    'values' => [
                                        'type' => 'code_block',
                                        'code' => ['code' => 'echo hello', 'mode' => 'htmlmixed'],
                                        'language' => 'bash',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $entry->save();
        Stache::refresh();

        // Now try a simple title-only update — this is what crashes in production
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'repro-entry-001',
            'data' => ['title' => 'Updated Title'],
        ]);

        $this->assertTrue($result['success'], 'Update failed: ' . json_encode($result['errors'] ?? $result['error'] ?? 'unknown'));
        $this->assertTrue($result['data']['updated']);
    }

    /**
     * Match production data shape: entry with extra metadata keys
     * (updated_by, updated_at, blueprint) that aren't blueprint fields.
     */
    public function test_update_entry_with_extra_metadata_in_stored_data(): void
    {
        $handle = 'repro_meta';

        Collection::make($handle)->title('Repro Meta')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'page_type' => [
                'type' => 'select',
                'display' => 'Page Type',
                'options' => ['default' => 'Default', 'landing' => 'Landing'],
            ],
            'description' => [
                'type' => 'bard',
                'display' => 'Description',
                'inline' => true,
            ],
            'show_toc' => ['type' => 'toggle', 'display' => 'Show TOC'],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        // Mimic production: entry data includes metadata keys the blueprint doesn't define
        $entry = Entry::make()
            ->id('repro-meta-001')
            ->collection($handle)
            ->slug('meta-test')
            ->data([
                'blueprint' => $handle,
                'title' => 'Meta Test',
                'page_type' => 'landing',
                'description' => [
                    ['type' => 'text', 'text' => 'A description'],
                ],
                'show_toc' => false,
                'updated_by' => '50532c3a-6680-4fee-9cc0-6e690437e458',
                'updated_at' => 1776087463,
            ]);

        $entry->save();
        Stache::refresh();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'repro-meta-001',
            'data' => ['title' => 'Updated Meta'],
        ]);

        $this->assertTrue($result['success'], 'Update failed: ' . json_encode($result['errors'] ?? $result['error'] ?? 'unknown'));
    }

    /**
     * Test with users and terms relationship fields stored as strings
     * (single-value relationship fields often store as plain strings in YAML).
     */
    public function test_update_entry_with_relationship_fields_stored_as_strings(): void
    {
        $handle = 'repro_rels';

        Collection::make($handle)->title('Repro Rels')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'author' => [
                'type' => 'users',
                'display' => 'Author',
                'max_items' => 1,
            ],
            'tags' => [
                'type' => 'terms',
                'display' => 'Tags',
                'taxonomies' => ['tags'],
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        // Store author as a plain string (how Statamic stores single-value users field)
        $entry = Entry::make()
            ->id('repro-rels-001')
            ->collection($handle)
            ->slug('rels-test')
            ->data([
                'title' => 'Rels Test',
                'author' => 'user-uuid-123',
                'tags' => ['product'],
            ]);

        $entry->save();
        Stache::refresh();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'repro-rels-001',
            'data' => ['title' => 'Updated Rels'],
        ]);

        $this->assertTrue($result['success'], 'Update failed: ' . json_encode($result['errors'] ?? $result['error'] ?? 'unknown'));
    }

    /**
     * Even simpler: just a bard field at top-level with inline: true.
     */
    public function test_update_entry_with_inline_bard(): void
    {
        $handle = 'repro_inline';

        Collection::make($handle)->title('Repro Inline')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'description' => [
                'type' => 'bard',
                'display' => 'Description',
                'inline' => true,
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        $entry = Entry::make()
            ->id('repro-inline-001')
            ->collection($handle)
            ->slug('inline-test')
            ->data([
                'title' => 'Inline Test',
                'description' => [
                    ['type' => 'text', 'text' => 'A description'],
                ],
            ]);

        $entry->save();
        Stache::refresh();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'repro-inline-001',
            'data' => ['title' => 'Updated Inline'],
        ]);

        $this->assertTrue($result['success'], 'Update failed: ' . json_encode($result['errors'] ?? $result['error'] ?? 'unknown'));
    }

    /**
     * Round-trip: CREATE via MCP router (which runs process()), then UPDATE.
     * This ensures the stored data is in Statamic's "processed" format.
     */
    public function test_update_entry_created_via_mcp_with_replicator_bard(): void
    {
        $handle = 'repro_roundtrip';

        Collection::make($handle)->title('Repro Roundtrip')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title', 'required' => true],
            'page_builder' => [
                'type' => 'replicator',
                'display' => 'Page Builder',
                'sets' => [
                    'main' => [
                        'display' => 'Main',
                        'sets' => [
                            'content_block' => [
                                'display' => 'Content Block',
                                'fields' => [
                                    ['handle' => 'content', 'field' => [
                                        'type' => 'bard',
                                        'display' => 'Content',
                                        'sets' => [
                                            'text' => [
                                                'display' => 'Text',
                                                'sets' => [
                                                    'code_block' => [
                                                        'display' => 'Code Block',
                                                        'fields' => [
                                                            ['handle' => 'code', 'field' => ['type' => 'code', 'display' => 'Code']],
                                                            ['handle' => 'language', 'field' => ['type' => 'text', 'display' => 'Language']],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ]],
                                ],
                            ],
                            'hero_simple' => [
                                'display' => 'Hero Simple',
                                'fields' => [
                                    ['handle' => 'headline', 'field' => ['type' => 'text', 'display' => 'Headline']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        // Step 1: Create via MCP router (triggers process() pipeline)
        $createResult = $this->router->execute([
            'action' => 'create',
            'collection' => $handle,
            'data' => [
                'title' => 'Roundtrip Test',
                'page_builder' => [
                    [
                        'type' => 'hero_simple',
                        'enabled' => true,
                        'headline' => 'Welcome',
                    ],
                    [
                        'type' => 'content_block',
                        'enabled' => true,
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => 'Hello world'],
                                ],
                            ],
                            [
                                'type' => 'set',
                                'attrs' => [
                                    'id' => 'bard-set-1',
                                    'values' => [
                                        'type' => 'code_block',
                                        'code' => ['code' => 'echo hello', 'mode' => 'htmlmixed'],
                                        'language' => 'bash',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($createResult['success'], 'Create failed: ' . json_encode($createResult['errors'] ?? $createResult['error'] ?? 'unknown'));
        $entryId = $createResult['data']['entry']['id'];

        Stache::refresh();

        // Step 2: Update via MCP router (reads processed data from stache, merges, validates)
        $updateResult = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => $entryId,
            'data' => ['title' => 'Updated Roundtrip'],
        ]);

        $this->assertTrue($updateResult['success'], 'Update failed: ' . json_encode($updateResult['errors'] ?? $updateResult['error'] ?? 'unknown'));
    }

    /**
     * Partial update where the blueprint has required fields.
     * Updating a non-required field should not fail because a required field is missing.
     */
    public function test_partial_update_does_not_fail_on_missing_required_fields(): void
    {
        $handle = 'repro_required';

        Collection::make($handle)->title('Repro Required')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => [
                'type' => 'text',
                'display' => 'Title',
                'required' => true,
                'validate' => ['required'],
            ],
            'page_type' => [
                'type' => 'select',
                'display' => 'Page Type',
                'options' => ['default' => 'Default', 'landing' => 'Landing'],
            ],
            'subtitle' => ['type' => 'text', 'display' => 'Subtitle'],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        $entry = Entry::make()
            ->id('repro-required-001')
            ->collection($handle)
            ->slug('required-test')
            ->data([
                'title' => 'Required Test',
                'page_type' => 'default',
                'subtitle' => 'A subtitle',
            ]);

        $entry->save();
        Stache::refresh();

        // Update only page_type — title is required but not in the update data
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'repro-required-001',
            'data' => ['page_type' => 'landing'],
        ]);

        $this->assertTrue($result['success'], 'Update failed: ' . json_encode($result['errors'] ?? $result['error'] ?? 'unknown'));

        // Verify existing data wasn't wiped
        $getResult = $this->router->execute([
            'action' => 'get',
            'collection' => $handle,
            'id' => 'repro-required-001',
        ]);

        $this->assertTrue($getResult['success']);
        $this->assertEquals('Required Test', $getResult['data']['entry']['data']['title']);
        $this->assertEquals('landing', $getResult['data']['entry']['data']['page_type']);
        $this->assertEquals('A subtitle', $getResult['data']['entry']['data']['subtitle']);
    }

    /**
     * Simulates a third-party fieldtype that throws TypeError
     * during validation (like SEO Pro's preProcessValidatable).
     * The update should succeed by falling back to incoming-only validation.
     */
    public function test_update_falls_back_when_fieldtype_throws_type_error(): void
    {
        // Register a fieldtype that crashes during validation
        $crashingFieldtype = new class extends StatamicFieldtype
        {
            /** @var string */
            protected static $handle = 'crashing_fieldtype';

            public function preProcessValidatable(mixed $value): mixed
            {
                if ($value !== null) {
                    // Simulate: Cannot access offset of type string on string
                    throw new \TypeError('Cannot access offset of type string on string');
                }

                return $value;
            }
        };

        app('statamic.fieldtypes')->put('crashing_fieldtype', $crashingFieldtype::class);

        $handle = 'repro_crash';

        Collection::make($handle)->title('Repro Crash')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'seo' => [
                'type' => 'crashing_fieldtype',
                'display' => 'SEO',
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        $entry = Entry::make()
            ->id('repro-crash-001')
            ->collection($handle)
            ->slug('crash-test')
            ->data([
                'title' => 'Crash Test',
                'seo' => ['description' => 'Test SEO data'],
            ]);

        $entry->save();
        Stache::refresh();

        // Update title — seo field has data that crashes its fieldtype
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'repro-crash-001',
            'data' => ['title' => 'Updated Crash Test'],
        ]);

        $this->assertTrue($result['success'], 'Update failed: ' . json_encode($result['errors'] ?? $result['error'] ?? 'unknown'));

        // Verify the update worked
        $getResult = $this->router->execute([
            'action' => 'get',
            'collection' => $handle,
            'id' => 'repro-crash-001',
        ]);

        $this->assertTrue($getResult['success']);
        $this->assertEquals('Updated Crash Test', $getResult['data']['entry']['data']['title']);
        // Existing seo data should be preserved (not wiped by the update)
        $this->assertEquals(['description' => 'Test SEO data'], $getResult['data']['entry']['data']['seo']);
    }

    /**
     * Deep nesting: replicator → bard with sets → nested replicator-like structure.
     * Multiple bard set types with different field compositions.
     */
    public function test_update_entry_with_deeply_nested_replicator_bard_sets(): void
    {
        $handle = 'repro_deep';

        Collection::make($handle)->title('Repro Deep')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'page_builder' => [
                'type' => 'replicator',
                'display' => 'Page Builder',
                'sets' => [
                    'headers' => [
                        'display' => 'Headers',
                        'sets' => [
                            'hero' => [
                                'display' => 'Hero',
                                'fields' => [
                                    ['handle' => 'headline', 'field' => [
                                        'type' => 'bard',
                                        'display' => 'Headline',
                                        'inline' => true,
                                        'buttons' => ['bold', 'italic'],
                                    ]],
                                    ['handle' => 'subtitle', 'field' => ['type' => 'text', 'display' => 'Subtitle']],
                                    ['handle' => 'buttons', 'field' => [
                                        'type' => 'grid',
                                        'display' => 'Buttons',
                                        'fields' => [
                                            ['handle' => 'text', 'field' => ['type' => 'text', 'display' => 'Text']],
                                            ['handle' => 'url', 'field' => ['type' => 'text', 'display' => 'URL']],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                    'content' => [
                        'display' => 'Content',
                        'sets' => [
                            'rich_text' => [
                                'display' => 'Rich Text',
                                'fields' => [
                                    ['handle' => 'body', 'field' => [
                                        'type' => 'bard',
                                        'display' => 'Body',
                                        'sets' => [
                                            'embeds' => [
                                                'display' => 'Embeds',
                                                'sets' => [
                                                    'code_block' => [
                                                        'display' => 'Code Block',
                                                        'fields' => [
                                                            ['handle' => 'code', 'field' => ['type' => 'code', 'display' => 'Code']],
                                                            ['handle' => 'language', 'field' => ['type' => 'text', 'display' => 'Language']],
                                                        ],
                                                    ],
                                                    'quote' => [
                                                        'display' => 'Quote',
                                                        'fields' => [
                                                            ['handle' => 'quote_text', 'field' => ['type' => 'textarea', 'display' => 'Quote']],
                                                            ['handle' => 'attribution', 'field' => ['type' => 'text', 'display' => 'Attribution']],
                                                        ],
                                                    ],
                                                    'callout' => [
                                                        'display' => 'Callout',
                                                        'fields' => [
                                                            ['handle' => 'callout_type', 'field' => [
                                                                'type' => 'select',
                                                                'display' => 'Type',
                                                                'options' => ['info' => 'Info', 'warning' => 'Warning'],
                                                            ]],
                                                            ['handle' => 'callout_body', 'field' => [
                                                                'type' => 'bard',
                                                                'display' => 'Body',
                                                                'inline' => true,
                                                            ]],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ]],
                                ],
                            ],
                            'features_grid' => [
                                'display' => 'Features Grid',
                                'fields' => [
                                    ['handle' => 'features', 'field' => [
                                        'type' => 'replicator',
                                        'display' => 'Features',
                                        'sets' => [
                                            'items' => [
                                                'display' => 'Items',
                                                'sets' => [
                                                    'feature' => [
                                                        'display' => 'Feature',
                                                        'fields' => [
                                                            ['handle' => 'icon', 'field' => ['type' => 'text', 'display' => 'Icon']],
                                                            ['handle' => 'feature_title', 'field' => ['type' => 'text', 'display' => 'Title']],
                                                            ['handle' => 'feature_body', 'field' => [
                                                                'type' => 'bard',
                                                                'display' => 'Body',
                                                                'inline' => true,
                                                            ]],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        // Create entry with deeply nested data: replicator → bard → sets → nested bard
        $entry = Entry::make()
            ->id('repro-deep-001')
            ->collection($handle)
            ->slug('deep-test')
            ->data([
                'title' => 'Deep Nesting Test',
                'page_builder' => [
                    [
                        'id' => 'hero-1',
                        'type' => 'hero',
                        'enabled' => true,
                        'headline' => [
                            ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Bold Headline'],
                        ],
                        'subtitle' => 'A subtitle',
                        'buttons' => [
                            ['text' => 'Get Started', 'url' => '/start'],
                            ['text' => 'Learn More', 'url' => '/learn'],
                        ],
                    ],
                    [
                        'id' => 'rt-1',
                        'type' => 'rich_text',
                        'enabled' => true,
                        'body' => [
                            [
                                'type' => 'paragraph',
                                'content' => [['type' => 'text', 'text' => 'Intro paragraph.']],
                            ],
                            [
                                'type' => 'set',
                                'attrs' => [
                                    'id' => 'code-1',
                                    'values' => [
                                        'type' => 'code_block',
                                        'code' => ['code' => 'npm install', 'mode' => 'shell'],
                                        'language' => 'bash',
                                    ],
                                ],
                            ],
                            [
                                'type' => 'paragraph',
                                'content' => [['type' => 'text', 'text' => 'Middle paragraph.']],
                            ],
                            [
                                'type' => 'set',
                                'attrs' => [
                                    'id' => 'quote-1',
                                    'values' => [
                                        'type' => 'quote',
                                        'quote_text' => 'This is a great product.',
                                        'attribution' => 'Customer',
                                    ],
                                ],
                            ],
                            [
                                'type' => 'set',
                                'attrs' => [
                                    'id' => 'callout-1',
                                    'values' => [
                                        'type' => 'callout',
                                        'callout_type' => 'warning',
                                        'callout_body' => [
                                            ['type' => 'text', 'text' => 'Nested inline bard inside a bard set'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'fg-1',
                        'type' => 'features_grid',
                        'enabled' => true,
                        'features' => [
                            [
                                'id' => 'feat-1',
                                'type' => 'feature',
                                'enabled' => true,
                                'icon' => 'rocket',
                                'feature_title' => 'Fast',
                                'feature_body' => [
                                    ['type' => 'text', 'text' => 'Blazing performance'],
                                ],
                            ],
                            [
                                'id' => 'feat-2',
                                'type' => 'feature',
                                'enabled' => true,
                                'icon' => 'shield',
                                'feature_title' => 'Secure',
                                'feature_body' => [
                                    ['type' => 'text', 'text' => 'Enterprise security'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $entry->save();
        Stache::refresh();

        // Update just the title — all the deeply nested data should not cause issues
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'repro-deep-001',
            'data' => ['title' => 'Updated Deep Nesting'],
        ]);

        $this->assertTrue($result['success'], 'Update failed: ' . json_encode($result['errors'] ?? $result['error'] ?? 'unknown'));

        // Verify deeply nested data is preserved
        $getResult = $this->router->execute([
            'action' => 'get',
            'collection' => $handle,
            'id' => 'repro-deep-001',
        ]);

        $this->assertTrue($getResult['success']);
        $data = $getResult['data']['entry']['data'];
        $this->assertEquals('Updated Deep Nesting', $data['title']);
        $this->assertCount(3, $data['page_builder']);
        $this->assertEquals('hero', $data['page_builder'][0]['type']);
        $this->assertEquals('rich_text', $data['page_builder'][1]['type']);
        $this->assertEquals('features_grid', $data['page_builder'][2]['type']);
    }

    /**
     * Round-trip deep nesting: CREATE via MCP → UPDATE via MCP.
     * process() transforms the data; update must handle the processed format.
     */
    public function test_roundtrip_deep_nested_replicator_bard_with_multiple_set_types(): void
    {
        $handle = 'repro_rt_deep';

        Collection::make($handle)->title('RT Deep')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title', 'required' => true],
            'builder' => [
                'type' => 'replicator',
                'display' => 'Builder',
                'sets' => [
                    'blocks' => [
                        'display' => 'Blocks',
                        'sets' => [
                            'text_block' => [
                                'display' => 'Text Block',
                                'fields' => [
                                    ['handle' => 'content', 'field' => [
                                        'type' => 'bard',
                                        'display' => 'Content',
                                        'sets' => [
                                            'inline' => [
                                                'display' => 'Inline',
                                                'sets' => [
                                                    'button' => [
                                                        'display' => 'Button',
                                                        'fields' => [
                                                            ['handle' => 'label', 'field' => ['type' => 'text', 'display' => 'Label']],
                                                            ['handle' => 'href', 'field' => ['type' => 'text', 'display' => 'URL']],
                                                            ['handle' => 'style', 'field' => [
                                                                'type' => 'select',
                                                                'display' => 'Style',
                                                                'options' => ['primary' => 'Primary', 'secondary' => 'Secondary'],
                                                            ]],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ]],
                                    ['handle' => 'layout', 'field' => [
                                        'type' => 'select',
                                        'display' => 'Layout',
                                        'options' => ['full' => 'Full', 'narrow' => 'Narrow'],
                                    ]],
                                ],
                            ],
                            'group_block' => [
                                'display' => 'Group Block',
                                'fields' => [
                                    ['handle' => 'settings', 'field' => [
                                        'type' => 'group',
                                        'display' => 'Settings',
                                        'fields' => [
                                            ['handle' => 'bg_color', 'field' => ['type' => 'text', 'display' => 'BG Color']],
                                            ['handle' => 'padding', 'field' => ['type' => 'text', 'display' => 'Padding']],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        // Step 1: Create via MCP
        $createResult = $this->router->execute([
            'action' => 'create',
            'collection' => $handle,
            'data' => [
                'title' => 'Deep RT Test',
                'builder' => [
                    [
                        'type' => 'text_block',
                        'enabled' => true,
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                            [
                                'type' => 'set',
                                'attrs' => [
                                    'id' => 'btn-1',
                                    'values' => [
                                        'type' => 'button',
                                        'label' => 'Click me',
                                        'href' => '/action',
                                        'style' => 'primary',
                                    ],
                                ],
                            ],
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After button']]],
                        ],
                        'layout' => 'full',
                    ],
                    [
                        'type' => 'group_block',
                        'enabled' => true,
                        'settings' => [
                            'bg_color' => '#ffffff',
                            'padding' => '2rem',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($createResult['success'], 'Create failed: ' . json_encode($createResult['errors'] ?? $createResult['error'] ?? 'unknown'));
        $entryId = $createResult['data']['entry']['id'];
        Stache::refresh();

        // Step 2: Update just title — deeply nested processed data in stache
        $updateResult = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => $entryId,
            'data' => ['title' => 'Updated Deep RT'],
        ]);

        $this->assertTrue($updateResult['success'], 'Update failed: ' . json_encode($updateResult['errors'] ?? $updateResult['error'] ?? 'unknown'));

        // Step 3: Update the replicator data itself
        $updateResult2 = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => $entryId,
            'data' => [
                'builder' => [
                    [
                        'type' => 'text_block',
                        'enabled' => true,
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Replaced content']]],
                        ],
                        'layout' => 'narrow',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($updateResult2['success'], 'Replicator update failed: ' . json_encode($updateResult2['errors'] ?? $updateResult2['error'] ?? 'unknown'));
    }

    /**
     * Bare minimum: just text fields, as a control test.
     */
    public function test_update_entry_with_only_text_fields(): void
    {
        $handle = 'repro_simple';

        Collection::make($handle)->title('Repro Simple')->save();

        $blueprint = BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Title'],
            'subtitle' => ['type' => 'text', 'display' => 'Subtitle'],
        ]);

        $blueprint->setHandle($handle)->setNamespace("collections.{$handle}")->save();

        $entry = Entry::make()
            ->id('repro-simple-001')
            ->collection($handle)
            ->slug('simple-test')
            ->data([
                'title' => 'Simple',
                'subtitle' => 'Sub',
            ]);

        $entry->save();
        Stache::refresh();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $handle,
            'id' => 'repro-simple-001',
            'data' => ['title' => 'Updated Simple'],
        ]);

        $this->assertTrue($result['success'], 'Update failed: ' . json_encode($result['errors'] ?? $result['error'] ?? 'unknown'));
    }
}
