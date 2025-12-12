<?php

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\SystemRouter;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

describe('Refactored Tools', function () {

    describe('BaseStatamicTool', function () {
        it('is abstract and cannot be instantiated directly', function () {
            $reflection = new ReflectionClass(BaseStatamicTool::class);
            expect($reflection->isAbstract())->toBeTrue();
        });

        it('has required abstract methods', function () {
            $reflection = new ReflectionClass(BaseStatamicTool::class);
            $abstractMethods = array_map(fn ($method) => $method->getName(),
                array_filter($reflection->getMethods(), fn ($method) => $method->isAbstract())
            );

            expect($abstractMethods)->toContain('getToolName');
            expect($abstractMethods)->toContain('getToolDescription');
            expect($abstractMethods)->toContain('defineSchema');
            // Note: execute() is now protected, not abstract
        });
    });

    describe('SystemRouter', function () {
        beforeEach(function () {
            $this->router = new SystemRouter;
        });

        it('extends BaseStatamicTool', function () {
            expect($this->router)->toBeInstanceOf(BaseStatamicTool::class);
        });

        it('has correct tool details', function () {
            expect($this->router->name())->toBe('statamic-system');
            expect($this->router->description())->toContain('system operations');
        });

        it('defines schema correctly', function () {
            $schema = new JsonSchemaTypeFactory;
            $definedSchema = $this->router->schema($schema);

            expect($definedSchema)->toBeArray();
            expect($definedSchema)->toHaveKey('action');
        });

        it('handles system info action', function () {
            $result = $this->router->execute(['action' => 'info']);

            expect($result)->toBeArray();
            expect($result)->toHaveKey('success');
            expect($result['success'])->toBeTrue();
        });

        it('handles cache management', function () {
            $result = $this->router->execute([
                'action' => 'cache',
                'operation' => 'status',
            ]);

            expect($result)->toBeArray();
            expect($result)->toHaveKey('success');
        });

        it('requires valid action parameter', function () {
            $result = $this->router->execute(['action' => 'invalid_action']);

            expect($result)->toBeArray();
            expect($result)->toHaveKey('success');
            expect($result['success'])->toBeFalse();
        });
    });
});
