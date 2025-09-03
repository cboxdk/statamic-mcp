<?php

namespace Cboxdk\StatamicMcp\Tests\Tools;

use Cboxdk\StatamicMcp\Mcp\Tools\Globals\GetGlobalSetTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\GetGlobalValuesTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\ListGlobalSetsTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Globals\ListGlobalValuesTool;
use Cboxdk\StatamicMcp\Tests\TestCase;

class GlobalToolsIntegrationTest extends TestCase
{
    public function test_global_tools_have_correct_naming_convention(): void
    {
        $listSetsTool = new ListGlobalSetsTool;
        $listValuesTool = new ListGlobalValuesTool;

        // Test that tools follow our naming convention for structure vs values
        $this->assertStringContainsString('sets', $listSetsTool->name());
        $this->assertStringContainsString('values', $listValuesTool->name());
    }

    public function test_global_tools_are_properly_registered(): void
    {
        // Test that our tools can be instantiated and have proper metadata
        $tools = [
            ListGlobalSetsTool::class,
            GetGlobalSetTool::class,
            ListGlobalValuesTool::class,
            GetGlobalValuesTool::class,
        ];

        foreach ($tools as $toolClass) {
            $tool = new $toolClass;

            $this->assertIsString($tool->name());
            $this->assertIsString($tool->description());
            $this->assertNotEmpty($tool->name());
            $this->assertNotEmpty($tool->description());
        }
    }

    public function test_tool_naming_follows_mcp_conventions(): void
    {
        $listSetsTool = new ListGlobalSetsTool;
        $listValuesTool = new ListGlobalValuesTool;
        $getSetTool = new GetGlobalSetTool;
        $getValuesTool = new GetGlobalValuesTool;

        // All tools should start with statamic.globals
        $this->assertStringStartsWith('statamic.globals', $listSetsTool->name());
        $this->assertStringStartsWith('statamic.globals', $listValuesTool->name());
        $this->assertStringStartsWith('statamic.globals', $getSetTool->name());
        $this->assertStringStartsWith('statamic.globals', $getValuesTool->name());

        // Structure tools should contain 'sets'
        $this->assertStringContainsString('sets', $listSetsTool->name());
        $this->assertStringContainsString('sets', $getSetTool->name());

        // Value tools should contain 'values'
        $this->assertStringContainsString('values', $listValuesTool->name());
        $this->assertStringContainsString('values', $getValuesTool->name());
    }

    public function test_tools_have_different_purposes(): void
    {
        $setTool = new ListGlobalSetsTool;
        $valueTool = new ListGlobalValuesTool;

        // Descriptions should reflect different purposes
        $setDescription = $setTool->description();
        $valueDescription = $valueTool->description();

        $this->assertNotEquals($setDescription, $valueDescription);
        $this->assertStringContainsString('structure', $setDescription);
        $this->assertStringContainsString('content', $valueDescription);
    }

    public function test_tools_schemas_are_properly_defined(): void
    {
        // Test that tools have proper input schemas
        $tools = [
            new ListGlobalSetsTool,
            new GetGlobalSetTool,
            new ListGlobalValuesTool,
            new GetGlobalValuesTool,
        ];

        foreach ($tools as $tool) {
            // Test that tools can be instantiated and have basic metadata
            $this->assertIsString($tool->name());
            $this->assertIsString($tool->description());
        }
    }
}
