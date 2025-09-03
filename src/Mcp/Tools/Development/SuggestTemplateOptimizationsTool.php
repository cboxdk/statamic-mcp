<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Suggest Template Optimizations')]
#[IsReadOnly]
class SuggestTemplateOptimizationsTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.templates.suggest-optimizations';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Analyze templates and suggest specific optimizations with code examples';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('template_path')
            ->description('Path to template file or directory to analyze')
            ->required()
            ->string('template_type')
            ->description('Template type: antlers, blade, or auto-detect')
            ->optional()
            ->string('optimization_focus')
            ->description('Focus area: performance, maintainability, security, or all')
            ->optional()
            ->boolean('include_code_examples')
            ->description('Include before/after code examples for suggestions')
            ->optional()
            ->boolean('prioritize_suggestions')
            ->description('Prioritize suggestions by impact and effort')
            ->optional()
            ->integer('max_suggestions')
            ->description('Maximum number of suggestions to return')
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
        $templatePath = $arguments['template_path'];
        $templateType = $arguments['template_type'] ?? 'auto-detect';
        $optimizationFocus = $arguments['optimization_focus'] ?? 'all';
        $includeCodeExamples = $arguments['include_code_examples'] ?? true;
        $prioritizeSuggestions = $arguments['prioritize_suggestions'] ?? true;
        $maxSuggestions = $arguments['max_suggestions'] ?? 20;

        try {
            $templates = $this->collectTemplates($templatePath);

            if (empty($templates)) {
                return $this->createErrorResponse('No templates found at the specified path')->toArray();
            }

            $analysis = [
                'templates_analyzed' => count($templates),
                'suggestions' => [],
                'statistics' => [
                    'total_suggestions' => 0,
                    'high_impact_suggestions' => 0,
                    'quick_wins' => 0,
                    'estimated_time_saved' => 0,
                ],
                'categories' => [
                    'performance' => [],
                    'maintainability' => [],
                    'security' => [],
                    'best_practices' => [],
                ],
            ];

            foreach ($templates as $template) {
                $templateSuggestions = $this->analyzeTemplateForOptimizations(
                    $template,
                    $templateType,
                    $optimizationFocus,
                    $includeCodeExamples
                );

                $analysis['suggestions'] = array_merge($analysis['suggestions'], $templateSuggestions);

                // Categorize suggestions
                foreach ($templateSuggestions as $suggestion) {
                    $category = $suggestion['category'];
                    $analysis['categories'][$category][] = $suggestion;

                    if ($suggestion['impact'] === 'high') {
                        $analysis['statistics']['high_impact_suggestions']++;
                    }

                    if ($suggestion['effort'] === 'low' && $suggestion['impact'] !== 'low') {
                        $analysis['statistics']['quick_wins']++;
                    }

                    $analysis['statistics']['estimated_time_saved'] += $suggestion['estimated_time_saved'] ?? 0;
                }
            }

            $analysis['statistics']['total_suggestions'] = count($analysis['suggestions']);

            // Prioritize suggestions if requested
            if ($prioritizeSuggestions) {
                $analysis['suggestions'] = $this->prioritizeSuggestions($analysis['suggestions']);
            }

            // Limit suggestions if requested
            if ($maxSuggestions && count($analysis['suggestions']) > $maxSuggestions) {
                $analysis['suggestions'] = array_slice($analysis['suggestions'], 0, $maxSuggestions);
            }

            return [
                'analysis' => $analysis,
                'summary' => [
                    'optimization_potential' => $this->calculateOptimizationPotential($analysis),
                    'top_categories' => $this->getTopCategories($analysis['categories']),
                    'implementation_roadmap' => $this->generateImplementationRoadmap($analysis['suggestions']),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to suggest template optimizations: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Collect templates from path.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function collectTemplates(string $templatePath): array
    {
        $templates = [];
        $basePath = resource_path('views');

        if (! str_starts_with($templatePath, '/')) {
            $fullPath = $basePath . '/' . ltrim($templatePath, '/');
        } else {
            $fullPath = $templatePath;
        }

        if (is_file($fullPath)) {
            $templates[] = [
                'path' => $fullPath,
                'relative_path' => str_replace($basePath . '/', '', $fullPath),
                'content' => file_get_contents($fullPath),
                'size' => filesize($fullPath),
            ];
        } elseif (is_dir($fullPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), ['antlers.html', 'blade.php', 'html', 'php'])) {
                    $templates[] = [
                        'path' => $file->getPathname(),
                        'relative_path' => str_replace($basePath . '/', '', $file->getPathname()),
                        'content' => file_get_contents($file->getPathname()),
                        'size' => $file->getSize(),
                    ];
                }
            }
        }

        return $templates;
    }

    /**
     * Analyze template for optimization opportunities.
     *
     * @param  array<string, mixed>  $template
     *
     * @return array<array<string, mixed>>
     */
    private function analyzeTemplateForOptimizations(
        array $template,
        string $templateType,
        string $optimizationFocus,
        bool $includeCodeExamples
    ): array {
        $content = $template['content'];
        $templateFile = $template['relative_path'];

        // Auto-detect template type
        if ($templateType === 'auto-detect') {
            $templateType = $this->detectTemplateType($templateFile, $content);
        }

        $suggestions = [];

        // Performance optimizations
        if ($optimizationFocus === 'all' || $optimizationFocus === 'performance') {
            $suggestions = array_merge($suggestions, $this->suggestPerformanceOptimizations($content, $templateFile, $templateType, $includeCodeExamples));
        }

        // Maintainability optimizations
        if ($optimizationFocus === 'all' || $optimizationFocus === 'maintainability') {
            $suggestions = array_merge($suggestions, $this->suggestMaintainabilityOptimizations($content, $templateFile, $templateType, $includeCodeExamples));
        }

        // Security optimizations
        if ($optimizationFocus === 'all' || $optimizationFocus === 'security') {
            $suggestions = array_merge($suggestions, $this->suggestSecurityOptimizations($content, $templateFile, $templateType, $includeCodeExamples));
        }

        // Best practices
        if ($optimizationFocus === 'all') {
            $suggestions = array_merge($suggestions, $this->suggestBestPractices($content, $templateFile, $templateType, $includeCodeExamples));
        }

        return $suggestions;
    }

    /**
     * Suggest performance optimizations.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function suggestPerformanceOptimizations(string $content, string $templateFile, string $templateType, bool $includeCodeExamples): array
    {
        $suggestions = [];

        if ($templateType === 'antlers') {
            // Suggest eager loading for collection loops
            if (preg_match_all('/\{\{\s*collection:(\w+)\s*\}\}(.*?)\{\{\s*\/collection:\1\s*\}\}/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $index => $match) {
                    $loopContent = $match[0];
                    $collection = $matches[1][$index][0];

                    if (preg_match('/\{\{\s*(author|user|taxonomy)\./m', $loopContent)) {
                        $suggestion = [
                            'id' => 'eager_loading_' . md5($loopContent),
                            'title' => "Add eager loading to '{$collection}' collection loop",
                            'description' => 'Collection loop accesses relationships without eager loading, causing N+1 queries',
                            'category' => 'performance',
                            'impact' => 'high',
                            'effort' => 'low',
                            'template' => $templateFile,
                            'estimated_time_saved' => 500, // ms
                        ];

                        if ($includeCodeExamples) {
                            $suggestion['examples'] = [
                                'before' => "{{ collection:{$collection} }}",
                                'after' => "{{ collection:{$collection} with=\"author,taxonomy\" }}",
                                'explanation' => 'Adding the with parameter eager loads relationships, reducing database queries',
                            ];
                        }

                        $suggestions[] = $suggestion;
                    }
                }
            }

            // Suggest caching for static content
            if (preg_match('/<header>.*?<\/header>|<footer>.*?<\/footer>|<nav>.*?<\/nav>/s', $content)) {
                $suggestions[] = [
                    'id' => 'cache_static_' . md5($templateFile),
                    'title' => 'Cache static header/footer/navigation content',
                    'description' => 'Static sections can be cached to improve performance',
                    'category' => 'performance',
                    'impact' => 'medium',
                    'effort' => 'low',
                    'template' => $templateFile,
                    'estimated_time_saved' => 100,
                    'examples' => $includeCodeExamples ? [
                        'before' => '<header>{{ nav:main }}</header>',
                        'after' => '{{ cache for="1 hour" }}
<header>{{ nav:main }}</header>
{{ /cache }}',
                        'explanation' => 'Caching prevents re-rendering static navigation on every request',
                    ] : null,
                ];
            }

            // Suggest pagination for large collections
            if (preg_match_all('/\{\{\s*collection:(\w+)(?!.*limit=)(?!.*paginate).*?\}\}/s', $content, $matches)) {
                foreach ($matches[1] as $collection) {
                    $suggestions[] = [
                        'id' => 'pagination_' . md5($collection . $templateFile),
                        'title' => "Add pagination to '{$collection}' collection",
                        'description' => 'Large collections without limits can slow page loading',
                        'category' => 'performance',
                        'impact' => 'medium',
                        'effort' => 'medium',
                        'template' => $templateFile,
                        'estimated_time_saved' => 300,
                        'examples' => $includeCodeExamples ? [
                            'before' => "{{ collection:{$collection} }}",
                            'after' => "{{ collection:{$collection} paginate=\"10\" }}
{{ entries }}
  <!-- entry content -->
{{ /entries }}
{{ paginate }}
  {{ if prev_page }}<a href=\"{{ prev_page }}\">Previous</a>{{ /if }}
  {{ if next_page }}<a href=\"{{ next_page }}\">Next</a>{{ /if }}
{{ /paginate }}",
                            'explanation' => 'Pagination limits entries per page and provides navigation',
                        ] : null,
                    ];
                }
            }
        }

        if ($templateType === 'blade') {
            // Suggest eager loading for foreach loops
            if (preg_match_all('/@foreach\s*\(\s*\$(\w+)\s+as.*?\@endforeach/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $index => $match) {
                    $loopContent = $match[0];

                    if (preg_match('/\$\w+->(author|user|category|tags)/', $loopContent)) {
                        $suggestions[] = [
                            'id' => 'blade_eager_loading_' . md5($loopContent),
                            'title' => 'Add eager loading in controller for relationship access',
                            'description' => 'Foreach loop accesses relationships without eager loading',
                            'category' => 'performance',
                            'impact' => 'high',
                            'effort' => 'medium',
                            'template' => $templateFile,
                            'estimated_time_saved' => 500,
                            'examples' => $includeCodeExamples ? [
                                'before' => '// In controller
$entries = Entry::all();

// In template
@foreach($entries as $entry)
  {{ $entry->author->name }}
@endforeach',
                                'after' => '// In controller
$entries = Entry::with(\'author\')->get();

// Template remains the same',
                                'explanation' => 'Eager loading in the controller prevents N+1 queries in the template',
                            ] : null,
                        ];
                    }
                }
            }
        }

        return $suggestions;
    }

    /**
     * Suggest maintainability optimizations.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function suggestMaintainabilityOptimizations(string $content, string $templateFile, string $templateType, bool $includeCodeExamples): array
    {
        $suggestions = [];

        // Suggest extracting repeated code to partials
        $lineCount = substr_count($content, "\n") + 1;
        if ($lineCount > 100) {
            $suggestions[] = [
                'id' => 'extract_partials_' . md5($templateFile),
                'title' => 'Extract repeated sections to partials',
                'description' => "Long template ({$lineCount} lines) could benefit from being split into smaller, reusable partials",
                'category' => 'maintainability',
                'impact' => 'medium',
                'effort' => 'medium',
                'template' => $templateFile,
                'estimated_time_saved' => 0,
                'examples' => $includeCodeExamples ? [
                    'before' => '<!-- Long template with repeated sections -->
<div class="card">
  <!-- 50+ lines of content -->
</div>',
                    'after' => $templateType === 'antlers' ?
                        '{{ partial:card }}' :
                        '@include(\'partials.card\')',
                    'explanation' => 'Breaking large templates into focused partials improves readability and reusability',
                ] : null,
            ];
        }

        // Suggest consolidating inline styles
        if (preg_match_all('/<[^>]+style\s*=\s*["\'][^"\']+["\']/i', $content, $matches)) {
            if (count($matches[0]) > 5) {
                $suggestions[] = [
                    'id' => 'extract_inline_styles_' . md5($templateFile),
                    'title' => 'Extract inline styles to CSS classes',
                    'description' => 'Multiple inline styles found - consider extracting to reusable CSS classes',
                    'category' => 'maintainability',
                    'impact' => 'low',
                    'effort' => 'medium',
                    'template' => $templateFile,
                    'estimated_time_saved' => 0,
                    'examples' => $includeCodeExamples ? [
                        'before' => '<div style="padding: 1rem; margin: 0.5rem; border: 1px solid #ccc;">Content</div>',
                        'after' => '<!-- In CSS -->
.card { padding: 1rem; margin: 0.5rem; border: 1px solid #ccc; }

<!-- In template -->
<div class="card">Content</div>',
                        'explanation' => 'CSS classes are more maintainable and can be reused across templates',
                    ] : null,
                ];
            }
        }

        // Suggest using constants for repeated values
        if (preg_match_all('/["\']([^"\']{10,})["\']/', $content, $matches)) {
            $repeatedStrings = array_filter(array_count_values($matches[1]), fn ($count) => $count > 2);

            if (! empty($repeatedStrings)) {
                $suggestions[] = [
                    'id' => 'extract_constants_' . md5($templateFile),
                    'title' => 'Extract repeated strings to constants or variables',
                    'description' => 'Repeated string values found - consider extracting to constants',
                    'category' => 'maintainability',
                    'impact' => 'low',
                    'effort' => 'low',
                    'template' => $templateFile,
                    'repeated_strings' => array_keys($repeatedStrings),
                    'examples' => $includeCodeExamples ? [
                        'before' => '{{ if status == "published" }}
  ...
{{ elseif status == "published" }}',
                        'after' => $templateType === 'antlers' ?
                            '{{ published_status = "published" }}
{{ if status == published_status }}' :
                            '@php($publishedStatus = "published")
@if($status === $publishedStatus)',
                        'explanation' => 'Constants make it easier to maintain and update repeated values',
                    ] : null,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Suggest security optimizations.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function suggestSecurityOptimizations(string $content, string $templateFile, string $templateType, bool $includeCodeExamples): array
    {
        $suggestions = [];

        // Check for unescaped output
        if ($templateType === 'blade') {
            if (preg_match_all('/\{\!\!\s*\$[^}]+\!\!\}/', $content, $matches)) {
                foreach ($matches[0] as $match) {
                    $suggestions[] = [
                        'id' => 'escape_output_' . md5($match . $templateFile),
                        'title' => 'Review unescaped output for XSS vulnerability',
                        'description' => 'Unescaped output detected - ensure content is safe from XSS attacks',
                        'category' => 'security',
                        'impact' => 'high',
                        'effort' => 'low',
                        'template' => $templateFile,
                        'code_snippet' => $match,
                        'examples' => $includeCodeExamples ? [
                            'before' => '{!! $userInput !!}',
                            'after' => '{{ $userInput }}
<!-- Or if HTML is needed: -->
{!! Purify::clean($userInput) !!}',
                            'explanation' => 'Always escape user input unless you explicitly need HTML and have sanitized it',
                        ] : null,
                    ];
                }
            }
        }

        if ($templateType === 'antlers') {
            // Check for raw/unescaped modifiers
            if (preg_match_all('/\{\{\s*[^}]*\|\s*raw\s*\}\}/', $content, $matches)) {
                foreach ($matches[0] as $match) {
                    $suggestions[] = [
                        'id' => 'review_raw_modifier_' . md5($match . $templateFile),
                        'title' => 'Review raw modifier usage for security',
                        'description' => 'Raw modifier bypasses HTML escaping - ensure content is safe',
                        'category' => 'security',
                        'impact' => 'medium',
                        'effort' => 'low',
                        'template' => $templateFile,
                        'code_snippet' => $match,
                        'examples' => $includeCodeExamples ? [
                            'before' => '{{ user_content | raw }}',
                            'after' => '{{ user_content }}
<!-- Or if HTML is needed: -->
{{ user_content | sanitize | raw }}',
                            'explanation' => 'Only use raw modifier with trusted or sanitized content',
                        ] : null,
                    ];
                }
            }
        }

        return $suggestions;
    }

    /**
     * Suggest best practices.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function suggestBestPractices(string $content, string $templateFile, string $templateType, bool $includeCodeExamples): array
    {
        $suggestions = [];

        // Suggest semantic HTML improvements
        if (! preg_match('/<(?:header|nav|main|section|article|aside|footer)/', $content) && preg_match('/<div/', $content)) {
            $suggestions[] = [
                'id' => 'semantic_html_' . md5($templateFile),
                'title' => 'Use semantic HTML elements',
                'description' => 'Consider using semantic HTML5 elements instead of generic divs',
                'category' => 'best_practices',
                'impact' => 'low',
                'effort' => 'low',
                'template' => $templateFile,
                'examples' => $includeCodeExamples ? [
                    'before' => '<div class="header">
  <div class="nav">Navigation</div>
</div>
<div class="content">Main content</div>',
                    'after' => '<header>
  <nav>Navigation</nav>
</header>
<main>Main content</main>',
                    'explanation' => 'Semantic HTML improves accessibility and SEO',
                ] : null,
            ];
        }

        // Suggest alt text for images
        if (preg_match_all('/<img[^>]+>/i', $content, $matches)) {
            $imagesWithoutAlt = 0;
            foreach ($matches[0] as $img) {
                if (! preg_match('/alt\s*=\s*["\'][^"\']*["\']/i', $img)) {
                    $imagesWithoutAlt++;
                }
            }

            if ($imagesWithoutAlt > 0) {
                $suggestions[] = [
                    'id' => 'add_alt_text_' . md5($templateFile),
                    'title' => 'Add alt attributes to images',
                    'description' => "{$imagesWithoutAlt} image(s) missing alt attributes for accessibility",
                    'category' => 'best_practices',
                    'impact' => 'medium',
                    'effort' => 'low',
                    'template' => $templateFile,
                    'examples' => $includeCodeExamples ? [
                        'before' => '<img src="photo.jpg">',
                        'after' => '<img src="photo.jpg" alt="Description of the photo">',
                        'explanation' => 'Alt text is essential for screen readers and accessibility',
                    ] : null,
                ];
            }
        }

        // Suggest using HTTPS for external links
        if (preg_match_all('/href\s*=\s*["\']http:\/\/[^"\']+["\']/i', $content, $matches)) {
            if (! empty($matches[0])) {
                $suggestions[] = [
                    'id' => 'https_links_' . md5($templateFile),
                    'title' => 'Use HTTPS for external links',
                    'description' => 'HTTP links found - consider using HTTPS for security',
                    'category' => 'best_practices',
                    'impact' => 'low',
                    'effort' => 'low',
                    'template' => $templateFile,
                    'http_links_count' => count($matches[0]),
                    'examples' => $includeCodeExamples ? [
                        'before' => '<a href="http://example.com">Link</a>',
                        'after' => '<a href="https://example.com">Link</a>',
                        'explanation' => 'HTTPS links are more secure and trusted by browsers',
                    ] : null,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Detect template type from path and content.
     */
    private function detectTemplateType(string $path, string $content): string
    {
        if (str_contains($path, '.antlers.html') || str_contains($content, '{{')) {
            return 'antlers';
        }

        if (str_contains($path, '.blade.php') || str_contains($content, '@')) {
            return 'blade';
        }

        return 'unknown';
    }

    /**
     * Prioritize suggestions by impact and effort.
     *
     * @param  array<array<string, mixed>>  $suggestions
     *
     * @return array<array<string, mixed>>
     */
    private function prioritizeSuggestions(array $suggestions): array
    {
        $priorityMap = [
            'high' => 3,
            'medium' => 2,
            'low' => 1,
        ];

        $effortMap = [
            'low' => 3,
            'medium' => 2,
            'high' => 1,
        ];

        usort($suggestions, function ($a, $b) use ($priorityMap, $effortMap) {
            $aScore = ($priorityMap[$a['impact']] ?? 1) * ($effortMap[$a['effort']] ?? 1);
            $bScore = ($priorityMap[$b['impact']] ?? 1) * ($effortMap[$b['effort']] ?? 1);

            return $bScore <=> $aScore;
        });

        return $suggestions;
    }

    /**
     * Calculate optimization potential.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function calculateOptimizationPotential(array $analysis): string
    {
        $stats = $analysis['statistics'];

        if ($stats['high_impact_suggestions'] >= 3) {
            return 'high';
        } elseif ($stats['quick_wins'] >= 5) {
            return 'medium';
        } elseif ($stats['total_suggestions'] >= 10) {
            return 'low';
        } else {
            return 'minimal';
        }
    }

    /**
     * Get top categories by suggestion count.
     *
     * @param  array<string, array<mixed>>  $categories
     *
     * @return array<string>
     */
    private function getTopCategories(array $categories): array
    {
        $counts = array_map('count', $categories);
        arsort($counts);

        return array_slice(array_keys($counts), 0, 3);
    }

    /**
     * Generate implementation roadmap.
     *
     * @param  array<array<string, mixed>>  $suggestions
     *
     * @return array<string, array<string>>
     */
    private function generateImplementationRoadmap(array $suggestions): array
    {
        $roadmap = [
            'immediate' => [], // High impact, low effort
            'short_term' => [], // High impact, medium effort OR medium impact, low effort
            'long_term' => [], // Everything else
        ];

        foreach ($suggestions as $suggestion) {
            $impact = $suggestion['impact'];
            $effort = $suggestion['effort'];

            if ($impact === 'high' && $effort === 'low') {
                $roadmap['immediate'][] = $suggestion['title'];
            } elseif (($impact === 'high' && $effort === 'medium') || ($impact === 'medium' && $effort === 'low')) {
                $roadmap['short_term'][] = $suggestion['title'];
            } else {
                $roadmap['long_term'][] = $suggestion['title'];
            }
        }

        // Limit each category to avoid overwhelming output
        foreach ($roadmap as $phase => &$items) {
            $items = array_slice($items, 0, 5);
        }

        return $roadmap;
    }
}
