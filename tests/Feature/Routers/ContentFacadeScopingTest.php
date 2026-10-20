<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\ContentFacadeRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * The content-facade sweeps read entries/terms/globals, so they must honour
 * those domains' resource-policy allowlists — the policy binds even the
 * super-only facade. Runs in CLI context, where the policy still applies.
 */
class ContentFacadeScopingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);

        foreach (['blog' => 'PUBLIC-ENTRY', 'secret' => 'SECRET-ENTRY'] as $handle => $title) {
            Collection::make($handle)->title(ucfirst($handle))->save();
            Blueprint::make($handle)->setNamespace("collections.{$handle}")
                ->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
            Stache::refresh();
            Entry::make()->id("{$handle}-1")->collection($handle)->slug("{$handle}-1")->data(['title' => $title])->published(true)->save();
        }

        foreach (['tags' => 'PUBLICTERM', 'internal' => 'SECRETTERM'] as $handle => $slug) {
            Taxonomy::make($handle)->title(ucfirst($handle))->save();
            Blueprint::make($handle)->setNamespace("taxonomies.{$handle}")
                ->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
            Stache::refresh();
            Term::make()->taxonomy($handle)->slug(strtolower($slug))->data(['title' => $slug])->save();
        }

        foreach (['public_g' => 'PUBLICGLOBAL', 'secret_g' => 'SECRETGLOBAL'] as $handle => $note) {
            $set = GlobalSet::make($handle)->title($note);
            $set->save();
            Blueprint::make($handle)->setNamespace('globals')
                ->setContents(['fields' => [['handle' => 'note', 'field' => ['type' => 'text']]]])->save();
            Stache::refresh();
            $set->makeLocalization('default')->data(['note' => $note])->save();
        }

        config([
            'statamic.mcp.tools.entries.resources.read' => ['blog'],
            'statamic.mcp.tools.terms.resources.read' => ['tags'],
            'statamic.mcp.tools.globals.resources.read' => ['public_g'],
        ]);
    }

    public function test_content_audit_hides_denied_resources(): void
    {
        $json = json_encode((new ContentFacadeRouter)->execute(['action' => 'content_audit']));

        expect($json)->toContain('blog')
            ->and($json)->not->toContain('secret')      // collection handle 'secret' and global 'secret_g'
            ->and($json)->not->toContain('internal')     // taxonomy handle
            ->and($json)->not->toContain('SECRETGLOBAL');
    }

    public function test_content_validate_sweep_excludes_denied_collections(): void
    {
        $json = json_encode((new ContentFacadeRouter)->execute(['action' => 'content_validate']));

        expect($json)->not->toContain('secret-1')         // the denied entry id
            ->and($json)->not->toContain('SECRETTERM');
    }

    public function test_cross_reference_excludes_denied_taxonomy(): void
    {
        $json = json_encode((new ContentFacadeRouter)->execute(['action' => 'cross_reference']));

        expect($json)->not->toContain('internal');
    }
}
