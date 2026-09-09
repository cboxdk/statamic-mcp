<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Stache;

class BlueprintFieldScopeTest extends TestCase
{
    private BlueprintsRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new BlueprintsRouter;
        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(4));
        Collection::make($this->collectionHandle)->title('Pages')->save();

        Blueprint::make('pages')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'page_builder', 'field' => [
                    'type' => 'replicator',
                    'sets' => [
                        'main' => [
                            'sets' => [
                                'hero' => ['fields' => [
                                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                                    ['handle' => 'media', 'field' => [
                                        'type' => 'group',
                                        'fields' => [
                                            ['handle' => 'source', 'field' => [
                                                'type' => 'select',
                                                'options' => [['key' => 'images', 'value' => 'Images']],
                                            ]],
                                        ],
                                    ]],
                                ]],
                                'cta' => ['fields' => [['handle' => 'label', 'field' => ['type' => 'text']]]],
                            ],
                        ],
                    ],
                ]],
            ],
        ])->save();

        Stache::refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @return array<string, mixed>
     */
    private function fetch(array $extra = []): array
    {
        return $this->router->execute(array_merge([
            'action' => 'get',
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'handle' => 'pages',
        ], $extra));
    }

    public function test_without_the_parameter_the_whole_blueprint_comes_back(): void
    {
        $data = $this->fetch()['data']['blueprint'];

        $this->assertArrayHasKey('fields', $data);
        $this->assertArrayNotHasKey('field_path', $data);
        $this->assertArrayHasKey('title', $data['fields']);
        $this->assertArrayHasKey('page_builder', $data['fields']);
    }

    public function test_a_field_path_returns_only_that_field(): void
    {
        $data = $this->fetch(['field' => 'page_builder'])['data']['blueprint'];

        $this->assertSame('page_builder', $data['field_path']);
        $this->assertSame('page_builder', $data['field']['handle']);
        $this->assertArrayNotHasKey('fields', $data);
    }

    public function test_a_set_path_returns_the_fields_of_that_set(): void
    {
        $data = $this->fetch(['field' => 'page_builder.hero'])['data']['blueprint'];

        $this->assertSame(['heading', 'media'], array_keys($data['fields']));
        // The other set is not in the payload at all, which is the point.
        $this->assertArrayNotHasKey('label', $data['fields']);
    }

    public function test_a_path_can_descend_into_a_group(): void
    {
        $data = $this->fetch(['field' => 'page_builder.hero.media'])['data']['blueprint'];

        $this->assertSame('media', $data['field']['handle']);
        $this->assertSame('group', $data['field']['type']);
    }

    public function test_config_is_scoped_to_the_subtree(): void
    {
        $data = $this->fetch(['field' => 'page_builder.hero', 'include_config' => true])['data']['blueprint'];

        $this->assertArrayHasKey('config', $data['fields']['heading']);
        $this->assertSame('text', $data['fields']['heading']['config']['type']);
    }

    public function test_format_spec_is_scoped_to_the_subtree(): void
    {
        $data = $this->fetch([
            'field' => 'page_builder.hero',
            'include_config' => false,
            'include_format_spec' => true,
        ])['data']['blueprint'];

        $this->assertSame('string', $data['fields']['heading']['_format_spec']['wire_format']);
        $this->assertArrayNotHasKey('config', $data['fields']['heading']);
    }

    public function test_a_scoped_response_is_far_smaller_than_the_whole_blueprint(): void
    {
        $whole = strlen((string) json_encode($this->fetch(['max_format_depth' => 3])));
        $scoped = strlen((string) json_encode($this->fetch(['field' => 'page_builder.hero', 'max_format_depth' => 3])));

        $this->assertLessThan($whole, $scoped);
    }

    public function test_an_unknown_top_level_segment_lists_the_valid_ones(): void
    {
        $result = $this->fetch(['field' => 'nope']);

        $this->assertFalse($result['success']);
        $errors = implode(' ', $result['errors']);
        $this->assertStringContainsString('[nope] is not a field or set at the top level', $errors);
        $this->assertStringContainsString('page_builder', $errors);
        $this->assertStringContainsString('title', $errors);
    }

    public function test_an_unknown_set_lists_the_valid_sets(): void
    {
        $result = $this->fetch(['field' => 'page_builder.nope']);

        $this->assertFalse($result['success']);
        $errors = implode(' ', $result['errors']);
        $this->assertStringContainsString('[page_builder]', $errors);
        $this->assertStringContainsString('hero', $errors);
        $this->assertStringContainsString('cta', $errors);
    }

    public function test_descending_into_a_leaf_field_reports_it_has_no_children(): void
    {
        $result = $this->fetch(['field' => 'title.nope']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no children', implode(' ', $result['errors']));
    }
}
