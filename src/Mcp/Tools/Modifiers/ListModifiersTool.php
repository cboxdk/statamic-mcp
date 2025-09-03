<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Modifiers;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Modifiers Scanner')]
#[IsReadOnly]
class ListModifiersTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.modifiers.list';
    }

    protected function getToolDescription(): string
    {
        return 'Extract and analyze available Statamic modifiers with their parameters and usage examples';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema = $this->addLimitSchema($schema);

        return $schema->boolean('include_data')
            ->description('Include modifier data')
            ->optional()
            ->boolean('include_meta')
            ->description('Include metadata and configuration')
            ->optional()
            ->string('filter')
            ->description('Filter results by name/handle')
            ->optional()
            ->boolean('include_examples')
            ->description('Include usage examples for modifiers')
            ->optional()
            ->boolean('include_parameters')
            ->description('Include parameter descriptions')
            ->optional()
            ->boolean('core_only')
            ->description('Show only core Statamic modifiers')
            ->optional()
            ->boolean('custom_only')
            ->description('Show only custom/addon modifiers')
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
        $includeData = $arguments['include_data'] ?? true;
        $includeMeta = $arguments['include_meta'] ?? true;
        $includeExamples = $arguments['include_examples'] ?? false;
        $includeParameters = $arguments['include_parameters'] ?? false;
        $coreOnly = $arguments['core_only'] ?? false;
        $customOnly = $arguments['custom_only'] ?? false;
        $filter = $arguments['filter'] ?? null;
        $limit = $arguments['limit'] ?? null;

        $modifiers = [];

        try {
            // Get registered modifiers from Statamic
            /** @var \Illuminate\Support\Collection<string, mixed>|null $registry */
            $registry = app('statamic.modifiers');
            $registry = $registry ?? collect();
            $coreModifiers = $this->getCoreModifiers();

            foreach ($registry as $handle => $modifier) {
                if ($filter && ! str_contains($handle, $filter)) {
                    continue;
                }

                $isCore = in_array($handle, array_keys($coreModifiers));

                // Apply core/custom filtering
                if ($coreOnly && ! $isCore) {
                    continue;
                }
                if ($customOnly && $isCore) {
                    continue;
                }

                $modifierData = [
                    'handle' => $handle,
                    'type' => $isCore ? 'core' : 'custom',
                ];

                if ($includeMeta || $includeData) {
                    $modifierData['class'] = is_object($modifier)
                        ? get_class($modifier)
                        : (is_string($modifier) ? $modifier : 'unknown');

                    $modifierData['description'] = $this->getModifierDescription($modifier, $handle);
                }

                if ($includeParameters) {
                    $modifierData['parameters'] = $this->getModifierParameters($modifier, $handle);
                }

                if ($includeExamples) {
                    $modifierData['examples'] = $this->getModifierExamples($handle);
                }

                $modifiers[] = $modifierData;
            }

            // Add core modifiers if registry is empty or incomplete
            if (empty($modifiers) || (count($modifiers) < 30 && ! $customOnly)) {
                foreach ($coreModifiers as $handle => $info) {
                    if ($filter && ! str_contains($handle, $filter)) {
                        continue;
                    }

                    if (collect($modifiers)->firstWhere('handle', $handle)) {
                        continue; // Already added from registry
                    }

                    $modifierData = [
                        'handle' => $handle,
                        'type' => 'core',
                    ];

                    if ($includeMeta || $includeData) {
                        $modifierData['description'] = $info['description'];
                    }

                    if ($includeParameters) {
                        $modifierData['parameters'] = $info['parameters'] ?? [];
                    }

                    if ($includeExamples) {
                        $modifierData['examples'] = $info['examples'] ?? [];
                    }

                    $modifiers[] = $modifierData;
                }
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not extract modifiers: ' . $e->getMessage())->toArray();
        }

        if ($limit) {
            $modifiers = array_slice($modifiers, 0, $limit);
        }

        return [
            'modifiers' => $modifiers,
            'count' => count($modifiers),
            'core_count' => collect($modifiers)->where('type', 'core')->count(),
            'custom_count' => collect($modifiers)->where('type', 'custom')->count(),
        ];
    }

    /**
     * Get modifier description.
     *
     * @param  mixed  $modifier
     */
    private function getModifierDescription($modifier, string $handle): string
    {
        if (is_object($modifier) && method_exists($modifier, 'description')) {
            return $modifier->description();
        }

        // Fallback descriptions for common modifiers
        $descriptions = [
            'upper' => 'Convert string to uppercase',
            'lower' => 'Convert string to lowercase',
            'title' => 'Convert string to title case',
            'slug' => 'Convert string to URL-friendly slug',
            'date' => 'Format date values',
            'limit' => 'Limit number of items in array',
            'pluck' => 'Extract specific field from array items',
            'sort' => 'Sort array items',
            'reverse' => 'Reverse array order',
            'count' => 'Count array items',
            'length' => 'Get string or array length',
        ];

        return $descriptions[$handle] ?? 'No description available';
    }

    /**
     * Get modifier parameters.
     *
     * @param  mixed  $modifier
     *
     * @return array<string, mixed>
     */
    private function getModifierParameters($modifier, string $handle): array
    {
        if (is_object($modifier) && method_exists($modifier, 'parameters')) {
            return $modifier->parameters();
        }

        // Common parameter patterns
        $parameters = [
            'limit' => ['count' => 'Number of items to return'],
            'date' => ['format' => 'Date format string (PHP date format)'],
            'pluck' => ['key' => 'Field name to extract from each item'],
            'sort' => ['key' => 'Field name to sort by (optional)'],
            'slice' => ['offset' => 'Starting position', 'length' => 'Number of items (optional)'],
        ];

        return $parameters[$handle] ?? [];
    }

    /**
     * Get modifier examples.
     *
     * @return array<string, mixed>
     */
    private function getModifierExamples(string $handle): array
    {
        $examples = [
            'upper' => ['examples' => ['{{ "hello world" | upper }}', '{{ title | upper }}']],
            'lower' => ['examples' => ['{{ "HELLO WORLD" | lower }}', '{{ title | lower }}']],
            'title' => ['examples' => ['{{ "hello world" | title }}', '{{ content | title }}']],
            'slug' => ['examples' => ['{{ "Hello World!" | slug }}', '{{ title | slug }}']],
            'date' => ['examples' => ['{{ published_at | date:Y-m-d }}', '{{ created_at | date:"F j, Y" }}']],
            'limit' => ['examples' => ['{{ entries | limit:5 }}', '{{ tags | limit:3 }}']],
            'pluck' => ['examples' => ['{{ entries | pluck:title }}', '{{ products | pluck:price }}']],
            'sort' => ['examples' => ['{{ entries | sort:title }}', '{{ items | sort:date }}']],
            'reverse' => ['examples' => ['{{ entries | reverse }}', '{{ array | reverse }}']],
            'count' => ['examples' => ['{{ entries | count }}', '{{ tags | count }}']],
            'length' => ['examples' => ['{{ title | length }}', '{{ content | length }}']],
        ];

        return $examples[$handle] ?? ['examples' => ["{{ value | {$handle} }}"]];
    }

    /**
     * Get core modifiers list.
     *
     * @return array<string, array<string, string>>
     */
    /**
     * @return array<string, mixed>
     */
    private function getCoreModifiers(): array
    {
        return [
            'abs' => ['description' => 'Return absolute value of number'],
            'add' => ['description' => 'Add value to number'],
            'ampersand' => ['description' => 'Replace last comma with ampersand'],
            'array' => ['description' => 'Convert value to array'],
            'as' => ['description' => 'Create variable alias'],
            'ascii' => ['description' => 'Convert to ASCII characters'],
            'at' => ['description' => 'Get array item at index'],
            'bool' => ['description' => 'Convert to boolean'],
            'camelize' => ['description' => 'Convert to camelCase'],
            'ceil' => ['description' => 'Round up to nearest integer'],
            'collapse' => ['description' => 'Collapse multi-dimensional array'],
            'compact' => ['description' => 'Remove empty/null values'],
            'count' => ['description' => 'Count array items'],
            'date' => ['description' => 'Format date values'],
            'dd' => ['description' => 'Dump and die'],
            'divide' => ['description' => 'Divide by value'],
            'dl' => ['description' => 'Convert array to definition list'],
            'dump' => ['description' => 'Dump variable for debugging'],
            'email' => ['description' => 'Obfuscate email address'],
            'embed_url' => ['description' => 'Convert URL to embed URL'],
            'ensure_left' => ['description' => 'Ensure string starts with prefix'],
            'ensure_right' => ['description' => 'Ensure string ends with suffix'],
            'explode' => ['description' => 'Split string into array'],
            'first' => ['description' => 'Get first item from array'],
            'flatten' => ['description' => 'Flatten multi-dimensional array'],
            'flip' => ['description' => 'Flip array keys and values'],
            'floor' => ['description' => 'Round down to nearest integer'],
            'format' => ['description' => 'Format using sprintf'],
            'gravatar' => ['description' => 'Get Gravatar URL'],
            'group_by' => ['description' => 'Group array by field'],
            'implode' => ['description' => 'Join array with separator'],
            'in_array' => ['description' => 'Check if value exists in array'],
            'is_alpha' => ['description' => 'Check if alphabetic'],
            'is_alpha_numeric' => ['description' => 'Check if alphanumeric'],
            'is_email' => ['description' => 'Check if valid email'],
            'is_empty' => ['description' => 'Check if empty'],
            'is_numeric' => ['description' => 'Check if numeric'],
            'is_url' => ['description' => 'Check if valid URL'],
            'join' => ['description' => 'Join array elements'],
            'keys' => ['description' => 'Get array keys'],
            'last' => ['description' => 'Get last item from array'],
            'length' => ['description' => 'Get string or array length'],
            'limit' => ['description' => 'Limit array items'],
            'lower' => ['description' => 'Convert to lowercase'],
            'markdown' => ['description' => 'Parse Markdown'],
            'max' => ['description' => 'Get maximum value'],
            'min' => ['description' => 'Get minimum value'],
            'mod' => ['description' => 'Modulo operation'],
            'multiply' => ['description' => 'Multiply by value'],
            'nl2br' => ['description' => 'Convert newlines to <br>'],
            'normalize' => ['description' => 'Normalize string'],
            'number' => ['description' => 'Format number'],
            'offset' => ['description' => 'Skip array items'],
            'ol' => ['description' => 'Convert array to ordered list'],
            'option' => ['description' => 'Get config option'],
            'pad_both' => ['description' => 'Pad string both sides'],
            'pad_left' => ['description' => 'Pad string left'],
            'pad_right' => ['description' => 'Pad string right'],
            'pluck' => ['description' => 'Extract field from array items'],
            'pluralize' => ['description' => 'Pluralize word'],
            'prepend' => ['description' => 'Prepend string'],
            'query' => ['description' => 'Build query string'],
            'random' => ['description' => 'Get random item'],
            'raw' => ['description' => 'Output raw HTML'],
            'regex_replace' => ['description' => 'Replace using regex'],
            'relative' => ['description' => 'Get relative date'],
            'remove_left' => ['description' => 'Remove prefix'],
            'remove_right' => ['description' => 'Remove suffix'],
            'repeat' => ['description' => 'Repeat string'],
            'replace' => ['description' => 'Replace text'],
            'reverse' => ['description' => 'Reverse array or string'],
            'round' => ['description' => 'Round number'],
            'sanitize' => ['description' => 'Sanitize string'],
            'segment' => ['description' => 'Get URL segment'],
            'shuffle' => ['description' => 'Shuffle array'],
            'singularize' => ['description' => 'Singularize word'],
            'slice' => ['description' => 'Extract array slice'],
            'slug' => ['description' => 'Convert to URL slug'],
            'sort' => ['description' => 'Sort array'],
            'split' => ['description' => 'Split string'],
            'strip_tags' => ['description' => 'Remove HTML tags'],
            'subtract' => ['description' => 'Subtract value'],
            'sum' => ['description' => 'Sum array values'],
            'title' => ['description' => 'Convert to title case'],
            'to_float' => ['description' => 'Convert to float'],
            'to_int' => ['description' => 'Convert to integer'],
            'trim' => ['description' => 'Trim whitespace'],
            'truncate' => ['description' => 'Truncate string'],
            'ul' => ['description' => 'Convert array to unordered list'],
            'unique' => ['description' => 'Remove duplicate values'],
            'upper' => ['description' => 'Convert to uppercase'],
            'url' => ['description' => 'Generate URL'],
            'urlencode' => ['description' => 'URL encode string'],
            'values' => ['description' => 'Get array values'],
            'where' => ['description' => 'Filter array by condition'],
            'wrap' => ['description' => 'Wrap string'],
        ];
    }
}
