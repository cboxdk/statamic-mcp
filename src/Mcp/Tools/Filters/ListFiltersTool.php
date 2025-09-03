<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Filters;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Filters Scanner')]
class ListFiltersTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.filters.list';
    }

    protected function getToolDescription(): string
    {
        return 'List available Statamic collection and entry filters for queries';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('search')
            ->description('Search for specific filters by name')
            ->optional()
            ->string('type')
            ->description('Filter type: collection, entries, taxonomy, or all')
            ->optional()
            ->boolean('include_examples')
            ->description('Include usage examples for filters')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $search = $arguments['search'] ?? null;
        $type = $arguments['type'] ?? 'all';
        $includeExamples = $arguments['include_examples'] ?? false;

        $filters = $this->getAvailableFilters($search, $type, $includeExamples);

        return [
            'filters' => $filters,
            'count' => count($filters),
            'types' => ['collection', 'entries', 'taxonomy', 'date', 'status'],
        ];
    }

    /**
     * Get available collection and entry filters.
     *
     * @return array<string, mixed>
     */
    private function getAvailableFilters(?string $search = null, string $type = 'all', bool $includeExamples = false): array
    {
        $filters = [
            // Collection filters
            'collection' => [
                'name' => 'collection',
                'type' => 'collection',
                'description' => 'Filter entries by collection handle',
                'parameters' => ['handle' => 'Collection handle or array of handles'],
                'usage' => 'collection="blog"',
            ],
            'not_collection' => [
                'name' => 'not_collection',
                'type' => 'collection',
                'description' => 'Exclude entries from specific collections',
                'parameters' => ['handle' => 'Collection handle or array of handles'],
                'usage' => 'not_collection="drafts"',
            ],

            // Status filters
            'status' => [
                'name' => 'status',
                'type' => 'status',
                'description' => 'Filter entries by publication status',
                'parameters' => ['status' => 'published, draft, scheduled, or expired'],
                'usage' => 'status="published"',
            ],
            'published' => [
                'name' => 'published',
                'type' => 'status',
                'description' => 'Show only published entries',
                'parameters' => [],
                'usage' => 'published="true"',
            ],

            // Date filters
            'since' => [
                'name' => 'since',
                'type' => 'date',
                'description' => 'Filter entries since a specific date',
                'parameters' => ['date' => 'Date in Y-m-d format or relative format'],
                'usage' => 'since="2024-01-01"',
            ],
            'until' => [
                'name' => 'until',
                'type' => 'date',
                'description' => 'Filter entries until a specific date',
                'parameters' => ['date' => 'Date in Y-m-d format or relative format'],
                'usage' => 'until="2024-12-31"',
            ],
            'before' => [
                'name' => 'before',
                'type' => 'date',
                'description' => 'Filter entries before a specific date',
                'parameters' => ['date' => 'Date in Y-m-d format or relative format'],
                'usage' => 'before="today"',
            ],
            'after' => [
                'name' => 'after',
                'type' => 'date',
                'description' => 'Filter entries after a specific date',
                'parameters' => ['date' => 'Date in Y-m-d format or relative format'],
                'usage' => 'after="yesterday"',
            ],

            // Taxonomy filters
            'taxonomy' => [
                'name' => 'taxonomy',
                'type' => 'taxonomy',
                'description' => 'Filter entries by taxonomy terms',
                'parameters' => [
                    'taxonomy' => 'Taxonomy handle',
                    'terms' => 'Term slug, ID, or array of terms',
                ],
                'usage' => 'taxonomy:tags="featured"',
            ],
            'not_taxonomy' => [
                'name' => 'not_taxonomy',
                'type' => 'taxonomy',
                'description' => 'Exclude entries with specific taxonomy terms',
                'parameters' => [
                    'taxonomy' => 'Taxonomy handle',
                    'terms' => 'Term slug, ID, or array of terms',
                ],
                'usage' => 'not_taxonomy:categories="hidden"',
            ],

            // Field filters
            'where' => [
                'name' => 'where',
                'type' => 'field',
                'description' => 'Filter entries by field value',
                'parameters' => [
                    'field' => 'Field handle',
                    'value' => 'Value to match',
                    'operator' => 'Comparison operator (optional)',
                ],
                'usage' => 'where:featured="true"',
            ],
            'where_not' => [
                'name' => 'where_not',
                'type' => 'field',
                'description' => 'Exclude entries by field value',
                'parameters' => [
                    'field' => 'Field handle',
                    'value' => 'Value to exclude',
                ],
                'usage' => 'where_not:status="hidden"',
            ],
            'where_in' => [
                'name' => 'where_in',
                'type' => 'field',
                'description' => 'Filter entries where field value is in array',
                'parameters' => [
                    'field' => 'Field handle',
                    'values' => 'Array of values',
                ],
                'usage' => 'where_in:category="news|updates|reviews"',
            ],
            'where_not_in' => [
                'name' => 'where_not_in',
                'type' => 'field',
                'description' => 'Exclude entries where field value is in array',
                'parameters' => [
                    'field' => 'Field handle',
                    'values' => 'Array of values',
                ],
                'usage' => 'where_not_in:status="draft|hidden"',
            ],

            // ID filters
            'id' => [
                'name' => 'id',
                'type' => 'entries',
                'description' => 'Filter entries by specific IDs',
                'parameters' => ['ids' => 'Entry ID or array of IDs'],
                'usage' => 'id="entry-id-123"',
            ],
            'not_id' => [
                'name' => 'not_id',
                'type' => 'entries',
                'description' => 'Exclude entries by specific IDs',
                'parameters' => ['ids' => 'Entry ID or array of IDs'],
                'usage' => 'not_id="current:id"',
            ],

            // Site filters
            'site' => [
                'name' => 'site',
                'type' => 'entries',
                'description' => 'Filter entries by site',
                'parameters' => ['site' => 'Site handle'],
                'usage' => 'site="default"',
            ],
            'locale' => [
                'name' => 'locale',
                'type' => 'entries',
                'description' => 'Filter entries by locale',
                'parameters' => ['locale' => 'Locale code'],
                'usage' => 'locale="en"',
            ],
        ];

        // Filter by type
        if ($type !== 'all') {
            $filters = array_filter($filters, fn ($filter) => $filter['type'] === $type);
        }

        // Filter by search term
        if ($search) {
            $filters = array_filter($filters, function ($filter) use ($search) {
                return str_contains(strtolower($filter['name']), strtolower($search)) ||
                       str_contains(strtolower($filter['description']), strtolower($search));
            });
        }

        // Add examples if requested
        if ($includeExamples) {
            foreach ($filters as &$filter) {
                $filter['examples'] = $this->getFilterExamples($filter['name']);
            }
        }

        return $filters;
    }

    /**
     * Get usage examples for a filter.
     *
     * @return array<string, mixed>
     */
    private function getFilterExamples(string $filterName): array
    {
        $examples = [
            'collection' => [
                'single' => '{{ entries collection="blog" }}{{ title }}{{ /entries }}',
                'multiple' => '{{ entries collection="blog|news" }}{{ title }}{{ /entries }}',
                'array' => '{{ entries :collection="[\'blog\', \'news\']" }}{{ title }}{{ /entries }}',
            ],
            'status' => [
                'published' => '{{ entries status="published" }}{{ title }}{{ /entries }}',
                'draft' => '{{ entries status="draft" }}{{ title }}{{ /entries }}',
                'scheduled' => '{{ entries status="scheduled" }}{{ title }}{{ /entries }}',
            ],
            'taxonomy' => [
                'single_term' => '{{ entries taxonomy:tags="featured" }}{{ title }}{{ /entries }}',
                'multiple_terms' => '{{ entries taxonomy:categories="news|updates" }}{{ title }}{{ /entries }}',
                'any_terms' => '{{ entries :taxonomy:tags="featured|popular" }}{{ title }}{{ /entries }}',
            ],
            'since' => [
                'absolute' => '{{ entries since="2024-01-01" }}{{ title }}{{ /entries }}',
                'relative' => '{{ entries since="1 week ago" }}{{ title }}{{ /entries }}',
                'field_specific' => '{{ entries since:publish_date="2024-01-01" }}{{ title }}{{ /entries }}',
            ],
            'where' => [
                'boolean' => '{{ entries where:featured="true" }}{{ title }}{{ /entries }}',
                'string' => '{{ entries where:category="news" }}{{ title }}{{ /entries }}',
                'comparison' => '{{ entries where:views=">100" }}{{ title }}{{ /entries }}',
            ],
            'id' => [
                'single' => '{{ entries id="entry-123" }}{{ title }}{{ /entries }}',
                'multiple' => '{{ entries id="entry-123|entry-456" }}{{ title }}{{ /entries }}',
                'current' => '{{ entries not_id="current:id" }}{{ title }}{{ /entries }}',
            ],
        ];

        return $examples[$filterName] ?? [
            'basic' => "{{ entries {$filterName}=\"value\" }}{{ title }}{{ /entries }}",
        ];
    }
}
