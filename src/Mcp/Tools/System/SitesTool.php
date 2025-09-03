<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Site;

class SitesTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\ClearsCaches;

    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.system.sites';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Manage Statamic multi-site configuration - list, configure, and manage sites';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('action', [
                'type' => 'string',
                'enum' => ['list', 'show', 'current', 'default'],
                'description' => 'The action to perform',
            ])
            ->required()
            ->string('handle')
            ->description('Site handle')
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
        $action = $arguments['action'];

        return match ($action) {
            'list' => $this->listSites(),
            'show' => $this->showSite($arguments),
            'current' => $this->getCurrentSite(),
            'default' => $this->getDefaultSite(),
            default => $this->createErrorResponse('Unknown action: ' . $action)->toArray(),
        };
    }

    /**
     * List all sites.
     */
    /**
     * @return array<string, mixed>
     */
    private function listSites(): array
    {
        try {
            $sites = Site::all()->map(function ($site) {
                return [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'url' => $site->url(),
                    'locale' => $site->locale(),
                    'lang' => $site->lang(),
                    'direction' => $site->direction(),
                    'attributes' => $site->attributes(),
                ];
            })->values()->toArray();

            return $this->createSuccessResponse([
                'sites' => $sites,
                'count' => count($sites),
                'multisite' => count($sites) > 1,
            ])->toArray();
        } catch (\Exception $e) {
            return $this->createErrorResponse($e->getMessage())->toArray();
        }
    }

    /**
     * Show a specific site.
     */
    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function showSite(array $arguments): array
    {
        $this->validateRequiredArguments($arguments, ['handle']);

        try {
            $site = Site::get($arguments['handle']);

            if (! $site) {
                return $this->createErrorResponse('Site not found')->toArray();
            }

            return $this->createSuccessResponse([
                'site' => [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'url' => $site->url(),
                    'absolute_url' => $site->absoluteUrl(),
                    'locale' => $site->locale(),
                    'short_locale' => $site->shortLocale(),
                    'lang' => $site->lang(),
                    'direction' => $site->direction(),
                    'attributes' => $site->attributes(),
                ],
            ])->toArray();
        } catch (\Exception $e) {
            return $this->createErrorResponse($e->getMessage())->toArray();
        }
    }

    /**
     * Get the current site.
     */
    /**
     * @return array<string, mixed>
     */
    private function getCurrentSite(): array
    {
        try {
            $site = Site::current();

            return $this->createSuccessResponse([
                'site' => [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'url' => $site->url(),
                    'locale' => $site->locale(),
                    'lang' => $site->lang(),
                ],
            ])->toArray();
        } catch (\Exception $e) {
            return $this->createErrorResponse($e->getMessage())->toArray();
        }
    }

    /**
     * Get the default site.
     */
    /**
     * @return array<string, mixed>
     */
    private function getDefaultSite(): array
    {
        try {
            $site = Site::default();

            return $this->createSuccessResponse([
                'site' => [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'url' => $site->url(),
                    'locale' => $site->locale(),
                    'lang' => $site->lang(),
                ],
            ])->toArray();
        } catch (\Exception $e) {
            return $this->createErrorResponse($e->getMessage())->toArray();
        }
    }
}
