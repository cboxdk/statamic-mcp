<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;

/**
 * Integration tests for the agent context-delivery improvements:
 *  1. BlueprintsRouter::get returns _format_spec describing each field's wire format.
 *  2. Entry write actions surface FieldFormatException messages with field paths
 *     instead of swallowing them into the generic "Error occurred" placeholder.
 */
class BlueprintFormatSpecTest extends TestCase
{
    private BlueprintsRouter $blueprintsRouter;

    private EntriesRouter $entriesRouter;

    private string $collectionHandle = 'format_spec_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->blueprintsRouter = new BlueprintsRouter;
        $this->entriesRouter = new EntriesRouter;

        $collection = CollectionFacade::make($this->collectionHandle)
            ->title('Format Spec Test');
        $collection->save();

        $blueprint = BlueprintFacade::make($this->collectionHandle);
        $blueprint->setNamespace("collections.{$this->collectionHandle}");
        $blueprint->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                                ['handle' => 'tagline', 'field' => [
                                    'type' => 'bard',
                                    'inline' => true,
                                    'buttons' => ['bold', 'italic'],
                                    'display' => 'Tagline',
                                ]],
                                ['handle' => 'page_builder', 'field' => [
                                    'type' => 'replicator',
                                    'display' => 'Page Builder',
                                    'sets' => [
                                        'main' => ['sets' => [
                                            'hero' => [
                                                'display' => 'Hero',
                                                'fields' => [
                                                    ['handle' => 'headline', 'field' => ['type' => 'text']],
                                                ],
                                            ],
                                        ]],
                                    ],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->save();
    }

    public function test_get_blueprint_includes_format_spec_for_inline_bard(): void
    {
        $result = $this->blueprintsRouter->execute([
            'action' => 'get',
            'handle' => $this->collectionHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
        ]);

        $this->assertTrue($result['success'], 'Expected blueprint get to succeed');

        $fields = collect($result['data']['blueprint']['fields']);
        $tagline = $fields->firstWhere('handle', 'tagline');

        $this->assertNotNull($tagline, 'tagline field present in blueprint output');
        $this->assertArrayHasKey('_format_spec', $tagline, '_format_spec attached to bard field');
        $this->assertSame('bard_inline', $tagline['_format_spec']['shape']);
        $this->assertContains('text', $tagline['_format_spec']['allowed_node_types']);
        $this->assertNotContains('paragraph', $tagline['_format_spec']['allowed_node_types']);
    }

    public function test_get_blueprint_lists_replicator_set_types_and_definitions(): void
    {
        $result = $this->blueprintsRouter->execute([
            'action' => 'get',
            'handle' => $this->collectionHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
        ]);

        $fields = collect($result['data']['blueprint']['fields']);
        $pageBuilder = $fields->firstWhere('handle', 'page_builder');

        $this->assertSame('replicator_items', $pageBuilder['_format_spec']['shape']);
        $this->assertContains('hero', $pageBuilder['_format_spec']['allowed_set_types']);
        $this->assertSame(['id', 'type', 'enabled'], $pageBuilder['_format_spec']['item_required_keys']);
        $this->assertArrayHasKey('hero', $pageBuilder['_format_spec']['set_definitions']);
    }

    public function test_get_blueprint_omits_format_spec_when_disabled(): void
    {
        $result = $this->blueprintsRouter->execute([
            'action' => 'get',
            'handle' => $this->collectionHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'include_format_spec' => false,
        ]);

        $fields = collect($result['data']['blueprint']['fields']);
        foreach ($fields as $field) {
            $this->assertArrayNotHasKey('_format_spec', $field);
        }
    }

    public function test_replicator_with_string_value_returns_field_path_in_error(): void
    {
        // Sending a string where the replicator expects an array of items is a classic agent
        // mistake. The error must reach the client with the field path so the agent can
        // self-correct.
        $result = $this->entriesRouter->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Bad replicator payload',
                'page_builder' => 'this is not an array',
            ],
        ]);

        $this->assertFalse($result['success'], 'Expected create to fail with malformed replicator data');

        $errorText = is_array($result['errors'] ?? null) ? implode(' ', $result['errors']) : '';
        $this->assertStringContainsString('page_builder', $errorText, 'Error message includes the field name');
        $this->assertStringContainsString('replicator', $errorText, 'Error message identifies the fieldtype');
    }

    public function test_replicator_with_unknown_set_type_returns_specific_error(): void
    {
        $result = $this->entriesRouter->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Unknown set type',
                'page_builder' => [
                    ['type' => 'nonexistent_block', 'enabled' => true, 'id' => 'abc12345'],
                ],
            ],
        ]);

        $this->assertFalse($result['success']);

        $errorText = is_array($result['errors'] ?? null) ? implode(' ', $result['errors']) : '';
        $this->assertStringContainsString('nonexistent_block', $errorText);
    }
}
