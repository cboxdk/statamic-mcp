<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

/**
 * Advanced template analyzer for performance optimization and edge case detection.
 */
class OptimizedTemplateAnalyzer
{
    /**
     * Analyze template for performance issues.
     *
     * @param  string  $type  'antlers' or 'blade'
     *
     * @return array<string, mixed>
     */
    public function analyzePerformance(string $template, string $type = 'antlers'): array
    {
        $issues = [];
        $metrics = [
            'complexity_score' => 0,
            'estimated_render_time' => 0,
            'memory_impact' => 'low',
        ];

        if ($type === 'antlers') {
            $issues = array_merge($issues, $this->analyzeAntlersPerformance($template, $metrics));
        } else {
            $issues = array_merge($issues, $this->analyzeBladePerformance($template, $metrics));
        }

        return [
            'issues' => $issues,
            'metrics' => $metrics,
            'optimizations' => $this->suggestOptimizations($issues),
        ];
    }

    /**
     * Analyze Antlers template for performance issues.
     *
     * @param  array<string, mixed>  &$metrics
     *
     * @return array<array<string, mixed>>
     */
    private function analyzeAntlersPerformance(string $template, array &$metrics): array
    {
        $issues = [];

        // Check for nested loops (N+1 query problem)
        if (preg_match_all('/\{\{\s*(\w+).*?\}\}(.*?)\{\{\s*\/\1\s*\}\}/s', $template, $outerLoops)) {
            foreach ($outerLoops[2] as $index => $loopContent) {
                if (preg_match_all('/\{\{\s*(\w+).*?\}\}/s', $loopContent, $innerLoops)) {
                    $metrics['complexity_score'] += count($innerLoops[0]) * 10;
                    if (count($innerLoops[0]) > 2) {
                        $issues[] = [
                            'type' => 'nested_loops',
                            'severity' => 'high',
                            'message' => "Deeply nested loops detected in '{$outerLoops[1][$index]}' tag",
                            'suggestion' => 'Consider using query builder with eager loading or caching',
                            'line' => $this->getLineNumber($template, $outerLoops[0][$index]),
                        ];
                    }
                }
            }
        }

        // Check for collection queries in loops
        if (preg_match_all('/\{\{\s*collection:.*?\}\}(.*?)\{\{\s*\/collection.*?\}\}/s', $template, $collections)) {
            foreach ($collections[1] as $collectionContent) {
                if (preg_match('/\{\{\s*(collection|entries|taxonomy|assets):/i', $collectionContent)) {
                    $metrics['complexity_score'] += 25;
                    $metrics['memory_impact'] = 'high';
                    $issues[] = [
                        'type' => 'query_in_loop',
                        'severity' => 'critical',
                        'message' => 'Collection query detected inside another collection loop',
                        'suggestion' => 'Use relationships, eager loading, or cache the outer query',
                    ];
                }
            }
        }

        // Check for excessive partial includes
        $partialCount = preg_match_all('/\{\{\s*partial:[\w\/]+/', $template, $partials);
        if ($partialCount > 10) {
            $metrics['complexity_score'] += $partialCount * 2;
            $issues[] = [
                'type' => 'excessive_partials',
                'severity' => 'medium',
                'message' => "Found {$partialCount} partial includes",
                'suggestion' => 'Consider combining related partials or using cached sections',
            ];
        }

        // Check for complex conditionals
        if (preg_match_all('/\{\{\s*(if|elseif|unless)([^}]+)\}\}/i', $template, $conditionals)) {
            foreach ($conditionals[2] as $condition) {
                $operators = preg_match_all('/(\|\||&&|\?:)/', $condition);
                if ($operators > 3) {
                    $metrics['complexity_score'] += $operators * 5;
                    $issues[] = [
                        'type' => 'complex_conditional',
                        'severity' => 'medium',
                        'message' => 'Complex conditional with multiple operators',
                        'suggestion' => 'Move complex logic to computed values or view composers',
                    ];
                }
            }
        }

        // Check for missing pagination
        if (preg_match('/\{\{\s*collection:.*?limit="(\d+)"/', $template, $limits)) {
            if (intval($limits[1]) > 50 && ! preg_match('/paginate/', $template)) {
                $issues[] = [
                    'type' => 'missing_pagination',
                    'severity' => 'high',
                    'message' => "Large collection limit ({$limits[1]}) without pagination",
                    'suggestion' => 'Add pagination for better performance',
                ];
            }
        }

        // Check for uncached dynamic content
        if (preg_match_all('/\{\{\s*now|current_date|random/', $template, $dynamic)) {
            if (count($dynamic[0]) > 3) {
                $issues[] = [
                    'type' => 'uncached_dynamic',
                    'severity' => 'low',
                    'message' => 'Multiple dynamic content blocks prevent full-page caching',
                    'suggestion' => 'Consider partial caching strategies',
                ];
            }
        }

        return $issues;
    }

    /**
     * Analyze Blade template for performance issues.
     *
     * @param  array<string, mixed>  &$metrics
     *
     * @return array<array<string, mixed>>
     */
    private function analyzeBladePerformance(string $template, array &$metrics): array
    {
        $issues = [];

        // Check for @foreach with nested queries
        if (preg_match_all('/@foreach\s*\((.*?)\)(.*?)@endforeach/s', $template, $loops)) {
            foreach ($loops[2] as $index => $loopContent) {
                if (preg_match('/(Entry|Collection|User|Asset)::(query|all|find|where)/', $loopContent)) {
                    $metrics['complexity_score'] += 30;
                    $metrics['memory_impact'] = 'high';
                    $issues[] = [
                        'type' => 'query_in_loop',
                        'severity' => 'critical',
                        'message' => 'Database query detected inside @foreach loop',
                        'suggestion' => 'Move queries outside loops and use eager loading',
                    ];
                }
            }
        }

        // Check for inline PHP
        if (preg_match_all('/@php(.*?)@endphp/s', $template, $phpBlocks)) {
            foreach ($phpBlocks[1] as $phpContent) {
                if (strlen($phpContent) > 200) {
                    $metrics['complexity_score'] += 20;
                    $issues[] = [
                        'type' => 'excessive_inline_php',
                        'severity' => 'high',
                        'message' => 'Large PHP block in template',
                        'suggestion' => 'Move logic to controller, view composer, or service class',
                    ];
                }
            }
        }

        // Check for missing component usage
        if (preg_match_all('/<div class="([^"]+)"/', $template, $divs)) {
            $repeatedPatterns = array_count_values($divs[1]);
            foreach ($repeatedPatterns as $pattern => $count) {
                if ($count > 3) {
                    $issues[] = [
                        'type' => 'repeated_markup',
                        'severity' => 'low',
                        'message' => "Repeated markup pattern '{$pattern}' found {$count} times",
                        'suggestion' => 'Consider extracting to a Blade component',
                    ];
                }
            }
        }

        // Check for facade usage in templates
        if (preg_match_all('/\{\{\s*(Auth|Cache|DB|Log|Storage|Config)::/i', $template)) {
            $metrics['complexity_score'] += 15;
            $issues[] = [
                'type' => 'facade_in_template',
                'severity' => 'medium',
                'message' => 'Direct facade usage in template',
                'suggestion' => 'Use Statamic tags or pass data from controller',
            ];
        }

        return $issues;
    }

    /**
     * Suggest optimizations based on issues found.
     *
     * @param  array<array<string, mixed>>  $issues
     *
     * @return array<string, array<string, string>>
     */
    private function suggestOptimizations(array $issues): array
    {
        $optimizations = [];
        $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($issues as $issue) {
            $severityCounts[$issue['severity']]++;
        }

        if ($severityCounts['critical'] > 0) {
            $optimizations['immediate'] = [
                'action' => 'Fix critical performance issues',
                'impact' => 'Can improve performance by 50-80%',
                'priority' => 'urgent',
            ];
        }

        if ($severityCounts['high'] > 2) {
            $optimizations['caching'] = [
                'action' => 'Implement fragment caching',
                'impact' => 'Can reduce render time by 30-50%',
                'priority' => 'high',
            ];
        }

        if (array_sum($severityCounts) > 5) {
            $optimizations['refactor'] = [
                'action' => 'Consider template refactoring',
                'impact' => 'Improves maintainability and performance',
                'priority' => 'medium',
            ];
        }

        return $optimizations;
    }

    /**
     * Get line number for a match in template.
     */
    private function getLineNumber(string $template, string $match): int
    {
        $position = strpos($template, $match);
        if ($position === false) {
            return 0;
        }

        return substr_count(substr($template, 0, $position), "\n") + 1;
    }

    /**
     * Detect edge cases in templates.
     *
     * @return array<int, array<string, string>>
     */
    public function detectEdgeCases(string $template, string $type = 'antlers'): array
    {
        $edgeCases = [];

        // Check for recursive partials
        if (preg_match('/\{\{\s*partial:(\w+)/', $template, $partialName)) {
            // This would need to check the partial file for self-reference
            $edgeCases[] = [
                'type' => 'potential_recursion',
                'message' => 'Check partial for recursive includes',
                'severity' => 'warning',
            ];
        }

        // Check for memory-intensive operations
        if (preg_match('/\{\{\s*\w+\s+.*?limit="(\d+)"/', $template, $limit)) {
            if (intval($limit[1]) > 1000) {
                $edgeCases[] = [
                    'type' => 'memory_intensive',
                    'message' => "Very large limit ({$limit[1]}) may cause memory issues",
                    'severity' => 'critical',
                ];
            }
        }

        // Check for infinite loop potential
        if (preg_match('/\{\{\s*while\s/', $template)) {
            $edgeCases[] = [
                'type' => 'infinite_loop_risk',
                'message' => 'While loops can cause infinite loops if not properly bounded',
                'severity' => 'critical',
            ];
        }

        // Check for XSS vulnerabilities
        if ($type === 'antlers' && preg_match('/\{\{\{/', $template)) {
            $edgeCases[] = [
                'type' => 'xss_risk',
                'message' => 'Triple braces output unescaped HTML - ensure data is sanitized',
                'severity' => 'high',
            ];
        }

        if ($type === 'blade' && preg_match('/\{!!/', $template)) {
            $edgeCases[] = [
                'type' => 'xss_risk',
                'message' => 'Unescaped output detected - ensure data is sanitized',
                'severity' => 'high',
            ];
        }

        return $edgeCases;
    }
}
