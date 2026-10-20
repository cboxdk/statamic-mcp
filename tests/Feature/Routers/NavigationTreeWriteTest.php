<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;

/**
 * Navigation writes reported success and saved no tree (#65): `update` never
 * read one, and `configure` read a `config` argument the schema does not
 * declare. Every payload shape a client tried came back `updated: true` and
 * changed nothing.
 */
class NavigationTreeWriteTest extends TestCase
{
    private StructuresRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new StructuresRouter;

        Collection::make('pages')->title('Pages')->save();
        Entry::make()->id('home-entry')->collection('pages')->slug('home')->data(['title' => 'Home'])->save();

        $nav = Nav::make('main')->title('Main')->maxDepth(2)->collections(['pages']);
        $nav->save();
        $nav->makeTree('default', [['id' => 'nav', 'entry' => 'home-entry']])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function write(string $action, array $data): array
    {
        return $this->router->execute([
            'action' => $action,
            'resource_type' => 'navigation',
            'handle' => 'main',
            'data' => $data,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedTree(): array
    {
        /** @var array<int, array<string, mixed>> $tree */
        $tree = Nav::find('main')->in('default')->tree();

        return $tree;
    }

    public function test_update_writes_the_tree_from_the_issue(): void
    {
        $result = $this->write('update', [
            'trees' => [
                'default' => [
                    ['id' => 'nav', 'entry' => 'home-entry'],
                    ['id' => 'nav-item', 'title' => 'Item', 'url' => 'https://item.domain'],
                ],
            ],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame(
            [['id' => 'nav', 'entry' => 'home-entry'], ['id' => 'nav-item', 'title' => 'Item', 'url' => 'https://item.domain']],
            $this->storedTree(),
        );
    }

    public function test_the_shape_get_returns_can_be_sent_back(): void
    {
        $read = $this->router->execute(['action' => 'get', 'resource_type' => 'navigation', 'handle' => 'main']);
        $trees = $read['data']['navigation']['trees'];
        $trees[0]['items'][] = ['title' => 'Contact', 'url' => '/contact'];

        $result = $this->write('update', ['trees' => $trees]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('/contact', $this->storedTree()[1]['url']);
        $this->assertSame($result['data']['navigation']['trees'], [['site' => 'default', 'items' => $this->storedTree()]]);
    }

    public function test_a_single_tree_with_a_site(): void
    {
        $result = $this->write('update', [
            'site' => 'default',
            'tree' => [['title' => 'Only', 'url' => '/only']],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertCount(1, $this->storedTree());
        $this->assertSame('/only', $this->storedTree()[0]['url']);
    }

    public function test_items_without_an_id_get_one(): void
    {
        $this->write('update', ['tree' => [
            ['title' => 'Parent', 'url' => '/parent', 'children' => [['title' => 'Child', 'url' => '/child']]],
        ]]);

        $tree = $this->storedTree();
        $this->assertIsString($tree[0]['id']);
        $this->assertIsString($tree[0]['children'][0]['id']);
        $this->assertNotSame($tree[0]['id'], $tree[0]['children'][0]['id']);
    }

    public function test_configure_reads_data(): void
    {
        $result = $this->write('configure', ['tree' => [['title' => 'Via configure', 'url' => '/c']]]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('/c', $this->storedTree()[0]['url']);
    }

    public function test_configure_still_reads_the_legacy_config_argument(): void
    {
        $result = $this->router->execute([
            'action' => 'configure',
            'resource_type' => 'navigation',
            'handle' => 'main',
            'config' => ['tree' => [['title' => 'Legacy', 'url' => '/legacy']]],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('/legacy', $this->storedTree()[0]['url']);
    }

    public function test_create_can_carry_a_tree(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'resource_type' => 'navigation',
            'handle' => 'footer',
            'data' => ['title' => 'Footer', 'tree' => [['title' => 'Privacy', 'url' => '/privacy']]],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame('/privacy', Nav::find('footer')->in('default')->tree()[0]['url']);
    }

    public function test_an_unknown_key_is_refused_and_nothing_is_saved(): void
    {
        $result = $this->write('update', ['title' => 'Renamed', 'items' => [['title' => 'x', 'url' => '/x']]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown navigation keys: items', $result['errors'][0]);
        $this->assertSame('Main', Nav::find('main')->title());
        $this->assertSame([['id' => 'nav', 'entry' => 'home-entry']], $this->storedTree());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedTrees(): array
    {
        return [
            'unknown item key' => [['tree' => [['title' => 'x', 'link' => '/x']]], 'trees.default[0] has unknown keys: link'],
            'empty item' => [['tree' => [['data' => []]]], 'trees.default[0] needs an entry, a url or a title'],
            'missing entry' => [['tree' => [['entry' => 'nope']]], "trees.default[0].entry: no entry with id 'nope'"],
            'too deep' => [['tree' => [['title' => 'a', 'children' => [['title' => 'b', 'children' => [['title' => 'c']]]]]]], 'trees.default[0].children[0].children[0] is 3 levels deep; this navigation allows 2'],
            'duplicate id' => [['tree' => [['id' => 'x', 'title' => 'a'], ['id' => 'x', 'title' => 'b']]], "trees.default[1].id 'x' is used more than once"],
            'unknown site' => [['trees' => ['nowhere' => [['title' => 'a']]]], "Unknown site 'nowhere'"],
            'tree and trees' => [['tree' => [], 'trees' => []], "Send either 'tree'"],
            'site without tree' => [['site' => 'default'], "'site' only applies to 'tree'"],
            'tree not a list' => [['tree' => ['title' => 'a']], "'tree' must be a list"],
            'rename' => [['handle' => 'other'], 'cannot be renamed'],
            'bad max depth' => [['max_depth' => 0], 'max_depth must be a positive integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('refusedTrees')]
    public function test_a_malformed_write_is_refused_and_nothing_is_saved(array $data, string $message): void
    {
        $result = $this->write('update', $data);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString($message, $result['errors'][0]);
        $this->assertSame([['id' => 'nav', 'entry' => 'home-entry']], $this->storedTree());
    }

    public function test_raising_max_depth_in_the_same_write_allows_the_deeper_tree(): void
    {
        $result = $this->write('update', [
            'max_depth' => 3,
            'tree' => [['title' => 'a', 'children' => [['title' => 'b', 'children' => [['title' => 'c']]]]]],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame(3, Nav::find('main')->maxDepth());
    }
}
