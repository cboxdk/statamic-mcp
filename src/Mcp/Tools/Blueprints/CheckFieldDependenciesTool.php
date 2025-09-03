<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;

#[Title('Check Field Dependencies')]
#[IsReadOnly]
class CheckFieldDependenciesTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.field-dependencies';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Analyze field dependencies, conditional logic, and cross-field relationships in blueprints';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('namespace')
            ->description('Blueprint namespace (e.g., collections.blog, taxonomies.tags)')
            ->optional()
            ->string('handle')
            ->description('Specific blueprint handle within the namespace')
            ->optional()
            ->boolean('check_circular_dependencies')
            ->description('Check for circular dependency chains')
            ->optional()
            ->boolean('analyze_complexity')
            ->description('Analyze conditional logic complexity')
            ->optional()
            ->boolean('suggest_optimizations')
            ->description('Suggest optimizations for complex dependencies')
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
        $namespace = $arguments['namespace'] ?? null;
        $handle = $arguments['handle'] ?? null;
        $checkCircularDependencies = $arguments['check_circular_dependencies'] ?? true;
        $analyzeComplexity = $arguments['analyze_complexity'] ?? true;
        $suggestOptimizations = $arguments['suggest_optimizations'] ?? true;

        try {
            $blueprints = [];

            if ($namespace && $handle) {
                // Analyze specific blueprint
                $blueprintHandle = "{$namespace}.{$handle}";
                $blueprint = Blueprint::find($blueprintHandle);

                if (! $blueprint) {
                    return $this->createErrorResponse("Blueprint '{$blueprintHandle}' not found")->toArray();
                }

                $blueprints = [$blueprint];
            } elseif ($namespace) {
                // Analyze all blueprints in namespace
                $blueprints = Blueprint::in($namespace)->all();
            } else {
                // Analyze all blueprints across namespaces
                $blueprints = collect();
                foreach (['collections', 'taxonomies', 'assets', 'globals', 'forms', 'users'] as $ns) {
                    $blueprints = $blueprints->merge(Blueprint::in($ns)->all());
                }
            }

            $analysis = [
                'blueprints_analyzed' => count($blueprints),
                'dependencies' => [],
                'issues' => [],
                'statistics' => [
                    'total_conditional_fields' => 0,
                    'total_validation_dependencies' => 0,
                    'max_dependency_depth' => 0,
                    'circular_dependencies' => 0,
                ],
                'recommendations' => [],
            ];

            foreach ($blueprints as $blueprint) {
                $blueprintAnalysis = $this->analyzeBlueprintDependencies(
                    $blueprint,
                    $checkCircularDependencies,
                    $analyzeComplexity
                );

                $analysis['dependencies'][$blueprint->handle()] = $blueprintAnalysis;

                // Update statistics
                $analysis['statistics']['total_conditional_fields'] += $blueprintAnalysis['conditional_fields_count'];
                $analysis['statistics']['total_validation_dependencies'] += $blueprintAnalysis['validation_dependencies_count'];
                $analysis['statistics']['max_dependency_depth'] = max(
                    $analysis['statistics']['max_dependency_depth'],
                    $blueprintAnalysis['max_depth']
                );

                if (! empty($blueprintAnalysis['circular_dependencies'])) {
                    $analysis['statistics']['circular_dependencies'] += count($blueprintAnalysis['circular_dependencies']);
                }

                // Collect issues
                $analysis['issues'] = array_merge($analysis['issues'], $blueprintAnalysis['issues']);
            }

            if ($suggestOptimizations) {
                $analysis['recommendations'] = $this->generateOptimizationRecommendations($analysis);
            }

            return [
                'analysis' => $analysis,
                'summary' => [
                    'status' => empty($analysis['issues']) ? 'healthy' : 'issues_detected',
                    'blueprints_with_dependencies' => count(array_filter(
                        $analysis['dependencies'],
                        fn ($dep) => $dep['has_dependencies']
                    )),
                    'total_issues' => count($analysis['issues']),
                    'complexity_level' => $this->getComplexityLevel($analysis['statistics']),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to check field dependencies: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Analyze dependencies for a single blueprint.
     *
     * @param  \Statamic\Fields\Blueprint  $blueprint
     *
     * @return array<string, mixed>
     */
    private function analyzeBlueprintDependencies($blueprint, bool $checkCircular, bool $analyzeComplexity): array
    {
        $analysis = [
            'title' => $blueprint->title(),
            'handle' => $blueprint->handle(),
            'has_dependencies' => false,
            'conditional_fields' => [],
            'validation_dependencies' => [],
            'circular_dependencies' => [],
            'dependency_chains' => [],
            'conditional_fields_count' => 0,
            'validation_dependencies_count' => 0,
            'max_depth' => 0,
            'complexity_score' => 0,
            'issues' => [],
        ];

        $fields = $blueprint->fields()->all();
        $fieldHandles = array_keys($fields->toArray());

        // Analyze conditional fields
        foreach ($fields as $handle => $field) {
            $config = $field->config();

            // Check 'if' conditions
            if (isset($config['if'])) {
                $analysis['has_dependencies'] = true;
                $analysis['conditional_fields_count']++;

                $conditions = $this->parseConditions($config['if']);
                $referencedFields = $this->extractReferencedFields($conditions);

                $conditionAnalysis = [
                    'field' => $handle,
                    'conditions' => $conditions,
                    'referenced_fields' => $referencedFields,
                    'missing_references' => array_diff($referencedFields, $fieldHandles),
                ];

                $analysis['conditional_fields'][] = $conditionAnalysis;

                // Track issues
                if (! empty($conditionAnalysis['missing_references'])) {
                    $analysis['issues'][] = [
                        'type' => 'missing_reference',
                        'field' => $handle,
                        'blueprint' => $blueprint->handle(),
                        'missing_fields' => $conditionAnalysis['missing_references'],
                        'severity' => 'error',
                    ];
                }

                if ($analyzeComplexity) {
                    $complexityScore = $this->calculateConditionComplexity($conditions);
                    $analysis['complexity_score'] += $complexityScore;

                    if ($complexityScore > 10) {
                        $analysis['issues'][] = [
                            'type' => 'complex_condition',
                            'field' => $handle,
                            'blueprint' => $blueprint->handle(),
                            'complexity_score' => $complexityScore,
                            'severity' => 'warning',
                        ];
                    }
                }
            }

            // Check validation dependencies
            if (isset($config['validate'])) {
                $validationRules = is_array($config['validate']) ? $config['validate'] : [$config['validate']];
                $validationDeps = $this->extractValidationDependencies($validationRules, $fieldHandles);

                if (! empty($validationDeps['referenced_fields'])) {
                    $analysis['has_dependencies'] = true;
                    $analysis['validation_dependencies_count']++;
                    $analysis['validation_dependencies'][] = [
                        'field' => $handle,
                        'dependencies' => $validationDeps,
                    ];

                    if (! empty($validationDeps['missing_references'])) {
                        $analysis['issues'][] = [
                            'type' => 'missing_validation_reference',
                            'field' => $handle,
                            'blueprint' => $blueprint->handle(),
                            'missing_fields' => $validationDeps['missing_references'],
                            'severity' => 'error',
                        ];
                    }
                }
            }
        }

        // Check for circular dependencies
        if ($checkCircular && $analysis['has_dependencies']) {
            $circularDeps = $this->findCircularDependencies($fields->toArray());
            $analysis['circular_dependencies'] = $circularDeps;

            foreach ($circularDeps as $chain) {
                $analysis['issues'][] = [
                    'type' => 'circular_dependency',
                    'blueprint' => $blueprint->handle(),
                    'chain' => $chain,
                    'severity' => 'error',
                ];
            }
        }

        // Calculate dependency depth
        $analysis['max_depth'] = $this->calculateMaxDependencyDepth($fields->toArray());

        return $analysis;
    }

    /**
     * Parse condition strings into structured format.
     *
     * @param  mixed  $conditions
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseConditions($conditions): array
    {
        if (is_string($conditions)) {
            return [$this->parseConditionString($conditions)];
        }

        if (is_array($conditions)) {
            $parsed = [];
            foreach ($conditions as $condition) {
                if (is_string($condition)) {
                    $parsed[] = $this->parseConditionString($condition);
                }
            }

            return $parsed;
        }

        return [];
    }

    /**
     * Parse a single condition string.
     *
     *
     * @return array<string, mixed>
     */
    private function parseConditionString(string $condition): array
    {
        // Basic parsing - can be extended for more complex conditions
        $parts = explode(' ', trim($condition));

        return [
            'raw' => $condition,
            'field' => $parts[0],
            'operator' => $parts[1] ?? '=',
            'value' => implode(' ', array_slice($parts, 2)),
        ];
    }

    /**
     * Extract referenced field names from conditions.
     *
     * @param  array<array<string, mixed>>  $conditions
     *
     * @return array<string>
     */
    private function extractReferencedFields(array $conditions): array
    {
        $fields = [];

        foreach ($conditions as $condition) {
            if (! empty($condition['field'])) {
                $fields[] = $condition['field'];
            }
        }

        return array_unique($fields);
    }

    /**
     * Extract validation dependencies.
     *
     * @param  array<string>  $rules
     * @param  array<string>  $availableFields
     *
     * @return array<string, mixed>
     */
    private function extractValidationDependencies(array $rules, array $availableFields): array
    {
        $dependencies = [
            'referenced_fields' => [],
            'missing_references' => [],
            'rules' => [],
        ];

        foreach ($rules as $rule) {
            if (str_contains($rule, 'same:')) {
                $referencedField = str_replace('same:', '', $rule);
                $dependencies['referenced_fields'][] = $referencedField;
                $dependencies['rules'][] = $rule;

                if (! in_array($referencedField, $availableFields)) {
                    $dependencies['missing_references'][] = $referencedField;
                }
            }

            if (str_contains($rule, 'different:')) {
                $referencedField = str_replace('different:', '', $rule);
                $dependencies['referenced_fields'][] = $referencedField;
                $dependencies['rules'][] = $rule;

                if (! in_array($referencedField, $availableFields)) {
                    $dependencies['missing_references'][] = $referencedField;
                }
            }
        }

        return $dependencies;
    }

    /**
     * Find circular dependencies in fields.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<array<string>>
     */
    private function findCircularDependencies(array $fields): array
    {
        $dependencies = [];
        $circularDeps = [];

        // Build dependency map
        foreach ($fields as $handle => $field) {
            $config = $field->config();
            $deps = [];

            if (isset($config['if'])) {
                $conditions = $this->parseConditions($config['if']);
                $deps = array_merge($deps, $this->extractReferencedFields($conditions));
            }

            if (! empty($deps)) {
                $dependencies[$handle] = $deps;
            }
        }

        // Check for circular dependencies using DFS
        foreach ($dependencies as $field => $deps) {
            $visited = [];
            if ($this->hasCycle($field, $dependencies, $visited, [])) {
                $circularDeps[] = $visited;
            }
        }

        return $circularDeps;
    }

    /**
     * Check if there's a cycle starting from a field.
     *
     * @param  array<string, array<string>>  $dependencies
     * @param  array<string>  $visited
     * @param  array<string>  $path
     */
    private function hasCycle(string $field, array $dependencies, array &$visited, array $path): bool
    {
        if (in_array($field, $path)) {
            $startIndex = array_search($field, $path, true);
            $visited = is_int($startIndex) ? array_slice($path, $startIndex) : [];

            return true;
        }

        if (! isset($dependencies[$field])) {
            return false;
        }

        $newPath = array_merge($path, [$field]);

        foreach ($dependencies[$field] as $dependency) {
            if ($this->hasCycle($dependency, $dependencies, $visited, $newPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate maximum dependency depth.
     *
     * @param  array<string, mixed>  $fields
     */
    private function calculateMaxDependencyDepth(array $fields): int
    {
        $maxDepth = 0;

        foreach ($fields as $handle => $field) {
            $config = $field->config();
            if (isset($config['if'])) {
                $conditions = $this->parseConditions($config['if']);
                $depth = count($this->extractReferencedFields($conditions));
                $maxDepth = max($maxDepth, $depth);
            }
        }

        return $maxDepth;
    }

    /**
     * Calculate complexity score for conditions.
     *
     * @param  array<array<string, mixed>>  $conditions
     */
    private function calculateConditionComplexity(array $conditions): int
    {
        $score = 0;

        foreach ($conditions as $condition) {
            $score += 1; // Base score per condition

            // Add complexity for operators
            $operator = $condition['operator'] ?? '=';
            if (in_array($operator, ['!=', '<>', 'not'])) {
                $score += 1;
            }
            if (in_array($operator, ['>', '<', '>=', '<='])) {
                $score += 2;
            }

            // Add complexity for value patterns
            $value = $condition['value'] ?? '';
            if (str_contains($value, '|')) {
                $score += count(explode('|', $value));
            }
        }

        return $score;
    }

    /**
     * Generate optimization recommendations.
     *
     * @param  array<string, mixed>  $analysis
     *
     * @return array<string>
     */
    private function generateOptimizationRecommendations(array $analysis): array
    {
        $recommendations = [];

        if ($analysis['statistics']['circular_dependencies'] > 0) {
            $recommendations[] = 'Fix circular dependencies to prevent UI issues';
        }

        if ($analysis['statistics']['max_dependency_depth'] > 5) {
            $recommendations[] = 'Consider simplifying complex dependency chains';
        }

        if ($analysis['statistics']['total_conditional_fields'] > 20) {
            $recommendations[] = 'High number of conditional fields may impact performance';
        }

        $errorCount = count(array_filter($analysis['issues'], fn ($issue) => $issue['severity'] === 'error'));
        if ($errorCount > 0) {
            $recommendations[] = "Fix {$errorCount} dependency errors to ensure proper field behavior";
        }

        return $recommendations;
    }

    /**
     * Get complexity level based on statistics.
     *
     * @param  array<string, mixed>  $stats
     */
    private function getComplexityLevel(array $stats): string
    {
        $score = 0;
        $score += $stats['total_conditional_fields'] * 1;
        $score += $stats['total_validation_dependencies'] * 2;
        $score += $stats['max_dependency_depth'] * 3;
        $score += $stats['circular_dependencies'] * 10;

        if ($score === 0) {
            return 'none';
        }
        if ($score <= 10) {
            return 'low';
        }
        if ($score <= 30) {
            return 'medium';
        }
        if ($score <= 60) {
            return 'high';
        }

        return 'very_high';
    }
}
