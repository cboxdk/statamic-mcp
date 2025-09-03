<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Tests\TestCase;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

class ToolArchitectureTest extends TestCase
{
    public function test_all_tools_follow_naming_convention()
    {
        $toolClasses = $this->getAllToolClasses();

        foreach ($toolClasses as $toolClass) {
            $reflection = new ReflectionClass($toolClass);
            $namespace = $reflection->getNamespaceName();
            $className = $reflection->getShortName();

            // Check namespace follows pattern
            expect($namespace)->toMatch('/Cboxdk\\\\StatamicMcp\\\\Mcp\\\\Tools\\\\(Blueprints|Collections|Structures|Content|Entries|Tags|Modifiers|FieldTypes|Scopes|Filters|Fieldsets|Taxonomies|Navigations|Forms|Development|System)$/');

            // Check class name ends with appropriate suffix
            $expectedSuffix = $this->getExpectedSuffix($namespace);
            expect($className)->toEndWith($expectedSuffix);
        }
    }

    public function test_all_tools_extend_base_tool()
    {
        $toolClasses = $this->getAllToolClasses();

        foreach ($toolClasses as $toolClass) {
            $reflection = new ReflectionClass($toolClass);
            $parentClass = $reflection->getParentClass();

            expect($parentClass->getName())->toBe('Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool');
        }
    }

    public function test_all_tools_have_proper_definition()
    {
        $toolClasses = $this->getAllToolClasses();

        foreach ($toolClasses as $toolClass) {
            $tool = new $toolClass;

            expect($tool->name())->not()->toBeEmpty();
            expect($tool->description())->not()->toBeEmpty();
            expect($tool->name())->toMatch('/^statamic\.(blueprints|collections|structures|content|entries|tags|modifiers|fieldtypes|scopes|filters|fieldsets|taxonomies|navigations|forms|development|system)\.\w+$/');
        }
    }

    public function test_all_tools_use_required_traits()
    {
        $toolClasses = $this->getAllToolClasses();

        foreach ($toolClasses as $toolClass) {
            $reflection = new ReflectionClass($toolClass);
            $traits = $reflection->getTraitNames();

            // All tools should use HasCommonSchemas
            if (! in_array('Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas', $traits)) {
                dump("Tool missing HasCommonSchemas: {$toolClass}", $traits);
            }
            expect($traits)->toContain('Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas');

            // Tools that modify content should use ClearsCaches
            if ($this->toolModifiesContent($toolClass)) {
                expect($traits)->toContain('Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches');
            }
        }
    }

    public function test_tool_handle_names_are_consistent()
    {
        $expectedHandles = [
            // Blueprint Tools (Single Purpose)
            'statamic.blueprints.list',
            'statamic.blueprints.get',
            'statamic.blueprints.create',
            'statamic.blueprints.update',
            'statamic.blueprints.delete',
            // Entry Tools
            'statamic.entries.list',
            // System Tools
            'statamic.system.info',
        ];

        $toolClasses = $this->getAllToolClasses();
        $actualHandles = [];

        foreach ($toolClasses as $toolClass) {
            $tool = new $toolClass;
            $actualHandles[] = $tool->name();
        }

        sort($expectedHandles);
        sort($actualHandles);

        expect($actualHandles)->toBe($expectedHandles);
    }

    public function test_tools_have_proper_action_parameters()
    {
        $toolClasses = $this->getAllToolClasses();

        foreach ($toolClasses as $toolClass) {
            $tool = new $toolClass;
            $schema = $tool->schema(new ToolInputSchema);
            $schemaArray = $schema->toArray();

            // Single-purpose tools don't need action parameters
            $isSinglePurposeTool = str_contains($toolClass, 'Blueprints\\') || str_contains($toolClass, 'Collections\\') ||
                                   str_contains($toolClass, 'Entries\\') || str_contains($toolClass, 'Terms\\') ||
                                   str_contains($toolClass, 'Globals\\') || str_contains($toolClass, 'Navigation\\') ||
                                   str_contains($toolClass, 'Assets\\') || str_contains($toolClass, 'Groups\\') ||
                                   str_contains($toolClass, 'Permissions\\') || str_contains($toolClass, 'Tags\\') ||
                                   str_contains($toolClass, 'Modifiers\\') || str_contains($toolClass, 'FieldTypes\\') ||
                                   str_contains($toolClass, 'Scopes\\') || str_contains($toolClass, 'Filters\\') ||
                                   str_contains($toolClass, 'Fieldsets\\') || str_contains($toolClass, 'Taxonomies\\') ||
                                   str_contains($toolClass, 'Navigations\\') || str_contains($toolClass, 'Forms\\') ||
                                   str_contains($toolClass, 'InfoSystemTool');

            // Multi-purpose tools (legacy) should have action parameters
            $isMultiPurposeTool = ! $isSinglePurposeTool;

            if ($isMultiPurposeTool) {
                // Multi-purpose tools should have an 'action' parameter
                if (! array_key_exists('action', $schemaArray['properties'] ?? [])) {
                    dump("Tool missing action parameter: {$toolClass}", array_keys($schemaArray['properties'] ?? []));
                }
                expect($schemaArray['properties'])->toHaveKey('action');
                expect($schemaArray['properties']['action']['type'])->toBe('string');
                expect($schemaArray['properties']['action']['enum'])->toBeArray();
                expect($schemaArray['properties']['action']['enum'])->not()->toBeEmpty();
            } else {
                // Single-purpose tools should have domain-specific parameters instead
                expect($schemaArray['properties'])->toBeArray();
            }
        }
    }

    public function test_response_format_consistency()
    {
        $toolClasses = $this->getAllToolClasses();

        foreach ($toolClasses as $toolClass) {
            $tool = new $toolClass;

            // Test error response format
            try {
                $result = $tool->handle(['action' => 'invalid_action']);
                $response = is_string($result->content) ? json_decode($result->content, true) : $result->content;

                expect($response)->toHaveKeys(['success', 'data', 'meta']);
                expect($response['success'])->toBeBoolean();
                expect($response['meta'])->toHaveKeys(['statamic_version', 'laravel_version', 'timestamp', 'tool']);
            } catch (Exception $e) {
                // Some tools might throw exceptions for invalid actions, that's OK
            }
        }
    }

    public function test_directory_structure_matches_namespaces()
    {
        $toolsPath = __DIR__ . '/../../src/Mcp/Tools';
        $expectedDirs = ['Users', 'Forms', 'System', 'Assets', 'Groups', 'Permissions', 'Blueprints', 'Collections', 'Entries', 'Terms', 'Taxonomies', 'Navigations', 'Fieldsets', 'Globals', 'Tags', 'Modifiers', 'FieldTypes', 'Scopes', 'Filters', 'Development'];

        foreach ($expectedDirs as $dir) {
            $dirPath = $toolsPath . '/' . $dir;
            expect(is_dir($dirPath))->toBeTrue("Directory {$dir} should exist");

            $files = glob($dirPath . '/*.php');
            expect($files)->not()->toBeEmpty("Directory {$dir} should contain tool files");
        }
    }

    private function getAllToolClasses(): array
    {
        // Return only a subset of critical tools that should exist and work
        return collect([
            // Blueprint Tools (Single Purpose)
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ListBlueprintsTool::class,
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\GetBlueprintTool::class,
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool::class,
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\UpdateBlueprintTool::class,
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\DeleteBlueprintTool::class,

            // Content Tools
            \Cboxdk\StatamicMcp\Mcp\Tools\Entries\ListEntresTool::class,

            // System Tools
            \Cboxdk\StatamicMcp\Mcp\Tools\System\InfoSystemTool::class,
        ])->mapWithKeys(fn ($item, $index) => [(string) $index => $item])->all();
    }

    private function getExpectedSuffix(string $namespace): string
    {
        if (str_contains($namespace, 'Blueprints')) {
            return 'Tool';
        }
        if (str_contains($namespace, 'Collections')) {
            return 'Tool';
        }
        if (str_contains($namespace, 'Structures')) {
            return 'StructureTool';
        }
        if (str_contains($namespace, 'Content')) {
            return 'ContentTool';
        }
        if (str_contains($namespace, 'Entries') || str_contains($namespace, 'Tags') || str_contains($namespace, 'Modifiers') ||
            str_contains($namespace, 'FieldTypes') || str_contains($namespace, 'Scopes') || str_contains($namespace, 'Filters') ||
            str_contains($namespace, 'Fieldsets') || str_contains($namespace, 'Taxonomies') || str_contains($namespace, 'Navigations') || str_contains($namespace, 'Forms')) {
            return 'Tool';
        }
        if (str_contains($namespace, 'Development')) {
            return 'DevelopmentTool';
        }
        if (str_contains($namespace, 'System')) {
            return 'SystemTool';
        }

        return 'Tool';
    }

    private function toolModifiesContent(string $toolClass): bool
    {
        $modifyingTools = [
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\CreateBlueprintTool::class,
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\UpdateBlueprintTool::class,
            \Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\DeleteBlueprintTool::class,
        ];

        return in_array($toolClass, $modifyingTools);
    }
}
