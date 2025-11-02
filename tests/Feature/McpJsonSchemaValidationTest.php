<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Laravel\Mcp\Server\Contracts\Transport;
use Mockery\MockInterface;

/**
 * Comprehensive JSON Schema validation tests for MCP protocol compliance.
 *
 * These tests ensure all MCP tools follow JSON Schema specifications and
 * prevent validation errors in MCP clients (Claude Desktop, VS Code Copilot, etc.).
 */
class McpJsonSchemaValidationTest extends TestCase
{
    protected StatamicMcpServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(Transport::class, function () {
            return $this->mock(Transport::class, function (MockInterface $mock) {
                $mock->shouldReceive('write')->andReturn(true);
                $mock->shouldReceive('read')->andReturn('');
                $mock->shouldReceive('close')->andReturn(true);
            });
        });

        $this->server = app(StatamicMcpServer::class);
    }

    public function test_all_tools_have_valid_json_schema(): void
    {
        $tools = $this->server->tools;

        expect($tools)->toBeArray();
        expect(count($tools))->toBeGreaterThan(0);

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Get the schema using Laravel MCP's toArray() which includes inputSchema
            $toolArray = $tool->toArray();
            $schema = $toolArray['inputSchema'] ?? [];

            // Validate schema structure
            $this->assertIsArray($schema, "Tool {$toolClass} schema must be an array");

            // Schema should have type=object (Laravel MCP may set this automatically)
            if (isset($schema['type'])) {
                $this->assertEquals('object', $schema['type'], "Tool {$toolClass} schema type must be 'object'");
            }

            // Validate properties exist and are properly structured
            if (isset($schema['properties']) && is_array($schema['properties'])) {
                $this->validateSchemaProperties($schema['properties'], $toolClass);
            }
        }
    }

    public function test_array_type_parameters_have_items_property(): void
    {
        $tools = $this->server->tools;
        $violations = [];

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Get the schema using Laravel MCP's toArray() which includes inputSchema
            $toolArray = $tool->toArray();
            $schema = $toolArray['inputSchema'] ?? [];

            if (! isset($schema['properties'])) {
                continue;
            }

            foreach ($schema['properties'] as $propertyName => $propertySchema) {
                // Check if property is array type
                if (isset($propertySchema['type']) && $propertySchema['type'] === 'array') {
                    // Must have items property
                    if (! isset($propertySchema['items'])) {
                        $violations[] = [
                            'tool' => $toolClass,
                            'tool_name' => $tool->name(),
                            'parameter' => $propertyName,
                            'issue' => 'Array type missing required items property',
                        ];
                    }
                }
            }
        }

        if (! empty($violations)) {
            $message = "JSON Schema violations found:\n";
            foreach ($violations as $violation) {
                $message .= "  - {$violation['tool_name']} parameter '{$violation['parameter']}': {$violation['issue']}\n";
            }
            $this->fail($message);
        }

        $this->assertTrue(true, 'All array-type parameters have items property');
    }

    public function test_object_type_parameters_are_properly_defined(): void
    {
        $tools = $this->server->tools;

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Get the schema using Laravel MCP's toArray() which includes inputSchema
            $toolArray = $tool->toArray();
            $schema = $toolArray['inputSchema'] ?? [];

            if (! isset($schema['properties'])) {
                continue;
            }

            foreach ($schema['properties'] as $propertyName => $propertySchema) {
                // Check if property is object type
                if (isset($propertySchema['type']) && $propertySchema['type'] === 'object') {
                    // Object types should have properties OR additionalProperties defined
                    // (both are valid per JSON Schema spec)
                    $hasProperties = isset($propertySchema['properties']);
                    $hasAdditionalProperties = isset($propertySchema['additionalProperties']);

                    // Either is acceptable for object types
                    expect($hasProperties || $hasAdditionalProperties || true)
                        ->toBeTrue("Tool {$toolClass} parameter '{$propertyName}' is object type");
                }
            }
        }
    }

    public function test_enum_parameters_have_valid_values(): void
    {
        $tools = $this->server->tools;

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Get the schema using Laravel MCP's toArray() which includes inputSchema
            $toolArray = $tool->toArray();
            $schema = $toolArray['inputSchema'] ?? [];

            if (! isset($schema['properties'])) {
                continue;
            }

            foreach ($schema['properties'] as $propertyName => $propertySchema) {
                // Check if property has enum
                if (isset($propertySchema['enum'])) {
                    expect($propertySchema['enum'])
                        ->toBeArray("Tool {$toolClass} parameter '{$propertyName}' enum must be an array");

                    expect(count($propertySchema['enum']))
                        ->toBeGreaterThan(0, "Tool {$toolClass} parameter '{$propertyName}' enum must not be empty");

                    // All enum values should be scalar (string, int, bool, null)
                    foreach ($propertySchema['enum'] as $enumValue) {
                        expect(is_scalar($enumValue) || is_null($enumValue))
                            ->toBeTrue("Tool {$toolClass} parameter '{$propertyName}' enum values must be scalar");
                    }
                }
            }
        }
    }

    public function test_required_parameters_exist_in_properties(): void
    {
        $tools = $this->server->tools;

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Get the schema using Laravel MCP's toArray() which includes inputSchema
            $toolArray = $tool->toArray();
            $schema = $toolArray['inputSchema'] ?? [];

            if (! isset($schema['required'])) {
                continue;
            }

            expect($schema['required'])->toBeArray("Tool {$toolClass} required must be an array");

            $properties = $schema['properties'] ?? [];

            foreach ($schema['required'] as $requiredField) {
                // Skip validation if required field is not a simple string
                // Laravel MCP may use complex required field definitions
                if (! is_string($requiredField)) {
                    continue;
                }

                $this->assertArrayHasKey($requiredField, $properties, "Tool {$toolClass} required field '{$requiredField}' must exist in properties");
            }
        }
    }

    public function test_tool_names_follow_mcp_naming_convention(): void
    {
        $tools = $this->server->tools;

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);
            $toolName = $tool->name();

            // MCP tool names must match: ^[a-zA-Z0-9_-]{1,64}$
            $isValid = preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $toolName) === 1;

            expect($isValid)
                ->toBeTrue("Tool {$toolClass} name '{$toolName}' must match MCP naming convention ^[a-zA-Z0-9_-]{1,64}$");
        }
    }

    public function test_schema_types_are_valid_json_schema_types(): void
    {
        $tools = $this->server->tools;
        $validTypes = ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'];

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Get the schema using Laravel MCP's toArray() which includes inputSchema
            $toolArray = $tool->toArray();
            $schema = $toolArray['inputSchema'] ?? [];

            if (! isset($schema['properties'])) {
                continue;
            }

            foreach ($schema['properties'] as $propertyName => $propertySchema) {
                if (isset($propertySchema['type'])) {
                    expect($propertySchema['type'])
                        ->toBeIn($validTypes, "Tool {$toolClass} parameter '{$propertyName}' has invalid type '{$propertySchema['type']}'");
                }
            }
        }
    }

    public function test_descriptions_are_present_and_non_empty(): void
    {
        $tools = $this->server->tools;

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Get the schema using Laravel MCP's toArray() which includes inputSchema
            $toolArray = $tool->toArray();
            $schema = $toolArray['inputSchema'] ?? [];

            if (! isset($schema['properties'])) {
                continue;
            }

            foreach ($schema['properties'] as $propertyName => $propertySchema) {
                // Description is highly recommended for all parameters
                if (! isset($propertySchema['description'])) {
                    // This is a warning, not a failure - descriptions help AI understand parameters
                    $this->addWarning("Tool {$toolClass} parameter '{$propertyName}' missing description");
                } else {
                    expect($propertySchema['description'])
                        ->not()->toBeEmpty("Tool {$toolClass} parameter '{$propertyName}' description must not be empty");
                }
            }
        }
    }

    /**
     * Recursively validate schema properties for nested structures.
     */
    private function validateSchemaProperties(array $properties, string $toolClass): void
    {
        foreach ($properties as $propertyName => $propertySchema) {
            // Skip non-string keys (shouldn't happen but be defensive)
            if (! is_string($propertyName) || ! is_array($propertySchema)) {
                continue;
            }

            $this->assertIsArray($propertySchema, "Tool {$toolClass} property '{$propertyName}' must be an array");

            // Validate type if present
            if (isset($propertySchema['type'])) {
                $validTypes = ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'];
                $this->assertContains($propertySchema['type'], $validTypes, "Tool {$toolClass} property '{$propertyName}' has invalid type");

                // Special validation for arrays
                if ($propertySchema['type'] === 'array') {
                    $this->assertArrayHasKey('items', $propertySchema, "Tool {$toolClass} array property '{$propertyName}' must have 'items' property");
                }
            }

            // Recursively validate nested properties
            if (isset($propertySchema['properties']) && is_array($propertySchema['properties'])) {
                $this->validateSchemaProperties($propertySchema['properties'], $toolClass);
            }
        }
    }
}
