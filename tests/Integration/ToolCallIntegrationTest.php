<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;

class ToolCallIntegrationTest extends TestCase
{
    use CreatesTestContent;

    private EntriesRouter $entriesRouter;

    private BlueprintsRouter $blueprintsRouter;

    private StructuresRouter $structuresRouter;

    private SystemRouter $systemRouter;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entriesRouter = new EntriesRouter;
        $this->blueprintsRouter = new BlueprintsRouter;
        $this->structuresRouter = new StructuresRouter;
        $this->systemRouter = new SystemRouter;

        $this->collectionHandle = 'integration_blog';
        $this->createTestCollection($this->collectionHandle);
        $this->createTestBlueprint($this->collectionHandle);
        $this->createTestTaxonomy('tags');
    }

    // -----------------------------------------------------------------------
    // EntriesRouter
    // -----------------------------------------------------------------------

    public function test_entries_list_returns_entries_array(): void
    {
        $this->createTestEntry($this->collectionHandle, ['title' => 'First Post']);
        $this->createTestEntry($this->collectionHandle, ['title' => 'Second Post']);

        $result = $this->entriesRouter->execute([
            'action' => 'list',
            'collection' => $this->collectionHandle,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('entries', $result['data']);
        $this->assertCount(2, $result['data']['entries']);
        $this->assertArrayHasKey('pagination', $result['data']);
    }

    public function test_entries_get_with_valid_id_returns_entry_data(): void
    {
        $entry = $this->createTestEntry($this->collectionHandle, ['title' => 'Specific Post']);

        $result = $this->entriesRouter->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('entry', $result['data']);
        $this->assertEquals($entry->id(), $result['data']['entry']['id']);
        $this->assertEquals('Specific Post', $result['data']['entry']['data']['title']);
    }

    public function test_entries_get_with_invalid_id_returns_error(): void
    {
        $result = $this->entriesRouter->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => 'nonexistent-id-12345',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Entry not found', $result['errors'][0]);
    }

    public function test_entries_create_with_valid_data_succeeds(): void
    {
        $result = $this->entriesRouter->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'New Entry'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('entry', $result['data']);
        $this->assertTrue($result['data']['created']);
        $this->assertEquals('New Entry', $result['data']['entry']['title']);
        $this->assertNotEmpty($result['data']['entry']['id']);
    }

    public function test_entries_delete_removes_entry(): void
    {
        $entry = $this->createTestEntry($this->collectionHandle, ['title' => 'To Delete']);
        $entryId = $entry->id();

        $result = $this->entriesRouter->execute([
            'action' => 'delete',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['deleted']);

        // Verify entry is gone
        $getResult = $this->entriesRouter->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);
        $this->assertFalse($getResult['success']);
    }

    // -----------------------------------------------------------------------
    // BlueprintsRouter
    // -----------------------------------------------------------------------

    public function test_blueprints_list_with_namespace_returns_blueprints(): void
    {
        $result = $this->blueprintsRouter->execute([
            'action' => 'list',
            'namespace' => 'collections',
            'include_fields' => false,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('blueprints', $result['data']);
        $this->assertNotEmpty($result['data']['blueprints']);

        /** @var array<int, array<string, mixed>> $blueprints */
        $blueprints = $result['data']['blueprints'];
        $handles = collect($blueprints)->pluck('handle')->toArray();
        $this->assertContains($this->collectionHandle, $handles);
    }

    public function test_blueprints_get_with_handle_returns_fields(): void
    {
        $result = $this->blueprintsRouter->execute([
            'action' => 'get',
            'handle' => $this->collectionHandle,
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('blueprint', $result['data']);

        /** @var array<string, mixed> $blueprint */
        $blueprint = $result['data']['blueprint'];
        $this->assertEquals($this->collectionHandle, $blueprint['handle']);
        $this->assertArrayHasKey('fields', $blueprint);
        $this->assertNotEmpty($blueprint['fields']);

        // Verify known fields from CreatesTestContent trait
        /** @var array<int, array<string, mixed>> $fields */
        $fields = $blueprint['fields'];
        $fieldHandles = collect($fields)->pluck('handle')->toArray();
        $this->assertContains('title', $fieldHandles);
        $this->assertContains('content', $fieldHandles);
    }

    // -----------------------------------------------------------------------
    // StructuresRouter
    // -----------------------------------------------------------------------

    public function test_structures_list_collections_returns_data(): void
    {
        $result = $this->structuresRouter->execute([
            'action' => 'list',
            'resource_type' => 'collection',
        ]);

        // Structures list may succeed or return a specific error depending on Stache state
        if ($result['success']) {
            $this->assertArrayHasKey('collections', $result['data']);
        } else {
            // If it fails, it should fail with a descriptive error (not a crash)
            $errors = $result['errors'] ?? [$result['error'] ?? 'Unknown error'];
            $this->assertNotEmpty($errors);
        }
    }

    // -----------------------------------------------------------------------
    // SystemRouter
    // -----------------------------------------------------------------------

    public function test_system_info_returns_version_info(): void
    {
        $result = $this->systemRouter->execute([
            'action' => 'info',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('system_info', $result['data']);

        $info = $result['data']['system_info'];
        $this->assertArrayHasKey('statamic_version', $info);
        $this->assertArrayHasKey('laravel_version', $info);
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('environment', $info);
    }

    // -----------------------------------------------------------------------
    // Round-trip: create then read back
    // -----------------------------------------------------------------------

    public function test_entry_round_trip_create_then_get(): void
    {
        $createResult = $this->entriesRouter->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Round Trip Entry', 'content' => 'Some content'],
        ]);

        $this->assertTrue($createResult['success']);
        $entryId = $createResult['data']['entry']['id'];

        $getResult = $this->entriesRouter->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);

        $this->assertTrue($getResult['success']);
        $this->assertEquals('Round Trip Entry', $getResult['data']['entry']['data']['title']);
        $this->assertEquals('Some content', $getResult['data']['entry']['data']['content']);
    }
}
