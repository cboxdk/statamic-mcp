<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Navigations;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Nav;

#[Title('List Statamic Navigations')]
#[IsReadOnly]
class ListNavigationsTool extends BaseStatamicTool
{
    protected function getToolName(): string
    {
        return 'statamic.navigations.list';
    }

    protected function getToolDescription(): string
    {
        return 'List all Statamic navigations with metadata and configuration';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->boolean('include_meta')
            ->description('Include metadata and configuration details')
            ->optional()
            ->string('filter')
            ->description('Filter results by name/handle')
            ->optional()
            ->integer('limit')
            ->description('Limit the number of results')
            ->optional();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeMeta = $arguments['include_meta'] ?? true;
        $filter = $arguments['filter'] ?? null;
        $limit = $arguments['limit'] ?? null;

        $navigations = [];

        try {
            $navs = Nav::all();

            foreach ($navs as $nav) {
                if ($filter && ! str_contains($nav->handle(), $filter) && ! str_contains($nav->title(), $filter)) {
                    continue;
                }

                $navData = [
                    'handle' => $nav->handle(),
                    'title' => $nav->title(),
                ];

                if ($includeMeta) {
                    $navData['sites'] = $nav->sites();
                    $navData['blueprint'] = $nav->blueprint()?->handle();
                    $navData['path'] = $nav->path();
                    $navData['collections'] = $nav->collections()?->map(fn ($item) => $item->handle())->all() ?? [];
                    $navData['max_depth'] = $nav->maxDepth();
                    $navData['expects_root'] = $nav->expectsRoot();
                }

                $navigations[] = $navData;
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list navigations: ' . $e->getMessage())->toArray();
        }

        if ($limit) {
            $navigations = array_slice($navigations, 0, $limit);
        }

        return [
            'navigations' => $navigations,
            'count' => count($navigations),
            'total_available' => Nav::all()->count(),
        ];
    }
}
