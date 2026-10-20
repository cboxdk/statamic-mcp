<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Tools;

use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

/**
 * statamic-system-discover's system_state must only list what the caller could
 * list through the routers: each section behind its configure permission, each
 * handle filtered by the resource policy.
 */
class DiscoveryScopingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        config(['statamic.mcp.security.force_web_mode' => true]);

        Collection::make('blog')->title('Blog')->save();
        Collection::make('secret')->title('Secret')->save();
        Stache::refresh();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actAs(array $permissions): void
    {
        $handle = 'role-' . bin2hex(random_bytes(4));
        Role::make()->handle($handle)->title('Role')->permissions(['access cp', ...$permissions])->save();
        $user = User::make()->id('u-' . bin2hex(random_bytes(4)))->email(bin2hex(random_bytes(4)) . '@example.test');
        $user->assignRole($handle);
        $user->save();
        $this->actingAs($user);
    }

    private function systemState(): array
    {
        $result = (new DiscoveryTool)->execute(['intent' => 'list content']);

        return $result['data']['discovery']['system_state'] ?? [];
    }

    public function test_a_user_without_configure_permissions_sees_no_structure(): void
    {
        $this->actAs([]);

        $state = $this->systemState();
        expect($state['collections'] ?? [])->toBe([])
            ->and($state)->not->toHaveKey('collections');
    }

    public function test_configure_collections_sees_only_policy_permitted_collections(): void
    {
        config(['statamic.mcp.tools.structures.resources.read' => ['blog']]);
        $this->actAs(['configure collections']);

        $handles = array_column($this->systemState()['collections'] ?? [], 'handle');
        expect($handles)->toContain('blog')->and($handles)->not->toContain('secret');
    }
}
