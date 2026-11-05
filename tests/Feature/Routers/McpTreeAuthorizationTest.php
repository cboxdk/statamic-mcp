<?php

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Contracts\CollectionTreeEditor;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Support\McpContext;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Statamic\Auth\File\User;
use Statamic\Contracts\Routing\UrlBuilder;
use Statamic\Entries\Collection;
use Statamic\Facades\Entry;
use Statamic\Sites\Site;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\CollectionTree;
use Statamic\Structures\Page;

class McpTreeAuthorizationTest extends TestCase
{
    private object $entry;

    private object $collection;

    private bool $root = false;

    private bool $cycle = false;

    private ?int $maxDepth = null;

    private string $parentCollection = 'pages';

    private string $parentSite = 'default';

    private ?string $deny = null;

    private bool $collision = false;

    protected function setUp(): void
    {
        parent::setUp();
        request()->attributes->set(McpContext::WEB_ATTRIBUTE, true);
        config(['statamic.mcp.tools.entries.resources.write' => ['*'], 'statamic.mcp.tools.structures.resources.write' => ['*']]);
        $guard = $this->createMock(Guard::class);
        $guard->method('user')->willReturn($this->createMock(User::class));
        Auth::swap($guard);
        $gate = $this->createMock(GateContract::class);
        $gate->method('forUser')->willReturnSelf();
        $gate->method('allows')->willReturnCallback(fn ($ability, $target) => $ability !== $this->deny && ! ($this->deny === 'site' && $target instanceof Site));
        Gate::swap($gate);
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('handle')->willReturn('pages');
        $this->collection->method('hasStructure')->willReturn(true);
        $this->collection->method('sites')->willReturn(collect(['default', 'other']));
        $structure = $this->createMock(CollectionStructure::class);
        $structure->method('flushCache')->willReturnSelf();
        $structure->method('maxDepth')->willReturnCallback(fn () => $this->maxDepth);
        $this->collection->method('structure')->willReturn($structure);
        $tree = $this->createMock(CollectionTree::class);
        $tree->method('path')->willReturn('/tmp/mcp-unit-tree-does-not-exist.yaml');
        $tree->expects($this->never())->method('save');
        $structure->method('in')->willReturn($tree);
        $structure->method('makeTree')->willReturn($tree);
        $tree->method('structure')->willReturn($structure);
        $tree->method('tree')->willReturnSelf();
        $tree->method('disableUriCache')->willReturnSelf();
        $this->entry = $this->createMock(\Statamic\Entries\Entry::class);
        $this->entry->method('id')->willReturn('subject');
        $this->entry->method('collection')->willReturn($this->collection);
        $this->entry->method('collectionHandle')->willReturn('pages');
        $this->entry->method('locale')->willReturn('default');
        $this->entry->method('route')->willReturnCallback(fn () => $this->collision ? '/{slug}' : null);
        $this->entry->expects($this->never())->method('save');
        $parentEntry = $this->createMock(\Statamic\Entries\Entry::class);
        $parentEntry->method('id')->willReturn('target');
        $parentEntry->method('collectionHandle')->willReturnCallback(fn () => $this->parentCollection);
        $parentEntry->method('locale')->willReturnCallback(fn () => $this->parentSite);
        $page = $this->createMock(Page::class);
        $page->method('reference')->willReturn('subject');
        $page->method('isRoot')->willReturnCallback(fn () => $this->root);
        $page->method('flattenedPages')->willReturn(collect());
        $target = $this->createMock(Page::class);
        $target->method('reference')->willReturn('target');
        $target->method('depth')->willReturn(1);
        $target->method('uri')->willReturn('/target');
        $target->method('parent')->willReturnCallback(fn () => $this->cycle ? $page : null);
        $tree->method('find')->willReturnCallback(fn ($id) => ['subject' => $page, 'target' => $target][$id] ?? null);
        Entry::swap(new class($this->entry, $parentEntry)
        {
            public function __construct(private $subject, private $target) {}

            public function find($id)
            {
                return ['subject' => $this->subject, 'target' => $this->target][$id] ?? null;
            }

            public function findByUri($uri, $site)
            {
                return $this->target;
            }
        });
        $builder = $this->createMock(\Statamic\Routing\UrlBuilder::class);
        $builder->method('content')->willReturnSelf();
        $builder->method('merge')->willReturnSelf();
        $builder->method('build')->willReturn('/collision');
        app()->instance(UrlBuilder::class, $builder);
    }

    private function rejected(string $message, mixed $parent = 'target'): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);
        app(CollectionTreeEditor::class)->validate($this->entry, $parent);
    }

    public function test_missing_parent_is_rejected(): void
    {
        $this->rejected('same collection and site', 'missing');
    }

    public function test_self_parent_is_rejected(): void
    {
        $this->rejected('own parent', 'subject');
    }

    public function test_non_string_parent_is_rejected(): void
    {
        $this->rejected('entry id or null', []);
    }

    public function test_cross_collection_parent_is_rejected(): void
    {
        $this->parentCollection = 'cases';
        $this->rejected('same collection and site');
    }

    public function test_cross_site_parent_is_rejected(): void
    {
        $this->parentSite = 'other';
        $this->rejected('same collection and site');
    }

    public function test_root_is_immutable(): void
    {
        $this->root = true;
        $this->rejected('root cannot be moved');
    }

    public function test_cycles_are_rejected(): void
    {
        $this->cycle = true;
        $this->rejected('descendant');
    }

    public function test_maximum_depth_is_enforced(): void
    {
        $this->maxDepth = 1;
        $this->rejected('maximum depth');
    }

    public function test_uri_collision_is_rejected(): void
    {
        $this->collision = true;
        $this->rejected('duplicate entry URI');
    }

    public function test_reorder_permission_is_required(): void
    {
        $this->deny = 'reorder';
        $this->rejected('Reordering');
    }

    public function test_site_access_is_required(): void
    {
        $this->deny = 'site';
        $this->rejected('collection site');
    }

    public function test_entry_author_policy_is_enforced(): void
    {
        $this->deny = 'edit';
        $this->rejected('author or site');
    }

    public function test_entry_resource_policy_is_enforced(): void
    {
        config(['statamic.mcp.tools.entries.resources.write' => ['cases']]);
        $this->rejected('entries resource policy');
    }

    public function test_structure_resource_policy_is_enforced(): void
    {
        config(['statamic.mcp.tools.structures.resources.write' => ['cases']]);
        $this->rejected('structures resource policy');
    }

    public function test_structure_write_scope_is_required(): void
    {
        app()->instance(TokenService::class, (new \ReflectionClass(TokenService::class))->newInstanceWithoutConstructor());
        request()->attributes->set('mcp_token', new McpTokenData('probe', 'user', 'Test', '', ['entries:write'], null, null, Carbon::now()));
        $this->rejected('structures:write');
    }

    public function test_structural_field_policy_is_enforced(): void
    {
        config(['statamic.mcp.tools.structures.denied_fields' => ['tree']]);
        $this->rejected('resource field policy');
    }
}
