<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Services;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use ReflectionClass;

class SchemaIntrospectionService
{
    /**
     * Extract tool schema by invoking defineSchema method with mock schema.
     *
     *
     * @return array<string, mixed>
     */
    public function extractToolSchema(BaseStatamicTool $tool): array
    {
        try {
            // Use reflection to access protected defineSchema method
            $reflection = new ReflectionClass($tool);
            $defineSchemaMethod = $reflection->getMethod('defineSchema');
            $defineSchemaMethod->setAccessible(true);

            // Create a mock schema to capture definition
            $mockSchema = $this->createMockSchema();
            $defineSchemaMethod->invoke($tool, $mockSchema);

            return [
                'parameters' => $mockSchema->fields,
                'parameter_count' => count($mockSchema->fields),
                'required_parameters' => array_keys(array_filter(
                    $mockSchema->fields,
                    fn ($field) => ($field['required'] ?? false)
                )),
                'optional_parameters' => array_keys(array_filter(
                    $mockSchema->fields,
                    fn ($field) => ! ($field['required'] ?? false)
                )),
            ];
        } catch (\Exception $e) {
            return [
                'parameters' => [],
                'parameter_count' => 0,
                'required_parameters' => [],
                'optional_parameters' => [],
                'error' => 'Could not extract schema: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create a mock schema object for parameter extraction.
     */
    public function createMockSchema(): MockToolSchema
    {
        return new MockToolSchema;
    }
}

class MockToolSchema
{
    /** @var array<string, array<string, mixed>> */
    public array $fields = [];

    public function string(string $name): self
    {
        $this->fields[$name] = ['type' => 'string'];

        return $this;
    }

    public function integer(string $name): self
    {
        $this->fields[$name] = ['type' => 'integer'];

        return $this;
    }

    public function boolean(string $name): self
    {
        $this->fields[$name] = ['type' => 'boolean'];

        return $this;
    }

    /** @param  array<string, mixed>  $config */
    public function raw(string $name, array $config): self
    {
        $this->fields[$name] = array_merge(['type' => 'raw'], $config);

        return $this;
    }

    public function description(string $description): self
    {
        if (! empty($this->fields)) {
            $lastField = array_key_last($this->fields);
            $this->fields[$lastField]['description'] = $description;
        }

        return $this;
    }

    public function required(): self
    {
        if (! empty($this->fields)) {
            $lastField = array_key_last($this->fields);
            $this->fields[$lastField]['required'] = true;
        }

        return $this;
    }

    public function optional(): self
    {
        if (! empty($this->fields)) {
            $lastField = array_key_last($this->fields);
            $this->fields[$lastField]['required'] = false;
        }

        return $this;
    }
}
