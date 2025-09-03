<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Analyze Template Performance')]
#[IsReadOnly]
class AnalyzeTemplatePerformanceTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.development.analyze-template-performance';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Comprehensive template performance analysis with optimization recommendations';
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
            ->boolean('include_partials')
            ->description('Include analysis of referenced partials and components')
            ->optional()
            ->boolean('check_n_plus_one')
            ->description('Detect potential N+1 query problems')
            ->optional()
            ->boolean('analyze_loops')
            ->description('Analyze loop complexity and performance')
            ->optional()
            ->boolean('suggest_caching')
            ->description('Suggest caching opportunities')
            ->optional()
            ->integer('complexity_threshold')
            ->description('Complexity threshold for warnings (1-100)')
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
        $includePartials = $arguments['include_partials'] ?? true;
        $checkNPlusOne = $arguments['check_n_plus_one'] ?? true;
        $analyzeLoops = $arguments['analyze_loops'] ?? true;
        $suggestCaching = $arguments['suggest_caching'] ?? true;
        $complexityThreshold = $arguments['complexity_threshold'] ?? 50;

        try {
            $templates = $this->collectTemplates($templatePath);

            if (empty($templates)) {
                return $this->createErrorResponse('No templates found at the specified path')->toArray();
            }

            $analysis = [
                'templates_analyzed' => count($templates),
                'performance_issues' => [],
                'optimization_opportunities' => [],
                'caching_suggestions' => [],
                'complexity_analysis' => [],
                'statistics' => [
                    'total_issues' => 0,
                    'critical_issues' => 0,
                    'performance_score' => 100,
                    'estimated_render_time' => 0,
                ],
                'recommendations' => [],
            ];

            foreach ($templates as $template) {
                $templateAnalysis = $this->analyzeTemplate(
                    $template,
                    $templateType,
                    $includePartials,
                    $checkNPlusOne,
                    $analyzeLoops,
                    $suggestCaching,
                    $complexityThreshold
                );

                $analysis['performance_issues'] = array_merge(
                    $analysis['performance_issues'],
                    $templateAnalysis['issues']
                );

                $analysis['optimization_opportunities'] = array_merge(
                    $analysis['optimization_opportunities'],
                    $templateAnalysis['optimizations']
                );

                if (! empty($templateAnalysis['caching'])) {
                    $analysis['caching_suggestions'] = array_merge(
                        $analysis['caching_suggestions'],
                        $templateAnalysis['caching']
                    );
                }

                $analysis['complexity_analysis'][$template['path']] = $templateAnalysis['complexity'];

                // Update statistics
                $analysis['statistics']['total_issues'] += count($templateAnalysis['issues']);
                $analysis['statistics']['critical_issues'] += count(array_filter(
                    $templateAnalysis['issues'],
                    fn ($issue) => $issue['severity'] === 'critical'
                ));
                $analysis['statistics']['estimated_render_time'] += $templateAnalysis['estimated_render_time'];
            }

            // Calculate overall performance score
            $analysis['statistics']['performance_score'] = $this->calculatePerformanceScore($analysis);

            // Generate recommendations
            $analysis['recommendations'] = $this->generateRecommendations($analysis);

            return [
                'analysis' => $analysis,
                'summary' => [
                    'status' => $this->getPerformanceStatus($analysis['statistics']['performance_score']),
                    'templates_with_issues' => count(array_filter(
                        $analysis['complexity_analysis'],
                        fn ($complexity) => $complexity['score'] > $complexityThreshold
                    )),
                    'most_critical_issues' => $this->getMostCriticalIssues($analysis['performance_issues']),
                    'estimated_total_render_time' => $analysis['statistics']['estimated_render_time'] . 'ms',
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to analyze template performance: ' . $e->getMessage())->toArray();
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

        // Convert relative path to absolute
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
     * Analyze a single template for performance.
     *
     * @param  array<string, mixed>  $template
     *
     * @return array<string, mixed>
     */
    private function analyzeTemplate(
        array $template,
        string $templateType,
        bool $includePartials,
        bool $checkNPlusOne,
        bool $analyzeLoops,
        bool $suggestCaching,
        int $complexityThreshold
    ): array {
        $content = $template['content'];
        $path = $template['relative_path'];

        // Auto-detect template type
        if ($templateType === 'auto-detect') {
            $templateType = $this->detectTemplateType($path, $content);
        }

        $analysis = [
            'template' => $path,
            'type' => $templateType,
            'issues' => [],
            'optimizations' => [],
            'caching' => [],
            'complexity' => [],
            'estimated_render_time' => 0,
        ];

        // Performance issue detection
        if ($checkNPlusOne) {
            $nPlusOneIssues = $this->detectNPlusOneQueries($content, $templateType);
            $analysis['issues'] = array_merge($analysis['issues'], $nPlusOneIssues);
        }

        // Loop analysis
        if ($analyzeLoops) {
            $loopIssues = $this->analyzeLoopPerformance($content, $templateType);
            $analysis['issues'] = array_merge($analysis['issues'], $loopIssues);
        }

        // Complexity analysis
        $analysis['complexity'] = $this->analyzeTemplateComplexity($content, $templateType);

        if ($analysis['complexity']['score'] > $complexityThreshold) {
            $analysis['issues'][] = [
                'type' => 'high_complexity',
                'severity' => 'warning',
                'template' => $path,
                'message' => "Template complexity score ({$analysis['complexity']['score']}) exceeds threshold ({$complexityThreshold})",
                'complexity_factors' => $analysis['complexity']['factors'],
            ];
        }

        // Caching opportunities
        if ($suggestCaching) {
            $analysis['caching'] = $this->identifyCachingOpportunities($content, $templateType, $path);
        }

        // General optimizations
        $analysis['optimizations'] = $this->identifyOptimizations($content, $templateType, $path);

        // Estimate render time
        $analysis['estimated_render_time'] = $this->estimateRenderTime($analysis);

        // Include partials analysis
        if ($includePartials) {
            $partialAnalysis = $this->analyzeReferencedPartials($content, $templateType);
            $analysis['partials'] = $partialAnalysis;
        }

        return $analysis;
    }

    /**
     * Detect template type from path and content.
     */
    private function detectTemplateType(string $path, string $content): string
    {
        if (str_contains($path, '.antlers.html') || str_contains($content, '{{')) {
            return 'antlers';
        }

        if (str_contains($path, '.blade.php') || str_contains($content, '@') || str_contains($content, '<?php')) {
            return 'blade';
        }

        return 'unknown';
    }

    /**
     * Detect potential N+1 query issues.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function detectNPlusOneQueries(string $content, string $templateType): array
    {
        $issues = [];

        if ($templateType === 'antlers') {
            // Check for collection loops without eager loading
            if (preg_match_all('/\{\{\s*collection:(\w+)\s*\}\}.*?\{\{\s*\/collection:\1\s*\}\}/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $loopContent = $match[0];

                    // Check for nested relationship access
                    if (preg_match('/\{\{\s*(author|user|taxonomy|collection)\./', $loopContent)) {
                        $issues[] = [
                            'type' => 'n_plus_one_query',
                            'severity' => 'critical',
                            'template_type' => 'antlers',
                            'message' => 'Potential N+1 query detected in collection loop',
                            'suggestion' => 'Use eager loading with :with parameter',
                            'pattern' => substr($loopContent, 0, 100) . '...',
                            'line' => substr_count(substr($content, 0, $match[1]), "\n") + 1,
                        ];
                    }
                }
            }

            // Check for relationship access in loops
            if (preg_match_all('/\{\{\s*(\w+)\s*\}\}.*?\{\{\s*(\w+)\.(\w+)/', $content, $matches)) {
                $issues[] = [
                    'type' => 'n_plus_one_query',
                    'severity' => 'warning',
                    'template_type' => 'antlers',
                    'message' => 'Relationship access detected - ensure proper eager loading',
                    'suggestion' => 'Use with() or load() to eager load relationships',
                ];
            }
        }

        if ($templateType === 'blade') {
            // Check for loops with model access
            if (preg_match_all('/@foreach\s*\(\s*\$(\w+)\s+as.*?\@endforeach/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $loopContent = $match[0];

                    // Check for relationship access
                    if (preg_match('/\$\w+->(author|user|category|tags)/', $loopContent)) {
                        $issues[] = [
                            'type' => 'n_plus_one_query',
                            'severity' => 'critical',
                            'template_type' => 'blade',
                            'message' => 'Potential N+1 query detected in foreach loop',
                            'suggestion' => 'Use eager loading with with() in controller',
                            'pattern' => substr($loopContent, 0, 100) . '...',
                            'line' => substr_count(substr($content, 0, $match[1]), "\n") + 1,
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Analyze loop performance.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function analyzeLoopPerformance(string $content, string $templateType): array
    {
        $issues = [];

        if ($templateType === 'antlers') {
            // Nested loops detection
            $nestedLoopCount = preg_match_all('/\{\{\s*collection:\w+\s*\}\}.*?\{\{\s*collection:\w+\s*\}\}.*?\{\{\s*\/collection:\w+\s*\}\}.*?\{\{\s*\/collection:\w+\s*\}\}/s', $content);

            if ($nestedLoopCount > 0) {
                $issues[] = [
                    'type' => 'nested_loops',
                    'severity' => 'warning',
                    'template_type' => 'antlers',
                    'message' => 'Nested loops detected - can impact performance',
                    'count' => $nestedLoopCount,
                    'suggestion' => 'Consider flattening data structure or using more efficient queries',
                ];
            }

            // Large loops without pagination
            if (preg_match_all('/\{\{\s*collection:(\w+)(?!.*limit=)(?!.*paginate).*?\}\}/s', $content, $matches)) {
                foreach ($matches[1] as $collection) {
                    $issues[] = [
                        'type' => 'unpaginated_loop',
                        'severity' => 'warning',
                        'template_type' => 'antlers',
                        'message' => "Collection '{$collection}' loop has no limit or pagination",
                        'suggestion' => 'Add limit parameter or use pagination',
                        'collection' => $collection,
                    ];
                }
            }
        }

        if ($templateType === 'blade') {
            // Nested foreach detection
            $nestedForeachCount = substr_count($content, '@foreach') - substr_count($content, '@endforeach');

            if ($nestedForeachCount !== 0) {
                $actualNested = preg_match_all('/@foreach.*?@foreach.*?@endforeach.*?@endforeach/s', $content);

                if ($actualNested > 0) {
                    $issues[] = [
                        'type' => 'nested_loops',
                        'severity' => 'warning',
                        'template_type' => 'blade',
                        'message' => 'Nested foreach loops detected - can impact performance',
                        'count' => $actualNested,
                        'suggestion' => 'Consider using eager loading or computed properties',
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Analyze template complexity.
     *
     *
     * @return array<string, mixed>
     */
    private function analyzeTemplateComplexity(string $content, string $templateType): array
    {
        $complexity = [
            'score' => 0,
            'factors' => [],
            'metrics' => [],
        ];

        // Basic metrics
        $lineCount = substr_count($content, "\n") + 1;
        $complexity['metrics']['line_count'] = $lineCount;
        $complexity['score'] += min($lineCount / 10, 20); // Max 20 points for lines

        if ($templateType === 'antlers') {
            // Count Antlers tags
            $tagCount = preg_match_all('/\{\{.*?\}\}/', $content);
            $complexity['metrics']['tag_count'] = $tagCount;
            $complexity['score'] += $tagCount * 0.5;

            // Count conditionals
            $conditionalCount = preg_match_all('/\{\{\s*if\s+/', $content);
            $complexity['metrics']['conditional_count'] = $conditionalCount;
            $complexity['score'] += $conditionalCount * 2;

            // Count loops
            $loopCount = preg_match_all('/\{\{\s*collection:/', $content);
            $complexity['metrics']['loop_count'] = $loopCount;
            $complexity['score'] += $loopCount * 3;

            if ($tagCount > 50) {
                $complexity['factors'][] = 'High tag count';
            }
            if ($conditionalCount > 10) {
                $complexity['factors'][] = 'Many conditionals';
            }
            if ($loopCount > 5) {
                $complexity['factors'][] = 'Multiple loops';
            }
        }

        if ($templateType === 'blade') {
            // Count Blade directives
            $directiveCount = preg_match_all('/@\w+/', $content);
            $complexity['metrics']['directive_count'] = $directiveCount;
            $complexity['score'] += $directiveCount * 0.5;

            // Count conditionals
            $conditionalCount = preg_match_all('/@if\s*\(|@unless\s*\(/', $content);
            $complexity['metrics']['conditional_count'] = $conditionalCount;
            $complexity['score'] += $conditionalCount * 2;

            // Count loops
            $loopCount = preg_match_all('/@foreach\s*\(|@for\s*\(/', $content);
            $complexity['metrics']['loop_count'] = $loopCount;
            $complexity['score'] += $loopCount * 3;

            if ($directiveCount > 30) {
                $complexity['factors'][] = 'High directive count';
            }
            if ($conditionalCount > 10) {
                $complexity['factors'][] = 'Many conditionals';
            }
            if ($loopCount > 5) {
                $complexity['factors'][] = 'Multiple loops';
            }
        }

        // General complexity factors
        if ($lineCount > 200) {
            $complexity['factors'][] = 'Long template';
        }
        if (substr_count($content, 'include') > 5) {
            $complexity['factors'][] = 'Many includes';
        }

        return $complexity;
    }

    /**
     * Identify caching opportunities.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function identifyCachingOpportunities(string $content, string $templateType, string $path): array
    {
        $opportunities = [];

        // Static content blocks
        if (preg_match('/(<header>.*?<\/header>|<footer>.*?<\/footer>|<nav>.*?<\/nav>)/s', $content)) {
            $opportunities[] = [
                'type' => 'static_caching',
                'template' => $path,
                'message' => 'Static header/footer/nav content detected',
                'suggestion' => 'Consider using fragment caching for static sections',
                'benefit' => 'High - reduces template compilation time',
            ];
        }

        if ($templateType === 'antlers') {
            // Collection queries that could be cached
            if (preg_match_all('/\{\{\s*collection:(\w+).*?\}\}/', $content, $matches)) {
                foreach ($matches[1] as $collection) {
                    $opportunities[] = [
                        'type' => 'collection_caching',
                        'template' => $path,
                        'collection' => $collection,
                        'message' => "Collection '{$collection}' query could benefit from caching",
                        'suggestion' => 'Use {{ cache }} tag around collection loop or enable collection caching',
                        'benefit' => 'Medium - reduces database queries',
                    ];
                }
            }

            // Expensive operations
            if (preg_match('/\{\{\s*(asset|image):/', $content)) {
                $opportunities[] = [
                    'type' => 'asset_caching',
                    'template' => $path,
                    'message' => 'Asset/image processing detected',
                    'suggestion' => 'Enable asset caching and consider using responsive images',
                    'benefit' => 'High - reduces file system operations',
                ];
            }
        }

        return $opportunities;
    }

    /**
     * Identify general optimizations.
     *
     *
     * @return array<array<string, mixed>>
     */
    private function identifyOptimizations(string $content, string $templateType, string $path): array
    {
        $optimizations = [];

        // Unused variables or tags
        if ($templateType === 'antlers') {
            // Check for unused variables
            preg_match_all('/\{\{\s*(\w+)\s*=/', $content, $assignments);
            preg_match_all('/\{\{\s*(\w+)(?!\s*=)/', $content, $usages);

            $assignedVars = $assignments[1];
            $usedVars = $usages[1];
            $unusedVars = array_diff($assignedVars, $usedVars);

            if (! empty($unusedVars)) {
                $optimizations[] = [
                    'type' => 'unused_variables',
                    'template' => $path,
                    'message' => 'Unused variables detected: ' . implode(', ', $unusedVars),
                    'suggestion' => 'Remove unused variable assignments',
                    'variables' => $unusedVars,
                ];
            }
        }

        // Large inline styles or scripts
        if (preg_match('/<style>(.{500,})<\/style>/', $content, $matches)) {
            $optimizations[] = [
                'type' => 'inline_styles',
                'template' => $path,
                'message' => 'Large inline CSS detected',
                'suggestion' => 'Move CSS to external stylesheets for better caching',
                'size' => strlen($matches[1]),
            ];
        }

        if (preg_match('/<script>(.{500,})<\/script>/', $content, $matches)) {
            $optimizations[] = [
                'type' => 'inline_scripts',
                'template' => $path,
                'message' => 'Large inline JavaScript detected',
                'suggestion' => 'Move JavaScript to external files for better caching',
                'size' => strlen($matches[1]),
            ];
        }

        return $optimizations;
    }

    /**
     * Analyze referenced partials.
     *
     *
     * @return array<string, mixed>
     */
    private function analyzeReferencedPartials(string $content, string $templateType): array
    {
        $partials = [];

        if ($templateType === 'antlers') {
            // Find partial tags
            if (preg_match_all('/\{\{\s*partial:(\w+)/', $content, $matches)) {
                $partials['referenced'] = array_unique($matches[1]);
            }
        }

        if ($templateType === 'blade') {
            // Find includes
            if (preg_match_all('/@include\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                $partials['referenced'] = array_unique($matches[1]);
            }
        }

        return $partials;
    }

    /**
     * Estimate render time based on analysis.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function estimateRenderTime(array $analysis): int
    {
        $baseTime = 10; // Base template render time in ms

        $complexity = $analysis['complexity'];
        $additionalTime = 0;

        // Add time based on complexity factors
        $additionalTime += ($complexity['metrics']['loop_count'] ?? 0) * 5;
        $additionalTime += ($complexity['metrics']['conditional_count'] ?? 0) * 1;
        $additionalTime += ($complexity['metrics']['tag_count'] ?? 0) * 0.1;

        // Add time for performance issues
        foreach ($analysis['issues'] as $issue) {
            if ($issue['severity'] === 'critical') {
                $additionalTime += 50;
            } elseif ($issue['severity'] === 'warning') {
                $additionalTime += 20;
            }
        }

        return (int) ($baseTime + $additionalTime);
    }

    /**
     * Calculate overall performance score.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function calculatePerformanceScore(array $analysis): int
    {
        $score = 100;

        // Deduct for issues
        $score -= $analysis['statistics']['critical_issues'] * 20;
        $score -= ($analysis['statistics']['total_issues'] - $analysis['statistics']['critical_issues']) * 10;

        // Deduct for high render time
        if ($analysis['statistics']['estimated_render_time'] > 1000) {
            $score -= 30;
        } elseif ($analysis['statistics']['estimated_render_time'] > 500) {
            $score -= 15;
        }

        return (int) max(0, $score);
    }

    /**
     * Generate recommendations based on analysis.
     *
     * @param  array<string, mixed>  $analysis
     *
     * @return array<string>
     */
    private function generateRecommendations(array $analysis): array
    {
        $recommendations = [];

        if ($analysis['statistics']['critical_issues'] > 0) {
            $recommendations[] = 'Fix critical performance issues immediately to prevent slow page loads';
        }

        if (! empty($analysis['caching_suggestions'])) {
            $recommendations[] = 'Implement suggested caching strategies to improve performance';
        }

        if ($analysis['statistics']['estimated_render_time'] > 500) {
            $recommendations[] = 'Consider breaking down complex templates into smaller components';
        }

        if (count($analysis['optimization_opportunities']) > 5) {
            $recommendations[] = 'Address optimization opportunities to improve maintainability';
        }

        return $recommendations;
    }

    /**
     * Get performance status.
     */
    private function getPerformanceStatus(int $score): string
    {
        if ($score >= 80) {
            return 'excellent';
        }
        if ($score >= 60) {
            return 'good';
        }
        if ($score >= 40) {
            return 'needs_improvement';
        }

        return 'poor';
    }

    /**
     * Get most critical issues.
     *
     * @param  array<array<string, mixed>>  $issues
     *
     * @return array<string>
     */
    private function getMostCriticalIssues(array $issues): array
    {
        $criticalIssues = array_filter($issues, fn ($issue) => $issue['severity'] === 'critical');

        $issueTypes = array_count_values(array_column($criticalIssues, 'type'));
        arsort($issueTypes);

        return array_slice(array_keys($issueTypes), 0, 3);
    }
}
