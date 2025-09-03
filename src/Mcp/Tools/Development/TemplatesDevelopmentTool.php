<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ScanBlueprintsTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Antlers Hints')]
#[IsReadOnly]
class TemplatesDevelopmentTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.development.templates';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get field hints and available variables for Antlers templates based on blueprints';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('blueprint')
            ->description('Blueprint handle to get hints for (optional - if not provided, returns general hints)')
            ->optional()
            ->string('context')
            ->description('Template context (entry, collection, taxonomy, etc.)')
            ->optional()
            ->boolean('include_globals')
            ->description('Include global variables and tags')
            ->optional()
            ->boolean('include_examples')
            ->description('Include usage examples for each field')
            ->optional()
            ->boolean('performance_analysis')
            ->description('Include performance optimization suggestions')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $blueprintHandle = $arguments['blueprint'] ?? null;
        $context = $arguments['context'] ?? 'entry';
        $includeGlobals = $arguments['include_globals'] ?? true;
        $includeExamples = $arguments['include_examples'] ?? true;
        $performanceAnalysis = $arguments['performance_analysis'] ?? false;

        $hints = [
            'context' => $context,
            'available_variables' => $this->getContextVariables($context),
            'tags' => $this->getTagHints($includeExamples),
            'modifiers' => $this->getModifierHints($includeExamples),
        ];

        // If blueprint is specified, get blueprint-specific hints
        if ($blueprintHandle) {
            // Get blueprint data using BlueprintsScanTool
            $scanTool = new ScanBlueprintsTool;
            $scanData = $scanTool->execute([]);

            if (! isset($scanData['blueprints'][$blueprintHandle])) {
                throw new \InvalidArgumentException("Blueprint '{$blueprintHandle}' not found");
            }

            $blueprint = $scanData['blueprints'][$blueprintHandle];
            $hints['blueprint'] = $blueprintHandle;
            $hints['blueprint_fields'] = $this->getFieldHints($blueprint, $includeExamples);
            $hints['sets'] = $this->getSetHints($blueprint, $includeExamples);
            $hints['loops'] = $this->getLoopHints($blueprint, $includeExamples);
        }

        if ($includeGlobals) {
            $hints['globals'] = $this->getGlobalHints($context, $includeExamples);
        }

        if ($performanceAnalysis) {
            $hints['performance_tips'] = $this->getPerformanceTips();
            $hints['edge_case_warnings'] = $this->getEdgeCaseWarnings();
        }

        return $hints;
    }

    /**
     * Get context-specific variables.
     *
     * @return array<string, mixed>
     */
    private function getContextVariables(string $context): array
    {
        $variables = match ($context) {
            'entry' => [
                'id', 'slug', 'url', 'title', 'collection', 'published', 'date',
                'last_modified', 'author', 'content', 'excerpt', 'status',
            ],
            'collection' => [
                'handle', 'title', 'entries', 'count', 'route', 'mount',
            ],
            'taxonomy' => [
                'handle', 'title', 'slug', 'url', 'entries', 'count', 'taxonomy',
            ],
            'global' => [
                'handle', 'title', 'slug', 'data',
            ],
            default => [
                'site', 'current_date', 'now', 'config', 'env',
            ],
        };

        // Convert indexed array to associative for PHPStan Level 8 compliance
        return collect($variables)->mapWithKeys(fn ($item) => [$item => 'string'])->all();
    }

    /**
     * Get modifier hints.
     *
     * @return array<string, mixed>
     */
    private function getModifierHints(bool $includeExamples): array
    {
        $modifiers = [
            'format' => [
                'description' => 'Format dates and numbers',
                'usage' => '{{ date | format("Y-m-d") }}',
            ],
            'markdown' => [
                'description' => 'Convert markdown to HTML',
                'usage' => '{{ content | markdown }}',
            ],
            'strip_tags' => [
                'description' => 'Remove HTML tags',
                'usage' => '{{ content | strip_tags }}',
            ],
            'truncate' => [
                'description' => 'Truncate text',
                'usage' => '{{ content | truncate(100) }}',
            ],
            'upper' => [
                'description' => 'Convert to uppercase',
                'usage' => '{{ title | upper }}',
            ],
            'lower' => [
                'description' => 'Convert to lowercase',
                'usage' => '{{ title | lower }}',
            ],
            'title' => [
                'description' => 'Convert to title case',
                'usage' => '{{ title | title }}',
            ],
            'slugify' => [
                'description' => 'Create URL-friendly slug',
                'usage' => '{{ title | slugify }}',
            ],
            'relative' => [
                'description' => 'Convert date to relative format',
                'usage' => '{{ date | relative }}',
            ],
            'count' => [
                'description' => 'Count items in array',
                'usage' => '{{ entries | count }}',
            ],
        ];

        if ($includeExamples) {
            foreach ($modifiers as $modifier => &$data) {
                $data['examples'] = [
                    'basic' => $data['usage'],
                    'chained' => "{{ value | {$modifier} | upper }}",
                ];
            }
        }

        return $modifiers;
    }

    /**
     * Get field hints for the blueprint.
     *
     * @param  array<string, mixed>  $blueprint
     *
     * @return array<string, mixed>
     */
    private function getFieldHints(array $blueprint, bool $includeExamples): array
    {
        $hints = [];

        foreach ($blueprint['fields'] as $handle => $field) {
            $hint = [
                'handle' => $handle,
                'type' => $field['type'] ?? 'text',
                'display' => $field['display'] ?? $handle,
                'description' => $field['instructions'] ?? null,
                'required' => $field['required'] ?? false,
                'antlers_usage' => $this->getAntlersUsage($handle, $field),
            ];

            if ($includeExamples) {
                $hint['examples'] = $this->getFieldExamples($handle, $field);
            }

            $hints[$handle] = $hint;
        }

        return $hints;
    }

    /**
     * Get set hints for Bard/Replicator fields.
     *
     * @param  array<string, mixed>  $blueprint
     *
     * @return array<string, mixed>
     */
    private function getSetHints(array $blueprint, bool $includeExamples): array
    {
        $hints = [];

        foreach ($blueprint['fields'] as $fieldHandle => $field) {
            if (in_array($field['type'] ?? '', ['bard', 'replicator']) && isset($field['sets'])) {
                $hints[$fieldHandle] = [
                    'field_type' => $field['type'],
                    'sets' => [],
                ];

                foreach ($field['sets'] as $setHandle => $setData) {
                    $setHint = [
                        'handle' => $setHandle,
                        'display' => $setData['display'] ?? $setHandle,
                        'fields' => [],
                        'antlers_usage' => $this->getSetAntlersUsage($fieldHandle, $setHandle, $setData),
                    ];

                    foreach ($setData['fields'] as $setField) {
                        $setFieldHandle = $setField['handle'];
                        $setFieldData = $setField['field'];

                        $setHint['fields'][$setFieldHandle] = [
                            'handle' => $setFieldHandle,
                            'type' => $setFieldData['type'] ?? 'text',
                            'display' => $setFieldData['display'] ?? $setFieldHandle,
                            'required' => $setFieldData['required'] ?? false,
                            'antlers_usage' => $this->getAntlersUsage($setFieldHandle, $setFieldData),
                        ];
                    }

                    if ($includeExamples) {
                        $setHint['examples'] = $this->getSetExamples($fieldHandle, $setHandle, $setData);
                    }

                    $hints[$fieldHandle]['sets'][$setHandle] = $setHint;
                }
            }
        }

        return $hints;
    }

    /**
     * Get loop hints for array-type fields.
     *
     * @param  array<string, mixed>  $blueprint
     *
     * @return array<string, mixed>
     */
    private function getLoopHints(array $blueprint, bool $includeExamples): array
    {
        $hints = [];

        foreach ($blueprint['fields'] as $handle => $field) {
            $type = $field['type'] ?? 'text';

            if (in_array($type, ['entries', 'taxonomy', 'users', 'assets', 'bard', 'replicator', 'grid'])) {
                $hint = [
                    'field' => $handle,
                    'type' => $type,
                    'loop_syntax' => $this->getLoopSyntax($handle, $type),
                    'available_variables' => $this->getLoopVariables($type),
                ];

                if ($includeExamples) {
                    $hint['examples'] = $this->getLoopExamples($handle, $type);
                }

                $hints[$handle] = $hint;
            }
        }

        return $hints;
    }

    /**
     * Get global hints for the context.
     *
     * @return array<string, mixed>
     */
    private function getGlobalHints(string $context, bool $includeExamples): array
    {
        $globals = [
            'common' => [
                'site' => [
                    'description' => 'Current site information',
                    'properties' => ['handle', 'name', 'locale', 'url'],
                    'usage' => '{{ site:name }}',
                ],
                'current_date' => [
                    'description' => 'Current date and time',
                    'usage' => '{{ current_date }}',
                ],
                'now' => [
                    'description' => 'Current timestamp',
                    'usage' => '{{ now }}',
                ],
                'config' => [
                    'description' => 'Access configuration values',
                    'usage' => '{{ config:app:name }}',
                ],
                'env' => [
                    'description' => 'Environment variables',
                    'usage' => '{{ env:APP_ENV }}',
                ],
            ],
        ];

        // Context-specific globals
        switch ($context) {
            case 'entry':
                $globals['entry'] = [
                    'id' => ['description' => 'Entry ID', 'usage' => '{{ id }}'],
                    'slug' => ['description' => 'Entry slug', 'usage' => '{{ slug }}'],
                    'url' => ['description' => 'Entry URL', 'usage' => '{{ url }}'],
                    'title' => ['description' => 'Entry title', 'usage' => '{{ title }}'],
                    'collection' => ['description' => 'Collection handle', 'usage' => '{{ collection }}'],
                    'published' => ['description' => 'Published status', 'usage' => '{{ published }}'],
                    'date' => ['description' => 'Entry date', 'usage' => '{{ date }}'],
                    'last_modified' => ['description' => 'Last modified date', 'usage' => '{{ last_modified }}'],
                    'author' => ['description' => 'Entry author', 'usage' => '{{ author:name }}'],
                ];
                break;
            case 'collection':
                $globals['collection'] = [
                    'handle' => ['description' => 'Collection handle', 'usage' => '{{ handle }}'],
                    'title' => ['description' => 'Collection title', 'usage' => '{{ title }}'],
                    'entries' => ['description' => 'Collection entries', 'usage' => '{{ entries }}'],
                    'count' => ['description' => 'Entry count', 'usage' => '{{ count }}'],
                ];
                break;
            case 'taxonomy':
                $globals['taxonomy'] = [
                    'handle' => ['description' => 'Taxonomy handle', 'usage' => '{{ handle }}'],
                    'title' => ['description' => 'Term title', 'usage' => '{{ title }}'],
                    'slug' => ['description' => 'Term slug', 'usage' => '{{ slug }}'],
                    'url' => ['description' => 'Term URL', 'usage' => '{{ url }}'],
                    'entries' => ['description' => 'Associated entries', 'usage' => '{{ entries }}'],
                ];
                break;
        }

        if ($includeExamples) {
            foreach ($globals as $category => &$categoryGlobals) {
                foreach ($categoryGlobals as $key => &$global) {
                    $global['examples'] = $this->getGlobalExamples($key, $global['usage']);
                }
            }
        }

        return $globals;
    }

    /**
     * Get tag hints.
     *
     * @return array<string, mixed>
     */
    private function getTagHints(bool $includeExamples): array
    {
        $tags = [
            'collection' => [
                'description' => 'Loop through entries in a collection',
                'syntax' => '{{ collection:handle }}{{ /collection:handle }}',
                'parameters' => ['limit', 'sort', 'filter', 'paginate'],
            ],
            'taxonomy' => [
                'description' => 'Loop through taxonomy terms',
                'syntax' => '{{ taxonomy:handle }}{{ /taxonomy:handle }}',
                'parameters' => ['limit', 'sort'],
            ],
            'nav' => [
                'description' => 'Generate navigation',
                'syntax' => '{{ nav:main }}{{ /nav:main }}',
                'parameters' => ['from', 'max_depth', 'include_home'],
            ],
            'form' => [
                'description' => 'Generate forms',
                'syntax' => '{{ form:handle }}{{ /form:handle }}',
                'parameters' => ['redirect', 'error_redirect'],
            ],
            'glide' => [
                'description' => 'Image manipulation',
                'syntax' => '{{ glide:image width="300" }}',
                'parameters' => ['width', 'height', 'quality', 'format'],
            ],
            'partial' => [
                'description' => 'Include partial templates',
                'syntax' => '{{ partial:name }}',
                'parameters' => ['src'],
            ],
            'section' => [
                'description' => 'Define template sections',
                'syntax' => '{{ section:name }}{{ /section:name }}',
                'parameters' => [],
            ],
            'yield' => [
                'description' => 'Output template sections',
                'syntax' => '{{ yield:name }}',
                'parameters' => [],
            ],
        ];

        if ($includeExamples) {
            foreach ($tags as $tag => &$tagData) {
                $tagData['examples'] = $this->getTagExamples($tag, $tagData);
            }
        }

        return $tags;
    }

    /**
     * Get Antlers usage for a field.
     *
     * @param  array<string, mixed>  $field
     *
     * @return array<string, mixed>
     */
    private function getAntlersUsage(string $handle, array $field): array
    {
        $type = $field['type'] ?? 'text';
        $usage = [];

        switch ($type) {
            case 'text':
            case 'textarea':
            case 'markdown':
                $usage = [
                    'basic' => "{{ {$handle} }}",
                    'with_formatting' => "{{ {$handle} | markdown }}",
                ];
                break;
            case 'bard':
            case 'redactor':
                $usage = [
                    'basic' => "{{ {$handle} }}",
                    'as_text' => "{{ {$handle} | strip_tags }}",
                ];
                break;
            case 'assets':
                $single = isset($field['max_files']) && $field['max_files'] == 1;
                if ($single) {
                    $usage = [
                        'basic' => "{{ {$handle} }}",
                        'with_alt' => "{{ {$handle}:alt }}",
                        'with_glide' => "{{ glide:{$handle} width='300' }}",
                        'with_focus' => "{{ glide:{$handle} width='300' focus='true' }}",
                    ];
                } else {
                    $usage = [
                        'loop' => "{{ {$handle} }}{{ url }}{{ /{$handle} }}",
                        'with_glide' => "{{ {$handle} }}{{ glide:url width='300' }}{{ /{$handle} }}",
                    ];
                }
                break;
            case 'entries':
                $usage = [
                    'basic' => "{{ {$handle} }}{{ title }}{{ /{$handle} }}",
                    'with_url' => "{{ {$handle} }}<a href=\"{{ url }}\">{{ title }}</a>{{ /{$handle} }}",
                ];
                break;
            case 'taxonomy':
                $usage = [
                    'basic' => "{{ {$handle} }}{{ title }}{{ /{$handle} }}",
                    'as_links' => "{{ {$handle} }}<a href=\"{{ url }}\">{{ title }}</a>{{ /{$handle} }}",
                ];
                break;
            case 'date':
                $usage = [
                    'basic' => "{{ {$handle} }}",
                    'formatted' => "{{ {$handle} format='M j, Y' }}",
                    'relative' => "{{ {$handle} | relative }}",
                ];
                break;
            case 'toggle':
                $usage = [
                    'conditional' => "{{ if {$handle} }}Yes{{ else }}No{{ /if }}",
                    'boolean' => "{{ {$handle} ? 'Yes' : 'No' }}",
                ];
                break;
            case 'select':
            case 'radio':
                $usage = [
                    'basic' => "{{ {$handle} }}",
                ];
                break;
            case 'replicator':
                $usage = [
                    'loop' => "{{ {$handle} }}{{ if type == 'text' }}{{ text }}{{ /if }}{{ /{$handle} }}",
                ];
                break;
            default:
                $usage = [
                    'basic' => "{{ {$handle} }}",
                ];
        }

        return $usage;
    }

    /**
     * Get Set Antlers usage.
     *
     * @param  array<string, mixed>  $setData
     *
     * @return array<string, mixed>
     */
    private function getSetAntlersUsage(string $fieldHandle, string $setHandle, array $setData): array
    {
        return [
            'conditional' => "{{ {$fieldHandle} }}{{ if type == '{$setHandle}' }}...{{ /if }}{{ /{$fieldHandle} }}",
            'loop' => "{{ {$fieldHandle} }}{{ partial:{$setHandle} }}{{ /{$fieldHandle} }}",
        ];
    }

    /**
     * Get loop syntax for field types.
     */
    private function getLoopSyntax(string $handle, string $type): string
    {
        switch ($type) {
            case 'entries':
            case 'taxonomy':
            case 'users':
            case 'assets':
                return "{{ {$handle} }}...{{ /{$handle} }}";
            case 'bard':
            case 'replicator':
                return "{{ {$handle} }}{{ if type == 'set_name' }}...{{ /if }}{{ /{$handle} }}";
            case 'grid':
                return "{{ {$handle} }}...{{ /{$handle} }}";
            default:
                return "{{ {$handle} }}...{{ /{$handle} }}";
        }
    }

    /**
     * Get available variables in loops.
     *
     * @return array<string, mixed>
     */
    private function getLoopVariables(string $type): array
    {
        $common = ['index', 'count', 'first', 'last', 'total_results'];

        $variables = match ($type) {
            'entries' => collect(array_merge($common, ['id', 'slug', 'url', 'title', 'date', 'collection']))->mapWithKeys(fn ($item) => [$item => 'string'])->all(),
            'taxonomy' => collect(array_merge($common, ['id', 'slug', 'url', 'title', 'taxonomy']))->mapWithKeys(fn ($item) => [$item => 'string'])->all(),
            'users' => collect(array_merge($common, ['id', 'email', 'name', 'roles']))->mapWithKeys(fn ($item) => [$item => 'string'])->all(),
            'assets' => collect(array_merge($common, ['url', 'alt', 'title', 'filename', 'extension', 'size']))->mapWithKeys(fn ($item) => [$item => 'string'])->all(),
            'bard', 'replicator' => collect(array_merge($common, ['type']))->mapWithKeys(fn ($item) => [$item => 'string'])->all(),
            default => collect($common)->mapWithKeys(fn ($item) => [$item => 'string'])->all(),
        };

        return $variables;
    }

    /**
     * Get field examples.
     *
     * @param  array<string, mixed>  $field
     *
     * @return array<string, mixed>
     */
    private function getFieldExamples(string $handle, array $field): array
    {
        // This would return comprehensive examples for each field type
        // Implementation would be similar to FieldTypesTool examples
        return [
            'basic_usage' => "{{ {$handle} }}",
            'in_template' => "<!-- Use in your template -->\n<p>{{ {$handle} }}</p>",
        ];
    }

    /**
     * Get set examples.
     *
     * @param  array<string, mixed>  $setData
     *
     * @return array<string, mixed>
     */
    private function getSetExamples(string $fieldHandle, string $setHandle, array $setData): array
    {
        return [
            'conditional' => "{{ {$fieldHandle} }}\n  {{ if type == '{$setHandle}' }}\n    <!-- {$setHandle} content -->\n  {{ /if }}\n{{ /{$fieldHandle} }}",
            'partial' => "{{ {$fieldHandle} }}\n  {{ partial:sets/{$setHandle} }}\n{{ /{$fieldHandle} }}",
        ];
    }

    /**
     * Get loop examples.
     *
     * @return array<string, mixed>
     */
    private function getLoopExamples(string $handle, string $type): array
    {
        switch ($type) {
            case 'entries':
                return [
                    'basic' => "{{ {$handle} }}\n  <article>\n    <h2><a href=\"{{ url }}\">{{ title }}</a></h2>\n    <p>{{ excerpt }}</p>\n  </article>\n{{ /{$handle} }}",
                    'with_conditions' => "{{ {$handle} }}\n  {{ if first }}<div class=\"featured\">{{ /if }}\n  <h3>{{ title }}</h3>\n  {{ if first }}</div>{{ /if }}\n{{ /{$handle} }}",
                ];
            case 'assets':
                return [
                    'gallery' => "{{ {$handle} }}\n  <img src=\"{{ glide:url width='300' }}\" alt=\"{{ alt }}\">\n{{ /{$handle} }}",
                ];
            default:
                return [
                    'basic' => "{{ {$handle} }}\n  {{ value }}\n{{ /{$handle} }}",
                ];
        }
    }

    /**
     * Get global examples.
     *
     * @return array<string, mixed>
     */
    private function getGlobalExamples(string $key, string $usage): array
    {
        return [
            'basic' => $usage,
            'in_context' => "<!-- Example usage -->\n{$usage}",
        ];
    }

    /**
     * Get tag examples.
     *
     * @param  array<string, mixed>  $tagData
     *
     * @return array<string, mixed>
     */
    private function getTagExamples(string $tag, array $tagData): array
    {
        switch ($tag) {
            case 'collection':
                return [
                    'basic' => "{{ collection:blog }}\n  <h2>{{ title }}</h2>\n  <p>{{ excerpt }}</p>\n{{ /collection:blog }}",
                    'with_params' => "{{ collection:blog limit=\"5\" sort=\"date:desc\" }}\n  <article>{{ title }}</article>\n{{ /collection:blog }}",
                ];
            case 'nav':
                return [
                    'basic' => "{{ nav:main }}\n  <a href=\"{{ url }}\">{{ title }}</a>\n{{ /nav:main }}",
                ];
            default:
                return [
                    'basic' => $tagData['syntax'],
                    'with_context' => "<!-- Example in template -->\n" . $tagData['syntax'],
                ];
        }
    }

    /**
     * Get performance optimization tips.
     *
     * @return array<string, mixed>
     */
    private function getPerformanceTips(): array
    {
        return [
            'collections' => [
                'tip' => 'Use limit and sort parameters for better performance',
                'example' => '{{ collection:blog limit="10" sort="date:desc" }}',
                'avoid' => 'Avoid deeply nested collection loops',
            ],
            'assets' => [
                'tip' => 'Always specify dimensions for Glide images',
                'example' => '{{ glide:image width="300" height="200" quality="80" }}',
                'avoid' => 'Avoid processing large images without constraints',
            ],
            'partials' => [
                'tip' => 'Cache frequently used partials',
                'example' => '{{ partial:cached-component }}',
                'avoid' => 'Avoid excessive partial nesting (>5 levels)',
            ],
            'conditionals' => [
                'tip' => 'Keep conditional logic simple',
                'example' => '{{ if field }}{{ field }}{{ /if }}',
                'avoid' => 'Avoid complex multi-operator conditions in templates',
            ],
            'loops' => [
                'tip' => 'Use pagination for large datasets',
                'example' => '{{ collection:blog paginate="10" }}',
                'avoid' => 'Avoid querying relationships inside loops',
            ],
        ];
    }

    /**
     * Get edge case warnings.
     *
     * @return array<string, mixed>
     */
    private function getEdgeCaseWarnings(): array
    {
        return [
            'memory_usage' => [
                'warning' => 'Large collections can cause memory issues',
                'solution' => 'Use pagination and limit parameters',
                'example' => '{{ collection:blog limit="50" paginate="10" }}',
            ],
            'recursive_partials' => [
                'warning' => 'Recursive partial includes can cause infinite loops',
                'solution' => 'Implement depth limits and cycle detection',
                'example' => '{{ partial:navigation depth="3" }}',
            ],
            'unescaped_output' => [
                'warning' => 'Triple braces output unescaped HTML',
                'solution' => 'Ensure content is sanitized before using {{{ }}}',
                'example' => '{{ content | strip_tags }} vs {{{ content }}}',
            ],
            'dynamic_content' => [
                'warning' => 'Dynamic content (dates, random) prevents full-page caching',
                'solution' => 'Use fragment caching or Edge Side Includes',
                'example' => '{{ cache:fragment }}{{ now }}{{ /cache:fragment }}',
            ],
            'large_loops' => [
                'warning' => 'Loops with >100 items can impact performance',
                'solution' => 'Implement pagination or lazy loading',
                'example' => '{{ entries limit="25" offset="{{ segment_2 * 25 }}" }}',
            ],
        ];
    }
}
