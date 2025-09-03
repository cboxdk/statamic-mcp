<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Terms;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

#[Title('Create Statamic Term')]
class CreateTermTool extends BaseStatamicTool
{
    use ClearsCaches;
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.terms.create';
    }

    protected function getToolDescription(): string
    {
        return 'Create a new taxonomy term';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('taxonomy')
            ->description('Taxonomy handle')
            ->required()
            ->string('title')
            ->description('Term title')
            ->required()
            ->string('slug')
            ->description('Term slug (auto-generated if not provided)')
            ->optional()
            ->raw('data', ['type' => 'object'])
            ->description('Term field data as key-value pairs')
            ->optional()
            ->boolean('published')
            ->description('Publish the term immediately')
            ->optional()
            ->boolean('dry_run')
            ->description('Validate without actually creating')
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
        $taxonomyHandle = $arguments['taxonomy'];
        $title = $arguments['title'];
        $slug = $arguments['slug'] ?? null;
        $data = $arguments['data'] ?? [];
        $published = $arguments['published'] ?? true;
        $dryRun = $arguments['dry_run'] ?? false;

        try {
            // Validate taxonomy exists
            $taxonomy = Taxonomy::find($taxonomyHandle);
            if (! $taxonomy) {
                return $this->createErrorResponse("Taxonomy '{$taxonomyHandle}' not found")->toArray();
            }

            // Auto-generate slug if not provided
            if (! $slug) {
                $slug = \Illuminate\Support\Str::slug($title);
            }

            // Check if term already exists
            $existingTerm = Term::query()
                ->where('taxonomy', $taxonomyHandle)
                ->where('slug', $slug)
                ->first();

            if ($existingTerm) {
                return $this->createErrorResponse("Term with slug '{$slug}' already exists in taxonomy '{$taxonomyHandle}'")->toArray();
            }

            if ($dryRun) {
                return [
                    'dry_run' => true,
                    'would_create' => [
                        'taxonomy' => $taxonomyHandle,
                        'title' => $title,
                        'slug' => $slug,
                        'published' => $published,
                        'data_fields' => array_keys($data),
                    ],
                ];
            }

            // Create the term
            $term = Term::make()
                ->taxonomy($taxonomyHandle)
                ->slug($slug)
                ->data(array_merge(['title' => $title], $data));

            if ($published) {
                $term->published(true);
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
                    'created_at' => $term->date()?->toISOString(),
                ],
                'cache' => $cacheResult,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not create term: ' . $e->getMessage())->toArray();
        }
    }
}
