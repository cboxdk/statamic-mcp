<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Blade Linter')]
#[IsReadOnly]
class BladeLintTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.development.blade-lint';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Lint Blade templates for Statamic best practices and policy enforcement';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('template')
            ->description('Blade template code to lint')
            ->required()
            ->boolean('strict_mode')
            ->description('Enable strict mode for more pedantic checks')
            ->optional()
            ->boolean('auto_fix')
            ->description('Generate auto-fix suggestions where possible')
            ->optional()
            ->boolean('performance_analysis')
            ->description('Include performance and edge case analysis')
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
        $template = $arguments['template'];
        $strictMode = $arguments['strict_mode'] ?? false;
        $autoFix = $arguments['auto_fix'] ?? true;
        $performanceAnalysis = $arguments['performance_analysis'] ?? true;

        // Get default policy from config
        $mergedPolicy = $this->getDefaultPolicy();

        // Create linter and run analysis
        $linter = new BladeLinter($mergedPolicy, $strictMode);
        $result = $linter->lint($template);

        if ($autoFix && ! empty($result['violations'])) {
            $result['suggestions'] = $this->generateAutoFix($template, $result['violations']);
        }

        // Add performance and edge case analysis if requested
        if ($performanceAnalysis) {
            $analyzer = new OptimizedTemplateAnalyzer;
            $performanceResult = $analyzer->analyzePerformance($template, 'blade');
            $edgeCases = $analyzer->detectEdgeCases($template, 'blade');

            $result['performance_analysis'] = $performanceResult;
            $result['edge_cases'] = $edgeCases;
        }

        return $result;
    }

    /**
     * Get default policy configuration.
     */
    /**
     * @return array<string, mixed>
     */
    private function getDefaultPolicy(): array
    {
        try {
            return config('statamic_mcp.blade_policy', [
                'forbid' => [
                    'inline_php' => true,
                    'facades' => ['Statamic', 'DB', 'Http', 'Cache', 'Storage'],
                    'models_in_view' => true,
                ],
                'prefer' => [
                    'tags' => true,
                    'components' => true,
                ],
                'allow' => [
                    'pure_blade_logic' => true,
                ],
            ]);
        } catch (\Exception $e) {
            // Fallback policy if config is not available
            return [
                'forbid' => [
                    'inline_php' => true,
                    'facades' => ['Statamic', 'DB', 'Http', 'Cache', 'Storage'],
                    'models_in_view' => true,
                ],
                'prefer' => [
                    'tags' => true,
                    'components' => true,
                ],
                'allow' => [
                    'pure_blade_logic' => true,
                ],
            ];
        }
    }

    /**
     * Generate auto-fix suggestions.
     *
     * @param  array<int, array<string, mixed>>  $violations
     *
     * @return array<int, array<string, mixed>>
     */
    private function generateAutoFix(string $template, array $violations): array
    {
        $autoFixer = new BladeAutoFixer;

        return $autoFixer->generateFixes($template, $violations);
    }
}

/**
 * Blade Linter for Statamic
 */
class BladeLinter
{
    /**
     * @var array<string, mixed>
     */
    private array $policy;

    private bool $strictMode;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $violations = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $warnings = [];

    /**
     * @param  array<string, mixed>  $policy
     */
    public function __construct(array $policy, bool $strictMode = false)
    {
        $this->policy = $policy;
        $this->strictMode = $strictMode;
    }

    /**
     * Lint the Blade template.
     */
    /**
     * @return array<string, mixed>
     */
    public function lint(string $template): array
    {
        $this->violations = [];
        $this->warnings = [];

        // Split template into lines for analysis
        $lines = explode("\n", $template);

        foreach ($lines as $lineNumber => $line) {
            $this->lintLine($line, $lineNumber + 1);
        }

        // Perform multi-line analysis
        $this->lintMultiLine($template);

        return [
            'ok' => empty($this->violations),
            'violations' => $this->violations,
            'warnings' => $this->warnings,
            'stats' => [
                'lines_analyzed' => count($lines),
                'violation_count' => count($this->violations),
                'warning_count' => count($this->warnings),
            ],
        ];
    }

    /**
     * Lint individual line.
     */
    private function lintLine(string $line, int $lineNumber): void
    {
        // Check for forbidden patterns
        $this->checkInlinePHP($line, $lineNumber);
        $this->checkFacadeCalls($line, $lineNumber);
        $this->checkModelCalls($line, $lineNumber);
        $this->checkDatabaseCalls($line, $lineNumber);
        $this->checkHTTPCalls($line, $lineNumber);

        // Check for preferred patterns
        $this->checkStatamicTagUsage($line, $lineNumber);
        $this->checkComponentUsage($line, $lineNumber);

        // Check for common issues
        $this->checkSecurityIssues($line, $lineNumber);
        $this->checkAccessibilityIssues($line, $lineNumber);

        if ($this->strictMode) {
            $this->checkStrictModeIssues($line, $lineNumber);
        }
    }

    /**
     * Check for inline PHP usage.
     */
    private function checkInlinePHP(string $line, int $lineNumber): void
    {
        if (! ($this->policy['forbid']['inline_php'] ?? false)) {
            return;
        }

        $patterns = [
            '/@php\s/' => '@php directive found',
            '/<\?php/' => 'PHP opening tag found',
            '/\?\>/' => 'PHP closing tag found',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                $this->addViolation(
                    'inline_php',
                    $message . '. Use Blade components or move logic to controllers.',
                    $lineNumber,
                    $matches[0][1] + 1,
                    'error'
                );
            }
        }
    }

    /**
     * Check for facade calls.
     */
    private function checkFacadeCalls(string $line, int $lineNumber): void
    {
        $forbiddenFacades = $this->policy['forbid']['facades'] ?? [];

        foreach ($forbiddenFacades as $facade) {
            $patterns = [
                "/\\\\{$facade}\\\\Facades\\\\/" => "Direct {$facade} facade call",
                "/\\\\{$facade}\\\\/" => "{$facade} namespace usage",
                "/use\\s+{$facade}\\\\/" => "{$facade} import statement",
            ];

            foreach ($patterns as $pattern => $message) {
                if (preg_match($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                    $this->addViolation(
                        'facade_call',
                        $message . '. Use Statamic Blade components instead.',
                        $lineNumber,
                        $matches[0][1] + 1,
                        'error'
                    );
                }
            }
        }
    }

    /**
     * Check for model calls in views.
     */
    private function checkModelCalls(string $line, int $lineNumber): void
    {
        if (! ($this->policy['forbid']['models_in_view'] ?? false)) {
            return;
        }

        $patterns = [
            '/\\\\App\\\\Models\\\\/' => 'Direct model usage in view',
            '/Model::/' => 'Static model method call',
            '/->where\(/' => 'Query builder usage in view',
            '/::query\(\)/' => 'Eloquent query in view',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                $this->addViolation(
                    'models_in_view',
                    $message . '. Move data fetching to controllers or view composers.',
                    $lineNumber,
                    $matches[0][1] + 1,
                    'error'
                );
            }
        }
    }

    /**
     * Check for database calls.
     */
    private function checkDatabaseCalls(string $line, int $lineNumber): void
    {
        $patterns = [
            '/DB::/' => 'Direct database query',
            '/\\\\DB::/' => 'Database facade usage',
            '/->select\(/' => 'Raw SQL select',
            '/->insert\(/' => 'Raw SQL insert',
            '/->update\(/' => 'Raw SQL update',
            '/->delete\(/' => 'Raw SQL delete',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                $this->addViolation(
                    'database_calls',
                    $message . ' in view. Move to controller or service layer.',
                    $lineNumber,
                    $matches[0][1] + 1,
                    'error'
                );
            }
        }
    }

    /**
     * Check for HTTP calls.
     */
    private function checkHTTPCalls(string $line, int $lineNumber): void
    {
        $patterns = [
            '/Http::/' => 'HTTP client usage',
            '/\\\\Http::/' => 'HTTP facade usage',
            '/curl_/' => 'cURL function usage',
            '/file_get_contents\(/' => 'file_get_contents for HTTP',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                $this->addViolation(
                    'http_calls',
                    $message . ' in view. Move HTTP requests to controllers.',
                    $lineNumber,
                    $matches[0][1] + 1,
                    'error'
                );
            }
        }
    }

    /**
     * Check for proper Statamic tag usage.
     */
    private function checkStatamicTagUsage(string $line, int $lineNumber): void
    {
        if (! ($this->policy['prefer']['tags'] ?? false)) {
            return;
        }

        // Look for patterns that could use Statamic tags
        $antiPatterns = [
            '/Entry::whereCollection/' => 'Use <x-statamic:entries> instead of Entry::whereCollection',
            '/Collection::findByHandle/' => 'Use <x-statamic:collection> instead of Collection::findByHandle',
            '/Taxonomy::findByHandle/' => 'Use <x-statamic:taxonomy> instead of Taxonomy::findByHandle',
            '/Asset::whereContainer/' => 'Use <x-statamic:assets> instead of Asset::whereContainer',
        ];

        foreach ($antiPatterns as $pattern => $suggestion) {
            if (preg_match($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                $this->addViolation(
                    'prefer_statamic_tags',
                    $suggestion,
                    $lineNumber,
                    $matches[0][1] + 1,
                    'warning'
                );
            }
        }
    }

    /**
     * Check for component usage recommendations.
     */
    private function checkComponentUsage(string $line, int $lineNumber): void
    {
        if (! ($this->policy['prefer']['components'] ?? false)) {
            return;
        }

        // Suggest components for repetitive patterns
        if (preg_match('/<article[^>]*>.*<h[1-6]>.*<\/h[1-6]>.*<\/article>/', $line)) {
            $this->addWarning(
                'suggest_component',
                'Consider creating an entry-card component for this article pattern',
                $lineNumber
            );
        }

        if (preg_match('/<img[^>]*src="[^"]*glide[^"]*"/', $line)) {
            $this->addWarning(
                'suggest_component',
                'Consider creating a responsive-image component for Glide images',
                $lineNumber
            );
        }
    }

    /**
     * Check for security issues.
     */
    private function checkSecurityIssues(string $line, int $lineNumber): void
    {
        // Check for unescaped output
        if (preg_match('/\{\!\!\s*\$/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->addViolation(
                'unescaped_output',
                'Unescaped output detected. Ensure content is safe or use {{ }} for auto-escaping.',
                $lineNumber,
                $matches[0][1] + 1,
                'error'
            );
        }

        // Check for potential XSS vulnerabilities
        if (preg_match('/innerHTML\s*=|outerHTML\s*=/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->addViolation(
                'xss_risk',
                'Direct HTML injection detected. Validate and sanitize content.',
                $lineNumber,
                $matches[0][1] + 1,
                'error'
            );
        }
    }

    /**
     * Check for accessibility issues.
     */
    private function checkAccessibilityIssues(string $line, int $lineNumber): void
    {
        // Check for images without alt text
        if (preg_match('/<img(?![^>]*alt=)[^>]*>/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->addWarning(
                'missing_alt_text',
                'Image missing alt attribute. Add alt text for accessibility.',
                $lineNumber,
                $matches[0][1] + 1
            );
        }

        // Check for links without descriptive text
        if (preg_match('/<a[^>]*>(click here|read more|more|here)<\/a>/i', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->addWarning(
                'non_descriptive_link',
                'Link text should be descriptive. Avoid generic phrases like "click here".',
                $lineNumber,
                $matches[0][1] + 1
            );
        }

        // Check for missing form labels
        if (preg_match('/<input(?![^>]*id=)/', $line) && ! preg_match('/<label/', $line)) {
            $this->addWarning(
                'missing_form_label',
                'Form input should have associated label for accessibility.',
                $lineNumber
            );
        }
    }

    /**
     * Check strict mode issues.
     */
    private function checkStrictModeIssues(string $line, int $lineNumber): void
    {
        // Check for hardcoded URLs
        if (preg_match('/https?:\/\/[^\s"\']+/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->addWarning(
                'hardcoded_url',
                'Hardcoded URL found. Consider using config values or relative URLs.',
                $lineNumber,
                $matches[0][1] + 1
            );
        }

        // Check for hardcoded text that should be localized
        if (preg_match('/>[A-Z][a-z\s]{10,}</', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->addWarning(
                'hardcoded_text',
                'Consider moving longer text to language files for localization.',
                $lineNumber,
                $matches[0][1] + 1
            );
        }

        // Check for complex Blade expressions
        if (preg_match('/\{\{[^}]{50,}\}\}/', $line, $matches, PREG_OFFSET_CAPTURE)) {
            $this->addWarning(
                'complex_expression',
                'Complex expression in template. Consider moving logic to controller.',
                $lineNumber,
                $matches[0][1] + 1
            );
        }
    }

    /**
     * Perform multi-line analysis.
     */
    private function lintMultiLine(string $template): void
    {
        // Check for unclosed Blade directives
        $this->checkUnclosedDirectives($template);

        // Check for component structure
        $this->checkComponentStructure($template);

        // Check for performance issues
        $this->checkPerformanceIssues($template);
    }

    /**
     * Check for unclosed Blade directives.
     */
    private function checkUnclosedDirectives(string $template): void
    {
        $openDirectives = [];
        $directives = ['if', 'unless', 'foreach', 'forelse', 'for', 'while', 'switch', 'section', 'push', 'component'];

        $lines = explode("\n", $template);

        foreach ($lines as $lineNumber => $line) {
            foreach ($directives as $directive) {
                // Opening directive
                if (preg_match("/@{$directive}(?:\s|$|\()/", $line)) {
                    $openDirectives[] = ['directive' => $directive, 'line' => $lineNumber + 1];
                }

                // Closing directive
                if (preg_match("/@end{$directive}/", $line)) {
                    $found = false;
                    for ($i = count($openDirectives) - 1; $i >= 0; $i--) {
                        if ($openDirectives[$i]['directive'] === $directive) {
                            unset($openDirectives[$i]);
                            $openDirectives = array_values($openDirectives);
                            $found = true;
                            break;
                        }
                    }

                    if (! $found) {
                        $this->addViolation(
                            'unmatched_directive',
                            "Closing @end{$directive} without matching @{$directive}",
                            $lineNumber + 1,
                            1,
                            'error'
                        );
                    }
                }
            }
        }

        // Report unclosed directives
        foreach ($openDirectives as $openDirective) {
            $this->addViolation(
                'unclosed_directive',
                "Unclosed @{$openDirective['directive']} directive",
                $openDirective['line'],
                1,
                'error'
            );
        }
    }

    /**
     * Check component structure.
     */
    private function checkComponentStructure(string $template): void
    {
        // Check for proper component naming
        if (preg_match_all('/<x-([a-z0-9\-_]+)/', $template, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $componentName = $match[0];
                if (! preg_match('/^[a-z][a-z0-9\-]*$/', $componentName)) {
                    $line = substr_count(substr($template, 0, $match[1]), "\n") + 1;
                    $this->addWarning(
                        'component_naming',
                        "Component name '{$componentName}' should use kebab-case",
                        $line
                    );
                }
            }
        }
    }

    /**
     * Check for performance issues.
     */
    private function checkPerformanceIssues(string $template): void
    {
        // Check for nested loops
        $nestedLoops = preg_match_all('/@foreach.*@foreach/s', $template);
        if ($nestedLoops > 0) {
            $this->addWarning(
                'nested_loops',
                'Nested loops detected. Consider optimizing data structure or using eager loading.',
                1
            );
        }

        // Check for excessive queries in loops
        if (preg_match('/(@foreach.*@endforeach)/s', $template, $loopMatches)) {
            foreach ($loopMatches as $loop) {
                $queryCount = preg_match_all('/\$\w+->/', $loop);
                if ($queryCount > 3) {
                    $this->addWarning(
                        'n_plus_one',
                        'Potential N+1 query issue in loop. Consider eager loading relationships.',
                        1
                    );
                }
            }
        }
    }

    /**
     * Add a violation.
     */
    private function addViolation(string $code, string $message, int $line, ?int $column = null, string $severity = 'error'): void
    {
        $violation = [
            'code' => $code,
            'message' => $message,
            'line' => $line,
            'column' => $column,
            'severity' => $severity,
        ];

        if ($severity === 'error') {
            $this->violations[] = $violation;
        } else {
            $this->warnings[] = $violation;
        }
    }

    /**
     * Add a warning.
     */
    private function addWarning(string $code, string $message, int $line, ?int $column = null): void
    {
        $this->addViolation($code, $message, $line, $column, 'warning');
    }
}

/**
 * Blade Auto-fixer
 */
class BladeAutoFixer
{
    /**
     * Generate auto-fix suggestions.
     *
     * @param  array<int, array<string, mixed>>  $violations
     *
     * @return array<int, array<string, mixed>>
     */
    public function generateFixes(string $template, array $violations): array
    {
        $suggestions = [];

        foreach ($violations as $violation) {
            $fix = $this->generateFixForViolation($template, $violation);
            if ($fix) {
                $suggestions[] = $fix;
            }
        }

        return $suggestions;
    }

    /**
     * Generate fix for individual violation.
     *
     * @param  array<string, mixed>  $violation
     *
     * @return array<string, mixed>|null
     */
    private function generateFixForViolation(string $template, array $violation): ?array
    {
        switch ($violation['code']) {
            case 'facade_call':
                return $this->fixFacadeCall($template, $violation);
            case 'inline_php':
                return $this->fixInlinePHP($template, $violation);
            case 'models_in_view':
                return $this->fixModelCall($template, $violation);
            case 'missing_alt_text':
                return $this->fixMissingAltText($template, $violation);
            default:
                return null;
        }
    }

    /**
     * Fix facade call violations.
     *
     * @param  array<string, mixed>  $violation
     *
     * @return array<string, mixed>
     */
    private function fixFacadeCall(string $template, array $violation): array
    {
        $line = $this->getLineFromTemplate($template, $violation['line']);

        // Common facade to component mappings
        $replacements = [
            '/\\\\Statamic\\\\Facades\\\\Entry::whereCollection\([\'"]([^\'"]+)[\'"]\)/' => '<x-statamic:entries :from="\'$1\'">',
            '/Entry::whereCollection\([\'"]([^\'"]+)[\'"]\)/' => '<x-statamic:entries :from="\'$1\'">',
            '/\\\\Statamic\\\\Facades\\\\Collection::findByHandle\([\'"]([^\'"]+)[\'"]\)/' => '<x-statamic:collection handle="$1">',
        ];

        foreach ($replacements as $pattern => $replacement) {
            if (preg_match($pattern, $line, $matches)) {
                return [
                    'type' => 'replacement',
                    'line' => $violation['line'],
                    'original' => $matches[0],
                    'replacement' => $replacement,
                    'description' => 'Replace facade call with Statamic component',
                ];
            }
        }

        return [
            'type' => 'suggestion',
            'line' => $violation['line'],
            'description' => 'Replace facade call with appropriate Statamic component',
            'examples' => [
                'Entry queries' => '<x-statamic:entries :from="collection_name">',
                'Collection data' => '<x-statamic:collection handle="collection_name">',
                'Taxonomy terms' => '<x-statamic:taxonomy :from="taxonomy_name">',
            ],
        ];
    }

    /**
     * Fix inline PHP violations.
     *
     * @param  array<string, mixed>  $violation
     *
     * @return array<string, mixed>
     */
    private function fixInlinePHP(string $template, array $violation): array
    {
        return [
            'type' => 'suggestion',
            'line' => $violation['line'],
            'description' => 'Move PHP logic to controller or create Blade component',
            'alternatives' => [
                'Controller' => 'Move data fetching to controller and pass to view',
                'View Composer' => 'Use view composer for complex view logic',
                'Component' => 'Create Blade component with computed properties',
            ],
        ];
    }

    /**
     * Fix model call violations.
     *
     * @param  array<string, mixed>  $violation
     *
     * @return array<string, mixed>
     */
    private function fixModelCall(string $template, array $violation): array
    {
        return [
            'type' => 'suggestion',
            'line' => $violation['line'],
            'description' => 'Move model queries to controller or service layer',
            'alternatives' => [
                'Controller' => 'Fetch data in controller method',
                'View Composer' => 'Use view composer for view-specific data',
                'Repository' => 'Create repository pattern for data access',
            ],
        ];
    }

    /**
     * Fix missing alt text violations.
     *
     * @param  array<string, mixed>  $violation
     *
     * @return array<string, mixed>|null
     */
    private function fixMissingAltText(string $template, array $violation): ?array
    {
        $line = $this->getLineFromTemplate($template, $violation['line']);

        if (preg_match('/(<img[^>]*)(>)/', $line, $matches)) {
            $replacement = $matches[1] . ' alt="{{ $alt ?? \'\' }}"' . $matches[2];

            return [
                'type' => 'replacement',
                'line' => $violation['line'],
                'original' => $matches[0],
                'replacement' => $replacement,
                'description' => 'Add alt attribute for accessibility',
            ];
        }

        return null;
    }

    /**
     * Get specific line from template.
     */
    private function getLineFromTemplate(string $template, int $lineNumber): string
    {
        $lines = explode("\n", $template);

        return $lines[$lineNumber - 1] ?? '';
    }
}
