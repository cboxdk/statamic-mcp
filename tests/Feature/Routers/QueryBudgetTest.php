<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Statamic\Assets\QueryBuilder;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Stache\Query\TermQueryBuilder;
use Statamic\Stache\Query\UserQueryBuilder;

class QueryBudgetTest extends TestCase
{
    public function test_asset_and_user_pages_hydrate_only_the_requested_records(): void
    {
        Storage::fake('budget_assets');
        AssetContainer::make('budget_assets')->disk('budget_assets')->save();
        foreach (range(1, 7) as $i) {
            Storage::disk('budget_assets')->put("{$i}.txt", 'example');
            User::make()->id("budget-{$i}")->email("budget-{$i}@example.test")->save();
        }
        $assets = new class(Stache::store('assets')) extends QueryBuilder
        {
            public int $loaded = 0;

            protected function getItems($keys)
            {
                $this->loaded += count($keys);

                return parent::getItems($keys);
            }
        };
        Asset::partialMock()->shouldReceive('query')->andReturn($assets);
        $result = (new AssetsRouter)->execute(['action' => 'list', 'resource_type' => 'asset',
            'container' => 'budget_assets', 'limit' => 2, 'include_details' => false]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(7, $result['data']['total']);
        $this->assertSame(['1.txt', '2.txt'], array_column($result['data']['items'], 'path'));
        $this->assertSame(2, $assets->loaded);

        $users = new class(Stache::store('users')) extends UserQueryBuilder
        {
            public int $loaded = 0;

            protected function getItems($keys)
            {
                $this->loaded += count($keys);

                return parent::getItems($keys);
            }
        };
        User::partialMock()->shouldReceive('query')->andReturn($users);
        $result = (new UsersRouter)->execute(['action' => 'list', 'resource_type' => 'user', 'limit' => 2]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertCount(2, $result['data']['items']);
        $this->assertSame(2, $users->loaded);
    }

    public function test_audit_counts_without_hydrating_records_and_cross_reference_refuses_an_incomplete_scan(): void
    {
        Collection::make('budget_pages')->save();
        Taxonomy::make('budget_tags')->save();
        Entry::make()->collection('budget_pages')->slug('one')->published(true)->save();
        Term::make()->taxonomy('budget_tags')->slug('one')->data(['title' => 'One'])->save();
        $entries = new class(Stache::store('entries')) extends EntryQueryBuilder
        {
            public function get($columns = ['*'])
            {
                throw new \LogicException('The audit must count without loading entries.');
            }
        };
        $terms = new class(Stache::store('terms')) extends TermQueryBuilder
        {
            public function get($columns = ['*'])
            {
                throw new \LogicException('The audit must count without loading terms.');
            }
        };
        Entry::partialMock()->shouldReceive('query')->andReturnUsing(fn () => clone $entries)->byDefault();
        Term::partialMock()->shouldReceive('query')->andReturnUsing(fn () => clone $terms);
        $result = (new ContentFacadeRouter)->execute(['action' => 'content_audit']);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(1, $result['data']['summary']['total_entries']);
        $this->assertSame(1, $result['data']['summary']['total_terms']);

        $large = new class(Stache::store('entries')) extends EntryQueryBuilder
        {
            public function count()
            {
                return 5000;
            }

            public function get($columns = ['*'])
            {
                throw new \LogicException('An over-budget scan must never start.');
            }
        };
        Entry::partialMock()->shouldReceive('query')->andReturn($large);
        $result = (new ContentFacadeRouter)->execute(['action' => 'cross_reference']);
        $this->assertFalse($result['success']);
        $this->assertSame('TOO_LARGE', $result['error']['code']);
    }
}
