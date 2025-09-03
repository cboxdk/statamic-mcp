<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Tests\TestCase;

class PromptsTest extends TestCase
{
    public function test_all_prompts_can_be_instantiated()
    {
        $server = app(StatamicMcpServer::class);
        $prompts = $server->prompts;

        expect($prompts)->not()->toBeEmpty();

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);
            expect($prompt)->toBeInstanceOf($promptClass);
        }
    }

    public function test_all_prompts_extend_prompt_base_class()
    {
        $server = app(StatamicMcpServer::class);
        $prompts = $server->prompts;

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);

            expect($prompt)->toBeInstanceOf(\Laravel\Mcp\Server\Prompt::class);
            expect(method_exists($prompt, 'handle'))->toBeTrue();
            expect(method_exists($prompt, 'arguments'))->toBeTrue();
        }
    }

    public function test_all_prompts_return_valid_data()
    {
        $server = app(StatamicMcpServer::class);
        $prompts = $server->prompts;

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);

            $name = $prompt->name();
            $description = $prompt->description();

            // Test handle method returns PromptResult
            $result = $prompt->handle([]);
            expect($result)->toBeInstanceOf(\Laravel\Mcp\Server\Prompts\PromptResult::class);

            $resultArray = $result->toArray();
            $content = $resultArray['messages'][0]['content']['text'];

            expect($name)->toBeString()->not()->toBeEmpty();
            expect($description)->toBeString()->not()->toBeEmpty();
            expect($content)->toBeString()->not()->toBeEmpty();

            // Name should be kebab-case for MCP consistency
            expect($name)->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');

            // Content should be substantial (more than just a placeholder)
            expect(strlen($content))->toBeGreaterThan(100);
        }
    }

    public function test_prompt_names_are_unique()
    {
        $server = app(StatamicMcpServer::class);
        $prompts = $server->prompts;

        $names = [];
        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);
            $name = $prompt->name();

            expect($names)->not()->toContain($name, "Duplicate prompt name found: {$name}");
            $names[] = $name;
        }
    }

    public function test_prompts_registration_format()
    {
        $server = app(StatamicMcpServer::class);

        // Test that prompts property exists and is an array
        expect($server)->toHaveProperty('prompts');
        expect($server->prompts)->toBeArray();

        // Each prompt should be a class string
        foreach ($server->prompts as $promptClass) {
            expect($promptClass)->toBeString();
            expect(class_exists($promptClass))->toBeTrue("Prompt class does not exist: {$promptClass}");
        }
    }

    public function test_prompt_content_quality()
    {
        $server = app(StatamicMcpServer::class);
        $prompts = $server->prompts;

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);
            $result = $prompt->handle([]);
            $resultArray = $result->toArray();
            $content = $resultArray['messages'][0]['content']['text'];

            // Should contain structured content
            expect($content)->toContain('#'); // Should have markdown headers

            // Should be substantial content, not just a placeholder
            expect(str_word_count($content))->toBeGreaterThan(50);

            // Should not contain placeholder text
            expect($content)->not()->toContain('TODO');
            expect($content)->not()->toContain('placeholder');
            expect($content)->not()->toContain('FIXME');
        }
    }

    public function test_statamic_specific_prompts_contain_relevant_content()
    {
        $server = app(StatamicMcpServer::class);
        $prompts = $server->prompts;

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);
            $result = $prompt->handle([]);
            $resultArray = $result->toArray();
            $content = $resultArray['messages'][0]['content']['text'];
            $name = $prompt->name();

            // All prompts should mention Statamic since this is a Statamic MCP server
            if (str_contains($name, 'statamic') || str_contains($name, 'fieldset') || str_contains($name, 'page-builder')) {
                expect(strtolower($content))->toMatch('/\b(statamic|antlers|blade|blueprint|fieldset|collection)\b/');
            }
        }
    }
}
