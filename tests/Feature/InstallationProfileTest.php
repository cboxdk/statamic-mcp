<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Mcp\Prompts\AgentEducationPrompt;
use Cboxdk\StatamicMcp\Mcp\Resources\BlueprintIndexResource;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\UsersRouter;
use Cboxdk\StatamicMcp\Testing\InteractsWithMcp;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Laravel\Mcp\Request;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class InstallationProfileTest extends TestCase
{
    use InteractsWithMcp;

    public function test_disabled_profile_blocks_resources_and_prompts(): void
    {
        config(['statamic.mcp.profiles.stdio' => null]);
        $this->assertTrue((new AgentEducationPrompt)->handle(new Request([]))->isError());
        $this->mcpResource(BlueprintIndexResource::class)->assertSee('explicitly configured installation profile');
    }

    public function test_installer_rejects_unknown_profile_before_installation(): void
    {
        $this->artisan('mcp:statamic:install', ['--profile' => 'unknown', '--no-interaction' => true])
            ->expectsOutput('Choose --profile=read_only, --profile=editor or --profile=operator before installing.')
            ->assertExitCode(1);
    }

    public function test_missing_and_unknown_profiles_fail_closed_even_in_cli(): void
    {
        foreach ([null, '', 'administrator', true] as $profile) {
            config(['statamic.mcp.profiles.stdio' => $profile]);
            $result = (new EntriesRouter)->execute(['action' => 'list', 'collection' => 'pages']);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('explicitly configured', implode(' ', $result['errors']));
        }
    }

    public function test_read_only_profile_rejects_sensitive_reads_and_all_unknown_actions(): void
    {
        config(['statamic.mcp.profiles.stdio' => 'read_only']);
        $profile = app(InstallationProfile::class);
        $this->assertNull($profile->denial('entries', 'get'));
        foreach (['delete', 'publish', 'new_extension_action', 'create'] as $action) {
            $this->assertNotNull($profile->denial('entries', $action));
        }
        $result = (new UsersRouter)->execute(['action' => 'list', 'resource_type' => 'user']);
        $this->assertFalse($result['success']);
    }

    public function test_editor_cannot_change_a_live_entry_even_with_explicit_unpublish(): void
    {
        config(['statamic.mcp.profiles.stdio' => 'editor']);
        $handle = 'profile-' . bin2hex(random_bytes(4));
        Collection::make($handle)->save();
        $entry = Entry::make()->collection($handle)->slug('live')->published(true)->data(['title' => 'Original']);
        $entry->save();
        foreach ([['title' => 'Changed'], ['published' => false, 'title' => 'Changed']] as $data) {
            $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => $handle, 'id' => $entry->id(), 'data' => $data]);
            $this->assertFalse($result['success']);
            $this->assertTrue(Entry::find($entry->id())->published());
            $this->assertSame('Original', Entry::find($entry->id())->get('title'));
        }
    }

    public function test_editor_can_update_a_draft_but_cannot_publish_it(): void
    {
        config(['statamic.mcp.profiles.stdio' => 'editor']);
        $handle = 'profile-' . bin2hex(random_bytes(4));
        Collection::make($handle)->save();
        $entry = Entry::make()->collection($handle)->slug('draft')->published(false)->data(['title' => 'Original']);
        $entry->save();
        $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => $handle, 'id' => $entry->id(), 'data' => ['title' => 'Changed']]);
        $this->assertTrue($result['success'], json_encode($result));
        $result = (new EntriesRouter)->execute(['action' => 'publish', 'collection' => $handle, 'id' => $entry->id()]);
        $this->assertFalse($result['success']);
        $this->assertFalse(Entry::find($entry->id())->published());
    }

    public function test_editor_profile_does_not_resolve_entries_before_authentication(): void
    {
        config(['statamic.mcp.profiles.http' => 'editor', 'statamic.mcp.security.force_web_mode' => true]);
        auth()->logout();
        Entry::shouldReceive('find')->never();
        $result = (new EntriesRouter)->execute(['action' => 'update', 'id' => 'private-entry', 'data' => ['title' => 'Changed']]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Authentication required', implode(' ', $result['errors']));
    }

    public function test_web_request_cannot_inherit_the_stdio_operator_profile(): void
    {
        config(['statamic.mcp.profiles.stdio' => 'operator', 'statamic.mcp.profiles.http' => null]);
        request()->attributes->set('mcp_web_request', true);
        $this->assertSame('disabled', app(InstallationProfile::class)->name());
    }
}
