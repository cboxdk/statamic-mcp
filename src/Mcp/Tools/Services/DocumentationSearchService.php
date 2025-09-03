<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Services;

class DocumentationSearchService
{
    /**
     * Search Statamic documentation.
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, ?string $section, int $limit, bool $includeContent, int $contentLength = 10000): array
    {
        $results = [];

        if (empty(trim($query))) {
            return [];
        }

        $searchTerms = $this->prepareSearchTerms($query);
        $docMap = app(DocumentationMapService::class)->getDocumentationMap($section);

        foreach ($docMap as $url => $docInfo) {
            $relevanceScore = $this->calculateRelevance($searchTerms, $docInfo);

            if ($relevanceScore > 0) {
                $result = [
                    'title' => $docInfo['title'],
                    'url' => $url,
                    'section' => $docInfo['section'],
                    'relevance_score' => $relevanceScore,
                    'summary' => $docInfo['summary'] ?? '',
                    'tags' => $docInfo['tags'] ?? [],
                ];

                if ($includeContent) {
                    $content = app(DocumentationContentService::class)->fetchContent($url, $docInfo);
                    if ($content) {
                        if (strlen($content) > $contentLength) {
                            $content = substr($content, 0, $contentLength) . '...';
                            $result['content_truncated'] = true;
                        }
                        $result['content'] = $content;
                        $result['content_excerpt'] = $this->createExcerpt($content, $searchTerms);
                    }
                }

                $results[] = $result;
            }
        }

        usort($results, fn ($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);
        $results = array_slice($results, 0, $limit);

        if ($includeContent) {
            $totalSize = 0;
            $finalResults = [];

            foreach ($results as $result) {
                $resultSize = strlen(json_encode($result));
                if ($totalSize + $resultSize > 20000) {
                    break;
                }
                $totalSize += $resultSize;
                $finalResults[] = $result;
            }

            return $finalResults;
        }

        return $results;
    }

    /**
     * Get search suggestions for low-result queries.
     *
     * @return array<int, string>
     */
    public function getSearchSuggestions(string $query): array
    {
        $suggestions = [];
        $queryLower = strtolower($query);

        $commonTerms = [
            'collection' => ['collections', 'entries', 'content structure'],
            'blueprint' => ['blueprints', 'fieldsets', 'schema'],
            'field' => ['fieldtypes', 'bard', 'replicator', 'assets'],
            'template' => ['antlers', 'blade', 'views', 'layouts'],
            'tag' => ['tags', 'antlers tags', 'templating'],
            'modifier' => ['modifiers', 'filters', 'data manipulation'],
            'antler' => ['antlers', 'templating', 'tags'],
            'blade' => ['blade templates', 'laravel', 'components'],
            'bard' => ['bard fieldtype', 'rich text', 'editor'],
            'replicate' => ['replicator', 'sets', 'flexible content'],
            'asset' => ['assets', 'files', 'images', 'media'],
            'taxonom' => ['taxonomies', 'terms', 'categories'],
            'user' => ['users', 'roles', 'permissions', 'authentication'],
            'route' => ['routing', 'urls', 'navigation'],
            'config' => ['configuration', 'settings', 'environment'],
            'install' => ['installation', 'requirements', 'setup'],
            'update' => ['updating', 'upgrading', 'migration'],
            'addon' => ['addons', 'packages', 'extending'],
            'api' => ['rest api', 'graphql', 'endpoints'],
            'cache' => ['caching', 'performance', 'optimization'],
            'search' => ['search functionality', 'indexing', 'algolia'],
            'form' => ['forms', 'submissions', 'validation'],
            'static' => ['static site generation', 'ssg'],
            'git' => ['git integration', 'version control'],
            'deploy' => ['deployment', 'hosting', 'production'],
        ];

        foreach ($commonTerms as $term => $relatedTerms) {
            if (str_contains($queryLower, $term)) {
                foreach ($relatedTerms as $suggestion) {
                    if (stripos($suggestion, $queryLower) === false) {
                        $suggestions[] = $suggestion;
                    }
                }
                break;
            }
        }

        if (empty($suggestions)) {
            $suggestions = [
                'Try searching for: "collections", "blueprints", "fieldtypes"',
                'Browse sections: core, tags, fieldtypes, modifiers, cli, rest-api',
                'Popular topics: "antlers templating", "bard field", "replicator sets"',
            ];
        }

        return array_slice($suggestions, 0, 3);
    }

    /**
     * Prepare search terms from query.
     *
     * @return array<int|string, mixed>
     */
    private function prepareSearchTerms(string $query): array
    {
        $query = strtolower(trim($query));
        $terms = preg_split('/[\s,]+/', $query);
        $terms = array_filter($terms, fn ($term) => strlen($term) > 2);

        $expandedTerms = [];
        foreach ($terms as $term) {
            $expandedTerms[] = $term;

            $synonyms = $this->getTermSynonyms($term);
            $expandedTerms = array_merge($expandedTerms, $synonyms);
        }

        return array_unique($expandedTerms);
    }

    /**
     * Get synonyms for search terms.
     *
     * @return array<int, string>
     */
    private function getTermSynonyms(string $term): array
    {
        $synonymMap = [
            'field' => ['fieldtype', 'input'],
            'tag' => ['variable', 'helper'],
            'collection' => ['entries', 'content'],
            'blueprint' => ['schema', 'structure'],
            'template' => ['view', 'layout'],
            'antler' => ['antlers'],
            'modifier' => ['filter', 'pipe'],
        ];

        return $synonymMap[$term] ?? [];
    }

    /**
     * Calculate relevance score for documentation.
     */
    private function calculateRelevance(array $searchTerms, array $docInfo): float
    {
        $score = 0;
        $title = strtolower($docInfo['title']);
        $summary = strtolower($docInfo['summary'] ?? '');
        $tags = array_map('strtolower', $docInfo['tags'] ?? []);
        $keywords = array_map('strtolower', $docInfo['keywords'] ?? []);

        foreach ($searchTerms as $term) {
            if (stripos($title, $term) !== false) {
                $score += 10;
            }
            if (stripos($summary, $term) !== false) {
                $score += 5;
            }
            if (in_array($term, $tags)) {
                $score += 8;
            }
            if (in_array($term, $keywords)) {
                $score += 6;
            }

            foreach ($tags as $tag) {
                if (stripos($tag, $term) !== false) {
                    $score += 4;
                }
            }

            foreach ($keywords as $keyword) {
                if (stripos($keyword, $term) !== false) {
                    $score += 3;
                }
            }
        }

        return $score;
    }

    /**
     * Create excerpt from content highlighting search terms.
     */
    private function createExcerpt(string $content, array $searchTerms): string
    {
        $excerptLength = 500;
        $bestMatch = '';
        $highestScore = 0;

        $sentences = preg_split('/[.!?]+/', $content);

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) < 50) {
                continue;
            }

            $score = 0;
            foreach ($searchTerms as $term) {
                $score += substr_count(strtolower($sentence), strtolower($term));
            }

            if ($score > $highestScore && strlen($sentence) <= $excerptLength) {
                $highestScore = $score;
                $bestMatch = $sentence;
            }
        }

        if ($bestMatch) {
            foreach ($searchTerms as $term) {
                $bestMatch = preg_replace('/(' . preg_quote($term, '/') . ')/i', '**$1**', $bestMatch);
            }

            return $bestMatch . '.';
        }

        return substr($content, 0, $excerptLength) . '...';
    }
}
