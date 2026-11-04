<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;

class StructuresResourcePolicyTest extends TestCase
{
    private StructuresRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'statamic.editions.pro' => true,
            'statamic.mcp.security.force_web_mode' => true,
            'statamic.mcp.web.enabled' => true,
            'statamic.mcp.confirmation.enabled' => false,
            'statamic.mcp.tools.structures.resources.read' => ['allowed*'],
            'statamic.mcp.tools.structures.resources.write' => ['allowed*'],
        ]);
        $this->router = new StructuresRouter;
        $this->actAs(['configure collections', 'configure taxonomies', 'configure globals', 'configure navs', 'configure sites', 'view protected nav', 'edit protected nav']);
        $this->token(['structures:read', 'structures:write']);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actAs(array $permissions): void
    {
        $role = 'role-' . bin2hex(random_bytes(4));
        Role::make()->handle($role)->title('Role')->permissions(['access cp', ...$permissions])->save();
        $user = User::make()->id('u-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        $user->assignRole($role);
        $user->save();
        $this->actingAs($user);
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function token(array $scopes): void
    {
        request()->attributes->set('mcp_token', new McpTokenData(
            'structures-test', auth()->id(), 'Structures test', '', $scopes, null, null, Carbon::now(),
        ));
    }

    public function test_collection_reads_and_writes_cannot_authorize_a_different_data_handle(): void
    {
        Collection::make('protected')->title('Original')->save();
        Stache::refresh();

        foreach (['get', 'update', 'delete', 'configure'] as $action) {
            foreach (['allowed', '', ['allowed']] as $dataHandle) {
                $result = $this->router->execute([
                    'action' => $action,
                    'resource_type' => 'collection',
                    'handle' => 'protected',
                    'data' => ['handle' => $dataHandle, 'title' => 'Hijacked'],
                    'config' => ['title' => 'Hijacked'],
                ]);

                $mode = $action === 'get' ? 'Read' : 'Write';
                $this->assertFalse($result['success'], json_encode($result) ?: '');
                $this->assertSame(["{$mode} access to 'protected' is not permitted by resource policy"], $result['errors']);
                $this->assertSame('Original', Collection::find('protected')?->title());
            }
        }
    }

    public function test_other_structure_types_bind_the_policy_to_the_handler_target(): void
    {
        Taxonomy::make('protected')->title('Original taxonomy')->save();
        Nav::make('protected')->title('Original navigation')->save();
        GlobalSet::make('protected')->title('Original globals')->save();
        Stache::refresh();

        foreach (['taxonomy', 'navigation', 'globalset', 'site'] as $type) {
            $handle = $type === 'site' ? 'default' : 'protected';
            $actions = $type === 'site' ? ['get'] : ['get', 'update', 'delete', 'configure'];

            foreach ($actions as $action) {
                foreach (['allowed', '', ['allowed']] as $dataHandle) {
                    $result = $this->router->execute([
                        'action' => $action,
                        'resource_type' => $type,
                        'handle' => $handle,
                        'data' => ['handle' => $dataHandle, 'title' => 'Hijacked'],
                        'config' => ['title' => 'Hijacked'],
                    ]);

                    $mode = $action === 'get' ? 'Read' : 'Write';
                    $this->assertFalse($result['success'], json_encode($result) ?: '');
                    $this->assertSame(["{$mode} access to '{$handle}' is not permitted by resource policy"], $result['errors']);
                }
            }
        }

        $this->assertSame('Original taxonomy', Taxonomy::find('protected')?->title());
        $this->assertSame('Original navigation', Nav::find('protected')?->title());
        $this->assertSame('Original globals', GlobalSet::find('protected')?->title());
    }

    public function test_allowed_collection_can_still_be_read_and_updated(): void
    {
        Collection::make('allowed')->title('Original')->save();
        Stache::refresh();

        $read = $this->router->execute(['action' => 'get', 'resource_type' => 'collection', 'handle' => 'allowed']);
        $this->assertTrue($read['success'], json_encode($read) ?: '');
        $this->assertSame('allowed', $read['data']['collection']['handle']);

        $update = $this->router->execute([
            'action' => 'update', 'resource_type' => 'collection', 'handle' => 'allowed',
            'data' => ['handle' => 'protected', 'title' => 'Updated'],
        ]);
        $this->assertTrue($update['success'], json_encode($update) ?: '');
        $this->assertSame('Updated', Collection::find('allowed')?->title());
    }

    public function test_create_authorizes_the_data_handle_it_creates(): void
    {
        $allowed = $this->router->execute([
            'action' => 'create', 'resource_type' => 'collection', 'handle' => 'protected',
            'data' => ['handle' => 'allowed', 'title' => 'Created'],
        ]);
        $this->assertTrue($allowed['success'], json_encode($allowed) ?: '');
        $this->assertSame('Created', Collection::find('allowed')?->title());
        $this->assertNull(Collection::find('protected'));

        $denied = $this->router->execute([
            'action' => 'create', 'resource_type' => 'collection', 'handle' => 'allowed',
            'data' => ['handle' => 'protected', 'title' => 'Denied'],
        ]);
        $this->assertFalse($denied['success'], json_encode($denied) ?: '');
        $this->assertSame(["Write access to 'protected' is not permitted by resource policy"], $denied['errors']);
        $this->assertNull(Collection::find('protected'));
    }

    public function test_create_nonstring_data_handle_falls_back_to_the_top_level_handle(): void
    {
        foreach ([null, [], 42] as $index => $dataHandle) {
            $allowedHandle = "allowed_{$index}";
            $allowed = $this->router->execute([
                'action' => 'create', 'resource_type' => 'collection', 'handle' => $allowedHandle,
                'data' => ['handle' => $dataHandle, 'title' => 'Created'],
            ]);
            $this->assertTrue($allowed['success'], json_encode($allowed) ?: '');
            $this->assertNotNull(Collection::find($allowedHandle));

            $denied = $this->router->execute([
                'action' => 'create', 'resource_type' => 'collection', 'handle' => 'protected',
                'data' => ['handle' => $dataHandle, 'title' => 'Denied'],
            ]);
            $this->assertFalse($denied['success'], json_encode($denied) ?: '');
            $this->assertSame(["Write access to 'protected' is not permitted by resource policy"], $denied['errors']);
            $this->assertNull(Collection::find('protected'));
        }
    }

    public function test_empty_string_data_handle_does_not_create_the_top_level_handle(): void
    {
        $result = $this->router->execute([
            'action' => 'create', 'resource_type' => 'collection', 'handle' => 'allowed',
            'data' => ['handle' => '', 'title' => 'Not created'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(['Collection handle is required'], $result['errors']);
        $this->assertNull(Collection::find('allowed'));
    }

    public function test_create_policy_uses_the_handle_remaining_after_denied_field_filtering(): void
    {
        config(['statamic.mcp.tools.structures.denied_fields' => ['handle']]);

        $result = $this->router->execute([
            'action' => 'create', 'resource_type' => 'collection', 'handle' => 'protected',
            'data' => ['handle' => 'allowed', 'title' => 'Denied'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(["Write access to 'protected' is not permitted by resource policy"], $result['errors']);
        $this->assertNull(Collection::find('protected'));
        $this->assertNull(Collection::find('allowed'));
    }

    public function test_allowed_resource_still_requires_token_scope_and_statamic_permissions(): void
    {
        Collection::make('allowed')->title('Original')->save();
        Stache::refresh();

        $this->token([]);
        foreach (['get' => 'read', 'update' => 'write'] as $action => $mode) {
            $result = $this->router->execute([
                'action' => $action, 'resource_type' => 'collection', 'handle' => 'allowed',
                'data' => ['title' => 'Hijacked'],
            ]);
            $this->assertSame(["Token missing required scope: structures:{$mode}"], $result['errors']);
        }

        $this->actAs([]);
        $this->token(['structures:read', 'structures:write']);
        foreach (['get', 'update'] as $action) {
            $result = $this->router->execute([
                'action' => $action, 'resource_type' => 'collection', 'handle' => 'allowed',
                'data' => ['title' => 'Hijacked'],
            ]);
            $this->assertSame(["Permission denied: Cannot {$action} structures"], $result['errors']);
        }
        $this->assertSame('Original', Collection::find('allowed')?->title());
    }
}
