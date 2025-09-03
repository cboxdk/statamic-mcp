<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

#[Title('Detect Field Conflicts')]
#[IsReadOnly]
class DetectFieldConflictsTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.field-conflicts';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Detect field conflicts, naming issues, and incompatibilities across blueprints';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('scope')
            ->description('Analysis scope: all, collection, taxonomy, or specific namespace')
            ->optional()
            ->string('collection_handle')
            ->description('Specific collection handle to analyze (when scope=collection)')
            ->optional()
            ->boolean('check_cross_blueprint_conflicts')
            ->description('Check for conflicts between different blueprints')
            ->optional()
            ->boolean('analyze_field_type_conflicts')
            ->description('Analyze conflicts between field types and configurations')
            ->optional()
            ->boolean('detect_naming_patterns')
            ->description('Detect problematic naming patterns and suggest improvements')
            ->optional()
            ->boolean('include_suggestions')
            ->description('Include resolution suggestions for detected conflicts')
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
        $scope = $arguments['scope'] ?? 'all';
        $collectionHandle = $arguments['collection_handle'] ?? null;
        $checkCrossBlueprintConflicts = $arguments['check_cross_blueprint_conflicts'] ?? true;
        $analyzeFieldTypeConflicts = $arguments['analyze_field_type_conflicts'] ?? true;
        $detectNamingPatterns = $arguments['detect_naming_patterns'] ?? true;
        $includeSuggestions = $arguments['include_suggestions'] ?? true;

        try {
            $blueprints = $this->getBlueprintsForScope($scope, $collectionHandle);

            if (empty($blueprints)) {
                return $this->createErrorResponse('No blueprints found for the specified scope')->toArray();
            }

            $conflictAnalysis = [
                'blueprints_analyzed' => count($blueprints),
                'conflicts' => [],
                'naming_issues' => [],
                'type_conflicts' => [],
                'cross_blueprint_issues' => [],
                'statistics' => [
                    'total_conflicts' => 0,
                    'critical_conflicts' => 0,
                    'warning_conflicts' => 0,
                    'unique_field_names' => 0,
                    'duplicate_field_names' => 0,
                ],
                'suggestions' => [],
            ];

            $allFields = $this->extractAllFields($blueprints);
            $conflictAnalysis['statistics']['unique_field_names'] = count(array_unique(array_keys($allFields)));

            // Analyze individual blueprints
            foreach ($blueprints as $blueprint) {
                $blueprintConflicts = $this->analyzeBlueprintConflicts($blueprint);
                $conflictAnalysis['conflicts'][$blueprint->handle()] = $blueprintConflicts;

                $conflictAnalysis['statistics']['total_conflicts'] += count($blueprintConflicts['conflicts']);
                $conflictAnalysis['statistics']['critical_conflicts'] += count(array_filter(
                    $blueprintConflicts['conflicts'],
                    fn ($conflict) => $conflict['severity'] === 'critical'
                ));
                $conflictAnalysis['statistics']['warning_conflicts'] += count(array_filter(
                    $blueprintConflicts['conflicts'],
                    fn ($conflict) => $conflict['severity'] === 'warning'
                ));
            }

            // Cross-blueprint analysis
            if ($checkCrossBlueprintConflicts) {
                $crossBlueprintIssues = $this->analyzeCrossBlueprintConflicts($blueprints);
                $conflictAnalysis['cross_blueprint_issues'] = $crossBlueprintIssues;
                $conflictAnalysis['statistics']['total_conflicts'] += count($crossBlueprintIssues);
            }

            // Field type conflict analysis
            if ($analyzeFieldTypeConflicts) {
                $typeConflicts = $this->analyzeFieldTypeConflicts($allFields);
                $conflictAnalysis['type_conflicts'] = $typeConflicts;
                $conflictAnalysis['statistics']['total_conflicts'] += count($typeConflicts);
            }

            // Naming pattern analysis
            if ($detectNamingPatterns) {
                $namingIssues = $this->analyzeNamingPatterns($allFields);
                $conflictAnalysis['naming_issues'] = $namingIssues;
                $conflictAnalysis['statistics']['total_conflicts'] += count($namingIssues);
            }

            // Count duplicate field names
            $fieldCounts = array_count_values(array_keys($allFields));
            $conflictAnalysis['statistics']['duplicate_field_names'] = count(array_filter(
                $fieldCounts,
                fn ($count) => $count > 1
            ));

            // Generate suggestions
            if ($includeSuggestions) {
                $conflictAnalysis['suggestions'] = $this->generateConflictSuggestions($conflictAnalysis);
            }

            return [
                'analysis' => $conflictAnalysis,
                'summary' => [
                    'status' => $conflictAnalysis['statistics']['total_conflicts'] === 0 ? 'clean' : 'conflicts_detected',
                    'conflict_level' => $this->getConflictLevel($conflictAnalysis['statistics']),
                    'most_common_issues' => $this->getMostCommonIssues($conflictAnalysis),
                    'blueprints_with_issues' => count(array_filter(
                        $conflictAnalysis['conflicts'],
                        fn ($blueprint) => ! empty($blueprint['conflicts'])
                    )),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to detect field conflicts: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Get blueprints based on scope.
     *
     *
     * @return array<\Statamic\Fields\Blueprint>
     */
    private function getBlueprintsForScope(string $scope, ?string $collectionHandle): array
    {
        switch ($scope) {
            case 'collection':
                if (! $collectionHandle) {
                    return [];
                }
                $collection = Collection::findByHandle($collectionHandle);

                return $collection ? $collection->entryBlueprints() : [];

            case 'taxonomy':
                return Blueprint::in('taxonomies')->all();

            case 'globals':
                return Blueprint::in('globals')->all();

            case 'all':
            default:
                $blueprints = collect();
                foreach (['collections', 'taxonomies', 'assets', 'globals', 'forms', 'users'] as $ns) {
                    $blueprints = $blueprints->merge(Blueprint::in($ns)->all());
                }

                return $blueprints->all();
        }
    }

    /**
     * Extract all fields from blueprints.
     *
     * @param  array<\Statamic\Fields\Blueprint>  $blueprints
     *
     * @return array<string, array<mixed>>
     */
    private function extractAllFields(array $blueprints): array
    {
        $allFields = [];

        foreach ($blueprints as $blueprint) {
            $fields = $blueprint->fields()->all();
            foreach ($fields as $handle => $field) {
                $allFields[$handle][] = [
                    'blueprint' => $blueprint->handle(),
                    'field' => $field,
                    'type' => $field->type(),
                    'config' => $field->config(),
                ];
            }
        }

        return $allFields;
    }

    /**
     * Analyze conflicts within a single blueprint.
     *
     * @param  \Statamic\Fields\Blueprint  $blueprint
     *
     * @return array<string, mixed>
     */
    private function analyzeBlueprintConflicts($blueprint): array
    {
        $analysis = [
            'blueprint' => $blueprint->handle(),
            'title' => $blueprint->title(),
            'conflicts' => [],
        ];

        $fields = $blueprint->fields()->all();
        $fieldHandles = array_keys($fields->toArray());

        foreach ($fields as $handle => $field) {
            $config = $field->config();

            // Check for reserved word conflicts
            $reservedConflict = $this->checkReservedWordConflicts($handle);
            if ($reservedConflict) {
                $analysis['conflicts'][] = $reservedConflict;
            }

            // Check field configuration conflicts
            $configConflicts = $this->checkFieldConfigConflicts($handle, $field);
            $analysis['conflicts'] = array_merge($analysis['conflicts'], $configConflicts);

            // Check validation rule conflicts
            $validationConflicts = $this->checkValidationConflicts($handle, $config, $fieldHandles);
            $analysis['conflicts'] = array_merge($analysis['conflicts'], $validationConflicts);
        }

        return $analysis;
    }

    /**
     * Analyze conflicts between different blueprints.
     *
     * @param  array<\Statamic\Fields\Blueprint>  $blueprints
     *
     * @return array<array<string, mixed>>
     */
    private function analyzeCrossBlueprintConflicts(array $blueprints): array
    {
        $conflicts = [];
        $fieldUsage = [];

        // Collect field usage across blueprints
        foreach ($blueprints as $blueprint) {
            $fields = $blueprint->fields()->all();
            foreach ($fields as $handle => $field) {
                $fieldUsage[$handle][] = [
                    'blueprint' => $blueprint->handle(),
                    'type' => $field->type(),
                    'config' => $field->config(),
                ];
            }
        }

        // Check for inconsistent field definitions
        foreach ($fieldUsage as $fieldHandle => $usages) {
            if (count($usages) > 1) {
                $conflict = $this->analyzeFieldUsageConsistency($fieldHandle, $usages);
                if ($conflict) {
                    $conflicts[] = $conflict;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Analyze field type conflicts.
     *
     * @param  array<string, array<mixed>>  $allFields
     *
     * @return array<array<string, mixed>>
     */
    private function analyzeFieldTypeConflicts(array $allFields): array
    {
        $conflicts = [];

        foreach ($allFields as $fieldHandle => $usages) {
            if (count($usages) <= 1) {
                continue;
            }

            $types = array_unique(array_column($usages, 'type'));

            if (count($types) > 1) {
                $conflicts[] = [
                    'type' => 'type_inconsistency',
                    'field' => $fieldHandle,
                    'severity' => 'critical',
                    'description' => "Field '{$fieldHandle}' uses different types across blueprints",
                    'types_used' => $types,
                    'blueprints' => array_column($usages, 'blueprint'),
                ];
            } else {
                // Check for configuration conflicts within the same type
                $configConflict = $this->checkConfigurationConsistency($fieldHandle, $usages);
                if ($configConflict) {
                    $conflicts[] = $configConflict;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Analyze naming patterns and issues.
     *
     * @param  array<string, array<mixed>>  $allFields
     *
     * @return array<array<string, mixed>>
     */
    private function analyzeNamingPatterns(array $allFields): array
    {
        $issues = [];

        foreach ($allFields as $fieldHandle => $usages) {
            // Check for problematic naming patterns
            $namingIssues = $this->checkNamingPatterns($fieldHandle, $usages);
            $issues = array_merge($issues, $namingIssues);
        }

        return $issues;
    }

    /**
     * Check for reserved word conflicts.
     *
     *
     * @return array<string, mixed>|null
     */
    private function checkReservedWordConflicts(string $handle): ?array
    {
        $reservedWords = [
            'id', 'slug', 'uri', 'url', 'title', 'status', 'published', 'date', 'author',
            'created_at', 'updated_at', 'collection', 'taxonomy', 'site', 'locale',
        ];

        if (in_array($handle, $reservedWords)) {
            return [
                'type' => 'reserved_word',
                'field' => $handle,
                'severity' => 'critical',
                'description' => "Field '{$handle}' conflicts with Statamic reserved word",
                'reserved_word' => $handle,
            ];
        }

        return null;
    }

    /**
     * Check field configuration for conflicts.
     *
     * @param  \Statamic\Fields\Field  $field
     *
     * @return array<array<string, mixed>>
     */
    private function checkFieldConfigConflicts(string $handle, $field): array
    {
        $conflicts = [];
        $config = $field->config();
        $type = $field->type();

        // Type-specific configuration conflicts
        switch ($type) {
            case 'text':
                if (isset($config['input_type']) && $config['input_type'] === 'number' && isset($config['character_limit'])) {
                    $conflicts[] = [
                        'type' => 'config_conflict',
                        'field' => $handle,
                        'severity' => 'warning',
                        'description' => "Text field '{$handle}' with number input type should not have character limit",
                    ];
                }
                break;

            case 'entries':
                if (isset($config['max_items']) && isset($config['collections']) && $config['max_items'] > 1 && empty($config['collections'])) {
                    $conflicts[] = [
                        'type' => 'config_conflict',
                        'field' => $handle,
                        'severity' => 'warning',
                        'description' => "Entries field '{$handle}' allows multiple items but no collections specified",
                    ];
                }
                break;

            case 'assets':
                if (isset($config['max_files']) && $config['max_files'] > 1 && ! isset($config['container'])) {
                    $conflicts[] = [
                        'type' => 'config_conflict',
                        'field' => $handle,
                        'severity' => 'warning',
                        'description' => "Assets field '{$handle}' allows multiple files but no container specified",
                    ];
                }
                break;
        }

        return $conflicts;
    }

    /**
     * Check validation rule conflicts.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string>  $fieldHandles
     *
     * @return array<array<string, mixed>>
     */
    private function checkValidationConflicts(string $handle, array $config, array $fieldHandles): array
    {
        $conflicts = [];

        if (! isset($config['validate'])) {
            return $conflicts;
        }

        $rules = is_array($config['validate']) ? $config['validate'] : [$config['validate']];

        foreach ($rules as $rule) {
            // Check for conflicting rules
            if (in_array('required', $rules) && str_contains($rule, 'nullable')) {
                $conflicts[] = [
                    'type' => 'validation_conflict',
                    'field' => $handle,
                    'severity' => 'critical',
                    'description' => "Field '{$handle}' has conflicting required and nullable rules",
                ];
            }

            // Check for invalid field references
            if (str_contains($rule, 'same:') || str_contains($rule, 'different:')) {
                $referencedField = str_replace(['same:', 'different:'], '', $rule);
                if (! in_array($referencedField, $fieldHandles)) {
                    $conflicts[] = [
                        'type' => 'invalid_reference',
                        'field' => $handle,
                        'severity' => 'critical',
                        'description' => "Field '{$handle}' validation references non-existent field '{$referencedField}'",
                        'referenced_field' => $referencedField,
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Analyze field usage consistency across blueprints.
     *
     * @param  array<array<string, mixed>>  $usages
     *
     * @return array<string, mixed>|null
     */
    private function analyzeFieldUsageConsistency(string $fieldHandle, array $usages): ?array
    {
        $types = array_unique(array_column($usages, 'type'));

        if (count($types) > 1) {
            return [
                'type' => 'inconsistent_type',
                'field' => $fieldHandle,
                'severity' => 'critical',
                'description' => "Field '{$fieldHandle}' uses different types across blueprints",
                'types' => $types,
                'blueprints' => array_column($usages, 'blueprint'),
            ];
        }

        return null;
    }

    /**
     * Check configuration consistency for same-type fields.
     *
     * @param  array<array<string, mixed>>  $usages
     *
     * @return array<string, mixed>|null
     */
    private function checkConfigurationConsistency(string $fieldHandle, array $usages): ?array
    {
        $configs = array_column($usages, 'config');
        $firstConfig = $configs[0];

        foreach ($configs as $config) {
            // Check important configuration differences
            $importantKeys = ['required', 'max_items', 'max_files', 'options', 'collections'];

            foreach ($importantKeys as $key) {
                $firstValue = $firstConfig[$key] ?? null;
                $currentValue = $config[$key] ?? null;

                if ($firstValue !== $currentValue) {
                    return [
                        'type' => 'config_inconsistency',
                        'field' => $fieldHandle,
                        'severity' => 'warning',
                        'description' => "Field '{$fieldHandle}' has inconsistent '{$key}' configuration across blueprints",
                        'config_key' => $key,
                        'blueprints' => array_column($usages, 'blueprint'),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Check naming patterns for issues.
     *
     * @param  array<array<string, mixed>>  $usages
     *
     * @return array<array<string, mixed>>
     */
    private function checkNamingPatterns(string $fieldHandle, array $usages): array
    {
        $issues = [];

        // Check for common naming anti-patterns
        if (strlen($fieldHandle) === 1) {
            $issues[] = [
                'type' => 'naming_issue',
                'field' => $fieldHandle,
                'severity' => 'warning',
                'description' => "Field '{$fieldHandle}' has a very short, unclear name",
                'suggestion' => 'Use more descriptive field names',
            ];
        }

        if (str_contains($fieldHandle, 'temp') || str_contains($fieldHandle, 'test')) {
            $issues[] = [
                'type' => 'naming_issue',
                'field' => $fieldHandle,
                'severity' => 'warning',
                'description' => "Field '{$fieldHandle}' appears to be temporary or test field",
                'suggestion' => 'Remove test fields from production blueprints',
            ];
        }

        if (preg_match('/\d+$/', $fieldHandle)) {
            $issues[] = [
                'type' => 'naming_issue',
                'field' => $fieldHandle,
                'severity' => 'info',
                'description' => "Field '{$fieldHandle}' ends with a number, consider using arrays or collections",
                'suggestion' => 'Consider using repeatable fields or collections instead',
            ];
        }

        return $issues;
    }

    /**
     * Generate conflict resolution suggestions.
     *
     * @param  array<string, mixed>  $analysis
     *
     * @return array<string>
     */
    private function generateConflictSuggestions(array $analysis): array
    {
        $suggestions = [];

        if ($analysis['statistics']['critical_conflicts'] > 0) {
            $suggestions[] = 'Fix critical conflicts immediately to prevent data integrity issues';
        }

        if ($analysis['statistics']['duplicate_field_names'] > 5) {
            $suggestions[] = 'Consider standardizing field names across blueprints for consistency';
        }

        if (! empty($analysis['type_conflicts'])) {
            $suggestions[] = 'Resolve field type inconsistencies to ensure predictable behavior';
        }

        if (! empty($analysis['naming_issues'])) {
            $suggestions[] = 'Review field naming conventions and establish consistent patterns';
        }

        return $suggestions;
    }

    /**
     * Get conflict level based on statistics.
     *
     * @param  array<string, mixed>  $stats
     */
    private function getConflictLevel(array $stats): string
    {
        if ($stats['critical_conflicts'] > 0) {
            return 'critical';
        }
        if ($stats['total_conflicts'] > 20) {
            return 'high';
        }
        if ($stats['total_conflicts'] > 5) {
            return 'medium';
        }
        if ($stats['total_conflicts'] > 0) {
            return 'low';
        }

        return 'none';
    }

    /**
     * Get most common issues.
     *
     * @param  array<string, mixed>  $analysis
     *
     * @return array<string>
     */
    private function getMostCommonIssues(array $analysis): array
    {
        $issueTypes = [];

        foreach ($analysis['conflicts'] as $blueprint) {
            foreach ($blueprint['conflicts'] as $conflict) {
                $type = $conflict['type'];
                $issueTypes[$type] = ($issueTypes[$type] ?? 0) + 1;
            }
        }

        foreach ($analysis['cross_blueprint_issues'] as $issue) {
            $type = $issue['type'];
            $issueTypes[$type] = ($issueTypes[$type] ?? 0) + 1;
        }

        arsort($issueTypes);

        return array_slice(array_keys($issueTypes), 0, 3);
    }
}
