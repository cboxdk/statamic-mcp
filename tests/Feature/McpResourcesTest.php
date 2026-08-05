<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Mcp\Resources\BlueprintIndexResource;
use Cboxdk\StatamicMcp\Mcp\Resources\BlueprintResource;
use Cboxdk\StatamicMcp\Testing\InteractsWithMcp;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Resources are a read surface alongside tools. The route middleware only
 * authenticates — scope checks are deferred to the primitive — so these tests
 * cover both the content and the authorization gates.
 */
class McpResourcesTest extends TestCase
{
    use InteractsWithMcp;

    private const COLLECTION = 'resource_pages';

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make(self::COLLECTION)->title('Resource Pages')->save();

        Blueprint::make('article')
            ->setNamespace('collections.' . self::COLLECTION)
            ->setContents([
                'title' => 'Article',
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
                    ['handle' => 'body', 'field' => ['type' => 'markdown']],
                ]]]]],
            ])
            ->save();
    }

    public function test_index_lists_blueprint_uris(): void
    {
        $this->mcpResource(BlueprintIndexResource::class)
            ->assertOk()
            ->assertSee('statamic://blueprints/collections.' . self::COLLECTION . '/article');
    }

    public function test_a_blueprint_can_be_read_by_uri(): void
    {
        $this->mcpResource(
            BlueprintResource::class,
            ['namespace' => 'collections.' . self::COLLECTION, 'handle' => 'article']
        )
            ->assertOk()
            ->assertSee(['"handle":"article"', '"type":"markdown"']);
    }

    public function test_reading_an_unknown_blueprint_reports_an_error(): void
    {
        $this->mcpResource(
            BlueprintResource::class,
            ['namespace' => 'collections.' . self::COLLECTION, 'handle' => 'nope']
        )
            ->assertHasErrors(['Blueprint not found']);
    }

    public function test_resource_policy_hides_blueprints_from_the_index(): void
    {
        config(['statamic.mcp.tools.blueprints.resources.read' => ['something-else']]);

        $this->mcpResource(BlueprintIndexResource::class)
            ->assertDontSee('/article');
    }

    public function test_resource_policy_blocks_reading_a_hidden_blueprint(): void
    {
        config(['statamic.mcp.tools.blueprints.resources.read' => ['something-else']]);

        $this->mcpResource(
            BlueprintResource::class,
            ['namespace' => 'collections.' . self::COLLECTION, 'handle' => 'article']
        )
            ->assertHasErrors(['resource policy']);
    }
}
