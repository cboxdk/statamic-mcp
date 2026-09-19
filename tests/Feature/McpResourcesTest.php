<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Mcp\Resources\BlueprintIndexResource;
use Cboxdk\StatamicMcp\Mcp\Resources\BlueprintResource;
use Cboxdk\StatamicMcp\Testing\InteractsWithMcp;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

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

    public function test_reading_an_unknown_blueprint_reports_an_error_without_failing_the_call(): void
    {
        // Deliberately not assertHasErrors(): laravel/mcp's ReadResource is not
        // Errable, so an error response becomes -32603 and HTTP 500, which a
        // hosted client behind a proxy renders as a bare 502 with the message
        // gone (#55). The refusal travels as a normal result instead.
        $this->mcpResource(
            BlueprintResource::class,
            ['namespace' => 'collections.' . self::COLLECTION, 'handle' => 'nope']
        )
            ->assertOk()
            ->assertSee(['"success":false', '"code":"NOT_FOUND"', 'Blueprint not found']);
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
            ->assertOk()
            ->assertSee(['"success":false', '"code":"PERMISSION_DENIED"', 'resource policy']);
    }

    public function test_blueprints_stay_readable_when_the_writable_tool_is_off(): void
    {
        // A site keeping its content model in Git turns the blueprints tool off
        // because it can create and delete blueprints. That must not take the
        // read-only resource with it — the server's own instructions tell
        // agents to read the blueprint before every write (#54).
        $this->actingInWebContext();
        config(['statamic.mcp.tools.blueprints.enabled' => false]);

        $this->mcpResource(
            BlueprintResource::class,
            ['namespace' => 'collections.' . self::COLLECTION, 'handle' => 'article']
        )
            ->assertOk()
            ->assertSee('"handle":"article"');
    }

    public function test_resources_can_still_be_switched_off_on_their_own(): void
    {
        $this->actingInWebContext();
        config(['statamic.mcp.resources.enabled' => false]);

        $this->mcpResource(BlueprintIndexResource::class)
            ->assertOk()
            ->assertSee(['"success":false', 'MCP resources are disabled']);
    }

    public function test_an_editor_without_configure_permissions_can_be_allowed_to_read(): void
    {
        // Reading schema to shape a write is not the same as editing the content
        // model, and 'configure fields' is an admin permission editors rarely
        // hold. Sites can let the token scope they minted decide instead (#54).
        $this->actingInWebContext(super: false);

        $this->mcpResource(BlueprintIndexResource::class)
            ->assertOk()
            ->assertSee(['"success":false', 'Insufficient Statamic permissions']);

        config(['statamic.mcp.resources.require_statamic_permission' => false]);

        $this->mcpResource(BlueprintIndexResource::class)
            ->assertOk()
            ->assertSee('/article');
    }

    /**
     * Put the resource gates into the mode a real client hits.
     *
     * Without this the tests run as CLI, where denyReason() skips the tool
     * toggle, the token scope and the Statamic permission entirely — so a test
     * of any of those would pass no matter what the code did.
     */
    private function actingInWebContext(bool $super = true): void
    {
        config(['statamic.mcp.security.force_web_mode' => true]);

        $user = User::make()->email(($super ? 'super' : 'editor') . '@example.com');

        if ($super) {
            $user->makeSuper();
        }

        $user->save();

        $this->actingAs($user);
    }
}
