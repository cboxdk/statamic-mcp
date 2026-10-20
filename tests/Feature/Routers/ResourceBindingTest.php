<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\GlobalsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * An id-addressed action authorizes the resource the caller *names* (the
 * collection/taxonomy/global-set argument) but used to act on whatever the id
 * resolved to. The binding and the resource-policy resolver now agree on one
 * resource, so a caller cannot reach a resource it did not name. These checks
 * live in the handler and the resource policy, which run in CLI context too, so
 * the test needs no token.
 */
class ResourceBindingTest extends TestCase
{
    private function collectionWithEntry(string $collection, string $id, string $title): void
    {
        Collection::make($collection)->title(ucfirst($collection))->save();
        Blueprint::make($collection)->setNamespace("collections.{$collection}")
            ->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        Stache::refresh();
        Entry::make()->id($id)->collection($collection)->slug($id)->data(['title' => $title])->save();
    }

    public function test_entry_get_is_bound_to_the_named_collection(): void
    {
        $this->collectionWithEntry('blog', 'blog-1', 'Public');
        $this->collectionWithEntry('secret', 'secret-1', 'Top secret');

        $mismatch = (new EntriesRouter)->execute(['action' => 'get', 'collection' => 'blog', 'id' => 'secret-1']);
        $this->assertFalse($mismatch['success']);
        $this->assertStringContainsString('not found', strtolower((string) ($mismatch['errors'][0] ?? '')));

        $match = (new EntriesRouter)->execute(['action' => 'get', 'collection' => 'secret', 'id' => 'secret-1']);
        $this->assertTrue($match['success'], json_encode($match['errors'] ?? []));
    }

    public function test_entry_update_and_delete_are_bound_to_the_named_collection(): void
    {
        $this->collectionWithEntry('blog', 'blog-1', 'Public');
        $this->collectionWithEntry('secret', 'secret-1', 'Top secret');

        $update = (new EntriesRouter)->execute([
            'action' => 'update', 'collection' => 'blog', 'id' => 'secret-1', 'data' => ['title' => 'HIJACKED'],
        ]);
        $this->assertFalse($update['success']);
        $this->assertSame('Top secret', Entry::find('secret-1')->get('title'));

        $delete = (new EntriesRouter)->execute(['action' => 'delete', 'collection' => 'blog', 'id' => 'secret-1']);
        $this->assertFalse($delete['success']);
        $this->assertNotNull(Entry::find('secret-1'));
    }

    public function test_term_update_and_delete_are_bound_to_the_named_taxonomy(): void
    {
        foreach (['tags', 'secret'] as $tax) {
            Taxonomy::make($tax)->title(ucfirst($tax))->save();
            Blueprint::make($tax)->setNamespace("taxonomies.{$tax}")
                ->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
            Stache::refresh();
        }
        Term::make()->taxonomy('secret')->slug('alpha')->data(['title' => 'Secret term'])->save();

        // The term lives in 'secret'; naming 'tags' must not reach it.
        foreach (['update' => ['data' => ['title' => 'HIJACKED']], 'delete' => []] as $action => $extra) {
            $result = (new TermsRouter)->execute(['action' => $action, 'taxonomy' => 'tags', 'id' => 'secret::alpha'] + $extra);
            $this->assertFalse($result['success'], "{$action} across taxonomies must fail");
            $this->assertStringContainsString('not found', strtolower((string) ($result['errors'][0] ?? '')));
        }

        // Named correctly, the same update resolves the term.
        $ok = (new TermsRouter)->execute([
            'action' => 'update', 'taxonomy' => 'secret', 'id' => 'secret::alpha', 'data' => ['title' => 'Renamed'],
        ]);
        $this->assertTrue($ok['success'], json_encode($ok['errors'] ?? []));
    }

    public function test_globals_resource_policy_follows_the_global_set_alias(): void
    {
        foreach (['public', 'secret'] as $handle) {
            $set = GlobalSet::make($handle)->title(ucfirst($handle));
            $set->save();
            Blueprint::make($handle)->setNamespace('globals')
                ->setContents(['fields' => [['handle' => 'note', 'field' => ['type' => 'text']]]])->save();
            Stache::refresh();
            $set->makeLocalization('default')->data(['note' => "{$handle}-note"])->save();
        }
        config(['statamic.mcp.tools.globals.resources.read' => ['public']]);

        // global_set alone used to skip the policy; it must now be refused.
        $aliased = (new GlobalsRouter)->execute(['action' => 'get', 'global_set' => 'secret']);
        $this->assertFalse($aliased['success']);
        $this->assertStringContainsString('resource policy', strtolower((string) ($aliased['errors'][0] ?? '')));

        $allowed = (new GlobalsRouter)->execute(['action' => 'get', 'global_set' => 'public']);
        $this->assertTrue($allowed['success'], json_encode($allowed['errors'] ?? []));
    }

    public function test_structures_resource_policy_follows_the_acted_on_handle(): void
    {
        Collection::make('allowed_one')->title('Allowed')->save();
        Stache::refresh();
        Collection::make('protected_one')->title('Protected')->save();
        Stache::refresh();
        config(['statamic.mcp.tools.structures.resources.write' => ['allowed_*']]);

        // An allowed extra `collection` key must not let the policy approve a
        // delete whose actual target (`handle`) is denied.
        $denied = (new StructuresRouter)->execute([
            'action' => 'delete', 'resource_type' => 'collection',
            'handle' => 'protected_one', 'collection' => 'allowed_one',
        ]);
        $this->assertFalse($denied['success']);
        $this->assertStringContainsString('not permitted by resource policy', (string) ($denied['errors'][0] ?? ''));
        $this->assertNotNull(Collection::find('protected_one'));

        // Control: a bare allowed handle is not blocked by the policy.
        $allowed = (new StructuresRouter)->execute([
            'action' => 'delete', 'resource_type' => 'collection', 'handle' => 'allowed_one',
        ]);
        $this->assertStringNotContainsString('resource policy', (string) ($allowed['errors'][0] ?? ''));
    }
}
