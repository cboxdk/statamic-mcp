<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;

class GroupFieldBugTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle = 'group_field_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;

        $collection = CollectionFacade::make($this->collectionHandle)
            ->title('Group Field Test');
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
                                [
                                    'handle' => 'title',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => 'Title',
                                    ],
                                ],
                                [
                                    'handle' => 'description',
                                    'field' => [
                                        'type' => 'bard',
                                        'display' => 'Description',
                                    ],
                                ],
                                [
                                    'handle' => 'seo',
                                    'field' => [
                                        'type' => 'group',
                                        'display' => 'SEO',
                                        'fields' => [
                                            [
                                                'handle' => 'description',
                                                'field' => [
                                                    'type' => 'text',
                                                    'display' => 'Meta Description',
                                                ],
                                            ],
                                            [
                                                'handle' => 'title',
                                                'field' => [
                                                    'type' => 'text',
                                                    'display' => 'Meta Title',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'page_builder',
                                    'field' => [
                                        'type' => 'replicator',
                                        'display' => 'Page Builder',
                                        'sets' => [
                                            'content_block' => [
                                                'display' => 'Content Block',
                                                'fields' => [
                                                    [
                                                        'handle' => 'content',
                                                        'field' => [
                                                            'type' => 'bard',
                                                            'display' => 'Content',
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'tags',
                                    'field' => [
                                        'type' => 'taggable',
                                        'display' => 'Tags',
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

    public function test_create_entry_with_nested_group_field_data(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Test Entry',
                'seo' => [
                    'description' => 'A test meta description',
                    'title' => 'Test SEO Title',
                ],
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'Expected entry creation with nested group field data to succeed. Response: ' . json_encode($result)
        );
        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        $this->assertTrue(($resultData['created'] ?? false) === true);
    }

    public function test_create_entry_with_full_payload_matching_user_report(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Geocodio now has a CLI',
                'description' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'A test description.'],
                        ],
                    ],
                ],
                'seo' => [
                    'description' => 'Geocodio now has a command-line interface for geocoding.',
                ],
                'page_builder' => [
                    [
                        'type' => 'content_block',
                        'enabled' => true,
                        'content' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    ['type' => 'text', 'text' => 'Some content.'],
                                ],
                            ],
                        ],
                    ],
                ],
                'tags' => ['cli', 'ai', 'tools'],
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'Expected entry with full payload including seo group to succeed. Response: ' . json_encode($result)
        );
        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        $this->assertTrue(($resultData['created'] ?? false) === true);
    }

    public function test_update_entry_with_nested_group_field_data(): void
    {
        $entry = EntryFacade::make()
            ->id('group-field-update-test')
            ->collection($this->collectionHandle)
            ->slug('group-field-update-test')
            ->data([
                'title' => 'Test Entry For Update',
            ]);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'seo' => [
                    'description' => 'Updated meta description',
                ],
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'Expected entry update with nested group field data to succeed. Response: ' . json_encode($result)
        );
        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        $this->assertTrue(($resultData['updated'] ?? false) === true);
    }

    public function test_group_field_data_persists_correctly(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Persistence Test',
                'seo' => [
                    'description' => 'Persisted description',
                    'title' => 'Persisted title',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        /** @var array<string, mixed> $entryData */
        $entryData = $resultData['entry'];
        $entryId = $entryData['id'];
        $entry = EntryFacade::find($entryId);

        $this->assertNotNull($entry);
        $data = $entry->data()->all();
        $this->assertArrayHasKey('seo', $data);
        /** @var array<string, mixed> $seo */
        $seo = $data['seo'];
        $this->assertEquals('Persisted description', $seo['description']);
        $this->assertEquals('Persisted title', $seo['title']);
    }

    public function test_create_with_seo_field_as_non_group_string_field(): void
    {
        $collection2 = CollectionFacade::make('seo_string_test')
            ->title('SEO String Test');
        $collection2->save();

        $blueprint = BlueprintFacade::make('seo_string_test');
        $blueprint->setNamespace('collections.seo_string_test');
        $blueprint->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => ['type' => 'text', 'display' => 'Title'],
                                ],
                                [
                                    'handle' => 'seo',
                                    'field' => ['type' => 'text', 'display' => 'SEO'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->save();

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => 'seo_string_test',
            'data' => [
                'title' => 'Test',
                'seo' => [
                    'description' => 'This is nested but seo is a text field',
                ],
            ],
        ]);

        $this->assertIsBool($result['success']);
    }

    public function test_blueprint_metadata_key_does_not_break_create(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'blueprint' => 'some_blueprint',
                'title' => 'Entry With Blueprint Key',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'Blueprint metadata key in data should not break creation. Response: ' . json_encode($result)
        );
        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        $this->assertTrue(($resultData['created'] ?? false) === true);
    }

    public function test_update_with_bard_string_in_existing_data_does_not_crash(): void
    {
        $collection2 = CollectionFacade::make('bard_string_test')
            ->title('Bard String Test');
        $collection2->save();

        $blueprint = BlueprintFacade::make('bard_string_test');
        $blueprint->setNamespace('collections.bard_string_test');
        $blueprint->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => ['type' => 'text', 'display' => 'Title'],
                                ],
                                [
                                    'handle' => 'content',
                                    'field' => ['type' => 'bard', 'display' => 'Content'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->save();

        // Create entry with string value for a Bard field (simulates legacy data)
        $entry = EntryFacade::make()
            ->id('bard-string-test-entry')
            ->collection('bard_string_test')
            ->slug('bard-string-test-entry')
            ->data([
                'title' => 'Entry With String Bard',
                'content' => 'Just plain text, not ProseMirror JSON',
            ]);
        $entry->save();

        // Update only the title — the existing string Bard value should not crash validation
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => 'bard_string_test',
            'id' => $entry->id(),
            'data' => [
                'title' => 'Updated Title',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'Updating entry with existing string Bard data should not crash. Response: ' . json_encode($result)
        );
        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        $this->assertTrue(($resultData['updated'] ?? false) === true);
    }

    public function test_invalid_group_scalar_returns_error_and_preserves_existing_data(): void
    {
        $entry = EntryFacade::make()
            ->id('group-field-invalid-scalar')
            ->collection($this->collectionHandle)
            ->slug('group-field-invalid-scalar')
            ->data([
                'title' => 'Existing Group Data',
                'seo' => [
                    'description' => 'Keep this value',
                ],
            ]);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => [
                'seo' => 'bad scalar',
            ],
        ]);

        $this->assertFalse($result['success']);
        $encodedResult = json_encode($result);
        $this->assertIsString($encodedResult);
        $this->assertStringContainsString('expects group data as an array', $encodedResult);

        $updatedEntry = EntryFacade::find($entry->id());
        $this->assertNotNull($updatedEntry);
        /** @var array<string, mixed> $seo */
        $seo = $updatedEntry->get('seo');
        $this->assertSame('Keep this value', $seo['description']);
    }

    public function test_id_field_handle_is_preserved_when_defined_in_blueprint(): void
    {
        $collection = CollectionFacade::make('id_field_test')
            ->title('ID Field Test');
        $collection->save();

        $blueprint = BlueprintFacade::make('id_field_test');
        $blueprint->setNamespace('collections.id_field_test');
        $blueprint->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => ['type' => 'text', 'display' => 'Title'],
                                ],
                                [
                                    'handle' => 'id',
                                    'field' => ['type' => 'text', 'display' => 'Custom ID'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->save();

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => 'id_field_test',
            'data' => [
                'title' => 'Entry With Custom ID Field',
                'id' => 'external-123',
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected custom id field to be preserved. Response: ' . json_encode($result));
        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        /** @var array<string, mixed> $entryData */
        $entryData = $resultData['entry'];
        /** @var array<string, mixed> $entryFields */
        $entryFields = $entryData['data'];
        $this->assertSame('external-123', $entryFields['id']);
    }

    public function test_create_with_nested_group_bard_string_succeeds(): void
    {
        $collection = CollectionFacade::make('nested_group_bard_test')
            ->title('Nested Group Bard Test');
        $collection->save();

        $blueprint = BlueprintFacade::make('nested_group_bard_test');
        $blueprint->setNamespace('collections.nested_group_bard_test');
        $blueprint->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => ['type' => 'text', 'display' => 'Title'],
                                ],
                                [
                                    'handle' => 'content_group',
                                    'field' => [
                                        'type' => 'group',
                                        'display' => 'Content Group',
                                        'fields' => [
                                            [
                                                'handle' => 'body',
                                                'field' => ['type' => 'bard', 'display' => 'Body'],
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

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => 'nested_group_bard_test',
            'data' => [
                'title' => 'Nested Bard',
                'content_group' => [
                    'body' => 'Legacy nested bard string',
                ],
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'Expected nested Bard strings inside groups to be sanitized. Response: ' . json_encode($result)
        );

        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        /** @var array<string, mixed> $entryData */
        $entryData = $resultData['entry'];
        $entryId = $entryData['id'];
        $entry = EntryFacade::find($entryId);

        $this->assertNotNull($entry);
        /** @var array<string, mixed> $contentGroup */
        $contentGroup = $entry->get('content_group');
        /** @var array<int, array<string, mixed>> $body */
        $body = $contentGroup['body'];
        $this->assertSame('paragraph', data_get($body, '0.type'));
        $this->assertSame('Legacy nested bard string', data_get($body, '0.content.0.text'));
    }

    public function test_update_with_nested_group_bard_string_in_existing_data_does_not_crash(): void
    {
        $collection = CollectionFacade::make('nested_group_bard_update_test')
            ->title('Nested Group Bard Update Test');
        $collection->save();

        $blueprint = BlueprintFacade::make('nested_group_bard_update_test');
        $blueprint->setNamespace('collections.nested_group_bard_update_test');
        $blueprint->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => ['type' => 'text', 'display' => 'Title'],
                                ],
                                [
                                    'handle' => 'content_group',
                                    'field' => [
                                        'type' => 'group',
                                        'display' => 'Content Group',
                                        'fields' => [
                                            [
                                                'handle' => 'body',
                                                'field' => ['type' => 'bard', 'display' => 'Body'],
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

        $createResult = $this->router->execute([
            'action' => 'create',
            'collection' => 'nested_group_bard_update_test',
            'data' => [
                'title' => 'Before Update',
                'content_group' => [
                    'body' => 'Legacy nested bard string',
                ],
            ],
        ]);

        $this->assertTrue($createResult['success'], 'Setup: entry creation should succeed');
        /** @var array<string, mixed> $createData */
        $createData = $createResult['data'];
        /** @var array<string, mixed> $createdEntry */
        $createdEntry = $createData['entry'];
        $entryId = $createdEntry['id'];

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => 'nested_group_bard_update_test',
            'id' => $entryId,
            'data' => [
                'title' => 'After Update',
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'Updating an entry with nested legacy Bard data should not crash. Response: ' . json_encode($result)
        );
        /** @var array<string, mixed> $resultData */
        $resultData = $result['data'];
        $this->assertTrue(($resultData['updated'] ?? false) === true);
    }
}
