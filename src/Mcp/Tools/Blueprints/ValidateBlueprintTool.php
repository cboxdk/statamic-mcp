<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Blueprints;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;
use Statamic\Fields\Field;

#[Title('Validate Blueprint')]
#[IsReadOnly]
class ValidateBlueprintTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.blueprints.validate';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Validate blueprint structure, field types, and configuration integrity';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('namespace')
            ->description('Blueprint namespace (e.g., collections.blog, taxonomies.tags)')
            ->required()
            ->string('handle')
            ->description('Blueprint handle within the namespace')
            ->required()
            ->boolean('check_field_dependencies')
            ->description('Validate field dependencies and relationships')
            ->optional()
            ->boolean('check_fieldset_references')
            ->description('Validate fieldset import references')
            ->optional()
            ->boolean('validate_field_configs')
            ->description('Deep validation of field type configurations')
            ->optional()
            ->boolean('check_naming_conventions')
            ->description('Check if field handles follow naming conventions')
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
        $namespace = $arguments['namespace'];
        $handle = $arguments['handle'];
        $checkFieldDependencies = $arguments['check_field_dependencies'] ?? true;
        $checkFieldsetReferences = $arguments['check_fieldset_references'] ?? true;
        $validateFieldConfigs = $arguments['validate_field_configs'] ?? true;
        $checkNamingConventions = $arguments['check_naming_conventions'] ?? true;

        try {
            // Find the blueprint
            $blueprintHandle = "{$namespace}.{$handle}";
            $blueprint = Blueprint::find($blueprintHandle);

            if (! $blueprint) {
                return $this->createErrorResponse("Blueprint '{$blueprintHandle}' not found", [
                    'available_namespaces' => $this->getAvailableNamespaces(),
                ])->toArray();
            }

            $validationResult = [
                'is_valid' => true,
                'errors' => [],
                'warnings' => [],
                'info' => [],
                'field_count' => 0,
                'tab_count' => 0,
            ];

            // Basic structure validation
            $structureValidation = $this->validateBasicStructure($blueprint);
            $validationResult = $this->mergeValidationResults($validationResult, $structureValidation);

            // Field validation
            $fields = $blueprint->fields();
            $validationResult['field_count'] = $fields->count();

            foreach ($fields->all() as $fieldHandle => $field) {
                $fieldValidation = $this->validateField($field, $fieldHandle);
                $validationResult = $this->mergeValidationResults($validationResult, $fieldValidation);

                if ($validateFieldConfigs) {
                    $configValidation = $this->validateFieldConfig($field, $fieldHandle);
                    $validationResult = $this->mergeValidationResults($validationResult, $configValidation);
                }

                if ($checkNamingConventions) {
                    $namingValidation = $this->validateFieldNaming($fieldHandle, $field);
                    $validationResult = $this->mergeValidationResults($validationResult, $namingValidation);
                }
            }

            // Field dependencies validation
            if ($checkFieldDependencies) {
                $dependencyValidation = $this->validateFieldDependencies($fields);
                $validationResult = $this->mergeValidationResults($validationResult, $dependencyValidation);
            }

            // Fieldset references validation
            if ($checkFieldsetReferences) {
                $fieldsetValidation = $this->validateFieldsetReferences($blueprint);
                $validationResult = $this->mergeValidationResults($validationResult, $fieldsetValidation);
            }

            // Tab validation
            $tabs = $blueprint->tabs();
            $validationResult['tab_count'] = count($tabs);

            if (count($tabs) === 0) {
                $validationResult['warnings'][] = 'Blueprint has no tabs defined';
            }

            // Set overall validity
            $validationResult['is_valid'] = empty($validationResult['errors']);

            return [
                'blueprint' => [
                    'namespace' => $namespace,
                    'handle' => $handle,
                    'full_handle' => $blueprintHandle,
                    'title' => $blueprint->title(),
                ],
                'validation' => $validationResult,
                'summary' => [
                    'status' => $validationResult['is_valid'] ? 'valid' : 'invalid',
                    'error_count' => count($validationResult['errors']),
                    'warning_count' => count($validationResult['warnings']),
                    'field_count' => $validationResult['field_count'],
                    'tab_count' => $validationResult['tab_count'],
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to validate blueprint: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Validate basic blueprint structure.
     *
     * @param  \Statamic\Fields\Blueprint  $blueprint
     *
     * @return array<string, mixed>
     */
    private function validateBasicStructure($blueprint): array
    {
        $validation = [
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];

        // Check if blueprint has a title
        if (! $blueprint->title()) {
            $validation['warnings'][] = 'Blueprint has no title defined';
        }

        // Check if blueprint has any fields
        if ($blueprint->fields()->isEmpty()) {
            $validation['errors'][] = 'Blueprint has no fields defined';
        }

        return $validation;
    }

    /**
     * Validate a single field.
     *
     * @param  Field  $field
     *
     * @return array<string, mixed>
     */
    private function validateField($field, string $handle): array
    {
        $validation = [
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];

        // Check field type exists
        $type = $field->type();
        if (! $type) {
            $validation['errors'][] = "Field '{$handle}' has no type defined";

            return $validation;
        }

        // Validate field handle format
        if (! preg_match('/^[a-z0-9_]+$/', $handle)) {
            $validation['warnings'][] = "Field '{$handle}' handle should contain only lowercase letters, numbers, and underscores";
        }

        // Check for display name
        if (! $field->display()) {
            $validation['warnings'][] = "Field '{$handle}' has no display name";
        }

        return $validation;
    }

    /**
     * Validate field configuration based on field type.
     *
     * @param  Field  $field
     *
     * @return array<string, mixed>
     */
    private function validateFieldConfig($field, string $handle): array
    {
        $validation = [
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];

        $type = $field->type();
        $config = $field->config();

        switch ($type) {
            case 'text':
            case 'textarea':
                if (isset($config['character_limit']) && ! is_int($config['character_limit'])) {
                    $validation['errors'][] = "Field '{$handle}' character_limit must be an integer";
                }
                break;

            case 'select':
            case 'radio':
                if (! isset($config['options']) || empty($config['options'])) {
                    $validation['errors'][] = "Field '{$handle}' of type '{$type}' requires options";
                }
                break;

            case 'entries':
                if (isset($config['collections']) && empty($config['collections'])) {
                    $validation['warnings'][] = "Field '{$handle}' has no collections specified - will show entries from all collections";
                }
                if (isset($config['max_items']) && ! is_int($config['max_items'])) {
                    $validation['errors'][] = "Field '{$handle}' max_items must be an integer";
                }
                break;

            case 'assets':
                if (isset($config['container']) && empty($config['container'])) {
                    $validation['warnings'][] = "Field '{$handle}' has no asset container specified";
                }
                if (isset($config['max_files']) && ! is_int($config['max_files'])) {
                    $validation['errors'][] = "Field '{$handle}' max_files must be an integer";
                }
                break;

            case 'range':
                if (! isset($config['min']) || ! isset($config['max'])) {
                    $validation['errors'][] = "Field '{$handle}' of type 'range' requires min and max values";
                }
                break;

            case 'date':
            case 'time':
                if (isset($config['format']) && ! $this->isValidDateFormat($config['format'])) {
                    $validation['warnings'][] = "Field '{$handle}' has potentially invalid date format";
                }
                break;

            case 'grid':
            case 'replicator':
                if (! isset($config['fields']) || empty($config['fields'])) {
                    $validation['errors'][] = "Field '{$handle}' of type '{$type}' requires field definitions";
                }
                break;
        }

        return $validation;
    }

    /**
     * Validate field naming conventions.
     *
     * @param  Field  $field
     *
     * @return array<string, mixed>
     */
    private function validateFieldNaming(string $handle, $field): array
    {
        $validation = [
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];

        // Check for reserved words
        $reservedWords = ['id', 'slug', 'uri', 'url', 'title', 'status', 'published', 'date', 'author'];
        if (in_array($handle, $reservedWords)) {
            $validation['warnings'][] = "Field '{$handle}' uses a reserved word that may conflict with Statamic internals";
        }

        // Check naming convention
        if (str_contains($handle, '-')) {
            $validation['warnings'][] = "Field '{$handle}' uses hyphens - consider using underscores for consistency";
        }

        if (strlen($handle) < 2) {
            $validation['warnings'][] = "Field '{$handle}' handle is very short - consider a more descriptive name";
        }

        if (strlen($handle) > 50) {
            $validation['warnings'][] = "Field '{$handle}' handle is very long - consider shortening for usability";
        }

        return $validation;
    }

    /**
     * Validate field dependencies and relationships.
     *
     * @param  \Statamic\Fields\Fields  $fields
     *
     * @return array<string, mixed>
     */
    private function validateFieldDependencies($fields): array
    {
        $validation = [
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];

        $fieldHandles = array_keys($fields->all()->toArray());

        foreach ($fields->all() as $handle => $field) {
            $config = $field->config();

            // Check if 'if' conditions reference valid fields
            if (isset($config['if'])) {
                $conditions = is_array($config['if']) ? $config['if'] : [$config['if']];
                foreach ($conditions as $condition) {
                    if (is_string($condition)) {
                        $referencedField = explode(' ', $condition)[0];
                        if (! in_array($referencedField, $fieldHandles)) {
                            $validation['errors'][] = "Field '{$handle}' condition references unknown field '{$referencedField}'";
                        }
                    }
                }
            }

            // Check validate rules for field references
            if (isset($config['validate'])) {
                $rules = is_array($config['validate']) ? $config['validate'] : [$config['validate']];
                foreach ($rules as $rule) {
                    if (str_contains($rule, 'same:')) {
                        $referencedField = str_replace('same:', '', $rule);
                        if (! in_array($referencedField, $fieldHandles)) {
                            $validation['errors'][] = "Field '{$handle}' validation rule references unknown field '{$referencedField}'";
                        }
                    }
                }
            }
        }

        return $validation;
    }

    /**
     * Validate fieldset references.
     *
     * @param  \Statamic\Fields\Blueprint  $blueprint
     *
     * @return array<string, mixed>
     */
    private function validateFieldsetReferences($blueprint): array
    {
        $validation = [
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];

        $tabs = $blueprint->tabs();

        foreach ($tabs as $tab) {
            if (isset($tab['import'])) {
                $fieldsetHandle = $tab['import'];
                $fieldset = Fieldset::find($fieldsetHandle);

                if (! $fieldset) {
                    $validation['errors'][] = "Blueprint imports unknown fieldset '{$fieldsetHandle}'";
                } else {
                    $validation['info'][] = "Successfully imports fieldset '{$fieldsetHandle}'";
                }
            }
        }

        return $validation;
    }

    /**
     * Merge validation results.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $source
     *
     * @return array<string, mixed>
     */
    private function mergeValidationResults(array $target, array $source): array
    {
        $target['errors'] = array_merge($target['errors'] ?? [], $source['errors'] ?? []);
        $target['warnings'] = array_merge($target['warnings'] ?? [], $source['warnings'] ?? []);
        $target['info'] = array_merge($target['info'] ?? [], $source['info'] ?? []);

        return $target;
    }

    /**
     * Check if a date format is valid.
     */
    private function isValidDateFormat(string $format): bool
    {
        $previous = error_reporting(0);
        $result = date_create_from_format($format, date($format));
        error_reporting($previous);

        return $result !== false;
    }

    /**
     * Get available blueprint namespaces.
     *
     * @return array<string>
     */
    private function getAvailableNamespaces(): array
    {
        $namespaces = [];

        try {
            $allBlueprints = collect();
            foreach (['collections', 'taxonomies', 'assets', 'globals', 'forms', 'users'] as $ns) {
                $allBlueprints = $allBlueprints->merge(Blueprint::in($ns)->all());
            }

            foreach ($allBlueprints as $blueprint) {
                $handle = $blueprint->handle();
                if (str_contains($handle, '.')) {
                    $lastDotPos = strrpos($handle, '.');
                    if ($lastDotPos !== false) {
                        $namespace = substr($handle, 0, $lastDotPos);
                        if (! in_array($namespace, $namespaces)) {
                            $namespaces[] = $namespace;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Return common namespaces if there's an error
            $namespaces = ['collections', 'taxonomies', 'globals', 'assets', 'users'];
        }

        return $namespaces;
    }
}
