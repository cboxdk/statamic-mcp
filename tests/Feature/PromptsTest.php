<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Prompts\AgentEducationPrompt;
use Cboxdk\StatamicMcp\Mcp\Prompts\ToolUsageContractPrompt;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Laravel\Mcp\Server\Prompt;

class PromptsTest extends TestCase
{
    private function getPromptClasses(): array
    {
        return [
            AgentEducationPrompt::class,
            ToolUsageContractPrompt::class,
        ];
    }

    public function test_all_prompts_can_be_instantiated()
    {
        $prompts = $this->getPromptClasses();

        expect($prompts)->not()->toBeEmpty();

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);
            expect($prompt)->toBeInstanceOf($promptClass);
        }
    }

    public function test_all_prompts_extend_prompt_base_class()
    {
        $prompts = $this->getPromptClasses();

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);

            expect($prompt)->toBeInstanceOf(\Laravel\Mcp\Server\Prompt::class);
            expect(method_exists($prompt, 'prompt'))->toBeTrue();
            expect(method_exists($prompt, 'arguments'))->toBeTrue();
        }
    }

    public function test_all_prompts_return_valid_data()
    {
        $prompts = $this->getPromptClasses();

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);

            $name = $prompt->name();
            $description = $prompt->description();

            // Test prompt method returns string content
            $content = $prompt->prompt();

            expect($name)->toBeString()->not()->toBeEmpty();
            expect($description)->toBeString()->not()->toBeEmpty();
            expect($content)->toBeString()->not()->toBeEmpty();

            // Name should be snake_case for MCP consistency
            expect($name)->toMatch('/^[a-z0-9]+(_[a-z0-9]+)*$/');

            // Content should be substantial (more than just a placeholder)
            expect(strlen($content))->toBeGreaterThan(100);
        }
    }

    public function test_prompt_names_are_unique()
    {
        $prompts = $this->getPromptClasses();

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
        $prompts = $this->getPromptClasses();

        // Test that prompts array is not empty
        expect($prompts)->toBeArray()->not()->toBeEmpty();

        // Each prompt should be a class string
        foreach ($prompts as $promptClass) {
            expect($promptClass)->toBeString();
            expect(class_exists($promptClass))->toBeTrue("Prompt class does not exist: {$promptClass}");
        }
    }

    public function test_prompt_content_quality()
    {
        $prompts = $this->getPromptClasses();

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);
            $content = $prompt->prompt();

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
        $prompts = $this->getPromptClasses();

        foreach ($prompts as $promptClass) {
            $prompt = app($promptClass);
            $content = $prompt->prompt();
            $name = $prompt->name();

            // All prompts should mention Statamic since this is a Statamic MCP server
            if (str_contains($name, 'statamic') || str_contains($name, 'agent') || str_contains($name, 'tool')) {
                expect(strtolower($content))->toMatch('/\b(statamic|antlers|blade|blueprint|fieldset|collection)\b/');
            }
        }
    }
}
