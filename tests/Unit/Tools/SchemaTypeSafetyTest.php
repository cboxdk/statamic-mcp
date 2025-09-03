<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Services\SchemaIntrospectionService;
use Cboxdk\StatamicMcp\Mcp\Tools\Filters\ListFiltersTool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

test('schema introspection service uses compatible schema type', function () {
    $service = new SchemaIntrospectionService;
    $mockSchema = $service->createMockSchema();
    $tool = new ListFiltersTool;

    // This should not throw a type error
    expect(function () use ($tool, $mockSchema) {
        $reflection = new ReflectionClass($tool);
        $method = $reflection->getMethod('defineSchema');
        $method->setAccessible(true);

        // This is where the type error occurs - MockToolSchema is passed
        // but defineSchema expects ToolInputSchema
        $result = $method->invoke($tool, $mockSchema);

        return $result;
    })->not->toThrow(TypeError::class);
});

test('mock schema implements expected interface methods', function () {
    $service = new SchemaIntrospectionService;
    $mockSchema = $service->createMockSchema();

    // Test that MockToolSchema has the same methods as ToolInputSchema
    $mockMethods = get_class_methods($mockSchema);
    $expectedMethods = ['string', 'integer', 'boolean', 'number', 'description', 'optional', 'required'];

    foreach ($expectedMethods as $method) {
        expect($mockMethods)->toContain($method);
    }
});

test('list filters tool can be introspected', function () {
    $service = new SchemaIntrospectionService;
    $tool = new ListFiltersTool;

    // This should work without type errors
    $schema = $service->extractToolSchema($tool);

    expect($schema)->toHaveKey('parameters');
    expect($schema)->toHaveKey('parameter_count');
    expect($schema['parameters'])->toBeArray();
});

test('original type error scenario is resolved', function () {
    // This test specifically targets the original error:
    // "Argument #1 ($schema) must be of type Laravel\Mcp\Server\Tools\ToolInputSchema,
    // Cboxdk\StatamicMcp\Mcp\Services\MockToolSchema given"

    $service = new SchemaIntrospectionService;
    $tool = new ListFiltersTool;
    $mockSchema = $service->createMockSchema();

    // This should not throw a TypeError anymore
    expect(function () use ($tool, $mockSchema) {
        $reflection = new ReflectionClass($tool);
        $method = $reflection->getMethod('defineSchema');
        $method->setAccessible(true);

        // The critical test: MockToolSchema should be accepted as ToolInputSchema
        $result = $method->invoke($tool, $mockSchema);

        return $result;
    })->not->toThrow(TypeError::class);

    // Verify the mock schema captured field definitions
    expect($mockSchema->fields)->toBeArray();
});
