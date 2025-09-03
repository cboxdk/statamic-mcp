<?php

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Laravel\Mcp\Server\Prompts\Arguments;
use Laravel\Mcp\Server\Prompts\PromptResult;

class StatamicWorkflowPrompt extends Prompt
{
    protected string $description = 'Step-by-step workflow guidance for common Statamic development tasks';

    public function arguments(): Arguments
    {
        return (new Arguments)
            ->add(new Argument(
                name: 'task_type',
                description: 'Type of task: blueprint-design, content-structure, template-development, or addon-creation',
                required: false,
            ))
            ->add(new Argument(
                name: 'complexity',
                description: 'Project complexity: simple, medium, or complex',
                required: false,
            ))
            ->add(new Argument(
                name: 'statamic_version',
                description: 'Statamic version: v4, v5, or v6 (auto-detected if not specified)',
                required: false,
            ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): PromptResult
    {
        $taskType = $arguments['task_type'] ?? 'general';
        $complexity = $arguments['complexity'] ?? 'medium';
        $statamicVersion = $arguments['statamic_version'] ?? $this->detectStatamicVersion();

        $content = "# Statamic {$statamicVersion} Development Workflow\n\n";
        $content .= "Follow this systematic approach for Statamic {$statamicVersion} development:\n\n";

        // Add version-specific workflow considerations
        $content .= $this->getVersionWorkflowGuidance($statamicVersion);

        if ($taskType === 'blueprint-design' || $taskType === 'general') {
            $content .= "## 1. Blueprint & Content Architecture\n";
            $content .= "### Analysis Phase\n";
            $content .= "- Use `statamic.system.info` to understand the current setup\n";
            $content .= "- Run `statamic.blueprints.scan` to analyze existing blueprints\n";
            $content .= "- Check `statamic.fieldsets.scan` for reusable components\n";
            $content .= "- Review `statamic.fieldtypes.list` for available field options\n\n";

            $content .= "### Design Phase\n";
            $content .= "- Plan your content relationships and taxonomy structure\n";
            $content .= "- Identify reusable fieldsets for common field groups\n";
            $content .= "- Design blueprints with proper validation and conditional logic\n";
            $content .= "- Use `statamic.blueprints.generate` for complex structures\n\n";

            $content .= "### Validation Phase\n";
            $content .= "- Generate TypeScript types with `statamic.blueprints.types`\n";
            $content .= "- Test blueprint structure with sample content\n";
            $content .= "- Validate field relationships and data integrity\n\n";
        }

        if ($taskType === 'template-development' || $taskType === 'general') {
            $content .= "## 2. Template Development\n";
            $content .= "### Discovery Phase\n";
            $content .= "- Use `statamic.docs.search` to find relevant documentation\n";
            $content .= "- Run `statamic.tags.scan` to discover available tags and parameters\n";
            $content .= "- Check `statamic.addons.scan` for additional functionality\n\n";

            $content .= "### Development Phase\n";
            $content .= "- Start with `statamic.antlers.hints` or `statamic.blade.hints` for context\n";
            $content .= "- Implement templates using available variables and tags\n";
            $content .= "- Use partials and layouts for maintainable code\n\n";

            $content .= "### Validation Phase\n";
            $content .= "- Lint templates with `statamic.antlers.lint` or `statamic.blade.lint`\n";
            $content .= "- Test with different content scenarios\n";
            $content .= "- Validate performance with caching strategies\n\n";
        }

        if ($taskType === 'content-structure' || $taskType === 'general') {
            $content .= "## 3. Content Management Setup\n";
            $content .= "### Content Architecture\n";
            $content .= "- Use `statamic.content.extract` to analyze current content\n";
            $content .= "- Plan collections, taxonomies, and navigation structures\n";
            $content .= "- Set up proper asset containers with `statamic.assets.containers`\n\n";

            $content .= "### Content Creation\n";
            $content .= "- Create initial content with `statamic.content.manage`\n";
            $content .= "- Organize assets with `statamic.assets.manage`\n";
            $content .= "- Test content relationships and references\n\n";
        }

        if ($complexity === 'complex') {
            $content .= "## Advanced Workflow Considerations\n";
            $content .= "- Implement comprehensive caching strategy with `statamic.cache.manage`\n";
            $content .= "- Plan for multi-site and localization requirements\n";
            $content .= "- Set up proper deployment and content sync processes\n";
            $content .= "- Implement performance monitoring and optimization\n\n";
        }

        $content .= "## General Development Flow\n";
        $content .= "1. **Analyze**: Understand current setup and requirements\n";
        $content .= "2. **Plan**: Design architecture and content structure\n";
        $content .= "3. **Implement**: Build blueprints, templates, and content\n";
        $content .= "4. **Validate**: Test functionality and performance\n";
        $content .= "5. **Optimize**: Refine based on testing and feedback\n";
        $content .= "6. **Document**: Record decisions and maintenance procedures\n\n";

        $content .= 'Remember to use the MCP tools throughout each phase for analysis, validation, and optimization.';

        return new PromptResult(
            content: $content,
            description: "Structured workflow for {$taskType} development in Statamic {$statamicVersion}"
        );
    }

    /**
     * Detect Statamic version from the system.
     */
    private function detectStatamicVersion(): string
    {
        try {
            if (class_exists('Statamic\Statamic')) {
                $version = \Statamic\Statamic::version();

                if (version_compare($version, '6.0', '>=')) {
                    return 'v6';
                } elseif (version_compare($version, '5.0', '>=')) {
                    return 'v5';
                } elseif (version_compare($version, '4.0', '>=')) {
                    return 'v4';
                }
            }
        } catch (\Exception $e) {
            // Fallback - assume v5 as it's the current stable
        }

        return 'v5';
    }

    /**
     * Get version-specific workflow guidance.
     */
    private function getVersionWorkflowGuidance(string $version): string
    {
        $guidance = '';

        switch ($version) {
            case 'v6':
                $guidance .= "## ⚡ v6 Workflow Adaptations\n";
                $guidance .= "- **Control Panel Testing**: Test UI changes with Vue 3 components\n";
                $guidance .= "- **Addon Migration**: Update addons for Vue 3 compatibility\n";
                $guidance .= "- **UI Kit Usage**: Use new UI Kit components for consistency\n";
                $guidance .= "- **Breaking Changes**: Review and adapt to v6 breaking changes\n\n";
                break;

            case 'v5':
                $guidance .= "## ✅ v5 Standard Workflow\n";
                $guidance .= "- **Modern Tooling**: Use current Laravel and PHP 8.1+ features\n";
                $guidance .= "- **Testing Suite**: Leverage Statamic's comprehensive testing utilities\n";
                $guidance .= "- **Performance**: Implement modern caching and optimization strategies\n\n";
                break;

            case 'v4':
                $guidance .= "## ⚠️ v4 Legacy Workflow Considerations\n";
                $guidance .= "- **Compatibility**: Ensure compatibility with older dependencies\n";
                $guidance .= "- **Upgrade Planning**: Consider migration path to newer versions\n";
                $guidance .= "- **Limited Features**: Work within v4 feature constraints\n\n";
                break;
        }

        return $guidance;
    }
}
