<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Globals;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\GlobalSet;

#[Title('Get Global Set Structure')]
#[IsReadOnly]
class GetGlobalSetTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.globals.sets.get';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific global set structure';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Global set handle to retrieve')
            ->required()
            ->boolean('include_blueprint_fields')
            ->description('Include detailed blueprint field definitions')
            ->optional()
            ->boolean('include_sample_data')
            ->description('Include sample data structure based on blueprint')
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
        $handle = $arguments['handle'];
        $includeBlueprintFields = $arguments['include_blueprint_fields'] ?? true;
        $includeSampleData = $arguments['include_sample_data'] ?? false;

        try {
            $globalSet = GlobalSet::findByHandle($handle);

            if (! $globalSet) {
                return $this->createErrorResponse("Global set '{$handle}' not found", [
                    'available_sets' => GlobalSet::all()->map->handle()->all(),
                ])->toArray();
            }

            $data = [
                'handle' => $globalSet->handle(),
                'title' => $globalSet->title(),
                'sites' => $globalSet->sites()->all(),
                'has_blueprint' => $globalSet->blueprint() !== null,
            ];

            if ($blueprint = $globalSet->blueprint()) {
                $data['blueprint'] = [
                    'handle' => $blueprint->handle(),
                    'title' => $blueprint->title(),
                    'namespace' => $blueprint->namespace(),
                ];

                if ($includeBlueprintFields) {
                    $fields = [];
                    foreach ($blueprint->fields()->all() as $field) {
                        $fields[$field->handle()] = [
                            'type' => $field->type(),
                            'display' => $field->display(),
                            'instructions' => $field->get('instructions'),
                            'required' => $field->get('required', false),
                            'visibility' => $field->get('visibility', 'visible'),
                            'config' => $field->config(),
                        ];
                    }
                    $data['blueprint']['fields'] = $fields;
                }

                if ($includeSampleData) {
                    $sampleData = [];
                    foreach ($blueprint->fields()->all() as $field) {
                        $sampleData[$field->handle()] = $this->generateSampleValue($field->type(), $field->config());
                    }
                    $data['sample_data_structure'] = $sampleData;
                }
            }

            // Get localization information
            $localizations = [];
            foreach ($globalSet->sites() as $siteHandle) {
                $localizedSet = $globalSet->in($siteHandle);
                $localizations[$siteHandle] = [
                    'exists' => $localizedSet !== null,
                    'has_data' => $localizedSet ? ! empty($localizedSet->data()->all()) : false,
                    'data_keys' => $localizedSet ? array_keys($localizedSet->data()->all()) : [],
                ];
            }
            $data['localizations'] = $localizations;

            return $data;

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to get global set: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Generate sample value based on field type and config.
     *
     * @param  array<string, mixed>  $config
     */
    private function generateSampleValue(string $type, array $config): mixed
    {
        return match ($type) {
            'text' => 'Sample text value',
            'textarea' => "Sample multiline text\nwith line breaks",
            'markdown' => "# Sample Markdown\n\nWith **bold** and *italic* text.",
            'toggle' => false,
            'select' => $config['options'][0] ?? 'option1',
            'date' => '2025-01-15',
            'time' => '14:30',
            'integer' => 42,
            'float' => 3.14,
            'url' => 'https://example.com',
            'email' => 'example@domain.com',
            'assets' => isset($config['max_files']) && $config['max_files'] === 1 ? 'sample-image.jpg' : ['sample1.jpg', 'sample2.jpg'],
            'entries' => isset($config['max_items']) && $config['max_items'] === 1 ? 'sample-entry-id' : ['entry-1', 'entry-2'],
            'taxonomy' => isset($config['max_items']) && $config['max_items'] === 1 ? 'sample-term' : ['term-1', 'term-2'],
            'users' => isset($config['max_items']) && $config['max_items'] === 1 ? 'user-id' : ['user-1', 'user-2'],
            'array' => ['item1', 'item2', 'item3'],
            'grid' => [
                ['column1' => 'value1', 'column2' => 'value2'],
                ['column1' => 'value3', 'column2' => 'value4'],
            ],
            'replicator' => [
                ['type' => 'text_block', 'text' => 'Sample replicator content'],
                ['type' => 'image_block', 'image' => 'sample-image.jpg'],
            ],
            'bard' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sample Bard content']]],
            ],
            default => 'Sample ' . $type . ' value',
        };
    }
}
