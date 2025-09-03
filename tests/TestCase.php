<?php

namespace Cboxdk\StatamicMcp\Tests;

use Cboxdk\StatamicMcp\ServiceProvider;
use Laravel\Mcp\Server\Tools\ToolResult;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Extract data from standardized MCP response format.
     */
    protected function extractMcpData(ToolResult $result): array
    {
        $resultArray = $result->toArray();

        // Handle both old and new formats for backward compatibility
        if (isset($resultArray['content'][0]['text'])) {
            $data = json_decode($resultArray['content'][0]['text'], true);

            // New standardized format has 'data' nested inside
            if (isset($data['success']) && isset($data['data'])) {
                return $data['data'];
            }

            // Old format returns data directly
            return $data;
        }

        return [];
    }

    /**
     * Check if MCP response was successful.
     */
    protected function isMcpSuccess(ToolResult $result): bool
    {
        $resultArray = $result->toArray();

        if (isset($resultArray['content'][0]['text'])) {
            $data = json_decode($resultArray['content'][0]['text'], true);

            // New standardized format
            if (isset($data['success'])) {
                return $data['success'] === true;
            }

            // Old format assumes success if no error
            return ! isset($data['error']);
        }

        return false;
    }

    /**
     * Get MCP response metadata.
     */
    protected function getMcpMetadata(ToolResult $result): ?array
    {
        $resultArray = $result->toArray();

        if (isset($resultArray['content'][0]['text'])) {
            $data = json_decode($resultArray['content'][0]['text'], true);

            if (isset($data['meta'])) {
                return $data['meta'];
            }
        }

        return null;
    }

    /**
     * Get MCP response errors.
     */
    protected function getMcpErrors(ToolResult $result): array
    {
        $resultArray = $result->toArray();

        if (isset($resultArray['content'][0]['text'])) {
            $data = json_decode($resultArray['content'][0]['text'], true);

            if (isset($data['errors'])) {
                return is_array($data['errors']) ? $data['errors'] : [$data['errors']];
            }

            // Handle old error format
            if (isset($data['error'])) {
                return [$data['error']];
            }
        }

        return [];
    }

    /**
     * Assert that MCP response is successful and contains expected keys.
     */
    protected function assertMcpSuccess(ToolResult $result, array $expectedKeys = []): array
    {
        expect($result)->toBeInstanceOf(ToolResult::class);
        expect($this->isMcpSuccess($result))->toBeTrue('MCP response should be successful');

        $data = $this->extractMcpData($result);
        expect($data)->toBeArray('MCP response data should be an array');

        if (! empty($expectedKeys)) {
            expect($data)->toHaveKeys($expectedKeys, 'MCP response should contain expected keys');
        }

        return $data;
    }

    /**
     * Assert that MCP response contains an error.
     */
    protected function assertMcpError(ToolResult $result, ?string $expectedErrorMessage = null): array
    {
        expect($result)->toBeInstanceOf(ToolResult::class);
        expect($this->isMcpSuccess($result))->toBeFalse('MCP response should contain an error');

        $errors = $this->getMcpErrors($result);
        expect($errors)->not()->toBeEmpty('MCP response should contain error messages');

        if ($expectedErrorMessage !== null) {
            $errorString = implode(' ', $errors);
            expect($errorString)->toContain($expectedErrorMessage, 'Error message should contain expected text');
        }

        return $errors;
    }
}
