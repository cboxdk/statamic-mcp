<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Contracts\CollectionTreeEditor;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class CollectionParentMoveTest extends TestCase
{
    public function test_editor_creates_and_moves_a_branch_and_reads_the_actual_parent(): void
    {
        config([
            'statamic.editions.pro' => true,
            'statamic.mcp.security.force_web_mode' => true,
            'statamic.mcp.web.enabled' => true,
            'statamic.mcp.confirmation.enabled' => false,
        ]);
        $handle = 'parent_move_' . bin2hex(random_bytes(4));
        $collection = Collection::make($handle)->title('Pages')->sites(['default'])
            ->routes('/{parent_uri}/{slug}')->structureContents(['root' => false, 'max_depth' => 5]);
        $collection->save();
        Blueprint::make('page')->setNamespace('collections.' . $handle)->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ]]]]],
        ])->save();
        $role = $handle . '-editor';
        Role::make()->handle($role)->permissions([
            'access cp', 'access default site', "view {$handle} entries",
            "create {$handle} entries", "edit {$handle} entries", "reorder {$handle} entries",
        ])->save();
        $user = User::make()->email($handle . '@example.test')->assignRole($role);
        $user->save();
        $this->actingAs($user);
        request()->attributes->set('mcp_token', new McpTokenData(
            'local-test', $user->id(), 'Test', '',
            ['entries:read', 'entries:write', 'structures:read', 'structures:write'], null, null, now(),
        ));
        $router = new EntriesRouter;
        $create = function ($slug, $parent = null) use ($router, $handle) {
            $result = $router->execute([
                'action' => 'create', 'collection' => $handle, 'site' => 'default',
                'slug' => $slug, 'published' => false, 'data' => ['title' => $slug, 'parent' => $parent],
            ]);
            $this->assertTrue($result['success'], json_encode($result) ?: '');

            return $result['data']['entry']['id'];
        };
        $a = $create('a');
        $b = $create('b');
        $child = $create('child', $a);
        $leaf = $create('leaf', $child);
        $writer = app(CollectionTreeEditor::class);
        $this->assertSame($a, $writer->parent(Entry::find($child)));
        $result = $router->execute([
            'action' => 'update', 'collection' => $handle, 'site' => 'default',
            'id' => $child, 'data' => ['parent' => $b],
        ]);
        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertSame($b, $writer->parent(Entry::find($child)));
        $this->assertSame($child, $writer->parent(Entry::find($leaf)));
        $result = $router->execute(['action' => 'get', 'collection' => $handle, 'id' => $child]);
        $this->assertSame($b, $result['data']['entry']['parent']);
        $structures = new StructuresRouter;
        $result = $structures->execute([
            'action' => 'get', 'resource_type' => 'collection', 'handle' => $handle,
            'site' => 'default', 'include_tree' => true,
        ]);
        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertSame('default', $result['data']['collection']['site']);
        $this->assertNotEmpty($result['data']['collection']['tree']);
        $result = $structures->execute([
            'action' => 'update', 'resource_type' => 'collection', 'handle' => $handle,
            'site' => 'default', 'data' => ['entry' => $child, 'parent' => null],
        ]);
        $this->assertTrue($result['success'], json_encode($result) ?: '');
        $this->assertNull($writer->parent(Entry::find($child)));
        $this->assertSame($child, $writer->parent(Entry::find($leaf)));
    }
}
