<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Sites;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Site;

#[Title('List Sites')]
#[IsReadOnly]
class ListSitesTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.sites.list';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'List all configured sites with their settings and status';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_config_details')
            ->description('Include detailed configuration for each site')
            ->optional()
            ->boolean('show_only_enabled')
            ->description('Show only enabled sites')
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
        $includeConfigDetails = $arguments['include_config_details'] ?? false;
        $showOnlyEnabled = $arguments['show_only_enabled'] ?? false;

        try {
            $allSites = Site::all();
            $sites = [];

            foreach ($allSites as $site) {
                // Filter by enabled status if requested
                if ($showOnlyEnabled && ! $site->enabled()) {
                    continue;
                }

                $siteData = [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'url' => $site->url(),
                    'locale' => $site->locale(),
                    'enabled' => $site->enabled(),
                    'is_default' => $site->handle() === Site::default()->handle(),
                ];

                if ($includeConfigDetails) {
                    $siteData['config'] = [
                        'lang' => $site->lang(),
                        'direction' => $site->direction(),
                        'attributes' => $site->attributes(),
                    ];
                }

                $sites[] = $siteData;
            }

            return [
                'sites' => $sites,
                'total_sites' => count($sites),
                'default_site' => Site::default()->handle(),
                'enabled_sites' => $showOnlyEnabled ? count($sites) : count(array_filter($sites, fn ($site) => $site['enabled'])),
                'meta' => [
                    'multisite_enabled' => Site::multiEnabled(),
                    'config_details_included' => $includeConfigDetails,
                    'filtered_to_enabled_only' => $showOnlyEnabled,
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to list sites: ' . $e->getMessage())->toArray();
        }
    }
}
