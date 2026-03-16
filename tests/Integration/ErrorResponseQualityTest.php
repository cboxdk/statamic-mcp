<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\AssetsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Collection;

class ErrorResponseQualityTest extends TestCase
{
    public function test_missing_collection_param_mentions_collection_in_error(): void
    {
        $router = new EntriesRouter;

        $result = $router->execute([
            'action' => 'list',
            // 'collection' deliberately omitted
        ]);

        $this->assertFalse($result['success']);
        $error = $result['errors'][0];
        $this->assertStringContainsStringIgnoringCase('collection', $error);
    }

    public function test_invalid_action_mentions_valid_actions_or_action_name(): void
    {
        $router = new StructuresRouter;

        $result = $router->execute([
            'action' => 'nonexistent',
            'resource_type' => 'collection',
        ]);

        $this->assertFalse($result['success']);
        $error = $result['errors'][0];
        // Should mention the invalid action name so user knows what went wrong
        $this->assertStringContainsString('nonexistent', $error);
    }

    public function test_invalid_resource_type_mentions_valid_types(): void
    {
        $router = new AssetsRouter;

        $result = $router->execute([
            'action' => 'list',
            'resource_type' => 'imaginary',
        ]);

        $this->assertFalse($result['success']);
        $error = $result['errors'][0];
        $this->assertStringContainsString('imaginary', $error);
    }

    public function test_all_errors_have_consistent_structure(): void
    {
        $entriesRouter = new EntriesRouter;
        $structuresRouter = new StructuresRouter;

        $errorResults = [
            $entriesRouter->execute(['action' => 'get', 'collection' => 'nonexistent_col']),
            $entriesRouter->execute(['action' => 'list']),
            $structuresRouter->execute(['action' => 'get', 'resource_type' => 'collection']),
        ];

        foreach ($errorResults as $index => $result) {
            $this->assertArrayHasKey('success', $result, "Error result {$index} missing 'success' key");
            $this->assertFalse($result['success'], "Error result {$index} should have success=false");
            $this->assertTrue(
                isset($result['errors']) || isset($result['error']),
                "Error result {$index} missing 'errors' or 'error' key"
            );
        }
    }

    public function test_entry_not_found_error_is_clear(): void
    {
        $router = new EntriesRouter;

        // First create a collection so the collection check passes
        Collection::make('error_test')->title('Error Test')->save();

        $result = $router->execute([
            'action' => 'get',
            'collection' => 'error_test',
            'id' => 'does-not-exist-uuid',
        ]);

        $this->assertFalse($result['success']);
        $error = $result['errors'][0];
        $this->assertStringContainsString('Entry not found', $error);
        $this->assertStringContainsString('does-not-exist-uuid', $error);
    }
}
