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
 * A field hidden by its `if` / `unless` conditions must not have its rules
 * enforced — the Control Panel never submits it, so it never validates it.
 * Statamic's server-side validator does not evaluate conditions, so before
 * the fix a conditionally required field blocked every MCP create.
 */
class ConditionalFieldValidationTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new EntriesRouter;
        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(6));

        Collection::make($this->collectionHandle)->title('Pages')->save();

        Blueprint::make($this->collectionHandle)
            ->setNamespace("collections.{$this->collectionHandle}")
            ->setContents([
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
                                    // `template` is entry data, not a blueprint field —
                                    // exactly the shape of the reported blueprint.
                                    ['handle' => 'brochure', 'field' => [
                                        'type' => 'text',
                                        'validate' => ['required'],
                                        'if' => ['template' => 'equals Brochure'],
                                    ]],
                                    ['handle' => 'is_external', 'field' => ['type' => 'toggle']],
                                    ['handle' => 'internal_note', 'field' => [
                                        'type' => 'text',
                                        'validate' => ['required'],
                                        'unless' => ['is_external' => 'equals true'],
                                    ]],
                                    ['handle' => 'blocks', 'field' => [
                                        'type' => 'replicator',
                                        'sets' => [
                                            'main' => [
                                                'sets' => [
                                                    'link' => [
                                                        'fields' => [
                                                            ['handle' => 'kind', 'field' => ['type' => 'text']],
                                                            ['handle' => 'url', 'field' => [
                                                                'type' => 'text',
                                                                'validate' => ['required'],
                                                                'if' => ['kind' => 'equals url'],
                                                            ]],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ]],
                                    ['handle' => 'cta', 'field' => [
                                        'type' => 'group',
                                        'fields' => [
                                            ['handle' => 'show', 'field' => ['type' => 'toggle']],
                                            ['handle' => 'label', 'field' => [
                                                'type' => 'text',
                                                'validate' => ['required'],
                                                'if' => ['show' => 'equals true'],
                                            ]],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->save();

        Stache::refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function create(array $data): array
    {
        return $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => array_merge(['title' => 'Team', 'is_external' => true], $data),
        ]);
    }

    public function test_create_skips_required_field_whose_if_condition_is_not_met(): void
    {
        $result = $this->create(['template' => 'team/style_a/Index']);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
    }

    public function test_create_enforces_required_field_whose_if_condition_is_met(): void
    {
        $result = $this->create(['template' => 'Brochure']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('brochure', (string) json_encode($result['errors']));
    }

    public function test_unless_condition_hides_and_shows_field(): void
    {
        $this->assertTrue($this->create(['is_external' => true])['success']);

        $result = $this->create(['is_external' => false]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('internal_note', (string) json_encode($result['errors']));
    }

    public function test_update_skips_required_field_whose_condition_is_not_met(): void
    {
        $entry = Entry::make()
            ->collection($this->collectionHandle)
            ->slug('team-' . bin2hex(random_bytes(4)))
            ->data(['title' => 'Team', 'template' => 'team/index', 'is_external' => true]);
        $entry->save();

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Team updated'],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        // Switching the stored condition target on makes the field required.
        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['template' => 'Brochure'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('brochure', (string) json_encode($result['errors']));
    }

    public function test_conditions_inside_replicator_sets_are_evaluated_per_set(): void
    {
        $result = $this->create([
            'blocks' => [
                ['id' => 'a1', 'type' => 'link', 'kind' => 'entry'],
            ],
        ]);
        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $result = $this->create([
            'blocks' => [
                ['id' => 'b1', 'type' => 'link', 'kind' => 'entry'],
                ['id' => 'b2', 'type' => 'link', 'kind' => 'url'],
            ],
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('blocks.1.url', (string) json_encode($result['errors']));
        $this->assertStringNotContainsString('blocks.0.url', (string) json_encode($result['errors']));
    }

    public function test_conditions_inside_group_are_evaluated(): void
    {
        $this->assertTrue($this->create(['cta' => ['show' => false]])['success']);

        $result = $this->create(['cta' => ['show' => true]]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cta.label', (string) json_encode($result['errors']));
    }
}
