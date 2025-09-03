<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Blueprints\ScanBlueprintsTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Blade Hints')]
#[IsReadOnly]
class BladeHintsTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.development.blade-hints';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get hints for Statamic tags, components, and best practices in Blade templates';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('blueprint')
            ->description('Blueprint handle to get component props from (optional)')
            ->optional()
            ->string('context')
            ->description('Template context (layout, entry, collection, etc.)')
            ->optional()
            ->boolean('include_components')
            ->description('Include Blade component suggestions')
            ->optional()
            ->boolean('include_examples')
            ->description('Include usage examples')
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
        $includeComponents = $arguments['include_components'] ?? true;
        $includeExamples = $arguments['include_examples'] ?? true;

        $hints = [
            'context' => $context,
            'statamic_tags' => $this->getStatamicTags($includeExamples),
            'blade_components' => $includeComponents ? $this->getBladeComponents($includeExamples) : [],
            'best_practices' => $this->getBestPractices($includeExamples),
            'anti_patterns' => $this->getAntiPatterns($includeExamples),
        ];

        // Add blueprint-specific component props if requested
        if ($blueprintHandle) {
            $componentProps = $this->getBlueprintComponentProps($blueprintHandle);
            if ($componentProps) {
                $hints['component_props'] = $componentProps;
            }
        }

        return $hints;
    }

    /**
     * Get available Statamic tags for Blade.
     *
     * @return array<string, mixed>
     */
    private function getStatamicTags(bool $includeExamples): array
    {
        $tags = [
            'collection' => [
                'description' => 'Loop through entries from collections with advanced filtering',
                'syntax' => '<s:collection from="collection_handle" />',
                'blade_syntax' => '<statamic:collection from="collection_handle" />',
                'parameters' => [
                    'from' => 'Collection handle(s) - supports wildcards (*) and multiple (|)',
                    'not_from' => 'Exclude specific collections when using wildcards',
                    'show_future' => 'Display future-dated entries (default: false)',
                    'show_past' => 'Display past entries (default: true)',
                    'since' => 'Earliest date for entries',
                    'until' => 'Latest date for entries',
                    'sort' => 'Sort entries by field(s) with direction (field:asc|desc)',
                    'limit' => 'Maximum number of entries to return',
                    'filter' => 'Apply custom filtering conditions',
                    'query_scope' => 'Apply custom query scope',
                    'offset' => 'Skip initial results',
                    'paginate' => 'Enable pagination with limit per page',
                    'as' => 'Alias entries to a new variable name',
                    'scope' => 'Prefix entry variables with scope',
                ],
                'variables' => ['title', 'content', 'slug', 'url', 'date', 'published', 'first', 'last', 'count', 'no_results', 'total_results'],
                'filtering' => [
                    'field_conditions' => 'title:contains="awesome", status:is="published"',
                    'taxonomy_filtering' => 'taxonomy:tags="tag-name"',
                    'date_filtering' => 'date:after="2023-01-01"',
                ],
            ],
            'form' => [
                'description' => 'Create and handle Statamic forms',
                'syntax' => '<s:form:create handle="contact" />',
                'blade_syntax' => '<statamic:form:create in="contact" />',
                'parameters' => [
                    'handle|is|in|form' => 'Form handle/name',
                    'redirect' => 'URL to redirect after successful submission',
                    'error_redirect' => 'URL to redirect after failed submission',
                    'js' => 'Enable conditional fields with JavaScript drivers',
                ],
                'variables' => ['fields', 'errors', 'old', 'success'],
                'features' => [
                    'dynamic_rendering' => 'Render form fields dynamically from blueprint',
                    'conditional_fields' => 'Show/hide fields based on conditions',
                    'validation' => 'Built-in validation with error handling',
                    'success_handling' => 'Success message and redirect management',
                ],
            ],
            'taxonomy' => [
                'description' => 'Loop through taxonomy terms',
                'syntax' => '<s:taxonomy from="taxonomy_handle" />',
                'blade_syntax' => '<statamic:taxonomy from="taxonomy_handle" />',
                'parameters' => [
                    'from' => 'Taxonomy handle(s) - supports multiple with |',
                    'sort' => 'Sort terms by field with direction (title:asc)',
                    'limit' => 'Maximum number of terms',
                    'show_future' => 'Include future-dated terms',
                    'show_past' => 'Include past terms (default: true)',
                ],
                'variables' => ['title', 'slug', 'url', 'content', 'entries_count', 'first', 'last', 'count'],
            ],
            'nav' => [
                'description' => 'Generate navigation menus from navigation structures',
                'syntax' => '<s:nav handle="main" />',
                'blade_syntax' => '<statamic:nav from="main" />',
                'parameters' => [
                    'handle|from' => 'Navigation handle/structure name',
                    'tree' => 'Navigation tree handle',
                    'from' => 'Starting point URL or ID',
                    'max_depth' => 'Maximum nesting depth',
                    'include_home' => 'Include home page in navigation',
                    'sort' => 'Override sort order',
                ],
                'variables' => ['url', 'title', 'children', 'is_current', 'is_parent', 'is_active', 'depth', 'page'],
            ],
            'glide' => [
                'description' => 'Image manipulation and optimization',
                'syntax' => '<x-statamic:glide :src="image" width="300" />',
                'parameters' => [
                    'src' => 'Image source',
                    'width' => 'Image width',
                    'height' => 'Image height',
                    'fit' => 'Fit mode (contain, crop, etc.)',
                    'quality' => 'JPEG quality (1-100)',
                    'format' => 'Output format (jpg, png, webp)',
                ],
                'slot_variables' => ['url', 'width', 'height'],
            ],
            'assets' => [
                'description' => 'Loop through assets from asset containers',
                'syntax' => '<s:assets container="main" />',
                'blade_syntax' => '<statamic:assets container="main" />',
                'parameters' => [
                    'container|from' => 'Asset container handle',
                    'folder' => 'Specific folder path within container',
                    'recursive' => 'Include assets from subfolders (default: false)',
                    'limit' => 'Maximum number of assets to return',
                    'sort' => 'Sort by field with direction (filename:asc)',
                    'filter' => 'Filter by file extension or other criteria',
                ],
                'variables' => ['url', 'title', 'alt', 'filename', 'basename', 'extension', 'size', 'last_modified', 'width', 'height', 'mime_type'],
            ],
            'search' => [
                'description' => 'Perform full-text searches across content',
                'syntax' => '<s:search:results />',
                'blade_syntax' => '<statamic:search:results />',
                'parameters' => [
                    'query' => 'Search query string',
                    'index' => 'Search index to use (if multiple configured)',
                    'collections' => 'Limit search to specific collections',
                    'limit' => 'Maximum number of results',
                    'supplement_data' => 'Include additional entry data (default: true)',
                ],
                'variables' => ['title', 'url', 'collection', 'search_score', 'result', 'no_results'],
                'features' => [
                    'full_text_search' => 'Search across all content fields',
                    'collection_filtering' => 'Limit to specific content types',
                    'scoring' => 'Relevance scoring for results',
                ],
            ],
            'partial' => [
                'description' => 'Include reusable template partials with slots',
                'syntax' => '<s:partial src="partial-name" />',
                'blade_syntax' => '<statamic:partial src="partial-name" />',
                'parameters' => [
                    'src|name' => 'Partial template name/path',
                ],
                'variables' => 'All variables from parent template context',
                'features' => [
                    'slots' => 'Support for named slots like Blade components',
                    'scoped_variables' => 'Access parent template variables',
                    'flexible_paths' => 'Support for nested partial paths',
                ],
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
     * Get recommended Blade components for Statamic.
     *
     * @return array<string, mixed>
     */
    private function getBladeComponents(bool $includeExamples): array
    {
        $components = [
            'layout' => [
                'description' => 'Main layout component',
                'file' => 'resources/views/components/layout.blade.php',
                'props' => ['title', 'meta_description', 'canonical_url'],
                'usage' => '<x-layout :title="$entry->title">',
            ],
            'content-block' => [
                'description' => 'Replicator/Bard set renderer',
                'file' => 'resources/views/components/content-block.blade.php',
                'props' => ['block', 'index'],
                'usage' => '<x-content-block :block="$block" />',
            ],
            'entry-card' => [
                'description' => 'Entry listing component',
                'file' => 'resources/views/components/entry-card.blade.php',
                'props' => ['entry', 'show_excerpt', 'show_date'],
                'usage' => '<x-entry-card :entry="$entry" />',
            ],
            'breadcrumbs' => [
                'description' => 'Breadcrumb navigation',
                'file' => 'resources/views/components/breadcrumbs.blade.php',
                'props' => ['items', 'separator'],
                'usage' => '<x-breadcrumbs :items="$breadcrumbs" />',
            ],
            'seo-meta' => [
                'description' => 'SEO meta tags',
                'file' => 'resources/views/components/seo-meta.blade.php',
                'props' => ['title', 'description', 'image', 'canonical'],
                'usage' => '<x-seo-meta :title="$entry->seo_title" />',
            ],
            'social-share' => [
                'description' => 'Social sharing buttons',
                'file' => 'resources/views/components/social-share.blade.php',
                'props' => ['url', 'title', 'platforms'],
                'usage' => '<x-social-share :url="$entry->url" />',
            ],
        ];

        if ($includeExamples) {
            foreach ($components as $component => &$componentData) {
                $componentData['examples'] = $this->getComponentExamples($component, $componentData);
            }
        }

        return $components;
    }

    /**
     * Get Statamic best practices for Blade.
     *
     * @return array<string, mixed>
     */
    private function getBestPractices(bool $includeExamples): array
    {
        $practices = [
            'use_statamic_tags' => [
                'title' => 'Use Statamic Tags Instead of Facades',
                'description' => 'Prefer Statamic Blade components over direct facade calls',
                'priority' => 'high',
                'category' => 'data_access',
            ],
            'component_driven' => [
                'title' => 'Create Reusable Components',
                'description' => 'Build components for common patterns like entry cards, content blocks',
                'priority' => 'medium',
                'category' => 'architecture',
            ],
            'avoid_complex_logic' => [
                'title' => 'Keep Templates Simple',
                'description' => 'Move complex logic to controllers or view composers',
                'priority' => 'high',
                'category' => 'maintainability',
            ],
            'use_slots_effectively' => [
                'title' => 'Leverage Blade Slots',
                'description' => 'Use named slots for flexible component composition',
                'priority' => 'medium',
                'category' => 'components',
            ],
            'handle_empty_states' => [
                'title' => 'Handle Empty States',
                'description' => 'Always provide fallbacks for when content is missing',
                'priority' => 'medium',
                'category' => 'user_experience',
            ],
            'optimize_images' => [
                'title' => 'Optimize Images with Glide',
                'description' => 'Always use Glide for image optimization and responsive images',
                'priority' => 'high',
                'category' => 'performance',
            ],
        ];

        if ($includeExamples) {
            $practices['use_statamic_tags']['examples'] = [
                'good' => '<x-statamic:entries :from="\'blog\'"><h2>{{ $entry->title }}</h2></x-statamic:entries>',
                'bad' => '@php $entries = \\Statamic\\Facades\\Entry::whereCollection(\'blog\')->get(); @endphp',
            ];

            $practices['component_driven']['examples'] = [
                'good' => '<x-entry-card :entry="$entry" />',
                'bad' => '<!-- Duplicated HTML for each entry listing -->',
            ];
        }

        return $practices;
    }

    /**
     * Get anti-patterns to avoid in Blade with Statamic.
     *
     * @return array<string, mixed>
     */
    private function getAntiPatterns(bool $includeExamples): array
    {
        $antiPatterns = [
            'inline_php' => [
                'title' => 'Avoid @php in Templates',
                'description' => 'Don\'t use @php blocks for data fetching or complex logic',
                'severity' => 'error',
                'category' => 'code_quality',
            ],
            'facade_calls' => [
                'title' => 'Avoid Direct Facade Calls',
                'description' => 'Don\'t call Statamic facades directly in views',
                'severity' => 'error',
                'category' => 'architecture',
            ],
            'database_queries' => [
                'title' => 'No Database Queries in Views',
                'description' => 'Don\'t perform database queries directly in templates',
                'severity' => 'error',
                'category' => 'performance',
            ],
            'hardcoded_content' => [
                'title' => 'Avoid Hardcoded Content',
                'description' => 'Use globals, blueprints, or configuration instead',
                'severity' => 'warning',
                'category' => 'maintainability',
            ],
            'missing_alt_text' => [
                'title' => 'Always Include Alt Text',
                'description' => 'Ensure images have proper alt attributes for accessibility',
                'severity' => 'warning',
                'category' => 'accessibility',
            ],
            'unescaped_output' => [
                'title' => 'Escape User Content',
                'description' => 'Use proper escaping for user-generated content',
                'severity' => 'error',
                'category' => 'security',
            ],
        ];

        if ($includeExamples) {
            $antiPatterns['inline_php']['examples'] = [
                'bad' => '@php $entries = Entry::whereCollection(\'blog\')->get(); @endphp',
                'good' => '<x-statamic:entries :from="\'blog\'">',
            ];

            $antiPatterns['facade_calls']['examples'] = [
                'bad' => '{{ \\Statamic\\Facades\\Entry::find($id)->title }}',
                'good' => '{{ $entry->title }}',
            ];
        }

        return $antiPatterns;
    }

    /**
     * Get component props based on blueprint.
     *
     * @return array<string, mixed>|null
     */
    private function getBlueprintComponentProps(string $blueprintHandle): ?array
    {
        // Get blueprint data using BlueprintsScanTool
        $scanTool = new ScanBlueprintsTool;
        $scanData = $scanTool->execute([]);

        if (! isset($scanData['blueprints'][$blueprintHandle])) {
            return null;
        }

        $blueprint = $scanData['blueprints'][$blueprintHandle];
        $componentProps = [];

        // Generate component suggestions based on blueprint fields
        foreach ($blueprint['fields'] as $fieldHandle => $field) {
            $fieldType = $field['type'] ?? 'text';

            if (in_array($fieldType, ['bard', 'replicator'])) {
                // Suggest content block components
                if (! empty($field['sets'])) {
                    foreach ($field['sets'] as $setHandle => $setData) {
                        $componentName = "content-{$setHandle}";
                        $componentProps[$componentName] = [
                            'description' => "Component for {$setData['display']} set",
                            'props' => $this->generatePropsFromSet($setData),
                            'usage' => "<x-{$componentName} :data=\"\$block\" />",
                        ];
                    }
                }
            }
        }

        // Suggest main entry component
        $componentProps['entry-' . $blueprintHandle] = [
            'description' => "Component for {$blueprint['title']} entries",
            'props' => $this->generatePropsFromBlueprint($blueprint),
            'usage' => "<x-entry-{$blueprintHandle} :entry=\"\$entry\" />",
        ];

        return $componentProps;
    }

    /**
     * Generate props from blueprint set.
     *
     * @param  array<string, mixed>  $setData
     *
     * @return array<string, mixed>
     */
    private function generatePropsFromSet(array $setData): array
    {
        $props = [];

        foreach ($setData['fields'] as $field) {
            $fieldHandle = $field['handle'];
            $fieldData = $field['field'];
            $fieldType = $fieldData['type'] ?? 'text';

            $props[$fieldHandle] = [
                'type' => $this->getPhpType($fieldType),
                'description' => $fieldData['display'] ?? $fieldHandle,
                'required' => $fieldData['required'] ?? false,
            ];
        }

        return $props;
    }

    /**
     * Generate props from entire blueprint.
     *
     * @param  array<string, mixed>  $blueprint
     *
     * @return array<string, mixed>
     */
    private function generatePropsFromBlueprint(array $blueprint): array
    {
        $props = ['entry' => ['type' => 'Entry', 'required' => true]];

        foreach ($blueprint['fields'] as $fieldHandle => $field) {
            $fieldType = $field['type'] ?? 'text';

            $props[$fieldHandle] = [
                'type' => $this->getPhpType($fieldType),
                'description' => $field['display'] ?? $fieldHandle,
                'required' => $field['required'] ?? false,
            ];
        }

        return $props;
    }

    /**
     * Get PHP type for Blade component prop.
     */
    private function getPhpType(string $fieldType): string
    {
        return match ($fieldType) {
            'text', 'textarea', 'markdown' => 'string',
            'toggle' => 'bool',
            'integer', 'range' => 'int',
            'float' => 'float',
            'date', 'time' => 'Carbon',
            'assets', 'entries', 'taxonomy', 'users' => 'Collection',
            'bard', 'replicator', 'grid' => 'array',
            default => 'mixed',
        };
    }

    /**
     * Get examples for Statamic tags.
     *
     * @param  array<string, mixed>  $tagData
     *
     * @return array<string, mixed>
     */
    private function getTagExamples(string $tag, array $tagData): array
    {
        switch ($tag) {
            case 'entries':
                return [
                    'basic' => '<x-statamic:entries :from="\'blog\'">
    <article>
        <h2>{{ $entry->title }}</h2>
        <p>{{ $entry->excerpt }}</p>
        <a href="{{ $entry->url }}">Read more</a>
    </article>
</x-statamic:entries>',
                    'with_pagination' => '<x-statamic:entries :from="\'blog\'" :limit="10" paginate="true">
    <x-slot:entries>
        <x-entry-card :entry="$entry" />
    </x-slot:entries>
    <x-slot:pagination>
        {{ $paginate->links() }}
    </x-slot:pagination>
</x-statamic:entries>',
                ];
            case 'glide':
                return [
                    'responsive' => '<x-statamic:glide :src="$entry->featured_image" 
    width="800" 
    height="600" 
    fit="crop" 
    quality="85" />',
                    'with_srcset' => '<picture>
    <source media="(min-width: 768px)" 
            srcset="{{ glide($entry->featured_image)->width(800)->height(600) }}">
    <x-statamic:glide :src="$entry->featured_image" 
        width="400" height="300" />
</picture>',
                ];
            default:
                return [
                    'basic' => $tagData['syntax'],
                ];
        }
    }

    /**
     * Get examples for Blade components.
     *
     * @param  array<string, mixed>  $componentData
     *
     * @return array<string, mixed>
     */
    private function getComponentExamples(string $component, array $componentData): array
    {
        switch ($component) {
            case 'content-block':
                return [
                    'basic' => '<x-content-block :block="$block" />',
                    'with_conditionals' => '@switch($block[\'type\'])
    @case(\'text\')
        <x-content-text :data="$block" />
        @break
    @case(\'image\')
        <x-content-image :data="$block" />
        @break
    @default
        <x-content-block :block="$block" />
@endswitch',
                ];
            case 'entry-card':
                return [
                    'basic' => '<x-entry-card :entry="$entry" />',
                    'with_options' => '<x-entry-card 
    :entry="$entry" 
    :show-excerpt="true" 
    :show-date="true" 
    class="mb-6" />',
                ];
            default:
                return [
                    'basic' => $componentData['usage'],
                ];
        }
    }
}
