<?php

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Laravel\Mcp\Server\Prompts\Arguments;
use Laravel\Mcp\Server\Prompts\PromptResult;

class StatamicTroubleshootingPrompt extends Prompt
{
    protected string $description = 'Diagnostic guidance and solutions for common Statamic issues and problems';

    public function arguments(): Arguments
    {
        return (new Arguments)
            ->add(new Argument(
                name: 'issue_category',
                description: 'Category of issue: performance, templates, cache, content, assets, or deployment',
                required: false,
            ))
            ->add(new Argument(
                name: 'error_context',
                description: 'Brief description of the error or problem being experienced',
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
        $issueCategory = $arguments['issue_category'] ?? 'general';
        $errorContext = $arguments['error_context'] ?? '';
        $statamicVersion = $arguments['statamic_version'] ?? $this->detectStatamicVersion();

        $content = "# Statamic {$statamicVersion} Troubleshooting Guide\n\n";
        $content .= "Use this systematic approach to diagnose and resolve Statamic {$statamicVersion} issues:\n\n";

        // Add version-specific troubleshooting guidance
        $content .= $this->getVersionTroubleshootingGuidance($statamicVersion);

        $content .= "## 1. Initial Diagnosis\n";
        $content .= "Start with these MCP tools to gather information:\n";
        $content .= "- `statamic.system.info` - Check Statamic version, environment, and configuration\n";
        $content .= "- `statamic.cache.manage` - Verify cache status and clear if needed\n";
        $content .= "- `statamic.addons.scan` - Ensure all addons are compatible and functioning\n\n";

        if ($issueCategory === 'performance' || $issueCategory === 'general') {
            $content .= $this->getPerformanceTroubleshooting($statamicVersion, $errorContext);
        }

        if ($issueCategory === 'templates' || $issueCategory === 'general') {
            $content .= "## Template Issues\n";
            $content .= "### Diagnostic Steps:\n";
            $content .= "1. Use `statamic.antlers.hints` to verify available variables\n";
            $content .= "2. Validate template syntax with `statamic.antlers.lint`\n";
            $content .= "3. Check blueprint compatibility with `statamic.blueprints.scan`\n";
            $content .= "4. Review available tags with `statamic.tags.scan`\n\n";

            $content .= "### Common Solutions:\n";
            $content .= "- Verify variable scope and context\n";
            $content .= "- Check for proper tag syntax and parameters\n";
            $content .= "- Ensure blueprint fields match template expectations\n";
            $content .= "- Use debugging techniques like `{{ dump }}` tags\n\n";
        }

        if ($issueCategory === 'content' || $issueCategory === 'general') {
            $content .= "## Content Issues\n";
            $content .= "### Diagnostic Steps:\n";
            $content .= "1. Analyze content structure with `statamic.content.extract`\n";
            $content .= "2. Validate blueprints with `statamic.blueprints.scan`\n";
            $content .= "3. Check field relationships and validation\n";
            $content .= "4. Verify asset references with `statamic.assets.manage`\n\n";

            $content .= "### Common Solutions:\n";
            $content .= "- Fix broken asset references\n";
            $content .= "- Update blueprint validation rules\n";
            $content .= "- Resolve taxonomy term conflicts\n";
            $content .= "- Correct entry relationship issues\n\n";
        }

        if ($issueCategory === 'cache' || $issueCategory === 'general') {
            $content .= "## Cache-Related Issues\n";
            $content .= "### Diagnostic Steps:\n";
            $content .= "1. Check cache status: `statamic.cache.manage` with action 'status'\n";
            $content .= "2. Clear specific caches: stache, static, glide, application\n";
            $content .= "3. Verify cache configuration in settings\n";
            $content .= "4. Test with cache disabled\n\n";

            $content .= "### Common Solutions:\n";
            $content .= "- Clear stache cache after content changes\n";
            $content .= "- Invalidate static cache for updated pages\n";
            $content .= "- Clear Glide cache for image issues\n";
            $content .= "- Restart queues for cache warming\n\n";
        }

        if ($issueCategory === 'assets' || $issueCategory === 'general') {
            $content .= "## Asset Issues\n";
            $content .= "### Diagnostic Steps:\n";
            $content .= "1. List asset containers: `statamic.assets.containers`\n";
            $content .= "2. Check asset status: `statamic.assets.manage`\n";
            $content .= "3. Verify file permissions and disk space\n";
            $content .= "4. Test Glide transformations\n\n";

            $content .= "### Common Solutions:\n";
            $content .= "- Fix file permission issues\n";
            $content .= "- Clear Glide cache for transformation problems\n";
            $content .= "- Update asset container configurations\n";
            $content .= "- Resolve broken asset references in content\n\n";
        }

        $content .= "## General Troubleshooting Workflow\n";
        $content .= "1. **Identify**: Use system info and cache status tools\n";
        $content .= "2. **Isolate**: Test with minimal configuration/content\n";
        $content .= "3. **Analyze**: Use relevant MCP tools for deeper inspection\n";
        $content .= "4. **Test**: Apply solutions systematically\n";
        $content .= "5. **Verify**: Confirm resolution and document solution\n\n";

        if (! empty($errorContext)) {
            $content .= "## Context-Specific Guidance\n";
            $content .= "Based on your error description: \"{$errorContext}\"\n\n";
            $content .= "Consider these additional steps:\n";
            $content .= "- Review logs for detailed error messages\n";
            $content .= "- Check for recent changes that might have caused the issue\n";
            $content .= "- Test in a clean environment if possible\n";
            $content .= "- Use the most relevant MCP tools for your specific issue category\n\n";
        }

        $content .= 'Remember: Always backup your site before implementing solutions, and test changes in a staging environment first.';

        return new PromptResult(
            content: $content,
            description: "Troubleshooting guidance for {$issueCategory} issues in Statamic {$statamicVersion}" . (! empty($errorContext) ? " related to: {$errorContext}" : '')
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
     * Get version-specific troubleshooting guidance.
     */
    private function getVersionTroubleshootingGuidance(string $version): string
    {
        $guidance = '';

        switch ($version) {
            case 'v6':
                $guidance .= "## ⚡ v6 Specific Troubleshooting\n";
                $guidance .= "### Vue 3 Migration Issues\n";
                $guidance .= "- Control panel components not loading: Check Vue 3 compatibility\n";
                $guidance .= "- UI inconsistencies: Ensure UI Kit components are used correctly\n";
                $guidance .= "- Addon conflicts: Verify addon Vue 3 compatibility\n";
                $guidance .= "- JavaScript errors: Check for Vue 2 to Vue 3 breaking changes\n\n";

                $guidance .= "### v6 Control Panel Issues\n";
                $guidance .= "- Use browser developer tools to check for Vue 3 console errors\n";
                $guidance .= "- Verify UI Kit component usage matches v6 patterns\n";
                $guidance .= "- Check for deprecated Vue 2 syntax in custom components\n\n";
                break;

            case 'v5':
                $guidance .= "## ✅ v5 Standard Troubleshooting\n";
                $guidance .= "- Use modern debugging tools and Laravel telescope if available\n";
                $guidance .= "- Leverage comprehensive logging and error reporting\n";
                $guidance .= "- Test with latest stable versions of dependencies\n\n";
                break;

            case 'v4':
                $guidance .= "## ⚠️ v4 Legacy Troubleshooting\n";
                $guidance .= "- Check compatibility with older Laravel and PHP versions\n";
                $guidance .= "- Some modern debugging tools may not be available\n";
                $guidance .= "- Consider upgrade path if issues persist\n";
                $guidance .= "- Ensure security patches are applied\n\n";
                break;
        }

        return $guidance;
    }

    /**
     * Get comprehensive performance troubleshooting guidance.
     */
    private function getPerformanceTroubleshooting(string $version, string $context): string
    {
        $content = "## 🚀 Performance Issues - Systematic Diagnosis\n\n";

        $content .= "### 1. Immediate Performance Audit\n";
        $content .= "**Use MCP tools for baseline measurement**:\n";
        $content .= "```bash\n";
        $content .= "# System status check\n";
        $content .= "# Tool: statamic.system.info\n";
        $content .= "# Tool: statamic.cache.manage (action: 'status')\n\n";
        $content .= "# Template complexity analysis\n";
        $content .= "# Tool: statamic.antlers.lint (all templates)\n";
        $content .= "# Tool: statamic.content.extract (analyze data volume)\n";
        $content .= "```\n\n";

        $content .= "### 2. Pinpoint Performance Bottlenecks\n";
        $content .= "#### A. Template-Level Issues\n";
        $content .= "**Identify problematic templates**:\n";
        $content .= "```antlers\n";
        $content .= "{{# Add to suspected slow templates #}}\n";
        $content .= "<!-- DEBUG: Template start {{ template }} at {{ now format='H:i:s.u' }} -->\n";
        $content .= "\n";
        $content .= "{{# Your template content #}}\n";
        $content .= "\n";
        $content .= "<!-- DEBUG: Template end {{ template }} at {{ now format='H:i:s.u' }} -->\n";
        $content .= "```\n\n";

        $content .= "**Common template performance killers**:\n";
        $content .= "- Nested collection loops without limits\n";
        $content .= "- Missing `limit` parameters on large collections\n";
        $content .= "- Excessive taxonomy queries\n";
        $content .= "- Heavy asset transformations in loops\n";
        $content .= "- Complex conditionals in tight loops\n\n";

        $content .= "#### B. Data-Level Issues\n";
        $content .= "**Identify data bottlenecks**:\n";
        $content .= "```antlers\n";
        $content .= "{{# Test collection performance #}}\n";
        $content .= "{{ collection:your_collection }}\n";
        $content .= "  {{# Count: {{ total_results }} (if > 100, add limit) #}}\n";
        $content .= "  {{ if index == 1 }}\n";
        $content .= "    <!-- First item load time: {{ now format='H:i:s.u' }} -->\n";
        $content .= "  {{ /if }}\n";
        $content .= "  {{ if index == 10 }}\n";
        $content .= "    <!-- 10th item load time: {{ now format='H:i:s.u' }} -->\n";
        $content .= "  {{ /if }}\n";
        $content .= "{{ /collection:your_collection }}\n";
        $content .= "```\n\n";

        $content .= "#### C. Cache-Level Issues\n";
        $content .= "**Systematic cache diagnosis**:\n";
        $content .= "```bash\n";
        $content .= "# Check each cache layer\n";
        $content .= "php artisan statamic:stache:clear && echo \"Stache cleared\"\n";
        $content .= "php artisan cache:clear && echo \"App cache cleared\"\n";
        $content .= "php artisan view:clear && echo \"View cache cleared\"\n";
        $content .= "php artisan statamic:static:clear && echo \"Static cache cleared\"\n";
        $content .= "php artisan statamic:glide:clear && echo \"Glide cache cleared\"\n";
        $content .= "```\n\n";

        $content .= "### 3. Systematic Performance Solutions\n";
        $content .= "#### Priority 1: Quick Wins\n";
        $content .= "```antlers\n";
        $content .= "{{# Add limits to all collection tags #}}\n";
        $content .= "{{ collection:articles limit='10' }}\n";
        $content .= "  {{# content #}}\n";
        $content .= "{{ /collection:articles }}\n\n";
        $content .= "{{# Use eager loading for relationships #}}\n";
        $content .= "{{ collection:articles with='author|featured_image' }}\n";
        $content .= "  <h2>{{ title }}</h2>\n";
        $content .= "  <p>By {{ author:name }}</p>\n";
        $content .= "  {{ featured_image }}<img src=\"{{ url }}\">{{ /featured_image }}\n";
        $content .= "{{ /collection:articles }}\n\n";
        $content .= "{{# Cache expensive operations #}}\n";
        $content .= "{{ cache key='expensive_calculation' for='1 hour' }}\n";
        $content .= "  {{# Expensive content here #}}\n";
        $content .= "{{ /cache }}\n";
        $content .= "```\n\n";

        $content .= "#### Priority 2: Caching Strategy\n";
        $content .= "```php\n";
        $content .= "// config/statamic/static_caching.php\n";
        $content .= "return [\n";
        $content .= "    'strategy' => 'file',  // or 'application'\n";
        $content .= "    'rules' => [\n";
        $content .= "        '/articles' => ['cache' => true],\n";
        $content .= "        '/articles/*' => ['cache' => true, 'ignore_query_strings' => true],\n";
        $content .= "    ],\n";
        $content .= "];\n";
        $content .= "```\n\n";

        $content .= "#### Priority 3: Asset Optimization\n";
        $content .= "```antlers\n";
        $content .= "{{# Optimize image loading #}}\n";
        $content .= "{{ featured_image }}\n";
        $content .= "  <img src=\"{{ glide:url width='800' height='600' quality='85' format='webp' }}\"\n";
        $content .= "       loading=\"lazy\"\n";
        $content .= "       alt=\"{{ alt ?? title }}\">\n";
        $content .= "{{ /featured_image }}\n\n";
        $content .= "{{# Pregenerate common sizes #}}\n";
        $content .= "{{# Run: php artisan statamic:assets:generate-presets #}}\n";
        $content .= "```\n\n";

        $content .= "### 4. Performance Monitoring\n";
        $content .= "**Create a performance test template**:\n";
        $content .= "```antlers\n";
        $content .= "{{# Save as performance-test.antlers.html #}}\n";
        $content .= "{{ header_type=\"text/plain\" }}\n";
        $content .= "Performance Test Report - {{ now format='Y-m-d H:i:s' }}\n";
        $content .= "========================================\n\n";
        $content .= "System Info:\n";
        $content .= "- Statamic Version: {{ version }}\n";
        $content .= "- PHP Memory Usage: {{ memory_usage }}\n";
        $content .= "- Peak Memory: {{ memory_peak }}\n\n";
        $content .= "Cache Status:\n";
        $content .= "{{ cache_status }}\n\n";
        $content .= "Collections Count:\n";
        $content .= "{{ collections }}\n";
        $content .= "- {{ title }}: {{ entries_count ?? 0 }} entries\n";
        $content .= "{{ /collections }}\n\n";
        $content .= "Large Collections (>50 entries):\n";
        $content .= "{{ collections }}\n";
        $content .= "  {{ if entries_count > 50 }}\n";
        $content .= "    ⚠️ {{ title }}: {{ entries_count }} entries\n";
        $content .= "  {{ /if }}\n";
        $content .= "{{ /collections }}\n";
        $content .= "```\n\n";

        return $content;
    }
}
