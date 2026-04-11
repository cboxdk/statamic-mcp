<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;

class NestedSanitizationReproTest extends TestCase
{
    public function test_nested_bard_string_inside_replicator_still_crashes(): void
    {
        $router = new EntriesRouter;

        $collection = CollectionFacade::make('nested_bard_repro')
            ->title('Nested Bard Repro');
        $collection->save();

        $blueprint = BlueprintFacade::make('nested_bard_repro');
        $blueprint->setNamespace('collections.nested_bard_repro');
        $blueprint->setContents([
            'tabs' => [
                'main' => [
                    'display' => 'Main',
                    'sections' => [[
                        'fields' => [
                            [
                                'handle' => 'title',
                                'field' => ['type' => 'text', 'display' => 'Title'],
                            ],
                            [
                                'handle' => 'blocks',
                                'field' => [
                                    'type' => 'replicator',
                                    'display' => 'Blocks',
                                    'sets' => [
                                        'content_block' => [
                                            'display' => 'Content Block',
                                            'fields' => [
                                                [
                                                    'handle' => 'content',
                                                    'field' => ['type' => 'bard', 'display' => 'Content'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);
        $blueprint->save();

        $result = $router->execute([
            'action' => 'create',
            'collection' => 'nested_bard_repro',
            'data' => [
                'title' => 'Nested Bard',
                'blocks' => [
                    [
                        'type' => 'content_block',
                        'content' => 'plain text instead of ProseMirror array',
                    ],
                ],
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(
            'Cannot access offset of type string on string',
            $result['errors'][0] ?? ''
        );
    }
}
