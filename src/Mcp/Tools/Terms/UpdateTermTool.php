<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Terms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Term;

#[Title('Update Statamic Term')]
class UpdateTermTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.terms.update';
    }

    protected function getToolDescription(): string
    {
        return 'Update an existing taxonomy term';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('id')
            ->description('Term ID or taxonomy::slug format')
            ->required()
            ->string('title')
            ->description('Update term title')
            ->optional()
            ->string('slug')
            ->description('Update term slug')
            ->optional()
            ->raw('data', ['type' => 'object'])
            ->description('Update term field data')
            ->optional()
            ->boolean('published')
            ->description('Update published status')
            ->optional()
            ->boolean('merge_data')
            ->description('Merge with existing data instead of replacing')
            ->optional()
            ->boolean('dry_run')
            ->description('Validate without actually updating')
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
        $id = $arguments['id'];
        $title = $arguments['title'] ?? null;
        $slug = $arguments['slug'] ?? null;
        $data = $arguments['data'] ?? [];
        $published = $arguments['published'] ?? null;
        $mergeData = $arguments['merge_data'] ?? true;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            $term = Term::find($id);
            if (! $term) {
                return $this->createErrorResponse("Term '{$id}' not found")->toArray();
            }

            $originalData = $term->data()->toArray();
            $changes = [];

            // Check for slug conflicts if updating slug
            if ($slug && $slug !== $term->slug()) {
                $conflictingTerm = Term::query()
                    ->where('taxonomy', $term->taxonomy()->handle())
                    ->where('slug', $slug)
                    ->where('id', '!=', $term->id())
                    ->first();

                if ($conflictingTerm) {
                    return $this->createErrorResponse("Term with slug '{$slug}' already exists in taxonomy '{$term->taxonomy()->handle()}'")->toArray();
                }
                $changes['slug'] = ['from' => $term->slug(), 'to' => $slug];
            }

            // Prepare data updates
            $newData = $originalData;
            if ($title) {
                $newData['title'] = $title;
                $changes['title'] = ['from' => $term->get('title'), 'to' => $title];
            }

            if (! empty($data)) {
                if ($mergeData) {
                    $newData = array_merge($newData, $data);
                } else {
                    // Keep title if not provided in data
                    $newData = array_merge(['title' => $newData['title'] ?? $title ?? $term->get('title')], $data);
                }
                $changes['data'] = ['merged' => $mergeData, 'fields' => array_keys($data)];
            }

            if ($published !== null && $published !== $term->published()) {
                $changes['published'] = ['from' => $term->published(), 'to' => $published];
            }

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'would_update' => $changes,
                    'current' => [
                        'id' => $term->id(),
                        'title' => $term->get('title'),
                        'slug' => $term->slug(),
                        'published' => $term->published(),
                    ],
                ];
            }

            // Apply updates
            if ($slug) {
                $term->slug($slug);
            }

            $term->data($newData);

            if ($published !== null) {
                $term->published($published);
            }

            $term->save();

            // Clear caches
            $cacheTypes = $this->getRecommendedCacheTypes('content_change');
            $cacheResult = $this->clearStatamicCaches($cacheTypes);

            return [
                'term' => [
                    'id' => $term->id(),
                    'title' => $term->get('title'),
                    'slug' => $term->slug(),
                    'taxonomy' => $term->taxonomy()->handle(),
                    'published' => $term->published(),
                    'uri' => $term->uri(),
                    'url' => $term->url(),
                    'updated_at' => $term->lastModified()?->toISOString(),
                ],
                'changes' => $changes,
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not update term: ' . $e->getMessage())->toArray();
        }
    }
}
