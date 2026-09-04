<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

/**
 * Covers the content_validate sweep — the read-side check for content that
 * drifted away from its blueprint without going through a validated write.
 */
class ContentValidateTest extends TestCase
{
    private ContentFacadeRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new ContentFacadeRouter;
        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(4));

        Collection::make($this->collectionHandle)->title('Pages')->save();

        Blueprint::make($this->collectionHandle)
            ->setNamespace("collections.{$this->collectionHandle}")
            ->setContents([
                'title' => 'Page',
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
                                    ['handle' => 'status', 'field' => [
                                        'type' => 'select',
                                        'options' => ['draft' => 'Draft', 'live' => 'Live'],
                                    ]],
                                    ['handle' => 'blocks', 'field' => [
                                        'type' => 'replicator',
                                        'sets' => [
                                            'content' => [
                                                'sets' => [
                                                    'hero' => [
                                                        'fields' => [
                                                            ['handle' => 'heading', 'field' => ['type' => 'text']],
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
            ])
            ->save();
    }

    /**
     * Write the entry straight to the Stache so the sweep sees data that never
     * passed through a validated write — which is the whole point of the action.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeEntry(string $slug, array $data): void
    {
        Entry::make()
            ->collection($this->collectionHandle)
            ->slug($slug)
            ->data($data)
            ->save();
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @return list<array<string, mixed>>
     */
    private function findingsOfType(array $result, string $type): array
    {
        return array_values(array_filter(
            $result['data']['findings'],
            fn (array $finding): bool => $finding['type'] === $type
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function validate(array $arguments = []): array
    {
        return $this->router->execute(array_merge([
            'action' => 'content_validate',
            'scope' => 'entries',
            'collection' => $this->collectionHandle,
        ], $arguments));
    }

    public function test_reports_no_findings_for_valid_content(): void
    {
        $this->storeEntry('valid', [
            'title' => 'A Valid Page',
            'status' => 'live',
            'blocks' => [
                ['type' => 'hero', 'id' => 'abc', 'heading' => 'Hello'],
            ],
        ]);

        $result = $this->validate();

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['findings']);
        $this->assertSame(0, $result['data']['summary']['findings']);
        $this->assertSame(1, $result['data']['summary']['records_scanned']);
        $this->assertTrue($result['data']['completed']);
    }

    public function test_reports_blueprint_rule_violations(): void
    {
        $this->storeEntry('no-title', ['status' => 'live']);

        $findings = $this->findingsOfType($this->validate(), 'rule_violation');

        $this->assertNotEmpty($findings, 'A required field with no value should be reported.');
        $this->assertSame('error', $findings[0]['severity']);
        $this->assertSame('entry', $findings[0]['record_type']);
    }

    public function test_reports_option_values_outside_the_declared_set(): void
    {
        $this->storeEntry('bad-option', [
            'title' => 'Bad Option',
            'status' => 'archived',
        ]);

        $findings = $this->findingsOfType($this->validate(), 'invalid_option');

        $this->assertCount(1, $findings);
        $this->assertSame('status', $findings[0]['field_path']);
        $this->assertStringContainsString("'archived'", $findings[0]['message']);
    }

    public function test_accepts_numeric_option_keys_stored_as_strings(): void
    {
        Blueprint::make('numeric-options')
            ->setNamespace("collections.{$this->collectionHandle}")
            ->setContents([
                'title' => 'Numeric',
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => 'rating', 'field' => ['type' => 'select', 'options' => [1 => 'One', 2 => 'Two']]],
                ]]]]],
            ])
            ->save();

        Entry::make()
            ->collection($this->collectionHandle)
            ->slug('numeric')
            ->blueprint('numeric-options')
            ->data(['title' => 'Numeric', 'rating' => '2'])
            ->save();

        $findings = $this->findingsOfType($this->validate(), 'invalid_option');

        $this->assertSame([], $findings, 'An int option key matched by a string value is not a drift.');

        // Guard against the assertion above passing because the blueprint never
        // resolved: a value that really is outside the options must still fail.
        Entry::make()
            ->collection($this->collectionHandle)
            ->slug('numeric-bad')
            ->blueprint('numeric-options')
            ->data(['title' => 'Numeric Bad', 'rating' => '7'])
            ->save();

        $this->assertCount(1, $this->findingsOfType($this->validate(), 'invalid_option'));
    }

    public function test_does_not_report_stored_asset_paths_as_rule_violations(): void
    {
        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);
        Storage::fake('assets');
        Storage::disk('assets')->put('icons/heart.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        AssetContainer::make('media')->title('Media')->disk('assets')->save();

        Blueprint::make('validated-assets')
            ->setNamespace("collections.{$this->collectionHandle}")
            ->setContents([
                'title' => 'Validated Assets',
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => 'icon', 'field' => [
                        'type' => 'assets',
                        'container' => 'media',
                        'max_files' => 1,
                        'validate' => ['required', 'mimes:svg'],
                    ]],
                    ['handle' => 'blocks', 'field' => [
                        'type' => 'replicator',
                        'sets' => ['content' => ['sets' => ['card' => ['fields' => [
                            ['handle' => 'card_icon', 'field' => [
                                'type' => 'assets',
                                'container' => 'media',
                                'max_files' => 1,
                                'validate' => ['required', 'mimes:svg'],
                            ]],
                        ]]]]],
                    ]],
                ]]]]],
            ])
            ->save();

        Entry::make()
            ->collection($this->collectionHandle)
            ->slug('valid-icons')
            ->blueprint('validated-assets')
            ->data([
                'title' => 'Valid Icons',
                'icon' => 'icons/heart.svg',
                'blocks' => [
                    ['type' => 'card', 'id' => 'block-1', 'card_icon' => 'icons/heart.svg'],
                ],
            ])
            ->save();

        $this->assertSame([], $this->findingsOfType($this->validate(), 'rule_violation'));
    }

    public function test_reports_asset_references_that_no_longer_resolve(): void
    {
        config(['filesystems.disks.assets' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/assets'),
        ]]);
        Storage::fake('assets');

        AssetContainer::make('media')->title('Media')->disk('assets')->save();

        Blueprint::make('with-assets')
            ->setNamespace("collections.{$this->collectionHandle}")
            ->setContents([
                'title' => 'With Assets',
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'media', 'max_files' => 1]],
                ]]]]],
            ])
            ->save();

        Entry::make()
            ->collection($this->collectionHandle)
            ->slug('broken-image')
            ->blueprint('with-assets')
            ->data(['title' => 'Broken Image', 'image' => 'gone.jpg'])
            ->save();

        $findings = $this->findingsOfType($this->validate(), 'missing_asset');

        $this->assertCount(1, $findings);
        $this->assertSame('error', $findings[0]['severity']);
        $this->assertSame('image', $findings[0]['field_path']);
        $this->assertStringContainsString('gone.jpg', $findings[0]['message']);
    }

    public function test_reports_navigation_items_pointing_at_deleted_entries(): void
    {
        $nav = Nav::make('footer-' . bin2hex(random_bytes(4)))->title('Footer');
        $nav->save();

        $tree = $nav->makeTree(Site::default()->handle());
        $tree->tree([
            ['entry' => 'nope-this-entry-is-gone'],
        ])->save();

        $result = $this->router->execute([
            'action' => 'content_validate',
            'scope' => 'navigations',
        ]);

        $findings = $this->findingsOfType($result, 'dangling_reference');

        $this->assertCount(1, $findings);
        $this->assertSame('navigation', $findings[0]['record_type']);
        $this->assertStringContainsString('nope-this-entry-is-gone', $findings[0]['message']);
    }

    public function test_reports_unknown_replicator_set_types(): void
    {
        $this->storeEntry('ghost-set', [
            'title' => 'Ghost Set',
            'blocks' => [
                ['type' => 'removed_block', 'id' => 'abc', 'heading' => 'Orphan'],
            ],
        ]);

        $findings = $this->findingsOfType($this->validate(), 'unknown_set_type');

        $this->assertCount(1, $findings);
        $this->assertSame('error', $findings[0]['severity']);
        $this->assertStringContainsString('removed_block', $findings[0]['message']);
        $this->assertSame('blocks.0', $findings[0]['field_path']);
    }

    public function test_reports_fields_stored_in_a_set_that_the_blueprint_dropped(): void
    {
        $this->storeEntry('stale-field', [
            'title' => 'Stale Field',
            'blocks' => [
                ['type' => 'hero', 'id' => 'abc', 'heading' => 'Hi', 'subheading' => 'Removed from blueprint'],
            ],
        ]);

        $findings = $this->findingsOfType($this->validate(), 'unknown_field');

        $this->assertCount(1, $findings);
        $this->assertSame('warning', $findings[0]['severity']);
        $this->assertSame('blocks.0.subheading', $findings[0]['field_path']);
    }

    public function test_severity_filter_narrows_the_findings(): void
    {
        $this->storeEntry('mixed', [
            'title' => 'Mixed',
            'status' => 'archived',
            'blocks' => [
                ['type' => 'hero', 'id' => 'abc', 'heading' => 'Hi', 'gone' => 'x'],
            ],
        ]);

        $errors = $this->validate(['severity' => 'error']);
        $warnings = $this->validate(['severity' => 'warning']);

        $this->assertNotEmpty($errors['data']['findings']);
        $this->assertNotEmpty($warnings['data']['findings']);

        foreach ($errors['data']['findings'] as $finding) {
            $this->assertSame('error', $finding['severity']);
        }

        foreach ($warnings['data']['findings'] as $finding) {
            $this->assertSame('warning', $finding['severity']);
        }
    }

    public function test_paginates_across_the_record_stream(): void
    {
        foreach (['one', 'two', 'three'] as $slug) {
            $this->storeEntry($slug, ['title' => ucfirst($slug)]);
        }

        $first = $this->validate(['limit' => 2, 'offset' => 0]);
        $second = $this->validate(['limit' => 2, 'offset' => 2]);

        $this->assertSame(2, $first['data']['summary']['records_scanned']);
        $this->assertSame(3, $first['data']['pagination']['total']);
        $this->assertTrue($first['data']['pagination']['has_more']);

        $this->assertSame(1, $second['data']['summary']['records_scanned']);
        $this->assertFalse($second['data']['pagination']['has_more']);
    }

    public function test_caps_returned_findings_while_keeping_counts_accurate(): void
    {
        foreach (['a', 'b', 'c'] as $slug) {
            $this->storeEntry($slug, ['title' => ucfirst($slug), 'status' => 'archived']);
        }

        $result = $this->validate(['max_findings' => 1]);

        $this->assertCount(1, $result['data']['findings']);
        $this->assertTrue($result['data']['findings_truncated']);
        $this->assertSame(3, $result['data']['summary']['findings']);
        $this->assertSame(3, $result['data']['summary']['records_with_issues']);
    }

    public function test_summary_groups_findings_by_severity_and_type(): void
    {
        $this->storeEntry('grouped', [
            'title' => 'Grouped',
            'status' => 'archived',
        ]);

        $summary = $this->validate()['data']['summary'];

        $this->assertSame(['error' => 1], $summary['by_severity']);
        $this->assertSame(['invalid_option' => 1], $summary['by_type']);
    }

    public function test_rejects_an_unknown_scope(): void
    {
        $result = $this->router->execute([
            'action' => 'content_validate',
            'scope' => 'widgets',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown scope', $result['errors'][0]);
    }
}
