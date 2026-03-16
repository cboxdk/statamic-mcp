<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Illuminate\Support\Collection;
use Statamic\Facades\Site;

/**
 * Site operations for the StructuresRouter (read-only).
 */
trait HandlesSites
{
    /**
     * Handle site operations.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function handleSiteAction(string $action, array $arguments): array
    {
        return match ($action) {
            'list' => $this->listSites($arguments),
            'get' => $this->getSite($arguments),
            'create', 'update', 'delete', 'configure' => $this->createErrorResponse('Site mutations require configuration file changes — not supported via MCP')->toArray(),
            default => $this->createErrorResponse("Unknown site action: {$action}")->toArray(),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function listSites(array $arguments): array
    {
        try {
            /** @var Collection<string, \Statamic\Sites\Site> $allSites */
            $allSites = Site::all();
            $sites = $allSites->map(function ($site) {
                /** @var \Statamic\Sites\Site $site */
                $data = [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'locale' => $site->locale(),
                    'short_locale' => $site->shortLocale(),
                    'url' => $site->url(),
                ];

                return $data;
            })->all();

            /** @var \Statamic\Sites\Site $defaultSite */
            $defaultSite = Site::default();

            return [
                'sites' => $sites,
                'default' => $defaultSite->handle(),
            ];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to list sites: {$e->getMessage()}")->toArray();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getSite(array $arguments): array
    {
        try {
            $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
            /** @var \Statamic\Sites\Site|null $site */
            $site = Site::get($handle);

            if (! $site) {
                return $this->createErrorResponse("Site not found: {$handle}")->toArray();
            }

            $data = [
                'handle' => $site->handle(),
                'name' => $site->name(),
                'locale' => $site->locale(),
                'short_locale' => $site->shortLocale(),
                'url' => $site->url(),
                'direction' => $site->direction(),
                'lang' => $site->lang(),
                'attributes' => $site->attributes(),
                'is_default' => $site === Site::default(),
                'is_selected' => $site === Site::selected(),
            ];

            return ['site' => $data];
        } catch (\Exception $e) {
            return $this->createErrorResponse("Failed to get site: {$e->getMessage()}")->toArray();
        }
    }
}
