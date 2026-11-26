<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Routers\Concerns;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
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
        /** @var Collection<string, \Statamic\Sites\Site> $allSites */
        $allSites = Site::all();
        $policy = app(ResourcePolicy::class);
        $sites = $allSites->filter(
            fn ($site): bool => $policy->canAccess('structures', $site->handle(), 'read')
        )->map(function ($site) {
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
            ...$this->paginateItems($sites, $arguments),
            'default' => $policy->canAccess('structures', $defaultSite->handle(), 'read') ? $defaultSite->handle() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function getSite(array $arguments): array
    {
        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        /** @var \Statamic\Sites\Site|null $site */
        $site = Site::get($handle);
        if (! $site) {
            /** @var Collection<string, \Statamic\Sites\Site> $sites */
            $sites = Site::all();

            return $this->missingResource('Site', $handle, candidates: $sites->map(fn ($site): string => $site->handle())->all(), arguments: $arguments)->toArray();
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
    }
}
