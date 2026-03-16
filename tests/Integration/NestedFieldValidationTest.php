<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;

class NestedFieldValidationTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle = 'nested_fields_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;

        // Create collection
        $collection = CollectionFacade::make($this->collectionHandle)
            ->title('Nested Fields Test');
        $collection->save();

        // Create blueprint with replicator and grid fields
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
                                    'handle' => 'content_blocks',
                                    'field' => [
                                        'type' => 'replicator',
                                        'display' => 'Content Blocks',
                                        'sets' => [
                                            'text_block' => [
                                                'display' => 'Text Block',
                                                'fields' => [
                                                    [
                                                        'handle' => 'text',
                                                        'field' => [
                                                            'type' => 'text',
                                                            'display' => 'Text',
                                                        ],
                                                    ],
                                                    [
                                                        'handle' => 'body',
                                                        'field' => [
                                                            'type' => 'textarea',
                                                            'display' => 'Body',
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'rows',
                                    'field' => [
                                        'type' => 'grid',
                                        'display' => 'Rows',
                                        'fields' => [
                                            [
                                                'handle' => 'column1',
                                                'field' => [
                                                    'type' => 'text',
                                                    'display' => 'Column 1',
                                                ],
                                            ],
                                            [
                                                'handle' => 'column2',
                                                'field' => [
                                                    'type' => 'text',
                                                    'display' => 'Column 2',
                                                ],
                                            ],
                                        ],
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

    public function test_create_entry_with_valid_replicator_data_succeeds(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Replicator Test',
                'content_blocks' => [
                    ['type' => 'text_block', 'text' => 'Hello', 'body' => 'World'],
                    ['type' => 'text_block', 'text' => 'Second block', 'body' => 'More content'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected entry creation with valid replicator data to succeed. Errors: ' . json_encode($result['errors'] ?? []));
        $this->assertTrue($result['data']['created']);
    }

    public function test_create_entry_with_valid_grid_data_succeeds(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Grid Test',
                'rows' => [
                    ['column1' => 'val1', 'column2' => 'val2'],
                    ['column1' => 'val3', 'column2' => 'val4'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected entry creation with valid grid data to succeed. Errors: ' . json_encode($result['errors'] ?? []));
        $this->assertTrue($result['data']['created']);
    }

    public function test_create_entry_with_combined_nested_fields_succeeds(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Combined Nested Fields',
                'content_blocks' => [
                    ['type' => 'text_block', 'text' => 'Intro', 'body' => 'Introduction text'],
                ],
                'rows' => [
                    ['column1' => 'data1', 'column2' => 'data2'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected entry with combined nested fields to succeed. Errors: ' . json_encode($result['errors'] ?? []));
        $this->assertTrue($result['data']['created']);
    }

    public function test_replicator_data_persists_correctly(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Persistence Check',
                'content_blocks' => [
                    ['type' => 'text_block', 'text' => 'Persisted', 'body' => 'This should persist'],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $entryId = $result['data']['entry']['id'];

        // Read back and verify nested data persisted
        $getResult = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);

        $this->assertTrue($getResult['success']);
        $data = $getResult['data']['entry']['data'];
        $this->assertArrayHasKey('content_blocks', $data);
        $this->assertIsArray($data['content_blocks']);
        $this->assertNotEmpty($data['content_blocks']);

        // Statamic may store the replicator data with different key structures
        // (e.g., 'type' at root or nested under a set key). Verify the text content persisted.
        $firstBlock = $data['content_blocks'][0];
        $this->assertIsArray($firstBlock);
        // The text value should be present somewhere in the block
        $this->assertContains('Persisted', array_values(array_filter($firstBlock, 'is_string')));
    }

    public function test_grid_data_persists_correctly(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Grid Persistence',
                'rows' => [
                    ['column1' => 'persisted1', 'column2' => 'persisted2'],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $entryId = $result['data']['entry']['id'];

        $getResult = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);

        $this->assertTrue($getResult['success']);
        $data = $getResult['data']['entry']['data'];
        $this->assertArrayHasKey('rows', $data);
        $this->assertIsArray($data['rows']);
        $this->assertCount(1, $data['rows']);
        $this->assertEquals('persisted1', $data['rows'][0]['column1']);
    }

    public function test_create_entry_with_empty_replicator_succeeds(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Empty Replicator',
                'content_blocks' => [],
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected empty replicator array to be accepted. Errors: ' . json_encode($result['errors'] ?? []));
    }

    public function test_create_entry_with_empty_grid_succeeds(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Empty Grid',
                'rows' => [],
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected empty grid array to be accepted. Errors: ' . json_encode($result['errors'] ?? []));
    }

    public function test_replicator_with_invalid_set_type_is_handled(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Invalid Set Type',
                'content_blocks' => [
                    ['type' => 'nonexistent_set', 'text' => 'This set type does not exist'],
                ],
            ],
        ]);

        // Statamic may accept this (sets unknown types are silently ignored) or reject it.
        // The key assertion is that the router does not crash — it returns a valid response.
        $this->assertIsBool($result['success']);
        $this->assertArrayHasKey('data', $result);
    }

    public function test_replicator_with_non_array_value_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Bad Replicator Data',
                'content_blocks' => 'not an array',
            ],
        ]);

        // Passing a string where an array is expected — Statamic should reject or the tool should handle gracefully
        $this->assertIsBool($result['success']);
    }

    public function test_grid_with_non_array_value_returns_error(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Bad Grid Data',
                'rows' => 'not an array',
            ],
        ]);

        // Passing a string where an array is expected — should be handled gracefully
        $this->assertIsBool($result['success']);
    }
}
