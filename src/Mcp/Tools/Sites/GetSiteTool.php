<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Sites;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Site;

#[Title('Get Site')]
#[IsReadOnly]
class GetSiteTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.sites.get';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Get detailed configuration and status for a specific site';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Site handle to retrieve')
            ->required()
            ->boolean('include_usage_stats')
            ->description('Include usage statistics like entry counts per collection')
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
        $handle = $arguments['handle'];
        $includeUsageStats = $arguments['include_usage_stats'] ?? false;

        try {
            $site = Site::get($handle);

            if (! $site) {
                return $this->createErrorResponse("Site '{$handle}' not found", [
                    'available_sites' => Site::all()->map->handle()->all(),
                ])->toArray();
            }

            $siteData = [
                'handle' => $site->handle(),
                'name' => $site->name(),
                'url' => $site->url(),
                'locale' => $site->locale(),
                'lang' => $site->lang(),
                'direction' => $site->direction(),
                'enabled' => $site->enabled(),
                'is_default' => $site->handle() === Site::default()->handle(),
                'attributes' => $site->attributes(),
                'config' => [
                    'full_url' => $site->absoluteUrl(),
                    'permalink_prefix' => $site->url(),
                ],
            ];

            if ($includeUsageStats) {
                $siteData['usage_stats'] = $this->getSiteUsageStats($site);
            }

            return [
                'site' => $siteData,
                'relations' => [
                    'other_sites' => Site::all()->filter(fn ($s) => $s->handle() !== $handle)->map->handle()->all(),
                    'is_multisite' => Site::multiEnabled(),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to get site: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Get usage statistics for a site.
     *
     * @param  \Statamic\Sites\Site  $site
     *
     * @return array<string, mixed>
     */
    private function getSiteUsageStats($site): array
    {
        $stats = [
            'collections' => [],
            'total_entries' => 0,
            'global_sets' => [],
        ];

        try {
            // Collection entry counts
            $collections = \Statamic\Facades\Collection::all();
            foreach ($collections as $collection) {
                if (in_array($site->handle(), $collection->sites())) {
                    $entryCount = $collection->queryEntries()->where('site', $site->handle())->count();
                    $stats['collections'][$collection->handle()] = $entryCount;
                    $stats['total_entries'] += $entryCount;
                }
            }

            // Global sets available in this site
            $globalSets = \Statamic\Facades\GlobalSet::all();
            foreach ($globalSets as $globalSet) {
                if (in_array($site->handle(), $globalSet->sites())) {
                    $stats['global_sets'][] = $globalSet->handle();
                }
            }
        } catch (\Exception $e) {
            $stats['error'] = 'Could not calculate usage stats: ' . $e->getMessage();
        }

        return $stats;
    }
}
