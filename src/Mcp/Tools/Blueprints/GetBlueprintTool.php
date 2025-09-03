<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;

#[Title('Get Statamic Blueprint')]
#[IsReadOnly]
class GetBlueprintTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.get';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get a specific blueprint with full field definitions and configuration';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Blueprint handle/identifier')
            ->required()
            ->string('namespace')
            ->description('Blueprint namespace (collections, forms, taxonomies, globals, assets, users, navs)')
            ->optional()
            ->boolean('include_field_details')
            ->description('Include detailed field configuration instead of just field names')
            ->optional();
    }

    /**
     * Execute the tool.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $namespace = $arguments['namespace'] ?? 'collections';
        $includeFieldDetails = $arguments['include_field_details'] ?? true;

        try {
            $blueprint = Blueprint::find("{$namespace}.{$handle}");

            if (! $blueprint) {
                return [
                    'error' => "Blueprint '{$handle}' not found in namespace '{$namespace}'",
                    'handle' => $handle,
                    'namespace' => $namespace,
                    'available_namespaces' => ['collections', 'forms', 'taxonomies', 'globals', 'assets', 'users', 'navs'],
                ];
            }

            /** @var array<string, mixed> $contents */
            $contents = $blueprint->contents();

            $processedTabs = [];
            $totalFieldCount = 0;
            $totalSectionCount = 0;

            // Process tabs structure (Statamic v5+)
            $tabs = $contents['tabs'] ?? [];
            foreach ($tabs as $tabName => $tab) {
                $processedSections = [];

                if (isset($tab['sections']) && is_array($tab['sections'])) {
                    foreach ($tab['sections'] as $sectionIndex => $section) {
                        $sectionHandle = is_string($sectionIndex) ? $sectionIndex : (string) $sectionIndex;
                        $processedFields = [];

                        /** @var array<string, mixed> $fields */
                        $fields = $section['fields'] ?? [];

                        foreach ($fields as $fieldConfig) {
                            if (isset($fieldConfig['handle']) && isset($fieldConfig['field'])) {
                                $fieldHandle = $fieldConfig['handle'];
                                /** @var array<string, mixed> $fieldDef */
                                $fieldDef = $fieldConfig['field'];

                                if ($includeFieldDetails) {
                                    $processedFields[$fieldHandle] = $fieldDef;
                                } else {
                                    $processedFields[$fieldHandle] = [
                                        'type' => $fieldDef['type'] ?? 'text',
                                        'display' => $fieldDef['display'] ?? $fieldHandle,
                                        'required' => $fieldDef['required'] ?? false,
                                    ];
                                }
                                $totalFieldCount++;
                            }
                        }

                        $processedSections[$sectionHandle] = [
                            'display' => $section['display'] ?? null,
                            'instructions' => $section['instructions'] ?? null,
                            'fields' => $processedFields,
                            'field_count' => count($processedFields),
                        ];
                        $totalSectionCount++;
                    }
                }

                $processedTabs[$tabName] = [
                    'display' => $tab['display'] ?? $tabName,
                    'sections' => $processedSections,
                    'sections_count' => count($processedSections),
                ];
            }

            // Fallback to direct sections for older format
            if (empty($processedTabs) && isset($contents['sections'])) {
                $processedSections = [];
                $allSections = $contents['sections'];

                foreach ($allSections as $sectionHandle => $sectionConfig) {
                    /** @var array<string, mixed> $fields */
                    $fields = $sectionConfig['fields'] ?? [];
                    $processedFields = [];

                    foreach ($fields as $fieldConfig) {
                        if (isset($fieldConfig['handle']) && isset($fieldConfig['field'])) {
                            $fieldHandle = $fieldConfig['handle'];
                            /** @var array<string, mixed> $fieldDef */
                            $fieldDef = $fieldConfig['field'];

                            if ($includeFieldDetails) {
                                $processedFields[$fieldHandle] = $fieldDef;
                            } else {
                                $processedFields[$fieldHandle] = [
                                    'type' => $fieldDef['type'] ?? 'text',
                                    'display' => $fieldDef['display'] ?? $fieldHandle,
                                    'required' => $fieldDef['required'] ?? false,
                                ];
                            }
                            $totalFieldCount++;
                        }
                    }

                    $processedSections[$sectionHandle] = [
                        'display' => $sectionConfig['display'] ?? $sectionHandle,
                        'instructions' => $sectionConfig['instructions'] ?? null,
                        'fields' => $processedFields,
                        'field_count' => count($processedFields),
                    ];
                    $totalSectionCount++;
                }
            }

            return [
                'handle' => $handle,
                'namespace' => $namespace,
                'title' => $blueprint->title(),
                'tabs' => $processedTabs,
                'tabs_count' => count($processedTabs),
                'sections_count' => $totalSectionCount,
                'total_field_count' => $totalFieldCount,
                'includes_field_details' => $includeFieldDetails,
                'hidden' => $contents['hidden'] ?? [],
                'order' => $contents['order'] ?? null,
                // Legacy sections for backwards compatibility
                'sections' => empty($processedTabs) ? $processedSections ?? [] : null,
            ];
        } catch (\Exception $e) {
            return [
                'error' => "Failed to get blueprint '{$handle}' from namespace '{$namespace}': " . $e->getMessage(),
                'handle' => $handle,
                'namespace' => $namespace,
            ];
        }
    }
}
