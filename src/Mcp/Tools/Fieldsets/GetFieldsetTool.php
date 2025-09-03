<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Fieldset;

#[Title('Get Statamic Fieldset')]
#[IsReadOnly]
class GetFieldsetTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.fieldsets.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific Statamic fieldset';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Fieldset handle')
            ->required()
            ->boolean('include_analysis')
            ->description('Include detailed analysis of fieldset structure')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $includeAnalysis = $arguments['include_analysis'] ?? true;

        $fieldset = Fieldset::find($handle);

        if (! $fieldset) {
            return $this->createErrorResponse("Fieldset '{$handle}' not found")->toArray();
        }

        $contents = $fieldset->contents();

        $fieldsetData = [
            'handle' => $fieldset->handle(),
            'title' => $fieldset->title(),
            'contents' => $contents,
            'fields' => $contents['fields'] ?? [],
            'field_count' => count($contents['fields'] ?? []),
        ];

        if ($includeAnalysis) {
            $analysis = $this->analyzeFieldsetStructure($fieldset);
            $fieldsetData['analysis'] = $analysis;
        }

        return [
            'fieldset' => $fieldsetData,
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

        $fieldTypes = [];
        $replicatorCount = 0;
        $complexityScore = 0;
        $hasReplicator = false;
        $hasBard = false;
        $fieldsByType = [];

        foreach ($fields as $field) {
            $fieldType = $field['field']['type'] ?? 'text';
            $fieldTypes[] = $fieldType;

            if (! isset($fieldsByType[$fieldType])) {
                $fieldsByType[$fieldType] = 0;
            }
            $fieldsByType[$fieldType]++;

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
            'field_types' => array_unique($fieldTypes),
            'fields_by_type' => $fieldsByType,
            'replicator_count' => $replicatorCount,
            'complexity_score' => $complexityScore,
            'page_builder_ready' => $pageBuilderReady,
            'has_replicator' => $hasReplicator,
            'has_bard' => $hasBard,
            'recommendations' => $this->getRecommendations($fieldsByType, $complexityScore),
        ];
    }

    /**
     * Get recommendations based on fieldset analysis.
     *
     * @param  array<string, int>  $fieldsByType
     *
     * @return array<int, string>
     */
    private function getRecommendations(array $fieldsByType, int $complexityScore): array
    {
        $recommendations = [];

        if ($complexityScore < 5) {
            $recommendations[] = 'Consider adding more field types to increase flexibility';
        }

        if (! isset($fieldsByType['replicator']) && $complexityScore > 10) {
            $recommendations[] = 'This fieldset could benefit from a replicator field for modular content';
        }

        if (isset($fieldsByType['text']) && $fieldsByType['text'] > 5) {
            $recommendations[] = 'Consider grouping some text fields or using different field types';
        }

        if ($complexityScore > 30) {
            $recommendations[] = 'This fieldset is quite complex - consider splitting into multiple fieldsets';
        }

        return $recommendations;
    }
}
