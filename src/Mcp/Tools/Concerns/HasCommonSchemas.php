<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Laravel\Mcp\Server\Tools\ToolInputSchema;

trait HasCommonSchemas
{
    /**
     * Add blueprint parameter to schema.
     */
    protected function addBlueprintSchema(ToolInputSchema $schema, bool $required = false): ToolInputSchema
    {
        $schema = $schema->string('blueprint')
            ->description('Blueprint handle to work with (optional for general queries)');

        return $required ? $schema->required() : $schema->optional();
    }

    /**
     * Add context parameter to schema.
     */
    protected function addContextSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('context')
            ->description('Template context: entry, collection, taxonomy, global, or general')
            ->optional();
    }

    /**
     * Add validation parameters to schema.
     */
    protected function addValidationSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('strict_mode')
            ->description('Enable strict validation mode for enhanced error checking')
            ->optional();
    }

    /**
     * Add examples parameter to schema.
     */
    protected function addExamplesSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('include_examples')
            ->description('Include usage examples in the response')
            ->optional();
    }

    /**
     * Add limit parameter to schema.
     */
    protected function addLimitSchema(ToolInputSchema $schema, int $default = 50, int $max = 1000): ToolInputSchema
    {
        return $schema->raw('limit', [
            'type' => 'integer',
            'description' => "Maximum number of results to return (default: {$default}, max: {$max})",
            'minimum' => 1,
            'maximum' => $max,
            'default' => $default,
        ])->optional();
    }

    /**
     * Add offset parameter to schema.
     */
    protected function addOffsetSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->raw('offset', [
            'type' => 'integer',
            'description' => 'Number of results to skip (for pagination)',
            'minimum' => 0,
            'default' => 0,
        ])->optional();
    }

    /**
     * Add search query parameter to schema.
     */
    protected function addSearchSchema(ToolInputSchema $schema, bool $required = false): ToolInputSchema
    {
        $schema = $schema->string('query')
            ->description('Search query or filter term');

        return $required ? $schema->required() : $schema->optional();
    }

    /**
     * Add type parameter to schema with enum values.
     *
     * @param  array<int, string>  $types
     */
    protected function addTypeSchema(ToolInputSchema $schema, array $types, bool $required = false): ToolInputSchema
    {
        $schema = $schema->raw('type', [
            'type' => 'string',
            'description' => 'Type of resource to work with',
            'enum' => $types,
        ]);

        return $required ? $schema->required() : $schema->optional();
    }

    /**
     * Add action parameter to schema with enum values.
     *
     * @param  array<int, string>  $actions
     */
    protected function addActionSchema(ToolInputSchema $schema, array $actions): ToolInputSchema
    {
        return $schema->raw('action', [
            'type' => 'string',
            'description' => 'Action to perform',
            'enum' => $actions,
        ])->required();
    }

    /**
     * Add handle parameter to schema.
     */
    protected function addHandleSchema(ToolInputSchema $schema, string $description = 'Handle/identifier for the resource'): ToolInputSchema
    {
        return $schema->string('handle')
            ->description($description)
            ->required();
    }

    /**
     * Add template parameter to schema.
     *
     * @param  array<int, string>  $templates
     */
    protected function addTemplateSchema(ToolInputSchema $schema, array $templates = []): ToolInputSchema
    {
        if (empty($templates)) {
            return $schema->string('template')
                ->description('Template name or type to use')
                ->optional();
        }

        return $schema->raw('template', [
            'type' => 'string',
            'description' => 'Template type to use',
            'enum' => $templates,
            'default' => 'custom',
        ])->optional();
    }

    /**
     * Add cache-related parameters to schema.
     */
    protected function addCacheSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('clear_cache')
            ->description('Clear relevant caches after operation')
            ->optional();
    }

    /**
     * Add verbose output parameter to schema.
     */
    protected function addVerboseSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('verbose')
            ->description('Include detailed information in response')
            ->optional();
    }

    /**
     * Add format parameter to schema.
     *
     * @param  array<int, string>  $formats
     */
    protected function addFormatSchema(ToolInputSchema $schema, array $formats = ['json', 'yaml']): ToolInputSchema
    {
        return $schema->raw('format', [
            'type' => 'string',
            'description' => 'Output format for structured data',
            'enum' => $formats,
            'default' => 'json',
        ])->optional();
    }
}
