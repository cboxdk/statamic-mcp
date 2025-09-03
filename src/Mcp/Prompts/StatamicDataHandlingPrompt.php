<?php

namespace Cboxdk\StatamicMcp\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Laravel\Mcp\Server\Prompts\Arguments;
use Laravel\Mcp\Server\Prompts\PromptResult;

class StatamicDataHandlingPrompt extends Prompt
{
    protected string $description = 'Comprehensive guidance on proper Statamic field data access, variable scoping, and common data handling pitfalls';

    public function arguments(): Arguments
    {
        return (new Arguments)
            ->add(new Argument(
                name: 'context',
                description: 'Data handling context: antlers-scoping, field-augmentation, data-validation, or template-debugging',
                required: false,
            ))
            ->add(new Argument(
                name: 'template_engine',
                description: 'Template engine: antlers or blade',
                required: false,
            ))
            ->add(new Argument(
                name: 'issue_type',
                description: 'Specific issue: variable-collision, empty-data-inheritance, field-augmentation, or scope-bleeding',
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
        $issueType = $arguments['issue_type'] ?? '';
        $statamicVersion = $arguments['statamic_version'] ?? $this->detectStatamicVersion();

        $content = "# Statamic {$statamicVersion} Data Handling & Variable Scoping Guide\n\n";
        $content .= "Master proper data access patterns and avoid common Statamic variable scoping pitfalls.\n\n";

        // Add issue-specific guidance first
        if (! empty($issueType)) {
            $content .= $this->getIssueSpecificGuidance($issueType, $templateEngine, $statamicVersion);
        }

        if ($context === 'antlers-scoping' || $context === 'general') {
            $content .= $this->getAntlersScoping($statamicVersion);
        }

        if ($context === 'field-augmentation' || $context === 'general') {
            $content .= $this->getFieldAugmentation($statamicVersion);
        }

        if ($context === 'data-validation' || $context === 'general') {
            $content .= $this->getDataValidation($templateEngine, $statamicVersion);
        }

        if ($context === 'template-debugging' || $context === 'general') {
            $content .= $this->getTemplateDebugging($templateEngine, $statamicVersion);
        }

        // Add systematic debugging approaches
        $content .= $this->getSystematicDebugging($templateEngine, $statamicVersion);

        return new PromptResult(
            content: $content,
            description: "Data handling guidance for {$context} in Statamic {$statamicVersion}"
        );
    }

    private function getIssueSpecificGuidance(string $issueType, string $templateEngine, string $version): string
    {
        $content = '## 🚨 Addressing Specific Issue: ' . ucwords(str_replace('-', ' ', $issueType)) . "\n\n";

        switch ($issueType) {
            case 'variable-collision':
                $content .= "### Variable Name Collision Solutions\n";
                $content .= "**Problem**: Multiple contexts have variables with the same name, causing unexpected output.\n\n";
                $content .= "**Solutions**:\n";
                if ($templateEngine === 'antlers') {
                    $content .= "```antlers\n";
                    $content .= "{{# Use explicit scoping with dollar prefix #}}\n";
                    $content .= "{{ \$title }}  {{# Current context title #}}\n\n";
                    $content .= "{{# Use colon notation for specific field access #}}\n";
                    $content .= "{{ entry:title }}  {{# Entry's title specifically #}}\n";
                    $content .= "{{ page:title }}   {{# Page's title specifically #}}\n\n";
                    $content .= "{{# Use scope directive to create isolated contexts #}}\n";
                    $content .= "{{ scope:my_entry }}\n";
                    $content .= "  {{ title }}  {{# This title is from my_entry scope #}}\n";
                    $content .= "{{ /scope:my_entry }}\n";
                    $content .= "```\n\n";
                }
                break;

            case 'empty-data-inheritance':
                $content .= "### Empty Data Variable Inheritance (Parent Bleed)\n";
                $content .= "**Problem**: When looping entries without data, parent context variables appear instead.\n\n";
                $content .= "**Classic Example**:\n";
                if ($templateEngine === 'antlers') {
                    $content .= "```antlers\n";
                    $content .= "{{# BAD: Parent title shows when entry title is empty #}}\n";
                    $content .= "{{ collection:articles }}\n";
                    $content .= "  <h2>{{ title }}</h2>  {{# May show parent's title! #}}\n";
                    $content .= "{{ /collection:articles }}\n\n";
                    $content .= "{{# GOOD: Always check for data existence #}}\n";
                    $content .= "{{ collection:articles }}\n";
                    $content .= "  {{ if title }}\n";
                    $content .= "    <h2>{{ title }}</h2>\n";
                    $content .= "  {{ else }}\n";
                    $content .= "    <h2>Untitled Article</h2>\n";
                    $content .= "  {{ /if }}\n";
                    $content .= "{{ /collection:articles }}\n\n";
                    $content .= "{{# BETTER: Use null coalescing #}}\n";
                    $content .= "{{ collection:articles }}\n";
                    $content .= "  <h2>{{ title ?? 'Untitled Article' }}</h2>\n";
                    $content .= "{{ /collection:articles }}\n";
                    $content .= "```\n\n";
                }
                break;

            case 'field-augmentation':
                $content .= "### Field Augmentation vs Raw Data Issues\n";
                $content .= "**Problem**: Confusion between raw field data and augmented (processed) data.\n\n";
                $content .= "**Solutions**:\n";
                if ($templateEngine === 'antlers') {
                    $content .= "```antlers\n";
                    $content .= "{{# Augmented data (default) - processed by fieldtype #}}\n";
                    $content .= "{{ markdown_content }}  {{# HTML output #}}\n\n";
                    $content .= "{{# Raw data - original input #}}\n";
                    $content .= "{{ markdown_content | raw }}  {{# Markdown source #}}\n\n";
                    $content .= "{{# Asset fields - augmented returns Asset objects #}}\n";
                    $content .= "{{ featured_image }}\n";
                    $content .= "  <img src=\"{{ url }}\" alt=\"{{ alt }}\">\n";
                    $content .= "{{ /featured_image }}\n\n";
                    $content .= "{{# Asset raw data - just the path string #}}\n";
                    $content .= "{{ featured_image | raw }}  {{# assets/image.jpg #}}\n";
                    $content .= "```\n\n";
                }
                break;

            case 'scope-bleeding':
                $content .= "### Variable Scope Bleeding Prevention\n";
                $content .= "**Problem**: Variables from outer scopes unexpectedly appearing in inner contexts.\n\n";
                $content .= "**Prevention Strategies**:\n";
                if ($templateEngine === 'antlers') {
                    $content .= "```antlers\n";
                    $content .= "{{# Use explicit variable checking #}}\n";
                    $content .= "{{ collection:articles }}\n";
                    $content .= "  {{ if exists:title }}{{ title }}{{ /if }}\n";
                    $content .= "  {{ if exists:content }}{{ content }}{{ /if }}\n";
                    $content .= "{{ /collection:articles }}\n\n";
                    $content .= "{{# Create isolated scopes with scope directive #}}\n";
                    $content .= "{{ collection:articles scope=\"article\" }}\n";
                    $content .= "  {{ scope:article }}\n";
                    $content .= "    {{ title }}  {{# Only article's title #}}\n";
                    $content .= "  {{ /scope:article }}\n";
                    $content .= "{{ /collection:articles }}\n";
                    $content .= "```\n\n";
                }
                break;
        }

        return $content;
    }

    private function getAntlersScoping(string $version): string
    {
        $content = "## 🎯 Antlers Variable Scoping Best Practices\n\n";

        $content .= "### Understanding Variable Resolution\n";
        $content .= "Antlers follows a **hierarchical variable resolution** pattern:\n";
        $content .= "1. Current context variables (loop item, tag context)\n";
        $content .= "2. Parent context variables (outer loops, page context)\n";
        $content .= "3. Global variables (site, system variables)\n\n";

        $content .= "### Preventing Variable Collision\n";
        $content .= "```antlers\n";
        $content .= "{{# Page context has 'title' = \"My Blog\" #}}\n";
        $content .= "<h1>{{ title }}</h1>  {{# \"My Blog\" #}}\n\n";
        $content .= "{{ collection:articles }}\n";
        $content .= "  {{# Article may not have title - parent bleeds through! #}}\n";
        $content .= "  <h2>{{ title }}</h2>  {{# May still show \"My Blog\"! #}}\n";
        $content .= "  \n";
        $content .= "  {{# SOLUTION 1: Explicit checking #}}\n";
        $content .= "  {{ if title && title != page:title }}\n";
        $content .= "    <h2>{{ title }}</h2>\n";
        $content .= "  {{ else }}\n";
        $content .= "    <h2>Untitled</h2>\n";
        $content .= "  {{ /if }}\n";
        $content .= "  \n";
        $content .= "  {{# SOLUTION 2: Use entry-specific access #}}\n";
        $content .= "  <h2>{{ entry:title ?? 'Untitled' }}</h2>\n";
        $content .= "  \n";
        $content .= "  {{# SOLUTION 3: Scope isolation #}}\n";
        $content .= "  {{ scope:article }}\n";
        $content .= "    {{ if title }}<h2>{{ title }}</h2>{{ /if }}\n";
        $content .= "  {{ /scope:article }}\n";
        $content .= "{{ /collection:articles }}\n";
        $content .= "```\n\n";

        $content .= "### Variable Access Patterns\n";
        $content .= "```antlers\n";
        $content .= "{{# Colon notation for nested access #}}\n";
        $content .= "{{ author:name }}\n";
        $content .= "{{ address:street:number }}\n\n";
        $content .= "{{# Dot notation (alternative) #}}\n";
        $content .= "{{ author.name }}\n";
        $content .= "{{ address.street.number }}\n\n";
        $content .= "{{# Bracket notation for dynamic keys #}}\n";
        $content .= "{{ author[field_name] }}\n";
        $content .= "{{ data[dynamic_key] }}\n\n";
        $content .= "{{# Explicit context prefixes #}}\n";
        $content .= "{{ page:title }}    {{# Current page title #}}\n";
        $content .= "{{ entry:title }}   {{# Current entry title #}}\n";
        $content .= "{{ site:name }}     {{# Site name #}}\n";
        $content .= "{{ user:name }}     {{# Current user name #}}\n";
        $content .= "```\n\n";

        return $content;
    }

    private function getFieldAugmentation(string $version): string
    {
        $content = "## 🔄 Field Augmentation & Raw Data Access\n\n";

        $content .= "### Understanding Augmentation\n";
        $content .= "Field augmentation transforms raw stored data into usable formats:\n\n";

        $content .= "| Fieldtype | Raw Data | Augmented Data |\n";
        $content .= "|-----------|----------|----------------|\n";
        $content .= "| Markdown | `# Hello` | `<h1>Hello</h1>` |\n";
        $content .= "| Assets | `assets/image.jpg` | Asset object with metadata |\n";
        $content .= "| Entries | `article-slug` | Entry object with all fields |\n";
        $content .= "| Date | `2024-01-01` | Carbon date object |\n";
        $content .= "| Textarea | `Hello world` | `Hello world` (no change) |\n\n";

        $content .= "### Accessing Raw vs Augmented Data\n";
        $content .= "```antlers\n";
        $content .= "{{# Markdown field example #}}\n";
        $content .= "{{ content }}         {{# Augmented: <p>Hello <strong>world</strong></p> #}}\n";
        $content .= "{{ content | raw }}   {{# Raw: Hello **world** #}}\n\n";
        $content .= "{{# Assets field example #}}\n";
        $content .= "{{ featured_image }}  {{# Augmented: Asset object #}}\n";
        $content .= "  <img src=\"{{ url }}\" alt=\"{{ alt }}\" width=\"{{ width }}\">\n";
        $content .= "{{ /featured_image }}\n\n";
        $content .= "{{ featured_image | raw }}  {{# Raw: assets/photos/hero.jpg #}}\n\n";
        $content .= "{{# Entries field example #}}\n";
        $content .= "{{ related_articles }}  {{# Augmented: Entry objects #}}\n";
        $content .= "  <a href=\"{{ url }}\">{{ title }}</a>\n";
        $content .= "{{ /related_articles }}\n\n";
        $content .= "{{ related_articles | raw }}  {{# Raw: [\"article-1\", \"article-2\"] #}}\n\n";
        $content .= "{{# Date field example #}}\n";
        $content .= "{{ publish_date }}           {{# Augmented: Carbon object #}}\n";
        $content .= "{{ publish_date | raw }}     {{# Raw: 2024-01-01 #}}\n";
        $content .= "{{ publish_date format=\"F j, Y\" }}  {{# January 1, 2024 #}}\n";
        $content .= "```\n\n";

        $content .= "### When to Use Raw Data\n";
        $content .= "- **API responses**: When you need the original stored format\n";
        $content .= "- **Debugging**: To see what's actually stored in the database\n";
        $content .= "- **Custom processing**: When you want to handle transformation yourself\n";
        $content .= "- **Form pre-population**: To show the original input format\n\n";

        return $content;
    }

    private function getDataValidation(string $templateEngine, string $version): string
    {
        $content = "## ✅ Data Validation & Empty Check Patterns\n\n";

        if ($templateEngine === 'antlers') {
            $content .= "### Comprehensive Empty Data Checking\n";
            $content .= "```antlers\n";
            $content .= "{{# Basic existence check #}}\n";
            $content .= "{{ if title }}\n";
            $content .= "  <h1>{{ title }}</h1>\n";
            $content .= "{{ /if }}\n\n";
            $content .= "{{# Check for specific field existence (not inherited) #}}\n";
            $content .= "{{ if exists:title }}\n";
            $content .= "  <h1>{{ title }}</h1>\n";
            $content .= "{{ else }}\n";
            $content .= "  <h1>No title provided</h1>\n";
            $content .= "{{ /if }}\n\n";
            $content .= "{{# Multiple fallback checks #}}\n";
            $content .= "<title>{{ meta_title ?? title ?? 'Default Page Title' }}</title>\n\n";
            $content .= "{{# Collection with empty data protection #}}\n";
            $content .= "{{ collection:articles }}\n";
            $content .= "  {{ if exists:title && title != '' }}\n";
            $content .= "    <h2>{{ title }}</h2>\n";
            $content .= "  {{ else }}\n";
            $content .= "    <h2>Article #{{ index }}</h2>\n";
            $content .= "  {{ /if }}\n";
            $content .= "  \n";
            $content .= "  {{ if exists:featured_image && featured_image }}\n";
            $content .= "    {{ featured_image }}\n";
            $content .= "      <img src=\"{{ url }}\" alt=\"{{ alt ?? title }}\">\n";
            $content .= "    {{ /featured_image }}\n";
            $content .= "  {{ /if }}\n";
            $content .= "{{ /collection:articles }}\n";
            $content .= "```\n\n";

            $content .= "### Advanced Validation Patterns\n";
            $content .= "```antlers\n";
            $content .= "{{# Check array/collection not empty #}}\n";
            $content .= "{{ if images && images | length > 0 }}\n";
            $content .= "  {{ images }}\n";
            $content .= "    <img src=\"{{ url }}\" alt=\"{{ alt }}\">\n";
            $content .= "  {{ /images }}\n";
            $content .= "{{ /if }}\n\n";
            $content .= "{{# Check for specific data types #}}\n";
            $content .= "{{ if price && price is not empty }}\n";
            $content .= "  <span class=\"price\">\${{ price | format_number }}</span>\n";
            $content .= "{{ /if }}\n\n";
            $content .= "{{# Prevent parent variable bleeding #}}\n";
            $content .= "{{ collection:products }}\n";
            $content .= "  {{ if exists:price && price != parent:price }}\n";
            $content .= "    <span class=\"price\">\${{ price }}</span>\n";
            $content .= "  {{ /if }}\n";
            $content .= "{{ /collection:products }}\n";
            $content .= "```\n\n";
        }

        return $content;
    }

    private function getTemplateDebugging(string $templateEngine, string $version): string
    {
        $content = "## 🐛 Template Debugging Techniques\n\n";

        if ($templateEngine === 'antlers') {
            $content .= "### Debug Variable Scoping Issues\n";
            $content .= "```antlers\n";
            $content .= "{{# Dump current context variables #}}\n";
            $content .= "{{ dump }}\n\n";
            $content .= "{{# Dump specific variable #}}\n";
            $content .= "{{ dump:title }}\n\n";
            $content .= "{{# Show variable origin/context #}}\n";
            $content .= "Current title: {{ title }}<br>\n";
            $content .= "Page title: {{ page:title }}<br>\n";
            $content .= "Entry title: {{ entry:title }}<br>\n";
            $content .= "Exists in current context: {{ exists:title ? 'Yes' : 'No' }}<br>\n\n";
            $content .= "{{# Debug collection loops #}}\n";
            $content .= "{{ collection:articles }}\n";
            $content .= "  <h3>Article {{ index }}: {{ slug }}</h3>\n";
            $content .= "  <pre>{{ dump:title }}</pre>\n";
            $content .= "  Title exists: {{ exists:title ? 'Yes' : 'No' }}<br>\n";
            $content .= "  Title value: {{ title ?? 'EMPTY' }}<br>\n";
            $content .= "  Parent title: {{ parent:title ?? 'NO PARENT' }}<br>\n";
            $content .= "{{ /collection:articles }}\n";
            $content .= "```\n\n";

            $content .= "### Systematic Debug Checklist\n";
            $content .= "1. **Check variable existence**: Use `{{ exists:field_name }}`\n";
            $content .= "2. **Verify data type**: Use `{{ dump:field_name }}`\n";
            $content .= "3. **Test parent bleeding**: Compare `{{ field }}` vs `{{ parent:field }}`\n";
            $content .= "4. **Validate field augmentation**: Test `{{ field }}` vs `{{ field | raw }}`\n";
            $content .= "5. **Isolate scope**: Use `{{ scope:variable }}` wrapper\n\n";
        }

        return $content;
    }

    private function getSystematicDebugging(string $templateEngine, string $version): string
    {
        $content = "## 🔍 Systematic Error Pinpointing\n\n";

        $content .= "### Step-by-Step Debugging Process\n";
        $content .= "When you encounter generic errors or unexpected output:\n\n";

        $content .= "#### 1. Isolate the Problem Area\n";
        $content .= "- **Comment out sections**: Gradually uncomment to find the problematic code\n";
        $content .= "- **Use simple output**: Replace complex logic with basic variable dumps\n";
        $content .= "- **Test with known data**: Use entries/content you know exists\n\n";

        $content .= "#### 2. Verify Data Existence\n";
        $content .= "```antlers\n";
        $content .= "{{# Step-by-step verification #}}\n";
        $content .= "Collection exists: {{ collection:articles ? 'Yes' : 'No' }}<br>\n";
        $content .= "Collection count: {{ collection:articles | length }}<br>\n\n";
        $content .= "{{ collection:articles limit=\"1\" }}\n";
        $content .= "  Entry slug: {{ slug ?? 'NO SLUG' }}<br>\n";
        $content .= "  Entry ID: {{ id ?? 'NO ID' }}<br>\n";
        $content .= "  Title exists: {{ exists:title ? 'Yes' : 'No' }}<br>\n";
        $content .= "  Title value: '{{ title ?? 'EMPTY' }}'<br>\n";
        $content .= "  Raw dump: <pre>{{ dump }}</pre>\n";
        $content .= "{{ /collection:articles }}\n";
        $content .= "```\n\n";

        $content .= "#### 3. Check Blueprint & Field Configuration\n";
        $content .= "**Use MCP tools for systematic analysis**:\n";
        $content .= "- `statamic.blueprints.scan` - Verify blueprint structure\n";
        $content .= "- `statamic.fieldsets.scan` - Check fieldset definitions\n";
        $content .= "- `statamic.content.extract` - Analyze actual content structure\n\n";

        $content .= "#### 4. Validate Template Context\n";
        $content .= "```antlers\n";
        $content .= "{{# Context debugging template #}}\n";
        $content .= "<div style=\"background:#f0f0f0; padding:10px; margin:10px; border:1px solid #ccc;\">\n";
        $content .= "  <strong>Debug Info:</strong><br>\n";
        $content .= "  Template: {{ template ?? 'No template' }}<br>\n";
        $content .= "  Layout: {{ layout ?? 'No layout' }}<br>\n";
        $content .= "  Collection: {{ collection ?? 'No collection' }}<br>\n";
        $content .= "  Entry ID: {{ id ?? 'No ID' }}<br>\n";
        $content .= "  Entry Type: {{ collection_handle ?? entry_type ?? 'Unknown' }}<br>\n";
        $content .= "  Blueprint: {{ blueprint ?? 'No blueprint' }}<br>\n";
        $content .= "  Published: {{ published ?? 'Unknown' }}<br>\n";
        $content .= "</div>\n";
        $content .= "```\n\n";

        $content .= "#### 5. Common Error Patterns & Solutions\n";
        $content .= "**\"Trying to get property of non-object\"**:\n";
        $content .= "- Check if variable exists before accessing properties\n";
        $content .= "- Verify field augmentation is working correctly\n\n";
        $content .= "**\"Undefined variable\"**:\n";
        $content .= "- Use `{{ exists:variable }}` checks\n";
        $content .= "- Verify variable scope and context\n\n";
        $content .= "**\"Call to a member function on null\"**:\n";
        $content .= "- Ensure collection/entry exists before calling methods\n";
        $content .= "- Check for empty relationships\n\n";

        $content .= "### Debug Template for Pinpointing Issues\n";
        $content .= "Create a temporary debug template to systematically test:\n";
        $content .= "```antlers\n";
        $content .= "{{# Save as debug.antlers.html #}}\n";
        $content .= "<h1>Systematic Debug Report</h1>\n\n";
        $content .= "<h2>1. Global Context</h2>\n";
        $content .= "<pre>{{ dump:global }}</pre>\n\n";
        $content .= "<h2>2. Page/Entry Context</h2>\n";
        $content .= "<pre>{{ dump:page }}</pre>\n\n";
        $content .= "<h2>3. Collection Test</h2>\n";
        $content .= "{{ collection:your_collection_name limit=\"3\" }}\n";
        $content .= "  <h3>Entry {{ index }}: {{ slug }}</h3>\n";
        $content .= "  <pre>{{ dump }}</pre>\n";
        $content .= "  <hr>\n";
        $content .= "{{ /collection:your_collection_name }}\n";
        $content .= "```\n\n";

        return $content;
    }

    private function detectStatamicVersion(): string
    {
        try {
            // Try to get version from composer
            if (file_exists(base_path('vendor/statamic/cms/composer.json'))) {
                $content = file_get_contents(base_path('vendor/statamic/cms/composer.json'));
                if ($content === false) {
                    throw new \RuntimeException('Could not read composer.json');
                }
                $composerData = json_decode($content, true);
                if (isset($composerData['version'])) {
                    $version = $composerData['version'];
                    if (version_compare($version, '6.0', '>=')) {
                        return 'v6';
                    } elseif (version_compare($version, '5.0', '>=')) {
                        return 'v5';
                    } elseif (version_compare($version, '4.0', '>=')) {
                        return 'v4';
                    }
                }
            }

            // Try Laravel app approach if available
            if (class_exists('Statamic\Statamic') && function_exists('app') && app()->bound('env')) {
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
}
