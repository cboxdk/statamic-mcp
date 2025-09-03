<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Terms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Term;

#[Title('List Statamic Terms')]
#[IsReadOnly]
class ListTermsTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.terms.list';
    }

    protected function getToolDescription(): string
    {
        return 'List taxonomy terms with filtering and pagination';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema = $this->addLimitSchema($schema);

        return $schema
            ->string('taxonomy')
            ->description('Taxonomy handle to list terms from')
            ->required()
            ->string('search')
            ->description('Search terms by title or slug')
            ->optional()
            ->string('status')
            ->description('Filter by status: all, published, draft')
            ->optional()
            ->boolean('include_data')
            ->description('Include term field data')
            ->optional()
            ->string('sort')
            ->description('Sort field (title, slug, created_at, updated_at)')
            ->optional()
            ->string('order')
            ->description('Sort order: asc or desc')
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
        $taxonomy = $arguments['taxonomy'];
        $search = $arguments['search'] ?? null;
        $status = $arguments['status'] ?? 'all';
        $includeData = $arguments['include_data'] ?? false;
        $sort = $arguments['sort'] ?? 'title';
        $order = $arguments['order'] ?? 'asc';
        $limit = $arguments['limit'] ?? null;

        try {
            $query = Term::query()->where('taxonomy', $taxonomy);

            // Apply status filter
            if ($status === 'published') {
                $query->where('published', true);
            } elseif ($status === 'draft') {
                $query->where('published', false);
            }

            // Apply search
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            // Apply sorting
            $query->orderBy($sort, $order);

            // Apply limit
            if ($limit) {
                $query->limit($limit);
            }

            $terms = $query->get();
            $termData = [];

            foreach ($terms as $term) {
                $data = [
                    'id' => $term->id(),
                    'title' => $term->get('title'),
                    'slug' => $term->slug(),
                    'taxonomy' => $term->taxonomy()->handle(),
                    'published' => $term->published(),
                    'uri' => $term->uri(),
                    'url' => $term->url(),
                    'created_at' => $term->date()?->toISOString(),
                    'updated_at' => $term->lastModified()?->toISOString(),
                ];

                if ($includeData) {
                    $data['data'] = $term->data()->toArray();
                }

                $termData[] = $data;
            }

            return [
                'terms' => $termData,
                'count' => count($termData),
                'taxonomy' => $taxonomy,
                'filters' => [
                    'search' => $search,
                    'status' => $status,
                    'sort' => $sort,
                    'order' => $order,
                    'limit' => $limit,
                ],
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list terms: ' . $e->getMessage())->toArray();
        }
    }
}
