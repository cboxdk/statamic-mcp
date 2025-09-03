<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Services\DocumentationSearchService;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic Documentation Search')]
#[IsReadOnly]
class DocsSystemTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.system.docs';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Search and retrieve Statamic documentation content on topics like collections, blueprints, field types, templating, and more';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('query')
            ->description('Search query or topic (e.g. "collections", "bard field", "antlers tags"). Empty query returns no results.')
            ->optional()
            ->string('section')
            ->description('Documentation section to search in: core, tags, fieldtypes, modifiers, cli, rest-api')
            ->optional()
            ->number('limit')
            ->description('Maximum number of results to return (default: 3, max: 10)')
            ->optional()
            ->boolean('include_content')
            ->description('Include content of documentation pages (default: false for performance)')
            ->optional()
            ->number('content_length')
            ->description('Maximum content length per result in characters (default: 10000)')
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
        $query = $arguments['query'] ?? '';
        $section = $this->getArgument($arguments, 'section');
        $limit = $this->getIntegerArgument($arguments, 'limit', 3, 1, 10);
        $includeContent = $this->getBooleanArgument($arguments, 'include_content', false);
        $contentLength = $this->getIntegerArgument($arguments, 'content_length', 10000, 1000, 50000);

        $searchService = app(DocumentationSearchService::class);
        $results = $searchService->search($query, $section, $limit, $includeContent, $contentLength);

        // Results are already guaranteed to be an array from the search service

        $response = [
            'query' => $query,
            'section' => $section,
            'results_count' => count($results),
            'limit_applied' => $limit,
            'include_content' => $includeContent,
            'results' => $results,
        ];

        if (count($results) < 3) {
            $response['search_suggestions'] = $searchService->getSearchSuggestions($query);
        }

        if (! $includeContent) {
            $response['note'] = 'Set include_content: true to get full documentation content (may be slower)';
        }

        return $response;
    }
}
