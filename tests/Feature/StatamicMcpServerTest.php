<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Tests\TestCase;

class StatamicMcpServerTest extends TestCase
{
    protected StatamicMcpServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new StatamicMcpServer;
    }

    public function test_mcp_server_can_be_instantiated()
    {
        expect($this->server)->toBeInstanceOf(StatamicMcpServer::class);
        expect($this->server->name())->toBeString();
        expect($this->server->version())->toBeString();
    }

    public function test_all_registered_tools_can_be_instantiated()
    {
        $tools = $this->server->tools;

        expect($tools)->toBeArray();
        expect(count($tools))->toBeGreaterThan(0);

        foreach ($tools as $toolClass) {
            // Test that the tool class exists and can be instantiated
            expect(class_exists($toolClass))
                ->toBeTrue("Tool class {$toolClass} does not exist");

            $tool = app($toolClass);

            expect($tool)
                ->toBeInstanceOf($toolClass);

            // Test that the tool has required methods
            expect(method_exists($tool, 'name'))->toBeTrue("Tool {$toolClass} missing name() method");
            expect(method_exists($tool, 'description'))->toBeTrue("Tool {$toolClass} missing description() method");
            expect(method_exists($tool, 'handle'))->toBeTrue("Tool {$toolClass} missing handle() method");

            // Test that tool methods return expected types
            expect($tool->name())->toBeString();
            expect($tool->description())->toBeString();
        }
    }

    public function test_all_registered_prompts_can_be_instantiated()
    {
        $prompts = $this->server->prompts;

        expect($prompts)->toBeArray();

        foreach ($prompts as $promptClass) {
            expect(class_exists($promptClass))
                ->toBeTrue("Prompt class {$promptClass} does not exist");

            $prompt = app($promptClass);

            expect($prompt)
                ->toBeInstanceOf($promptClass);
        }
    }

    public function test_mcp_server_tool_definitions_are_valid()
    {
        $tools = $this->server->tools;

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Test that each tool can provide its definition
            $toolName = $tool->name();
            $toolDescription = $tool->description();

            expect($toolName)
                ->not()->toBeEmpty("Tool {$toolClass} has empty name");

            expect($toolDescription)
                ->not()->toBeEmpty("Tool {$toolClass} has empty description");

            // Test that tool name follows expected pattern
            expect($toolName)
                ->toMatch('/^statamic\.[\w-]+\.[\w-]+(?:\.[\w-]+)?$/', "Tool {$toolClass} name '{$toolName}' does not follow expected pattern 'statamic.category.name' or 'statamic.category.subcategory.action'");
        }
    }

    public function test_no_duplicate_tool_names()
    {
        $tools = $this->server->tools;
        $toolNames = [];

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);
            $toolName = $tool->name();

            expect($toolNames)
                ->not()->toContain($toolName, "Duplicate tool name '{$toolName}' found for {$toolClass}");

            $toolNames[] = $toolName;
        }
    }

    public function test_tool_categories_are_properly_organized()
    {
        $tools = $this->server->tools;
        $expectedCategories = ['blueprints', 'collections', 'fieldsets', 'taxonomies', 'navigations', 'forms', 'entries', 'terms', 'globals', 'assets', 'groups', 'permissions', 'roles', 'sites', 'tags', 'modifiers', 'fieldtypes', 'scopes', 'filters', 'development', 'system', 'users'];
        $foundCategories = [];

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);
            $toolName = $tool->name();

            // Extract category from tool name (e.g., 'statamic.structures.blueprints' -> 'structures')
            $parts = explode('.', $toolName);
            expect(count($parts))->toBeIn([3, 4], "Tool name '{$toolName}' should have 3 or 4 parts separated by dots");

            $category = $parts[1];
            $foundCategories[] = $category;

            expect($category)
                ->toBeIn($expectedCategories, "Unknown tool category '{$category}' in tool '{$toolName}'. Available categories: " . implode(', ', $expectedCategories));
        }

        $uniqueFoundCategories = array_unique($foundCategories);

        // Ensure we have tools in all expected categories
        foreach ($expectedCategories as $expectedCategory) {
            expect(in_array($expectedCategory, $foundCategories))
                ->toBeTrue("No tools found in expected category '{$expectedCategory}'. Found categories: " . implode(', ', $uniqueFoundCategories));
        }
    }
}
