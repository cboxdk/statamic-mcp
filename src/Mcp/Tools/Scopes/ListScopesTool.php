<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Scopes;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Scopes Scanner')]
class ListScopesTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.scopes.list';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'List available Statamic query scopes for entries and collections';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('search')
            ->description('Search for specific scopes by name')
            ->optional()
            ->boolean('include_examples')
            ->description('Include usage examples for scopes')
            ->optional();
    }

    /**
     * Execute the tool.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $search = $arguments['search'] ?? null;
        $includeExamples = $arguments['include_examples'] ?? false;

        $scopes = $this->getAvailableScopes($search, $includeExamples);

        return [
            'scopes' => $scopes,
            'count' => count($scopes),
            'categories' => ['published', 'date', 'collection', 'taxonomy', 'custom'],
        ];
    }

    /**
     * Get available query scopes.
     *
     * @return array<string, mixed>
     */
    private function getAvailableScopes(?string $search = null, bool $includeExamples = false): array
    {
        $scopes = [
            // Publishing scopes
            'published' => [
                'name' => 'published',
                'category' => 'published',
                'description' => 'Filter to published entries only',
                'parameters' => [],
                'usage' => '{{ collection:blog scope:published }}',
            ],
            'unpublished' => [
                'name' => 'unpublished',
                'category' => 'published',
                'description' => 'Filter to unpublished entries only',
                'parameters' => [],
                'usage' => '{{ collection:blog scope:unpublished }}',
            ],
            'scheduled' => [
                'name' => 'scheduled',
                'category' => 'published',
                'description' => 'Filter to scheduled entries only',
                'parameters' => [],
                'usage' => '{{ collection:blog scope:scheduled }}',
            ],

            // Date scopes
            'past' => [
                'name' => 'past',
                'category' => 'date',
                'description' => 'Filter to entries with dates in the past',
                'parameters' => ['field' => 'Date field to compare (optional)'],
                'usage' => '{{ collection:blog scope:past }}',
            ],
            'future' => [
                'name' => 'future',
                'category' => 'date',
                'description' => 'Filter to entries with dates in the future',
                'parameters' => ['field' => 'Date field to compare (optional)'],
                'usage' => '{{ collection:blog scope:future }}',
            ],
            'today' => [
                'name' => 'today',
                'category' => 'date',
                'description' => 'Filter to entries from today',
                'parameters' => ['field' => 'Date field to compare (optional)'],
                'usage' => '{{ collection:blog scope:today }}',
            ],

            // Collection scopes
            'in_collection' => [
                'name' => 'in_collection',
                'category' => 'collection',
                'description' => 'Filter entries to specific collections',
                'parameters' => ['collections' => 'Array of collection handles'],
                'usage' => '{{ entries scope:in_collection:blog,news }}',
            ],
            'not_in_collection' => [
                'name' => 'not_in_collection',
                'category' => 'collection',
                'description' => 'Exclude entries from specific collections',
                'parameters' => ['collections' => 'Array of collection handles'],
                'usage' => '{{ entries scope:not_in_collection:drafts }}',
            ],

            // Taxonomy scopes
            'has_taxonomy' => [
                'name' => 'has_taxonomy',
                'category' => 'taxonomy',
                'description' => 'Filter entries that have specific taxonomy terms',
                'parameters' => [
                    'taxonomy' => 'Taxonomy handle',
                    'terms' => 'Array of term slugs or IDs',
                ],
                'usage' => '{{ collection:blog scope:has_taxonomy:tags:featured }}',
            ],
            'without_taxonomy' => [
                'name' => 'without_taxonomy',
                'category' => 'taxonomy',
                'description' => 'Filter entries that do not have specific taxonomy terms',
                'parameters' => [
                    'taxonomy' => 'Taxonomy handle',
                    'terms' => 'Array of term slugs or IDs',
                ],
                'usage' => '{{ collection:blog scope:without_taxonomy:tags:hidden }}',
            ],
        ];

        // Filter by search term if provided
        if ($search) {
            $scopes = array_filter($scopes, function ($scope) use ($search) {
                return str_contains(strtolower($scope['name']), strtolower($search)) ||
                       str_contains(strtolower($scope['description']), strtolower($search));
            });
        }

        // Add examples if requested
        if ($includeExamples) {
            foreach ($scopes as &$scope) {
                $scope['examples'] = $this->getScopeExamples($scope['name']);
            }
        }

        return $scopes;
    }

    /**
     * Get usage examples for a scope.
     *
     * @return array<string, mixed>
     */
    private function getScopeExamples(string $scopeName): array
    {
        $examples = [
            'published' => [
                'basic' => '{{ collection:blog scope:published }}{{ title }}{{ /collection:blog }}',
                'with_sort' => '{{ collection:blog scope:published sort="date:desc" }}{{ title }}{{ /collection:blog }}',
                'with_limit' => '{{ collection:blog scope:published limit="5" }}{{ title }}{{ /collection:blog }}',
            ],
            'unpublished' => [
                'basic' => '{{ collection:blog scope:unpublished }}{{ title }}{{ /collection:blog }}',
                'admin_preview' => '{{ if can:edit }}{{ collection:blog scope:unpublished }}{{ title }}{{ /collection:blog }}{{ /if }}',
            ],
            'past' => [
                'basic' => '{{ collection:events scope:past }}{{ title }} - {{ event_date }}{{ /collection:events }}',
                'specific_field' => '{{ collection:events scope:past:publish_date }}{{ title }}{{ /collection:events }}',
            ],
            'future' => [
                'basic' => '{{ collection:events scope:future }}{{ title }} - {{ event_date }}{{ /collection:events }}',
                'upcoming' => '{{ collection:events scope:future sort="event_date:asc" limit="3" }}{{ title }}{{ /collection:events }}',
            ],
            'today' => [
                'basic' => '{{ collection:events scope:today }}{{ title }}{{ /collection:events }}',
                'news' => '{{ collection:news scope:today }}{{ title }}{{ /collection:news }}',
            ],
            'has_taxonomy' => [
                'single_term' => '{{ collection:blog scope:has_taxonomy:tags:featured }}{{ title }}{{ /collection:blog }}',
                'multiple_terms' => '{{ collection:blog scope:has_taxonomy:categories:news,updates }}{{ title }}{{ /collection:blog }}',
                'with_any' => '{{ collection:blog scope:has_taxonomy:tags:featured|popular }}{{ title }}{{ /collection:blog }}',
            ],
        ];

        return $examples[$scopeName] ?? [
            'basic' => "{{ collection:example scope:{$scopeName} }}{{ title }}{{ /collection:example }}",
        ];
    }
}
