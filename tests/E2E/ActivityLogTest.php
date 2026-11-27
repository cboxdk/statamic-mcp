<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\E2E;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesAuthenticatedUser;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Role;

class ActivityLogTest extends TestCase
{
    use CreatesAuthenticatedUser;
    use CreatesTestContent;

    private string $auditPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditPath = storage_path('statamic-mcp/test-audit-' . bin2hex(random_bytes(4)) . '.log');

        config([
            'statamic.mcp.security.audit_logging' => true,
        ]);

        $this->app->singleton(AuditStore::class, fn (): FileAuditStore => new FileAuditStore($this->auditPath));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->auditPath)) {
            unlink($this->auditPath);
        }

        parent::tearDown();
    }

    public function test_audit_endpoint_returns_entries_after_log_write(): void
    {
        /** @var AuditStore $store */
        $store = app(AuditStore::class);
        $store->write([
            'level' => 'info',
            'message' => 'statamic-entries.list: success',
            'tool' => 'statamic-entries',
            'action' => 'list',
            'status' => 'success',
            'duration_ms' => 42.5,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->actingAsAdmin()
            ->getJson(cp_route('statamic-mcp.audit.index'))
            ->assertOk()
            ->assertJsonPath('data.0.tool', 'statamic-entries')
            ->assertJsonPath('data.0.status', 'success');
    }

    public function test_personal_activity_cannot_be_changed_to_another_actor_or_cli(): void
    {
        $user = $this->createRegularUser();
        Role::make('mcp-editor')->permissions(['access cp', 'view mcp dashboard'])->save();
        $user->assignRole('mcp-editor')->save();
        $this->actingAs($user);
        $store = app(AuditStore::class);
        foreach ([(string) $user->id(), 'someone-else', null] as $id) {
            $store->write(['level' => 'info', 'message' => 'm', 'tool' => 'statamic-entries-read', 'status' => 'success',
                'timestamp' => now()->toIso8601String(), 'user_id' => $id, 'context' => $id ? 'web' : 'cli']);
        }
        foreach (['', 'user:someone-else', 'cli'] as $actor) {
            $this->getJson(cp_route('statamic-mcp.audit.mine', ['actor' => $actor]))
                ->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.user_id', (string) $user->id());
        }
        $this->getJson(cp_route('statamic-mcp.audit.index'))->assertForbidden();
    }

    public function test_audit_endpoint_filters_by_tool(): void
    {
        /** @var AuditStore $store */
        $store = app(AuditStore::class);

        $store->write([
            'level' => 'info',
            'message' => 'statamic-entries.list: success',
            'tool' => 'statamic-entries',
            'status' => 'success',
            'timestamp' => now()->toIso8601String(),
        ]);

        $store->write([
            'level' => 'info',
            'message' => 'statamic-blueprints.get: success',
            'tool' => 'statamic-blueprints',
            'status' => 'success',
            'timestamp' => now()->toIso8601String(),
        ]);

        $response = $this->actingAsAdmin()
            ->getJson(cp_route('statamic-mcp.audit.index', ['tool' => 'entries']))
            ->assertOk();

        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json('data');

        foreach ($data as $entry) {
            $this->assertArrayHasKey('tool', $entry);
            $tool = $entry['tool'];
            $this->assertIsString($tool);
            $this->assertStringContainsString('entries', $tool);
        }
    }

    public function test_audit_endpoint_filters_by_status(): void
    {
        /** @var AuditStore $store */
        $store = app(AuditStore::class);

        $store->write([
            'level' => 'info',
            'message' => 'statamic-entries.list: success',
            'tool' => 'statamic-entries',
            'status' => 'success',
            'timestamp' => now()->toIso8601String(),
        ]);

        $store->write([
            'level' => 'error',
            'message' => 'statamic-entries.create: error',
            'tool' => 'statamic-entries',
            'status' => 'error',
            'timestamp' => now()->toIso8601String(),
        ]);

        $response = $this->actingAsAdmin()
            ->getJson(cp_route('statamic-mcp.audit.index', ['status' => 'success']))
            ->assertOk();

        /** @var array<int, array<string, mixed>> $data */
        $data = $response->json('data');

        foreach ($data as $entry) {
            $this->assertArrayHasKey('status', $entry);
            $this->assertEquals('success', $entry['status']);
        }
    }

    public function test_audit_entry_has_expected_structure(): void
    {
        /** @var AuditStore $store */
        $store = app(AuditStore::class);
        $store->write([
            'level' => 'info',
            'message' => 'statamic-entries.list: success',
            'tool' => 'statamic-entries',
            'action' => 'list',
            'status' => 'success',
            'duration_ms' => 25.0,
            'timestamp' => now()->toIso8601String(),
        ]);

        $response = $this->actingAsAdmin()
            ->getJson(cp_route('statamic-mcp.audit.index'))
            ->assertOk();

        $entry = $response->json('data.0');

        $this->assertArrayHasKey('tool', $entry);
        $this->assertArrayHasKey('status', $entry);
        $this->assertArrayHasKey('duration_ms', $entry);
        $this->assertArrayHasKey('timestamp', $entry);
    }

    public function test_audit_endpoint_filters_by_who_made_the_call(): void
    {
        /** @var AuditStore $store */
        $store = app(AuditStore::class);
        foreach (['alice-id', 'bob-id', 'alice-id'] as $userId) {
            $store->write(['level' => 'info', 'message' => 'm', 'tool' => 'statamic-entries', 'status' => 'success',
                'timestamp' => now()->toIso8601String(), 'user_id' => $userId, 'context' => 'web']);
        }

        $this->actingAsAdmin()
            ->getJson(cp_route('statamic-mcp.audit.index', ['actor' => 'user:alice-id', 'per_page' => 10]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');
    }
}
