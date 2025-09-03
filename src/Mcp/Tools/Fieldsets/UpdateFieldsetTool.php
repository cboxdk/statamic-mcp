<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Fieldset;

#[Title('Update Statamic Fieldset')]
class UpdateFieldsetTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.fieldsets.update';
    }

    protected function getToolDescription(): string
    {
        return 'Update an existing Statamic fieldset';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Fieldset handle')
            ->required()
            ->string('title')
            ->description('Fieldset title')
            ->optional()
            ->raw('fields', [
                'type' => 'array',
                'description' => 'Field definitions for the fieldset',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => ['type' => 'string'],
                        'field' => ['type' => 'object'],
                    ],
                ],
            ])
            ->optional()
            ->boolean('dry_run')
            ->description('Preview changes without updating')
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
        $newTitle = $arguments['title'] ?? null;
        $newFields = $arguments['fields'] ?? null;
        $dryRun = $arguments['dry_run'] ?? false;

        $fieldset = Fieldset::find($handle);

        if (! $fieldset) {
            return $this->createErrorResponse("Fieldset '{$handle}' not found")->toArray();
        }

        $currentContents = $fieldset->contents();
        $changes = [];

        // Check for title change
        if ($newTitle !== null && $newTitle !== $fieldset->title()) {
            $changes['title'] = ['from' => $fieldset->title(), 'to' => $newTitle];
        }

        // Check for fields change
        $validatedFields = null;
        if ($newFields !== null) {
            $validatedFields = $this->validateFields($newFields);
            if (isset($validatedFields['error'])) {
                return $this->createErrorResponse($validatedFields['error'])->toArray();
            }

            $currentFields = $currentContents['fields'] ?? [];
            if ($this->fieldsChanged($currentFields, $validatedFields)) {
                $changes['fields'] = [
                    'from_count' => count($currentFields),
                    'to_count' => count($validatedFields),
                    'from' => $currentFields,
                    'to' => $validatedFields,
                ];
            }
        }

        if (count($changes) === 0) {
            return [
                'handle' => $handle,
                'message' => 'No changes detected',
                'fieldset' => [
                    'handle' => $fieldset->handle(),
                    'title' => $fieldset->title(),
                    'field_count' => count($currentContents['fields'] ?? []),
                ],
            ];
        }

        if ($dryRun) {
            return [
                'dry_run' => true,
                'handle' => $handle,
                'proposed_changes' => $changes,
                'has_changes' => true,
            ];
        }

        try {
            // Apply updates
            $updatedContents = $currentContents;

            if (isset($changes['title'])) {
                $updatedContents['title'] = $newTitle;
            }

            if (isset($changes['fields']) && $validatedFields !== null) {
                $updatedContents['fields'] = $validatedFields;
            }

            // Update the fieldset
            $fieldset->setContents($updatedContents);
            $fieldset->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('fieldset_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'fieldset' => [
                    'handle' => $fieldset->handle(),
                    'title' => $fieldset->title(),
                    'field_count' => count($fieldset->contents()['fields'] ?? []),
                    'contents' => $fieldset->contents(),
                ],
                'changes' => $changes,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not update fieldset: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Validate field definitions.
     *
     * @param  array<int, mixed>  $fields
     *
     * @return array<int, mixed>|array<string, string>
     */
    private function validateFields(array $fields): array
    {
        if (empty($fields)) {
            return ['error' => 'Fields array cannot be empty'];
        }

        $validatedFields = [];
        $handles = [];

        foreach ($fields as $field) {
            if (! isset($field['handle'])) {
                return ['error' => 'Each field must have a handle'];
            }

            if (! isset($field['field'])) {
                return ['error' => 'Each field must have field configuration'];
            }

            $handle = $field['handle'];

            // Check for duplicate handles
            if (in_array($handle, $handles)) {
                return ['error' => "Duplicate field handle: {$handle}"];
            }

            $handles[] = $handle;

            // Validate field structure
            $fieldConfig = $field['field'];
            if (! isset($fieldConfig['type'])) {
                $fieldConfig['type'] = 'text'; // Default field type
            }

            $validatedFields[] = [
                'handle' => $handle,
                'field' => $fieldConfig,
            ];
        }

        return $validatedFields;
    }

    /**
     * Check if fields have changed.
     *
     * @param  array<int|string, mixed>  $currentFields
     * @param  array<int|string, mixed>  $newFields
     */
    private function fieldsChanged(array $currentFields, array $newFields): bool
    {
        return serialize($currentFields) !== serialize($newFields);
    }
}
