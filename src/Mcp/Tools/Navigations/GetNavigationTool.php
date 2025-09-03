<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Navigations;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Nav;

#[Title('Get Statamic Navigation')]
#[IsReadOnly]
class GetNavigationTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.navigations.get';
    }

    protected function getToolDescription(): string
    {
        return 'Get detailed information about a specific Statamic navigation including tree structure';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('handle')
            ->description('Navigation handle')
            ->required()
            ->boolean('include_tree')
            ->description('Include navigation tree structure')
            ->optional()
            ->boolean('include_urls')
            ->description('Include resolved URLs for navigation items')
            ->optional()
            ->integer('max_depth')
            ->description('Maximum depth for navigation tree (default: unlimited)')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $includeTree = $arguments['include_tree'] ?? true;
        $includeUrls = $arguments['include_urls'] ?? false;
        $maxDepth = $arguments['max_depth'] ?? null;

        $nav = Nav::find($handle);

        if (! $nav) {
            return $this->createErrorResponse("Navigation '{$handle}' not found")->toArray();
        }

        try {
            $navData = [
                'handle' => $nav->handle(),
                'title' => $nav->title(),
                'sites' => $nav->sites(),
                'blueprint' => $nav->blueprint()?->handle(),
                'path' => $nav->path(),
                'collections' => $nav->collections()?->map(fn ($item) => $item->handle())->all() ?? [],
                'max_depth' => $nav->maxDepth(),
                'expects_root' => $nav->expectsRoot(),
            ];

            if ($includeTree) {
                $navData['trees'] = [];
                foreach ($nav->sites() as $site) {
                    $tree = $nav->in($site)?->tree();
                    if ($tree) {
                        $treeData = $this->buildTreeData($tree, $includeUrls, $maxDepth);
                        $navData['trees'][$site] = $treeData;
                    }
                }
            }

            return [
                'navigation' => $navData,
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not retrieve navigation: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Build tree data structure.
     *
     * @param  mixed  $tree
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTreeData($tree, bool $includeUrls, ?int $maxDepth, int $currentDepth = 0): array
    {
        if ($maxDepth && $currentDepth >= $maxDepth) {
            return [];
        }

        $treeData = [];

        foreach ($tree as $page) {
            $pageData = [
                'id' => $page->id(),
                'title' => $page->title(),
                'data' => $page->data(),
            ];

            if ($includeUrls) {
                $pageData['url'] = $page->url();
                $pageData['absoluteUrl'] = $page->absoluteUrl();
            }

            if ($page->children() && $page->children()->count() > 0) {
                $pageData['children'] = $this->buildTreeData(
                    $page->children(),
                    $includeUrls,
                    $maxDepth,
                    $currentDepth + 1
                );
                $pageData['has_children'] = true;
                $pageData['children_count'] = count($pageData['children']);
            } else {
                $pageData['has_children'] = false;
                $pageData['children_count'] = 0;
            }

            $treeData[] = $pageData;
        }

        return $treeData;
    }
}
