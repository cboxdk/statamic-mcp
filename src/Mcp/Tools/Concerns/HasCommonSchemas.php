<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\JsonSchema\JsonSchema;

trait HasCommonSchemas
{
    /**
     * Add blueprint parameter to schema.
     *
     * @return array<string, mixed>
     */
    protected function addBlueprintSchema(bool $required = false): array
    {
        $schema = JsonSchema::string()
            ->description('Blueprint handle to work with (optional for general queries)');

        return [
            'blueprint' => $required ? $schema->required() : $schema,
        ];
    }

    /**
     * Add context parameter to schema.
     *
     * @return array<string, mixed>
     */
    protected function addContextSchema(): array
    {
        return [
            'context' => JsonSchema::string()
                ->description('Template context: entry, collection, taxonomy, global, or general'),
        ];
    }

    /**
     * Add validation parameters to schema.
     *
     * @return array<string, mixed>
     */
    protected function addValidationSchema(): array
    {
        return [
            'strict_mode' => JsonSchema::boolean()
                ->description('Enable strict validation mode for enhanced error checking'),
        ];
    }

    /**
     * Add examples parameter to schema.
     *
     * @return array<string, mixed>
     */
    protected function addExamplesSchema(): array
    {
        return [
            'include_examples' => JsonSchema::boolean()
                ->description('Include usage examples in the response'),
        ];
    }

    /**
     * Add limit parameter to schema.
     *
     * @return array<string, mixed>
     */
    protected function addLimitSchema(int $default = 50, int $max = 1000): array
    {
        return [
            'limit' => JsonSchema::integer()
                ->description("Maximum number of results to return (default: {$default}, max: {$max})")
                ->min(1)
                ->max($max)
                ->default($default),
        ];
    }

    /**
     * Add offset parameter to schema.
     *
     * @return array<string, mixed>
     */
    protected function addOffsetSchema(): array
    {
        return [
            'offset' => JsonSchema::integer()
                ->description('Number of results to skip (for pagination)')
                ->min(0)
                ->default(0),
        ];
    }

    /**
     * Add search query parameter to schema.
     *
     * @return array<string, mixed>
     */
    protected function addSearchSchema(bool $required = false): array
    {
        $schema = JsonSchema::string()
            ->description('Search query or filter term');

        return [
            'query' => $required ? $schema->required() : $schema,
        ];
    }

    /**
     * Add type parameter to schema with enum values.
     *
     * @param  array<int, string>  $types
     *
     * @return array<string, mixed>
     */
    protected function addTypeSchema(array $types, bool $required = false): array
    {
        $schema = JsonSchema::string()
            ->description('Type of resource to work with')
            ->enum($types);

        return [
            'type' => $required ? $schema->required() : $schema,
        ];
    }

    /**
     * Add action parameter to schema with enum values.
     *
     * @param  array<int, string>  $actions
     *
     * @return array<string, mixed>
     */
    protected function addActionSchema(array $actions): array
    {
        return [
            'action' => JsonSchema::string()
                ->description('Action to perform')
                ->enum($actions)
                ->required(),
        ];
    }

    /**
     * Add handle parameter to schema.
     *
     * @return array<string, mixed>
     */
    protected function addHandleSchema(string $description = 'Handle/identifier for the resource'): array
    {
        return [
            'handle' => JsonSchema::string()
                ->description($description)
                ->required(),
        ];
    }

    /**
     * Add template parameter to schema.
     *
     * @param  array<int, string>  $templates
     *
     * @return array<string, mixed>
     */
    protected function addTemplateSchema(array $templates = []): array
    {
        if (empty($templates)) {
            return [
                'template' => JsonSchema::string()
                    ->description('Template name or type to use'),
            ];
        }

        return [
            'template' => JsonSchema::string()
                ->description('Template type to use')
                ->enum($templates)
                ->default('custom'),
        ];
    }

    /**
     * Add cache-related parameters to schema.
     *
     * @return array<string, mixed>
     */
    protected function addCacheSchema(): array
    {
        return [
            'clear_cache' => JsonSchema::boolean()
                ->description('Clear relevant caches after operation'),
        ];
    }

    /**
     * Add verbose output parameter to schema.
     *
     * @return array<string, mixed>
     */
    protected function addVerboseSchema(): array
    {
        return [
            'verbose' => JsonSchema::boolean()
                ->description('Include detailed information in response'),
        ];
    }

    /**
     * Add format parameter to schema.
     *
     * @param  array<int, string>  $formats
     *
     * @return array<string, mixed>
     */
    protected function addFormatSchema(array $formats = ['json', 'yaml']): array
    {
        return [
            'format' => JsonSchema::string()
                ->description('Output format for structured data')
                ->enum($formats)
                ->default('json'),
        ];
    }
}
