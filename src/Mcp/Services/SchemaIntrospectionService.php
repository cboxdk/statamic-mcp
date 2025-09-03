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

use Laravel\Mcp\Server\Tools\ToolInputSchema;

class MockToolSchema extends ToolInputSchema
{
    /** @var array<string, array<string, mixed>> */
    public array $fields = [];

    public function string(string $name): static
    {
        parent::string($name);
        $this->fields[$name] = ['type' => 'string'];

        return $this;
    }

    public function integer(string $name): static
    {
        parent::integer($name);
        $this->fields[$name] = ['type' => 'integer'];

        return $this;
    }

    public function boolean(string $name): static
    {
        parent::boolean($name);
        $this->fields[$name] = ['type' => 'boolean'];

        return $this;
    }

    public function number(string $name): static
    {
        parent::number($name);
        $this->fields[$name] = ['type' => 'number'];

        return $this;
    }

    /** @param  array<string, mixed>  $config */
    public function raw(string $name, array $config): static
    {
        $this->fields[$name] = array_merge(['type' => 'raw'], $config);

        return $this;
    }

    public function description(string $description): static
    {
        parent::description($description);

        if (! empty($this->fields)) {
            $lastField = array_key_last($this->fields);
            $this->fields[$lastField]['description'] = $description;
        }

        return $this;
    }

    public function required(bool $required = true): static
    {
        parent::required($required);

        if (! empty($this->fields)) {
            $lastField = array_key_last($this->fields);
            $this->fields[$lastField]['required'] = $required;
        }

        return $this;
    }

    public function optional(): static
    {
        parent::optional();

        if (! empty($this->fields)) {
            $lastField = array_key_last($this->fields);
            $this->fields[$lastField]['required'] = false;
        }

        return $this;
    }
}
