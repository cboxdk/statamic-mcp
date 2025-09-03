<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Navigations;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Nav;

#[Title('List Statamic Navigation Content')]
#[IsReadOnly]
class ListNavigationContentTool extends BaseStatamicTool
{
    use HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.navigations.list_content';
    }

    protected function getToolDescription(): string
    {
        return 'List navigation trees with their content and structure';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('navigation')
            ->description('Navigation handle (optional - lists all if not provided)')
            ->optional()
            ->string('site')
            ->description('Site to get navigation for')
            ->optional()
            ->boolean('include_entries')
            ->description('Include linked entry details')
            ->optional()
            ->boolean('flatten')
            ->description('Return flat array instead of nested tree structure')
            ->optional()
            ->integer('max_depth')
            ->description('Maximum depth to traverse')
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
        $navigationHandle = $arguments['navigation'] ?? null;
        $site = $arguments['site'] ?? null;
        $includeEntries = $arguments['include_entries'] ?? false;
        $flatten = $arguments['flatten'] ?? false;
        $maxDepth = $arguments['max_depth'] ?? null;

        try {
            if ($navigationHandle) {
                // Get specific navigation
                $nav = Nav::find($navigationHandle);
                if (! $nav) {
                    return $this->createErrorResponse("Navigation '{$navigationHandle}' not found")->toArray();
                }

                $tree = $this->getNavigationTree($nav, $site, $includeEntries, $flatten, $maxDepth);

                return [
                    'navigation' => [
                        'handle' => $nav->handle(),
                        'title' => $nav->title(),
                        'sites' => $nav->sites(),
                        'tree' => $tree,
                    ],
                ];
            } else {
                // List all navigations
                $navigations = Nav::all()->map(function ($nav) use ($site, $includeEntries, $flatten, $maxDepth) {
                    return [
                        'handle' => $nav->handle(),
                        'title' => $nav->title(),
                        'sites' => $nav->sites(),
                        'tree' => $this->getNavigationTree($nav, $site, $includeEntries, $flatten, $maxDepth),
                    ];
                })->values()->toArray();

                return [
                    'navigations' => $navigations,
                    'count' => count($navigations),
                ];
            }
        } catch (\Exception $e) {
            return $this->createErrorResponse('Could not list navigation content: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Get navigation tree content.
     *
     * @param  mixed  $nav
     *
     * @return array<int, array<string, mixed>>
     */
    private function getNavigationTree($nav, ?string $site, bool $includeEntries, bool $flatten, ?int $maxDepth): array
    {
        $targetSite = $site ?? $nav->sites()[0] ?? 'default';

        try {
            $tree = $nav->in($targetSite);
            if (! $tree) {
                return [];
            }

            $items = $tree->tree();

            return $this->processNavigationItems($items, $includeEntries, $flatten, $maxDepth, 0);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Process navigation items recursively.
     *
     * @param  array<string, mixed>  $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function processNavigationItems(array $items, bool $includeEntries, bool $flatten, ?int $maxDepth, int $currentDepth): array
    {
        if ($maxDepth !== null && $currentDepth >= $maxDepth) {
            return [];
        }

        $processed = [];

        foreach ($items as $item) {
            $data = [
                'id' => $item['id'] ?? null,
                'title' => $item['title'] ?? null,
                'url' => $item['url'] ?? null,
                'entry' => $item['entry'] ?? null,
                'depth' => $currentDepth,
            ];

            // Include entry details if requested
            if ($includeEntries && isset($item['entry']) && $item['entry']) {
                try {
                    $entry = \Statamic\Facades\Entry::find($item['entry']);
                    if ($entry) {
                        $data['entry_details'] = [
                            'id' => $entry->id(),
                            'title' => $entry->get('title'),
                            'collection' => $entry->collection()->handle(),
                            'published' => $entry->published(),
                            'url' => $entry->url(),
                        ];
                    }
                } catch (\Exception $e) {
                    // Entry not found or error - continue without entry details
                }
            }

            // Process children
            $children = [];
            if (isset($item['children']) && is_array($item['children']) && ! empty($item['children'])) {
                $children = $this->processNavigationItems($item['children'], $includeEntries, $flatten, $maxDepth, $currentDepth + 1);
            }

            if ($flatten) {
                // Add current item to flat list
                $processed[] = $data;
                // Add children to flat list
                $processed = array_merge($processed, $children);
            } else {
                // Keep tree structure
                if (! empty($children)) {
                    $data['children'] = $children;
                }
                $processed[] = $data;
            }
        }

        return $processed;
    }
}
