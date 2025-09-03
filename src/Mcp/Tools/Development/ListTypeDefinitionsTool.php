<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Development;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

#[Title('List Available Type Definitions')]
#[IsReadOnly]
class ListTypeDefinitionsTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.development.types.list';
    }

    protected function getToolDescription(): string
    {
        return 'List available type definitions based on Statamic blueprints';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('scope', [
                'type' => 'string',
                'enum' => ['collections', 'blueprints', 'fields', 'all'],
                'description' => 'Scope of types to list',
            ])
            ->optional()
            ->boolean('include_details')
            ->description('Include detailed information about fields (default: false)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $scope = $arguments['scope'] ?? 'all';
        $includeDetails = $arguments['include_details'] ?? false;

        $result = [
            'scope' => $scope,
            'available_formats' => ['typescript', 'php', 'json'],
        ];

        if (in_array($scope, ['collections', 'all'])) {
            $result['collections'] = $this->listCollectionTypes($includeDetails);
        }

        if (in_array($scope, ['blueprints', 'all'])) {
            $result['blueprints'] = $this->listBlueprintTypes($includeDetails);
        }

        if (in_array($scope, ['fields', 'all'])) {
            $result['field_types'] = $this->listFieldTypes($includeDetails);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function listCollectionTypes(bool $includeDetails): array
    {
        $collections = Collection::all();
        $collectionTypes = [];

        foreach ($collections as $collection) {
            $collectionData = [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'blueprint_count' => count($collection->entryBlueprints()),
                'entry_count' => $collection->queryEntries()->count(),
            ];

            if ($includeDetails) {
                $collectionData['blueprints'] = $collection->entryBlueprints()
                    ->map(fn ($bp) => [
                        'handle' => $bp->handle(),
                        'title' => $bp->title(),
                        'field_count' => count($bp->fields()->all()),
                    ])->all();
            }

            $collectionTypes[] = $collectionData;
        }

        return ['collections' => $collectionTypes];
    }

    /**
     * @return array<string, mixed>
     */
    private function listBlueprintTypes(bool $includeDetails): array
    {
        $blueprints = collect([
            ...Blueprint::in('collections')->all(),
            ...Blueprint::in('taxonomies')->all(),
            ...Blueprint::in('globals')->all(),
            ...Blueprint::in('assets')->all(),
            ...Blueprint::in('users')->all(),
        ]);
        $blueprintTypes = [];

        foreach ($blueprints as $blueprint) {
            $blueprintData = [
                'handle' => $blueprint->handle(),
                'title' => $blueprint->title(),
                'namespace' => $blueprint->namespace(),
                'field_count' => count($blueprint->fields()->all()),
            ];

            if ($includeDetails) {
                $fields = $blueprint->fields()->all();
                $fieldData = [];
                foreach ($fields as $handle => $field) {
                    $fieldData[$handle] = [
                        'handle' => $handle,
                        'type' => $field->type(),
                        'required' => $field->get('required', false),
                        'instructions' => $field->get('instructions'),
                    ];
                }
                $blueprintData['fields'] = $fieldData;
            }

            $blueprintTypes[] = $blueprintData;
        }

        return ['blueprints' => $blueprintTypes];
    }

    /**
     * @return array<string, mixed>
     */
    private function listFieldTypes(bool $includeDetails): array
    {
        // Get all unique field types across all blueprints
        /** @var \Illuminate\Support\Collection<int, string> $allFieldTypes */
        $allFieldTypes = collect();

        $allBlueprints = collect([
            ...Blueprint::in('collections')->all(),
            ...Blueprint::in('taxonomies')->all(),
            ...Blueprint::in('globals')->all(),
            ...Blueprint::in('assets')->all(),
            ...Blueprint::in('users')->all(),
        ]);

        foreach ($allBlueprints as $blueprint) {
            foreach ($blueprint->fields()->all() as $field) {
                $allFieldTypes->push($field->type());
            }
        }

        $uniqueFieldTypes = $allFieldTypes->unique()->sort()->values();

        $fieldTypeMappings = [
            'typescript' => [],
            'php' => [],
            'json_schema' => [],
        ];

        foreach ($uniqueFieldTypes as $fieldType) {
            $fieldTypeMappings['typescript'][$fieldType] = $this->mapFieldTypeToTypeScript($fieldType);
            $fieldTypeMappings['php'][$fieldType] = $this->mapFieldTypeToPHP($fieldType);
            $fieldTypeMappings['json_schema'][$fieldType] = $this->getJsonSchemaType($fieldType);
        }

        $result = [
            'unique_field_types' => $uniqueFieldTypes->all(),
            'total_unique_types' => $uniqueFieldTypes->count(),
        ];

        if ($includeDetails) {
            $result['type_mappings'] = $fieldTypeMappings;
        }

        return $result;
    }

    private function mapFieldTypeToTypeScript(string $fieldType): string
    {
        return match ($fieldType) {
            'text', 'textarea', 'markdown', 'code', 'slug', 'template', 'color' => 'string',
            'integer', 'range', 'float' => 'number',
            'toggle', 'checkbox' => 'boolean',
            'date', 'time' => 'string',
            'entries', 'terms', 'assets', 'users' => 'string[]',
            'select', 'radio', 'button_group' => 'string',
            'checkboxes' => 'string[]',
            'yaml', 'array', 'grid', 'replicator' => 'any[]',
            'bard' => 'any',
            default => 'any',
        };
    }

    private function mapFieldTypeToPHP(string $fieldType): string
    {
        return match ($fieldType) {
            'text', 'textarea', 'markdown', 'code', 'slug', 'template', 'color' => 'string',
            'integer', 'range' => 'int',
            'float' => 'float',
            'toggle', 'checkbox' => 'bool',
            'date', 'time' => 'string',
            'entries', 'terms', 'assets', 'users', 'checkboxes' => 'array',
            'select', 'radio', 'button_group' => 'string',
            'yaml', 'array', 'grid', 'replicator' => 'array',
            'bard' => 'array',
            default => 'mixed',
        };
    }

    private function getJsonSchemaType(string $fieldType): string
    {
        return match ($fieldType) {
            'text', 'textarea', 'markdown', 'code', 'slug', 'template', 'color' => 'string',
            'integer', 'range' => 'integer',
            'float' => 'number',
            'toggle', 'checkbox' => 'boolean',
            'entries', 'terms', 'assets', 'users', 'checkboxes', 'yaml', 'array', 'grid', 'replicator' => 'array',
            default => 'object',
        };
    }
}
