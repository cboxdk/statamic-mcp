<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Services;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use ReflectionClass;

class SchemaIntrospectionService
{
    /**
     * Extract tool schema by invoking defineSchema method with mock schema.
     *
     *
     * @return array<string, mixed>
     */
    public function extractToolSchema(BaseStatamicTool $tool): array
    {
        try {
            // Use reflection to access protected defineSchema method
            $reflection = new ReflectionClass($tool);
            $defineSchemaMethod = $reflection->getMethod('defineSchema');
            $defineSchemaMethod->setAccessible(true);

            // Create a JsonSchema instance and get the schema definition
            $schema = new \Illuminate\JsonSchema\JsonSchema;
            $schemaDefinition = $defineSchemaMethod->invoke($tool, $schema);

            return [
                'parameters' => $schemaDefinition,
                'parameter_count' => count($schemaDefinition),
                'required_parameters' => [], // New API doesn't have explicit required marking
                'optional_parameters' => array_keys($schemaDefinition),
            ];
        } catch (\Exception $e) {
            return [
                'parameters' => [],
                'parameter_count' => 0,
                'required_parameters' => [],
                'optional_parameters' => [],
                'error' => 'Could not extract schema: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create a mock schema object for parameter extraction.
     */
    public function createMockSchema(): MockToolSchema
    {
        return new MockToolSchema;
    }
}

use Illuminate\JsonSchema\JsonSchema;

class MockToolSchema
{
    /** @var array<string, array<string, mixed>> */
    public array $fields = [];

    /**
     * @param  array<string, mixed>  $schema
     */
    public function captureSchema(array $schema): void
    {
        $this->fields = $schema;
    }
}
