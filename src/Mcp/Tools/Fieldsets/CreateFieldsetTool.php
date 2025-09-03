<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Fieldsets;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Fieldset;

#[Title('Create Statamic Fieldset')]
class CreateFieldsetTool extends BaseStatamicTool
{
    use ClearsCaches;

    protected function getToolName(): string
    {
        return 'statamic.fieldsets.create';
    }

    protected function getToolDescription(): string
    {
        return 'Create a new Statamic fieldset with field definitions';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Fieldset handle (unique identifier)')
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
            ->required()
            ->boolean('dry_run')
            ->description('Preview changes without creating')
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
        $title = $arguments['title'] ?? ucfirst(str_replace(['_', '-'], ' ', $handle));
        $fields = $arguments['fields'] ?? [];
        $dryRun = $arguments['dry_run'] ?? false;

        // Validate handle
        if (Fieldset::find($handle)) {
            return $this->createErrorResponse("Fieldset '{$handle}' already exists")->toArray();
        }

        // Validate fields structure
        $validatedFields = $this->validateFields($fields);
        if (isset($validatedFields['error'])) {
            return $this->createErrorResponse($validatedFields['error'])->toArray();
        }

        $fieldsetData = [
            'title' => $title,
            'fields' => $validatedFields,
        ];

        if ($dryRun) {
            return [
                'dry_run' => true,
                'would_create' => [
                    'handle' => $handle,
                    'title' => $title,
                    'field_count' => count($validatedFields),
                    'fieldset_data' => $fieldsetData,
                ],
            ];
        }

        try {
            // Create the fieldset
            $fieldset = Fieldset::make($handle);
            $fieldset->setContents($fieldsetData);
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
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not create fieldset: ' . $e->getMessage())->toArray();
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
}
