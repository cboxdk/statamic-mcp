<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Entries;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

#[Title('Advanced Entry Search')]
#[IsReadOnly]
class SearchEntresTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.entries.search';
    }

    protected function getToolDescription(): string
    {
        return 'Advanced search and filtering of entries with full-text search, field filters, and sorting';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('query')
            ->description('Search query (searches title, content, and other text fields)')
            ->optional()
            ->raw('filters', [
                'type' => 'object',
                'description' => 'Advanced filters',
                'properties' => [
                    'collections' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Filter by collection handles',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['published', 'draft', 'scheduled'],
                        'description' => 'Filter by publication status',
                    ],
                    'authors' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Filter by author IDs',
                    ],
                    'blueprints' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Filter by blueprint handles',
                    ],
                    'sites' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Filter by site locales',
                    ],
                    'date_range' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'from' => ['type' => 'string'],
                            'to' => ['type' => 'string'],
                        ],
                        'description' => 'Filter by date range',
                    ],
                    'field_filters' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'field' => ['type' => 'string'],
                                'operator' => ['type' => 'string'],
                                'value' => ['type' => 'string'],
                            ],
                            'required' => ['field', 'operator', 'value'],
                        ],
                        'description' => 'Custom field filters',
                    ],
                    'has_fields' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Entries that have these fields (non-empty)',
                    ],
                    'missing_fields' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Entries missing these fields (empty or null)',
                    ],
                ],
                'additionalProperties' => false,
            ])
            ->optional()
            ->raw('sort', [
                'type' => 'object',
                'description' => 'Sorting options',
                'properties' => [
                    'field' => ['type' => 'string'],
                    'direction' => [
                        'type' => 'string',
                        'enum' => ['asc', 'desc'],
                    ],
                ],
                'additionalProperties' => false,
            ])
            ->optional()
            ->integer('limit')
            ->description('Maximum number of results (default: 50, max: 500)')
            ->optional()
            ->integer('offset')
            ->description('Number of results to skip (for pagination)')
            ->optional()
            ->raw('fields', [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Specific fields to return (default: all)',
            ])
            ->optional()
            ->boolean('include_drafts')
            ->description('Include draft entries in search')
            ->optional()
            ->boolean('include_content')
            ->description('Include full entry content in results')
            ->optional()
            ->boolean('fuzzy_search')
            ->description('Enable fuzzy/partial matching for text search')
            ->optional()
            ->boolean('highlight_matches')
            ->description('Highlight search matches in returned content')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $query = $arguments['query'] ?? null;
        $filters = $arguments['filters'] ?? [];
        $sort = $arguments['sort'] ?? null;
        $limit = min($arguments['limit'] ?? 50, 500);
        $offset = $arguments['offset'] ?? 0;
        $fields = $arguments['fields'] ?? null;
        $includeDrafts = $arguments['include_drafts'] ?? false;
        $includeContent = $arguments['include_content'] ?? false;
        $fuzzySearch = $arguments['fuzzy_search'] ?? false;
        $highlightMatches = $arguments['highlight_matches'] ?? false;

        $startTime = microtime(true);

        // Build the query
        $entryQuery = Entry::query();

        // Apply collection filters
        if (isset($filters['collections']) && is_array($filters['collections'])) {
            $entryQuery->whereIn('collection', $filters['collections']);
        }

        // Apply status filter
        if (isset($filters['status'])) {
            switch ($filters['status']) {
                case 'published':
                    $entryQuery->where('published', true);
                    break;
                case 'draft':
                    $entryQuery->where('published', false);
                    break;
                case 'scheduled':
                    $entryQuery->where('published', true)
                        ->where('date', '>', Carbon::now());
                    break;
            }
        } elseif (! $includeDrafts) {
            $entryQuery->where('published', true);
        }

        // Apply author filter
        if (isset($filters['authors']) && is_array($filters['authors'])) {
            $entryQuery->whereIn('author', $filters['authors']);
        }

        // Apply site filter
        if (isset($filters['sites']) && is_array($filters['sites'])) {
            $entryQuery->whereIn('locale', $filters['sites']);
        }

        // Apply date range filter
        if (isset($filters['date_range']) && is_array($filters['date_range'])) {
            $dateField = $filters['date_range']['field'] ?? 'date';
            if (isset($filters['date_range']['from'])) {
                $entryQuery->where($dateField, '>=', Carbon::parse($filters['date_range']['from']));
            }
            if (isset($filters['date_range']['to'])) {
                $entryQuery->where($dateField, '<=', Carbon::parse($filters['date_range']['to']));
            }
        }

        // Apply custom field filters
        if (isset($filters['field_filters']) && is_array($filters['field_filters'])) {
            foreach ($filters['field_filters'] as $fieldFilter) {
                if (! isset($fieldFilter['field'], $fieldFilter['operator'], $fieldFilter['value'])) {
                    continue;
                }

                $field = $fieldFilter['field'];
                $operator = $fieldFilter['operator'];
                $value = $fieldFilter['value'];

                switch ($operator) {
                    case 'equals':
                        $entryQuery->where($field, $value);
                        break;
                    case 'not_equals':
                        $entryQuery->where($field, '!=', $value);
                        break;
                    case 'contains':
                        $entryQuery->where($field, 'like', "%{$value}%");
                        break;
                    case 'starts_with':
                        $entryQuery->where($field, 'like', "{$value}%");
                        break;
                    case 'ends_with':
                        $entryQuery->where($field, 'like', "%{$value}");
                        break;
                    case 'greater_than':
                        $entryQuery->where($field, '>', $value);
                        break;
                    case 'less_than':
                        $entryQuery->where($field, '<', $value);
                        break;
                    case 'in':
                        $values = explode(',', $value);
                        $entryQuery->whereIn($field, $values);
                        break;
                }
            }
        }

        // Get initial results
        $entries = $entryQuery->get();

        // Apply text search if provided
        $searchResults = [];
        if ($query) {
            foreach ($entries as $entry) {
                $searchScore = $this->calculateSearchScore($entry, $query, $fuzzySearch);
                if ($searchScore > 0) {
                    $searchResults[] = [
                        'entry' => $entry,
                        'score' => $searchScore,
                        'matches' => $this->findMatches($entry, $query, $fuzzySearch),
                    ];
                }
            }

            // Sort by search score
            usort($searchResults, fn ($a, $b) => $b['score'] <=> $a['score']);
            $entries = collect($searchResults)->pluck('entry');
        }

        // Apply field existence filters
        if (isset($filters['has_fields']) && is_array($filters['has_fields'])) {
            $entries = $entries->filter(function ($entry) use ($filters) {
                foreach ($filters['has_fields'] as $field) {
                    if (! $entry->has($field) || empty($entry->get($field))) {
                        return false;
                    }
                }

                return true;
            });
        }

        if (isset($filters['missing_fields']) && is_array($filters['missing_fields'])) {
            $entries = $entries->filter(function ($entry) use ($filters) {
                foreach ($filters['missing_fields'] as $field) {
                    if ($entry->has($field) && ! empty($entry->get($field))) {
                        return false;
                    }
                }

                return true;
            });
        }

        // Apply sorting
        if ($sort && isset($sort['field'])) {
            $sortField = $sort['field'];
            $sortDirection = $sort['direction'] ?? 'asc';

            $entries = $entries->sortBy(function ($entry) use ($sortField) {
                return $entry->get($sortField) ?? $entry->$sortField ?? '';
            }, SORT_REGULAR, $sortDirection === 'desc');
        }

        $totalResults = $entries->count();

        // Apply pagination
        $entries = $entries->slice($offset, $limit);

        // Format results
        $results = [];
        foreach ($entries as $index => $entry) {
            $result = [
                'id' => $entry->id(),
                'title' => $entry->get('title'),
                'slug' => $entry->slug(),
                'url' => $entry->url(),
                'collection' => $entry->collection()->handle(),
                'blueprint' => $entry->blueprint()?->handle(),
                'status' => $entry->status(),
                'published' => $entry->published(),
                'date' => $entry->date()?->toISOString(),
                'author' => $entry->get('author'),
                'site' => $entry->locale(),
                'last_modified' => $entry->lastModified()?->toISOString(),
            ];

            // Add search-specific data
            if ($query && isset($searchResults[$index])) {
                $result['search_score'] = $searchResults[$index]['score'];
                if ($highlightMatches) {
                    $result['matches'] = $searchResults[$index]['matches'];
                }
            }

            // Add specific fields if requested
            if ($fields && is_array($fields)) {
                $result['fields'] = [];
                foreach ($fields as $field) {
                    $result['fields'][$field] = $entry->get($field);
                }
            } elseif ($includeContent) {
                $result['data'] = $entry->data()->all();
            }

            $results[] = $result;
        }

        $executionTime = microtime(true) - $startTime;

        return [
            'results' => $results,
            'search_meta' => [
                'query' => $query,
                'total_results' => $totalResults,
                'returned_results' => count($results),
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => $totalResults > ($offset + $limit),
                'execution_time_ms' => round($executionTime * 1000, 2),
                'fuzzy_search_enabled' => $fuzzySearch,
                'highlight_matches' => $highlightMatches,
            ],
            'filters_applied' => $filters,
            'collections_searched' => $filters['collections'] ?? $this->getAllCollectionHandles(),
        ];
    }

    /**
     * Calculate search score for an entry.
     */
    private function calculateSearchScore(\Statamic\Contracts\Entries\Entry $entry, string $query, bool $fuzzy = false): float
    {
        $score = 0.0;
        $queryLower = strtolower($query);
        $queryWords = explode(' ', $queryLower);

        // Search in title (highest weight)
        $title = strtolower($entry->get('title') ?? '');
        if (str_contains($title, $queryLower)) {
            $score += 10.0;
        }

        foreach ($queryWords as $word) {
            if (str_contains($title, $word)) {
                $score += 5.0;
            }
        }

        // Search in content/body fields
        $contentFields = ['content', 'body', 'description', 'excerpt', 'summary'];
        foreach ($contentFields as $field) {
            $content = strtolower($entry->get($field) ?? '');
            if ($content) {
                if (str_contains($content, $queryLower)) {
                    $score += 3.0;
                }

                foreach ($queryWords as $word) {
                    if (str_contains($content, $word)) {
                        $score += 1.0;
                    }
                }
            }
        }

        // Search in slug
        $slug = strtolower($entry->slug() ?? '');
        if (str_contains($slug, $queryLower)) {
            $score += 2.0;
        }

        // Fuzzy matching bonus
        if ($fuzzy && $score === 0.0) {
            foreach ($queryWords as $word) {
                if (strlen($word) > 3) {
                    $pattern = str_split($word);
                    $fuzzyPattern = implode('.*', array_map('preg_quote', $pattern));

                    $contentToSearch = $content ?: '';
                    if (preg_match("/{$fuzzyPattern}/i", $title) || preg_match("/{$fuzzyPattern}/i", $contentToSearch)) {
                        $score += 0.5;
                    }
                }
            }
        }

        return $score;
    }

    /**
     * Find search matches in entry content.
     *
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function findMatches(\Statamic\Contracts\Entries\Entry $entry, string $query, bool $fuzzy = false): array
    {
        $matches = [];
        $queryLower = strtolower($query);

        $searchFields = [
            'title' => $entry->get('title') ?? '',
            'content' => $entry->get('content') ?? '',
            'slug' => $entry->slug() ?? '',
        ];

        foreach ($searchFields as $field => $content) {
            if (stripos($content, $query) !== false) {
                $matches[$field] = $this->extractMatchContext($content, $query);
            }
        }

        return $matches;
    }

    /**
     * Extract context around search matches.
     */
    private function extractMatchContext(string $content, string $query, int $contextLength = 100): string
    {
        $pos = stripos($content, $query);
        if ($pos === false) {
            return '';
        }

        $start = max(0, $pos - $contextLength);
        $end = min(strlen($content), $pos + strlen($query) + $contextLength);

        $context = substr($content, $start, $end - $start);

        // Add ellipsis if truncated
        if ($start > 0) {
            $context = '...' . $context;
        }
        if ($end < strlen($content)) {
            $context = $context . '...';
        }

        return $context;
    }

    /**
     * Get all collection handles.
     *
     * @return array<string>
     */
    private function getAllCollectionHandles(): array
    {
        return Collection::handles()->all();
    }
}
