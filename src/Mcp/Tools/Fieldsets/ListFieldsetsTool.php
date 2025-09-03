<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Fieldset;

#[Title('List Statamic Fieldsets')]
#[IsReadOnly]
class ListFieldsetsTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.fieldsets.list';
    }

    protected function getToolDescription(): string
    {
        return 'List all Statamic fieldsets with analysis and complexity information';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('include_analysis')
            ->description('Include detailed analysis of fieldset complexity and structure')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeAnalysis = $arguments['include_analysis'] ?? true;

        $fieldsets = Fieldset::all();
        $fieldsetList = [];

        foreach ($fieldsets as $fieldset) {
            $fieldsetData = [
                'handle' => $fieldset->handle(),
                'title' => $fieldset->title(),
                'field_count' => count($fieldset->contents()['fields'] ?? []),
            ];

            if ($includeAnalysis) {
                $analysis = $this->analyzeFieldsetStructure($fieldset);
                $fieldsetData = array_merge($fieldsetData, [
                    'page_builder_ready' => $analysis['page_builder_ready'],
                    'replicator_fields' => $analysis['replicator_count'],
                    'complexity_score' => $analysis['complexity_score'],
                ]);
            }

            $fieldsetList[] = $fieldsetData;
        }

        // Sort by complexity if analysis included
        if ($includeAnalysis) {
            usort($fieldsetList, fn ($a, $b) => ($b['complexity_score'] ?? 0) <=> ($a['complexity_score'] ?? 0));
        }

        return [
            'fieldsets' => $fieldsetList,
            'total_fieldsets' => count($fieldsetList),
            'analysis_included' => $includeAnalysis,
        ];
    }

    /**
     * Analyze fieldset structure for page builder compatibility and complexity.
     *
     * @param  mixed  $fieldset
     *
     * @return array<string, mixed>
     */
    private function analyzeFieldsetStructure($fieldset): array
    {
        $contents = $fieldset->contents();
        $fields = $contents['fields'] ?? [];

        $replicatorCount = 0;
        $complexityScore = 0;
        $hasReplicator = false;
        $hasBard = false;

        foreach ($fields as $field) {
            $fieldType = $field['field']['type'] ?? 'text';

            switch ($fieldType) {
                case 'replicator':
                    $replicatorCount++;
                    $hasReplicator = true;
                    $complexityScore += 10;
                    break;
                case 'bard':
                    $hasBard = true;
                    $complexityScore += 8;
                    break;
                case 'grid':
                    $complexityScore += 6;
                    break;
                case 'group':
                    $complexityScore += 4;
                    break;
                default:
                    $complexityScore += 1;
                    break;
            }
        }

        // Page builder readiness check
        $pageBuilderReady = $hasReplicator || $hasBard || $complexityScore >= 15;

        return [
            'replicator_count' => $replicatorCount,
            'complexity_score' => $complexityScore,
            'page_builder_ready' => $pageBuilderReady,
            'field_types' => array_unique(array_column($fields, 'field.type')),
        ];
    }
}
