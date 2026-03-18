<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesTestContent;
use Cboxdk\StatamicMcp\Tests\TestCase;

class EntryValidationTest extends TestCase
{
    use CreatesTestContent;

    private EntriesRouter $router;

    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'entry_val_test';
        $this->createTestCollection($this->collectionHandle);
        $this->createTestBlueprint($this->collectionHandle);
    }

    public function test_create_entry_with_valid_data_succeeds(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Valid Entry', 'content' => 'Some content here'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['created']);
        $this->assertEquals('Valid Entry', $result['data']['entry']['title']);
    }

    public function test_create_entry_with_empty_data_returns_validation_error(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [],
        ]);

        // Empty data should either fail or result in entry without required fields
        // The router requires data for create action
        $this->assertFalse($result['success']);
        $error = $result['errors'][0];
        $this->assertStringContainsString('Data is required', $error);
    }

    public function test_update_with_partial_data_changes_only_provided_fields(): void
    {
        $entry = $this->createTestEntry($this->collectionHandle, [
            'title' => 'Original Title',
            'content' => 'Original content',
        ]);

        $result = $this->router->execute([
            'action' => 'update',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
            'data' => ['title' => 'Updated Title'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['updated']);

        // Verify the update persisted and content was not wiped
        $getResult = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entry->id(),
        ]);

        $this->assertTrue($getResult['success']);
        $this->assertEquals('Updated Title', $getResult['data']['entry']['data']['title']);
        $this->assertEquals('Original content', $getResult['data']['entry']['data']['content']);
    }

    public function test_data_persists_correctly_after_create(): void
    {
        $result = $this->router->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => [
                'title' => 'Persistence Test',
                'content' => 'Content to persist',
            ],
        ]);

        $this->assertTrue($result['success']);
        $entryId = $result['data']['entry']['id'];

        // Read back immediately
        $getResult = $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => $entryId,
        ]);

        $this->assertTrue($getResult['success']);
        $data = $getResult['data']['entry']['data'];
        $this->assertEquals('Persistence Test', $data['title']);
        $this->assertEquals('Content to persist', $data['content']);
    }
}
