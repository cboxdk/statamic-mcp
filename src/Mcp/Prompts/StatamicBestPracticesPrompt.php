<?php

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Laravel\Mcp\Server\Prompts\Arguments;
use Laravel\Mcp\Server\Prompts\PromptResult;

class StatamicBestPracticesPrompt extends Prompt
{
    protected string $description = 'Comprehensive guidance on Statamic development best practices, conventions, and architectural patterns';

    public function arguments(): Arguments
    {
        return (new Arguments)
            ->add(new Argument(
                name: 'context',
                description: 'The development context: templates, content-modeling, addons, or performance',
                required: false,
            ))
            ->add(new Argument(
                name: 'template_engine',
                description: 'Preferred template engine: antlers or blade',
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
        $context = $arguments['context'] ?? 'general';
        $templateEngine = $arguments['template_engine'] ?? 'antlers';
        $statamicVersion = $arguments['statamic_version'] ?? $this->detectStatamicVersion();

        $content = "# Statamic Development Best Practices\n\n";
        $content .= "You are working with Statamic CMS {$statamicVersion}. Follow these version-specific best practices:\n\n";

        // Add version-specific warnings and guidance
        $content .= $this->getVersionSpecificGuidance($statamicVersion);

        if ($context === 'templates' || $context === 'general') {
            $content .= "## Template Development\n";

            if ($templateEngine === 'antlers') {
                $content .= "- **Use Antlers templating** for better Statamic integration and performance\n";
                $content .= "- Leverage Statamic's built-in tags like `{{ collection }}`, `{{ nav }}`, `{{ assets }}`\n";
                $content .= "- Use modifiers for data transformation: `{{ title | upper }}`, `{{ content | markdown }}`\n";
                $content .= "- Implement proper variable scoping with `{{ scope:variable }}`\n";
                $content .= "- Use partials and layouts to avoid code duplication\n\n";
            } else {
                $content .= "- **Use Blade with Statamic components** when Blade is preferred\n";
                $content .= "- Avoid inline PHP, facades, and direct database calls in templates\n";
                $content .= "- Use Statamic's Blade directives: `@statamic:collection`, `@statamic:nav`\n";
                $content .= "- Prefer Statamic components over raw PHP logic\n";
                $content .= "- Use view models or composers for complex data preparation\n\n";
            }
        }

        if ($context === 'content-modeling' || $context === 'general') {
            $content .= "## Content Modeling\n";
            $content .= "- Design blueprints with clear field relationships and validation\n";
            $content .= "- Use fieldsets for reusable field groups across blueprints\n";
            $content .= "- Implement proper handle naming: snake_case for fields, kebab-case for collections\n";
            $content .= "- Leverage conditional fields to keep interfaces clean\n";
            $content .= "- Use taxonomies for categorization, not custom select fields\n";
            $content .= "- Plan for localization with multi-site considerations\n\n";
        }

        if ($context === 'performance' || $context === 'general') {
            $content .= "## Performance Optimization\n";
            $content .= "- Enable static caching for high-traffic pages\n";
            $content .= "- Use eager loading with `with()` parameter in collection tags\n";
            $content .= "- Optimize asset handling with Glide transformations\n";
            $content .= "- Implement proper cache invalidation strategies\n";
            $content .= "- Use `limit` parameters to avoid loading excessive entries\n\n";
        }

        if ($context === 'addons' || $context === 'general') {
            $content .= "## Addon Development\n";
            $content .= $this->getAddonGuidance($statamicVersion);
        }

        $content .= "## General Guidelines\n";
        $content .= "- Always validate user input and sanitize output\n";
        $content .= "- Use Statamic's built-in features before creating custom solutions\n";
        $content .= "- Follow Laravel conventions for controllers, models, and services\n";
        $content .= "- Implement proper error handling and logging\n";
        $content .= "- Document your code and provide examples\n";
        $content .= "- Test your implementations thoroughly\n\n";

        $content .= 'Use the available MCP tools to analyze your codebase and validate implementations.';

        return new PromptResult(
            content: $content,
            description: "Statamic {$statamicVersion} best practices for {$context} development"
        );
    }

    /**
     * Detect Statamic version from the system.
     */
    private function detectStatamicVersion(): string
    {
        try {
            // Use the MCP tool to get system information
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
     * Get version-specific guidance and warnings.
     */
    private function getVersionSpecificGuidance(string $version): string
    {
        $guidance = '';

        switch ($version) {
            case 'v6':
                $guidance .= "## ⚡ Statamic v6 Specific Considerations\n";
                $guidance .= "- **Control Panel**: Upgraded to Vue 3 with new UI Kit components\n";
                $guidance .= "- **Addon Development**: Update your addons to support Vue 3 and new UI components\n";
                $guidance .= "- **Control Panel Customization**: Use the new UI Kit for consistent styling\n";
                $guidance .= "- **Breaking Changes**: Review the v6 upgrade guide for breaking changes\n";
                $guidance .= "- **Performance**: Take advantage of Vue 3 performance improvements\n";
                $guidance .= "- **Testing**: Update your tests to work with the new control panel architecture\n\n";
                break;

            case 'v5':
                $guidance .= "## ✅ Statamic v5 Current Best Practices\n";
                $guidance .= "- **Laravel Compatibility**: Full Laravel 10+ support with modern features\n";
                $guidance .= "- **PHP Requirements**: Minimum PHP 8.1, leverage modern PHP features\n";
                $guidance .= "- **Addon Development**: Use current addon structure and service providers\n";
                $guidance .= "- **Performance**: Utilize improved caching and static site generation\n";
                $guidance .= "- **Testing**: Use Statamic's testing utilities and factories\n\n";
                break;

            case 'v4':
                $guidance .= "## ⚠️ Statamic v4 Legacy Considerations\n";
                $guidance .= "- **Upgrade Planning**: Consider upgrading to v5 for better support and features\n";
                $guidance .= "- **Laravel Version**: Limited to older Laravel versions\n";
                $guidance .= "- **PHP Compatibility**: May have PHP version limitations\n";
                $guidance .= "- **Feature Limitations**: Some newer features not available\n";
                $guidance .= "- **Security**: Ensure you have the latest v4 security patches\n\n";
                break;
        }

        if ($version === 'v6') {
            $guidance .= "### Control Panel Development (v6)\n";
            $guidance .= "- Use the new UI Kit components for consistent control panel integration\n";
            $guidance .= "- Migrate Vue 2 components to Vue 3 composition API\n";
            $guidance .= "- Test control panel customizations thoroughly after Vue 3 upgrade\n";
            $guidance .= "- Follow the new component patterns and naming conventions\n\n";
        }

        if (in_array($version, ['v5', 'v6'])) {
            $guidance .= "### Modern Development Features\n";
            $guidance .= "- Leverage Laravel's modern features (queues, jobs, notifications)\n";
            $guidance .= "- Use PHP 8+ features (enums, attributes, named arguments)\n";
            $guidance .= "- Implement proper type declarations and return types\n";
            $guidance .= "- Use Statamic's modern testing utilities\n\n";
        }

        return $guidance;
    }

    /**
     * Get version-specific addon development guidance.
     */
    private function getAddonGuidance(string $version): string
    {
        $guidance = '';

        // Common addon guidance
        $guidance .= "- Follow Statamic's addon conventions and file structure\n";
        $guidance .= "- Use proper service provider registration\n";
        $guidance .= "- Implement tags, modifiers, and fieldtypes following Statamic patterns\n";
        $guidance .= "- Provide comprehensive configuration options\n";
        $guidance .= "- Write tests using Statamic's testing utilities\n";

        // Version-specific addon guidance
        switch ($version) {
            case 'v6':
                $guidance .= "\n### v6 Addon Considerations:\n";
                $guidance .= "- **Control Panel UI**: Update to use Vue 3 and new UI Kit components\n";
                $guidance .= "- **Component Migration**: Migrate Vue 2 components to Vue 3 Composition API\n";
                $guidance .= "- **UI Kit Integration**: Use new UI Kit for consistent control panel styling\n";
                $guidance .= "- **Breaking Changes**: Review and update for v6 breaking changes\n";
                $guidance .= "- **Testing**: Update tests for new control panel architecture\n";
                $guidance .= "- **Compatibility**: Ensure addon works with Vue 3 ecosystem\n";
                break;

            case 'v5':
                $guidance .= "\n### v5 Addon Best Practices:\n";
                $guidance .= "- **Modern PHP**: Use PHP 8.1+ features and type declarations\n";
                $guidance .= "- **Laravel Integration**: Leverage Laravel 10+ features\n";
                $guidance .= "- **Service Providers**: Use proper addon service provider patterns\n";
                $guidance .= "- **Control Panel**: Use current Vue 2 component patterns\n";
                $guidance .= "- **Testing**: Implement comprehensive test coverage\n";
                break;

            case 'v4':
                $guidance .= "\n### v4 Addon Considerations:\n";
                $guidance .= "- **Legacy Support**: Maintain compatibility with older Laravel versions\n";
                $guidance .= "- **PHP Compatibility**: Consider PHP version limitations\n";
                $guidance .= "- **Upgrade Path**: Plan for v5/v6 migration\n";
                $guidance .= "- **Limited Features**: Some modern Statamic features may not be available\n";
                break;
        }

        return $guidance . "\n";
    }
}
