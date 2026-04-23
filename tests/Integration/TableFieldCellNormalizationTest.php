<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;

class TableFieldCellNormalizationTest extends TestCase
{
    private EntriesRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
    }

    public function test_direct_table_field_unwraps_augmented_cell_objects(): void
    {
        $handle = 'table_cell_test';
        $this->makeCollectionWithTable($handle);

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $handle,
            'data' => [
                'title' => 'Pricing',
                'pricing' => [
                    [
                        'cells' => [
                            ['value' => null],
                            ['value' => 'Geocodio'],
                            ['value' => 'Google Maps'],
                        ],
                    ],
                    [
                        'cells' => [
                            ['value' => 'Free tier'],
                            ['value' => '$1.00 per 1,000 lookups'],
                            ['value' => '~10,000 requests/month'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected table write to succeed. Response: ' . json_encode($result));

        /** @var array<string, mixed> $data */
        $data = $result['data'];
        /** @var array<string, mixed> $entry */
        $entry = $data['entry'];
        $stored = EntryFacade::find($entry['id']);
        $this->assertNotNull($stored);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stored->get('pricing');
        $this->assertSame([null, 'Geocodio', 'Google Maps'], $rows[0]['cells']);
        $this->assertSame(['Free tier', '$1.00 per 1,000 lookups', '~10,000 requests/month'], $rows[1]['cells']);
    }

    public function test_bare_scalar_cells_pass_through_unchanged(): void
    {
        $handle = 'table_scalar_test';
        $this->makeCollectionWithTable($handle);

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $handle,
            'data' => [
                'title' => 'Bare scalars',
                'pricing' => [
                    ['cells' => [null, 'a', 'b']],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected bare scalar table write to succeed.');

        /** @var array<string, mixed> $data */
        $data = $result['data'];
        /** @var array<string, mixed> $entry */
        $entry = $data['entry'];
        $stored = EntryFacade::find($entry['id']);
        $this->assertNotNull($stored);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stored->get('pricing');
        $this->assertSame([null, 'a', 'b'], $rows[0]['cells']);
    }

    public function test_invalid_cell_shape_rejects_write(): void
    {
        $handle = 'table_invalid_test';
        $this->makeCollectionWithTable($handle);

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $handle,
            'data' => [
                'title' => 'Invalid',
                'pricing' => [
                    ['cells' => [['not_value' => 'x']]],
                ],
            ],
        ]);

        $this->assertFalse($result['success']);
        $encoded = json_encode($result);
        $this->assertIsString($encoded);
        $this->assertStringContainsString('table cell must be a string or null', $encoded);
    }

    public function test_table_nested_in_bard_set_normalizes_cells(): void
    {
        $handle = 'table_in_bard_test';
        $collection = CollectionFacade::make($handle)->title('Table In Bard');
        $collection->save();

        $blueprint = BlueprintFacade::make($handle);
        $blueprint->setNamespace("collections.{$handle}");
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
                                    'handle' => 'page_builder',
                                    'field' => [
                                        'type' => 'bard',
                                        'display' => 'Page Builder',
                                        'sets' => [
                                            'table' => [
                                                'display' => 'Table',
                                                'fields' => [
                                                    [
                                                        'handle' => 'table',
                                                        'field' => ['type' => 'table', 'display' => 'Table'],
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
            ],
        ]);
        $blueprint->save();

        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $handle,
            'data' => [
                'title' => 'Entry with Bard table',
                'page_builder' => [
                    [
                        'type' => 'set',
                        'attrs' => [
                            'id' => 'pricing_table_1',
                            'values' => [
                                'type' => 'table',
                                'table' => [
                                    [
                                        'cells' => [
                                            ['value' => 'Geocodio'],
                                            ['value' => 'Google Maps'],
                                        ],
                                    ],
                                    [
                                        'cells' => [
                                            ['value' => 'Free tier'],
                                            ['value' => null],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], 'Expected Bard-nested table write to succeed. Response: ' . json_encode($result));

        /** @var array<string, mixed> $data */
        $data = $result['data'];
        /** @var array<string, mixed> $entry */
        $entry = $data['entry'];
        $stored = EntryFacade::find($entry['id']);
        $this->assertNotNull($stored);

        /** @var array<int, array<string, mixed>> $pageBuilder */
        $pageBuilder = $stored->get('page_builder');
        /** @var array<string, mixed> $attrs */
        $attrs = $pageBuilder[0]['attrs'];
        /** @var array<string, mixed> $values */
        $values = $attrs['values'];
        /** @var array<int, array<string, mixed>> $tableRows */
        $tableRows = $values['table'];

        $this->assertSame(['Geocodio', 'Google Maps'], $tableRows[0]['cells']);
        $this->assertSame(['Free tier', null], $tableRows[1]['cells']);
    }

    private function makeCollectionWithTable(string $handle): void
    {
        $collection = CollectionFacade::make($handle)->title(ucfirst($handle));
        $collection->save();

        $blueprint = BlueprintFacade::make($handle);
        $blueprint->setNamespace("collections.{$handle}");
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
                                    'handle' => 'pricing',
                                    'field' => ['type' => 'table', 'display' => 'Pricing'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $blueprint->save();
    }
}
