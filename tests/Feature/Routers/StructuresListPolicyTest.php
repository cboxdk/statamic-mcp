<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

class StructuresListPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function structureTypes(): array
    {
        return [
            'collections' => ['collection'],
            'taxonomies' => ['taxonomy'],
            'navigations' => ['navigation'],
            'global sets' => ['globalset'],
            'sites' => ['site'],
        ];
    }

    private function createStructures(string $type): void
    {
        if ($type === 'site') {
            config(['statamic.system.multisite' => true]);
            Site::setSites([
                'hidden_first' => ['name' => 'Hidden', 'locale' => 'en_US', 'url' => '/'],
                'visible_one' => ['name' => 'Visible one', 'locale' => 'da_DK', 'url' => '/da/'],
                'visible_two' => ['name' => 'Visible two', 'locale' => 'de_DE', 'url' => '/de/'],
            ]);

            return;
        }

        foreach (['hidden_first', 'visible_one', 'visible_two'] as $handle) {
            $structure = match ($type) {
                'collection' => Collection::make($handle),
                'taxonomy' => Taxonomy::make($handle),
                'navigation' => Nav::make($handle),
                'globalset' => GlobalSet::make($handle),
            };
            $structure->title($handle)->save();
        }
    }

    #[DataProvider('structureTypes')]
    public function test_lists_only_include_resources_allowed_by_the_read_policy(string $type): void
    {
        $this->createStructures($type);
        config(['statamic.mcp.tools.structures.resources.read' => ['visible_*']]);

        $result = (new StructuresRouter)->execute([
            'action' => 'list',
            'resource_type' => $type,
        ]);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertEqualsCanonicalizing(['visible_one', 'visible_two'], array_column($result['data']['items'], 'handle'));

        if ($type === 'site') {
            $this->assertNull($result['data']['default']);
        } else {
            $this->assertSame(2, $result['data']['total']);
        }
    }

    #[DataProvider('structureTypes')]
    public function test_an_empty_read_policy_returns_no_structures(string $type): void
    {
        $this->createStructures($type);
        config(['statamic.mcp.tools.structures.resources.read' => []]);

        $result = (new StructuresRouter)->execute([
            'action' => 'list',
            'resource_type' => $type,
        ]);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame([], $result['data']['items']);

        if ($type === 'site') {
            $this->assertNull($result['data']['default']);
        } else {
            $this->assertSame(0, $result['data']['total']);
        }
    }

    #[DataProvider('structureTypes')]
    public function test_a_wildcard_read_policy_preserves_the_complete_list(string $type): void
    {
        $this->createStructures($type);
        config(['statamic.mcp.tools.structures.resources.read' => ['*']]);

        $result = (new StructuresRouter)->execute([
            'action' => 'list',
            'resource_type' => $type,
        ]);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertEqualsCanonicalizing(
            ['hidden_first', 'visible_one', 'visible_two'],
            array_column($result['data']['items'], 'handle'),
        );

        if ($type === 'site') {
            $this->assertSame('hidden_first', $result['data']['default']);
        } else {
            $this->assertSame(3, $result['data']['total']);
        }
    }

    #[DataProvider('structureTypes')]
    public function test_pagination_counts_only_allowed_resources(string $type): void
    {
        config(['statamic.mcp.tools.structures.resources.read' => ['visible_*']]);
        $this->createStructures($type);
        $seen = [];
        $arguments = ['action' => 'list', 'resource_type' => $type, 'limit' => 1];

        foreach ([0, 1] as $page) {
            $result = (new StructuresRouter)->execute($arguments);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertCount(1, $result['data']['items']);
            $this->assertSame(2, $result['data']['total']);
            $this->assertSame($page === 0, is_string($result['data']['next_cursor']));
            $arguments['cursor'] = $result['data']['next_cursor'];
            $seen[] = $result['data']['items'][0]['handle'];
        }

        $this->assertSame(['visible_one', 'visible_two'], $seen);
        $this->assertNull($arguments['cursor']);
    }
}
