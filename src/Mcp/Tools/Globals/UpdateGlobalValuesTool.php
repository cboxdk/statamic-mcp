<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

#[Title('Update Global Values')]
class UpdateGlobalValuesTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.globals.values.update';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Update global values (content) in a global set';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('global_set')
            ->description('Global set handle to update values in')
            ->required()
            ->raw('values', [
                'type' => 'object',
                'description' => 'Key-value pairs of field handles and their new values',
                'additionalProperties' => true,
            ])
            ->required()
            ->string('site')
            ->description('Site handle to update values for (defaults to default site)')
            ->optional()
            ->boolean('merge_values')
            ->description('Merge with existing values (true) or replace all values (false)')
            ->optional()
            ->boolean('validate_fields')
            ->description('Validate fields against blueprint before saving')
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
        $globalSetHandle = $arguments['global_set'];
        $newValues = $arguments['values'];
        $siteHandle = $arguments['site'] ?? Site::default()->handle();
        $mergeValues = $arguments['merge_values'] ?? true;
        $validateFields = $arguments['validate_fields'] ?? true;

        try {
            // Validate site
            if (! Site::all()->map(fn ($site) => $site->handle())->contains($siteHandle)) {
                return $this->createErrorResponse("Site '{$siteHandle}' not found", [
                    'available_sites' => Site::all()->map(fn ($site) => $site->handle())->all(),
                ])->toArray();
            }

            $globalSet = GlobalSet::findByHandle($globalSetHandle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set '{$globalSetHandle}' not found", [
                    'available_sets' => GlobalSet::all()->map(fn ($set) => $set->handle())->all(),
                ])->toArray();
            }

            $localizedSet = $globalSet->in($siteHandle);

            if (! $localizedSet) {
                return $this->createErrorResponse("Global set '{$globalSetHandle}' does not exist for site '{$siteHandle}'", [
                    'available_sites_for_set' => $globalSet->sites()->all(),
                ])->toArray();
            }

            // Validate fields against blueprint if requested
            if ($validateFields && $blueprint = $globalSet->blueprint()) {
                $validationErrors = $this->validateValuesAgainstBlueprint($newValues, $blueprint);
                if (! empty($validationErrors)) {
                    return $this->createErrorResponse('Field validation failed', [
                        'validation_errors' => $validationErrors,
                        'available_fields' => array_keys($blueprint->fields()->all()->toArray()),
                    ])->toArray();
                }
            }

            // Get current values
            $currentValues = $localizedSet->data()->all();

            // Determine final values based on merge setting
            if ($mergeValues) {
                $finalValues = array_merge($currentValues, $newValues);
            } else {
                $finalValues = $newValues;
            }

            // Update the values
            $localizedSet->data($finalValues);
            $localizedSet->save();

            // Clear relevant caches
            Stache::clear();

            return [
                'success' => true,
                'global_set_handle' => $globalSetHandle,
                'site' => $siteHandle,
                'updated_fields' => array_keys($newValues),
                'merge_mode' => $mergeValues,
                'previous_values' => $currentValues,
                'new_values' => $finalValues,
                'changes' => $this->getChanges($currentValues, $finalValues),
                'metadata' => [
                    'updated_at' => now()->toISOString(),
                    'total_fields' => count($finalValues),
                    'fields_modified' => count($newValues),
                    'validation_performed' => $validateFields,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to update global values: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Validate values against blueprint.
     *
     * @param  array<string, mixed>  $values
     * @param  \Statamic\Fields\Blueprint  $blueprint
     *
     * @return array<string, string>
     */
    private function validateValuesAgainstBlueprint(array $values, $blueprint): array
    {
        $errors = [];
        $blueprintFields = $blueprint->fields()->all();

        foreach ($values as $field => $value) {
            if (! $blueprintFields->has($field)) {
                $errors[$field] = "Field '{$field}' is not defined in the blueprint";
                continue;
            }

            $fieldDefinition = $blueprintFields->get($field);

            // Check required fields
            if ($fieldDefinition->get('required', false) && ($value === null || $value === '')) {
                $errors[$field] = "Field '{$field}' is required but was empty";
            }

            // Type-specific validation could be added here
            // For now, we only check if the field exists in the blueprint
        }

        return $errors;
    }

    /**
     * Get changes between old and new values.
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     *
     * @return array<string, mixed>
     */
    private function getChanges(array $oldValues, array $newValues): array
    {
        $changes = [
            'added' => [],
            'modified' => [],
            'removed' => [],
        ];

        // Find added and modified fields
        foreach ($newValues as $field => $newValue) {
            if (! array_key_exists($field, $oldValues)) {
                $changes['added'][$field] = $newValue;
            } elseif ($oldValues[$field] !== $newValue) {
                $changes['modified'][$field] = [
                    'old' => $oldValues[$field],
                    'new' => $newValue,
                ];
            }
        }

        // Find removed fields
        foreach ($oldValues as $field => $oldValue) {
            if (! array_key_exists($field, $newValues)) {
                $changes['removed'][$field] = $oldValue;
            }
        }

        return $changes;
    }
}
