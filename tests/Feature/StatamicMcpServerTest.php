<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Laravel\Mcp\Server\Contracts\Transport;
use Mockery\MockInterface;

class StatamicMcpServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Transport contract that StatamicMcpServer depends on
        $this->app->bind(Transport::class, function () {
            return $this->mock(Transport::class, function (MockInterface $mock) {
                $mock->shouldReceive('write')->andReturn(true);
                $mock->shouldReceive('read')->andReturn('');
                $mock->shouldReceive('close')->andReturn(true);
            });
        });
    }

    protected function getServer(): StatamicMcpServer
    {
        return app(StatamicMcpServer::class);
    }

    public function test_mcp_server_can_be_instantiated()
    {
        $server = $this->getServer();
        expect($server)->toBeInstanceOf(StatamicMcpServer::class);
        expect($server->name())->toBeString();
        expect($server->version())->toBeString();
    }

    public function test_all_registered_tools_can_be_instantiated()
    {
        $server = $this->getServer();
        $tools = $server->tools;

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
            // Note: handle() method is provided by base classes in Laravel MCP v0.2.0

            // Test that tool methods return expected types
            expect($tool->name())->toBeString();
            expect($tool->description())->toBeString();
        }
    }

    public function test_all_registered_prompts_can_be_instantiated()
    {
        $server = $this->getServer();
        $prompts = $server->prompts;

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
        $server = $this->getServer();
        $tools = $server->tools;

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);

            // Test that each tool can provide its definition
            $toolName = $tool->name();
            $toolDescription = $tool->description();

            expect($toolName)
                ->not()->toBeEmpty("Tool {$toolClass} has empty name");

            expect($toolDescription)
                ->not()->toBeEmpty("Tool {$toolClass} has empty description");

            // Test that tool name follows expected pattern (hyphen-based routing)
            // Pattern: statamic-domain or statamic-domain-action
            $isValidPattern = preg_match('/^statamic-[\w-]+$/', $toolName) === 1;

            expect($isValidPattern)
                ->toBeTrue("Tool {$toolClass} name '{$toolName}' does not follow expected pattern 'statamic-domain' or 'statamic-domain-action'");
        }
    }

    public function test_no_duplicate_tool_names()
    {
        $server = $this->getServer();
        $tools = $server->tools;
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
        $server = $this->getServer();
        $tools = $server->tools;
        // Updated for hyphen-based naming: statamic-domain or statamic-domain-action
        $expectedCategories = ['content', 'entries', 'terms', 'globals', 'content-facade', 'structures', 'assets', 'users', 'system', 'system-discover', 'system-schema', 'blueprints'];
        $foundCategories = [];

        foreach ($tools as $toolClass) {
            $tool = app($toolClass);
            $toolName = $tool->name();

            // Extract category from tool name (everything after 'statamic-')
            if (str_starts_with($toolName, 'statamic-')) {
                $category = substr($toolName, strlen('statamic-'));
                $foundCategories[] = $category;

                expect($category)
                    ->toBeIn($expectedCategories, "Unknown tool category '{$category}' in tool '{$toolName}'. Available categories: " . implode(', ', $expectedCategories));
            } else {
                $this->fail("Tool name '{$toolName}' should start with 'statamic-'");
            }
        }

        $uniqueFoundCategories = array_unique($foundCategories);

        // Ensure we have tools in most expected categories (relaxed for router architecture)
        $coreCategories = ['content', 'system', 'blueprints'];
        foreach ($coreCategories as $coreCategory) {
            expect(in_array($coreCategory, $foundCategories))
                ->toBeTrue("No tools found in core category '{$coreCategory}'. Found categories: " . implode(', ', $uniqueFoundCategories));
        }
    }
}
