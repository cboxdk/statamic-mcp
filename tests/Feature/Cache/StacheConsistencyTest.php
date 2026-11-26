<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Cache;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Stache\Stores\AggregateStore;
use Statamic\Stache\Stores\Store;

/**
 * The oracle for what a write leaves stale, without having to know the list.
 *
 * Statamic keeps a store current when its own item is saved, but an index in
 * another store can depend on the write too (term usage, URIs of descendants,
 * role membership...). Enumerating those by hand failed repeatedly. So each
 * write here runs with no clear, and then every index of every store, as the
 * next request reads it, must equal what a full rebuild from disk produces.
 */
class StacheConsistencyTest extends TestCase
{
    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.revisions.enabled' => false, 'statamic.stache.watcher' => false,
            'statamic.mcp.cache.stache_after_write' => 'repair', 'statamic.mcp.cache.clear_static_after_write' => false]);

        Taxonomy::make('tags')->save();
        Blueprint::make('tag')->setNamespace('taxonomies.tags')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        foreach (['php' => 'PHP', 'laravel' => 'Laravel', 'unused' => 'Unused'] as $slug => $title) {
            Term::make()->taxonomy('tags')->slug($slug)->data(['title' => $title])->save();
        }

        Collection::make('pages')->routes('{parent_uri}/{slug}')->structureContents(['root' => true])->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'related', 'field' => ['type' => 'entries', 'collections' => ['blog'], 'max_items' => 1]],
        ]])->save();
        foreach (['home' => 'Home', 'about' => 'About', 'team' => 'Team', 'company' => 'Company'] as $slug => $title) {
            $page = Entry::make()->collection('pages')->slug($slug)->data(['title' => $title]);
            $page->save();
            $this->ids[$slug] = (string) $page->id();
        }
        Collection::findByHandle('pages')->structure()->in('default')
            ->tree([['entry' => $this->ids['home']], ['entry' => $this->ids['about'], 'children' => [['entry' => $this->ids['team']]]], ['entry' => $this->ids['company']]])
            ->save();

        Collection::make('blog')->routes('/blog/{slug}')->dated(true)->taxonomies(['tags'])->save();
        Blueprint::make('post')->setNamespace('collections.blog')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'tags', 'field' => ['type' => 'terms', 'taxonomies' => ['tags']]],
        ]])->save();
        foreach (['post-a' => ['PHP', ['php']], 'post-b' => ['Laravel', ['php', 'laravel']]] as $slug => [$title, $tags]) {
            $post = Entry::make()->collection('blog')->slug($slug)->date('2026-01-0' . (count($this->ids) % 9 + 1))->data(['title' => $title, 'tags' => $tags]);
            $post->save();
            $this->ids[$slug] = (string) $post->id();
        }
        $draft = Entry::make()->collection('blog')->slug('draft')->date('2026-02-01')->published(false)->data(['title' => 'Draft', 'tags' => ['laravel']]);
        $draft->save();
        $this->ids['draft'] = (string) $draft->id();

        Collection::make('news')->routes('{mount}/{slug}')->mount($this->ids['about'])->save();
        Blueprint::make('item')->setNamespace('collections.news')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        Entry::make()->collection('news')->slug('launch')->data(['title' => 'Launch'])->save();

        $settings = GlobalSet::make('settings')->title('Settings');
        $settings->save();
        $settings->makeLocalization('default')->data(['tagline' => 'Old'])->save();
        Blueprint::make('settings')->setNamespace('globals')->setContents(['fields' => [['handle' => 'tagline', 'field' => ['type' => 'text']]]])->save();

        $nav = Nav::make('main')->title('Main')->collections(['pages', 'blog'])->maxDepth(2);
        $nav->save();
        $nav->makeTree('default', [['entry' => $this->ids['post-a']]])->save();

        Role::make('editor')->title('Editor')->permissions(['access cp'])->save();
        UserGroup::make()->handle('staff')->title('Staff')->roles(['editor'])->save();
        $member = User::make()->email('member@example.test')->groups(['staff']);
        $member->save();
        $this->ids['member'] = (string) $member->id();
        $super = User::make()->id('consistency-super')->email('super@example.test')->makeSuper();
        $super->save();

        $this->settle();
    }

    /** @return array<string, array{callable(self): array<string, mixed>}> */
    public static function writes(): array
    {
        $entries = fn (array $a) => function (self $t) use ($a) {
            array_walk_recursive($a, function (&$v) use ($t) {
                $v = is_string($v) && str_starts_with($v, '@') ? $t->ids[substr($v, 1)] : $v;
            });

            return (new EntriesRouter)->execute($a);
        };

        return [
            'entry title' => [$entries(['action' => 'update', 'collection' => 'blog', 'id' => '@post-a', 'data' => ['title' => 'Changed']])],
            'entry slug' => [$entries(['action' => 'update', 'collection' => 'blog', 'id' => '@post-a', 'data' => ['slug' => 'moved']])],
            'entry date' => [$entries(['action' => 'update', 'collection' => 'blog', 'id' => '@post-a', 'data' => ['date' => '2025-06-01']])],
            'entry tags added' => [$entries(['action' => 'update', 'collection' => 'blog', 'id' => '@post-a', 'data' => ['tags' => ['php', 'unused']]])],
            'entry tags cleared' => [$entries(['action' => 'update', 'collection' => 'blog', 'id' => '@post-b', 'data' => ['tags' => []]])],
            // A new tag is created with the terms tool first: an entry write
            // refuses an unknown term instead of letting Statamic invent it.
            'entry create tagged' => [fn (self $t) => $t->steps(
                fn () => (new TermsRouter)->execute(['action' => 'create', 'taxonomy' => 'tags', 'slug' => 'fresh', 'data' => ['title' => 'Fresh']]),
                fn () => $entries(['action' => 'create', 'collection' => 'blog', 'data' => ['title' => 'New', 'slug' => 'new', 'date' => '2026-03-01', 'tags' => ['laravel', 'fresh']]])($t),
            )],
            'entry delete (last use of a term)' => [$entries(['action' => 'delete', 'collection' => 'blog', 'id' => '@post-b'])],
            'entry unpublish' => [$entries(['action' => 'unpublish', 'collection' => 'blog', 'id' => '@post-a'])],
            'entry publish' => [$entries(['action' => 'publish', 'collection' => 'blog', 'id' => '@draft'])],
            'parent page slug (descendants)' => [$entries(['action' => 'update', 'collection' => 'pages', 'id' => '@about', 'data' => ['slug' => 'about-us']])],
            'page create under parent' => [$entries(['action' => 'create', 'collection' => 'pages', 'data' => ['title' => 'Jobs', 'slug' => 'jobs', 'parent' => '@about']])],
            'page move' => [$entries(['action' => 'update', 'collection' => 'pages', 'id' => '@team', 'data' => ['parent' => '@company']])],
            'page delete with child' => [$entries(['action' => 'delete', 'collection' => 'pages', 'id' => '@about'])],
            'term title' => [fn (self $t) => (new TermsRouter)->execute(['action' => 'update', 'taxonomy' => 'tags', 'slug' => 'php', 'data' => ['title' => 'Modern PHP']])],
            'term create' => [fn (self $t) => (new TermsRouter)->execute(['action' => 'create', 'taxonomy' => 'tags', 'slug' => 'go', 'data' => ['title' => 'Go']])],
            'term delete' => [fn (self $t) => (new TermsRouter)->execute(['action' => 'delete', 'taxonomy' => 'tags', 'slug' => 'unused'])],
            'global value' => [fn (self $t) => (new GlobalsRouter)->execute(['action' => 'update', 'handle' => 'settings', 'data' => ['tagline' => 'New']])],
            'navigation tree' => [fn (self $t) => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'navigation', 'handle' => 'main',
                'data' => ['trees' => [['site' => 'default', 'items' => [['entry' => $t->ids['post-b']]]]]]])],
            'collection route' => [fn (self $t) => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'blog', 'data' => ['route' => '/news/{slug}']])],
            'collection mount' => [fn (self $t) => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'news', 'data' => ['mount' => $t->ids['company']]])],
            'collection taxonomies removed' => [fn (self $t) => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'blog', 'data' => ['taxonomies' => []]])],
            'collection dated off' => [fn (self $t) => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'blog', 'data' => ['dated' => false]])],
            'relationship max_items' => [fn (self $t) => (new BlueprintsRouter)->execute(['action' => 'update', 'namespace' => 'collections.pages', 'handle' => 'page', 'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'related', 'field' => ['type' => 'entries', 'collections' => ['blog']]],
            ]])],
            'group roles' => [fn (self $t) => (new UsersRouter)->execute(['action' => 'update', 'resource_type' => 'group', 'handle' => 'staff', 'data' => ['roles' => []]])],
            'page to root' => [$entries(['action' => 'update', 'collection' => 'pages', 'id' => '@team', 'data' => ['parent' => null]])],
            'navigation delete' => [fn (self $t) => (new StructuresRouter)->execute(['action' => 'delete', 'resource_type' => 'navigation', 'handle' => 'main'])],
            'collection delete' => [fn (self $t) => $t->steps(
                fn () => (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'news', 'id' => (string) Entry::query()->where('collection', 'news')->first()?->id()]),
                fn () => (new StructuresRouter)->execute(['action' => 'delete', 'resource_type' => 'collection', 'handle' => 'news']),
            )],
            'taxonomy delete' => [fn (self $t) => $t->steps(
                fn () => (new StructuresRouter)->execute(['action' => 'update', 'resource_type' => 'collection', 'handle' => 'blog', 'data' => ['taxonomies' => []]]),
                ...array_map(fn (string $slug) => fn () => (new TermsRouter)->execute(['action' => 'delete', 'taxonomy' => 'tags', 'slug' => $slug]), ['php', 'laravel', 'unused']),
                ...[fn () => (new StructuresRouter)->execute(['action' => 'delete', 'resource_type' => 'taxonomy', 'handle' => 'tags'])],
            )],
            'role delete' => [fn (self $t) => $t->steps(
                fn () => (new UsersRouter)->execute(['action' => 'update', 'resource_type' => 'group', 'handle' => 'staff', 'data' => ['roles' => []]]),
                fn () => (new UsersRouter)->execute(['action' => 'delete', 'resource_type' => 'role', 'handle' => 'editor']),
            )],
            'group delete' => [fn (self $t) => (new UsersRouter)->execute(['action' => 'delete', 'resource_type' => 'group', 'handle' => 'staff'])],
            'user delete' => [fn (self $t) => (new UsersRouter)->execute(['action' => 'delete', 'resource_type' => 'user', 'id' => $t->ids['member']])],
            'blueprint field removed' => [fn (self $t) => (new BlueprintsRouter)->execute(['action' => 'update', 'namespace' => 'collections.blog', 'handle' => 'post', 'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ]])],
            'user create with role' => [fn (self $t) => (new UsersRouter)->execute(['action' => 'create', 'resource_type' => 'user', 'email' => 'new@example.test', 'data' => ['email' => 'new@example.test', 'roles' => ['editor']]])],
        ];
    }

    /** @param  callable(self): array<string, mixed>  $write */
    #[DataProvider('writes')]
    public function test_the_next_request_reads_what_a_rebuild_from_disk_reads(callable $write): void
    {
        $this->actingAs(User::find('consistency-super'));
        $result = $write($this);
        $this->assertTrue($result['success'] ?? false, json_encode($result['errors'] ?? $result, JSON_PRETTY_PRINT) ?: '');

        $this->settle();
        [$names, $warm] = $this->indexes();

        Stache::clear();
        $this->settle();
        [, $cold] = $this->indexes($names);

        $stale = [];
        foreach (array_unique([...array_keys($cold), ...array_keys($warm)]) as $key) {
            if (($cold[$key] ?? null) !== ($warm[$key] ?? null)) {
                $stale[$key] = ['next request' => $warm[$key] ?? null, 'rebuilt' => $cold[$key] ?? null];
            }
        }
        $this->assertSame([], $stale, 'Stale after the write: ' . implode(', ', array_keys($stale)) . "\n" . json_encode($stale, JSON_PRETTY_PRINT));
    }

    /**
     * Several calls in a row, each its own request; the last result counts.
     *
     * @param  callable(): array<string, mixed>  ...$calls
     *
     * @return array<string, mixed>
     */
    private function steps(callable ...$calls): array
    {
        $result = [];
        foreach ($calls as $call) {
            $result = $call();
            if (($result['success'] ?? false) !== true) {
                return $result;
            }
            $this->settle();
        }

        return $result;
    }

    /** What the next request starts from: persisted Stache state, no memory. */
    private function settle(): void
    {
        Stache::stores()->each->resetMemoizedState();
        app('stache.indexes')->each->resetMemoizedState();
        Blink::swap(new \Statamic\Support\Blink);
    }

    /**
     * Every index of every store, keyed store/index, items sorted.
     *
     * @param  array<string, list<string>>|null  $names  the index names per store to read; defaults to what the store knows now
     *
     * @return array{array<string, list<string>>, array<string, mixed>}
     */
    private function indexes(?array $names = null): array
    {
        $stores = [];
        foreach (Stache::stores() as $store) {
            if ($store instanceof AggregateStore) {
                foreach ($store->stores() as $handle => $child) {
                    // A deleted taxonomy's store lingers in memory, but no
                    // request reads it: there is no taxonomy to ask for.
                    if ($store->key() === 'terms' && ! Taxonomy::findByHandle((string) $handle)) {
                        continue;
                    }
                    $stores[$child->key()] = $child;
                }
            } else {
                $stores[$store->key()] = $store;
            }
        }
        ksort($stores);

        $known = [];
        $dump = [];
        foreach ($stores as $key => $store) {
            /** @var Store $store */
            $known[$key] = $names[$key] ?? $store->indexes()->keys()->map(fn ($n) => (string) $n)->sort()->values()->all();
            foreach ($known[$key] as $name) {
                $items = json_decode((string) json_encode($store->index($name)->items()->all()), true);
                // Positional indexes (term associations) carry no meaning in
                // their keys or order, only in their rows.
                if ($items !== [] && array_filter(array_keys($items), 'is_string') === []) {
                    $items = array_values($items);
                    usort($items, fn ($a, $b) => json_encode($a) <=> json_encode($b));
                } else {
                    ksort($items);
                }
                $dump["{$key}/{$name}"] = $items;
            }
        }

        return [$known, $dump];
    }
}
