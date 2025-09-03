<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\System;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

#[Title('Statamic System Information')]
#[IsReadOnly]
class InfoSystemTool extends BaseStatamicTool
{
    use \Cboxdk\StatamicMcp\Mcp\Tools\Concerns\HasCommonSchemas;

    protected function getToolName(): string
    {
        return 'statamic.system.info';
    }

    protected function getToolDescription(): string
    {
        return 'Get comprehensive information about the Statamic installation, version, configuration, and environment';
    }

    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->boolean('include_config')
            ->description('Include configuration details')
            ->optional()
            ->boolean('include_environment')
            ->description('Include environment and server information')
            ->optional()
            ->boolean('include_cache')
            ->description('Include cache configuration and status')
            ->optional()
            ->boolean('include_collections')
            ->description('Include collections and sites information')
            ->optional();
    }

    /**
     * Handle the tool execution.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $includeConfig = $arguments['include_config'] ?? true;
        $includeEnvironment = $arguments['include_environment'] ?? true;
        $includeCache = $arguments['include_cache'] ?? true;
        $includeCollections = $arguments['include_collections'] ?? true;

        $systemInfo = [
            'statamic' => $this->getStatamicInfo(),
            'laravel' => $this->getLaravelInfo(),
            'storage' => $this->getStorageInfo(),
        ];

        if ($includeConfig) {
            $systemInfo['configuration'] = $this->getConfigurationInfo();
        }

        if ($includeEnvironment) {
            $systemInfo['environment'] = $this->getEnvironmentInfo();
        }

        if ($includeCache) {
            $systemInfo['cache'] = $this->getCacheInfo();
        }

        if ($includeCollections) {
            $systemInfo['content'] = $this->getContentInfo();
        }

        return $systemInfo;
    }

    /**
     * Get Statamic installation information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getStatamicInfo(): array
    {
        $info = [
            'installed' => class_exists('\Statamic\Statamic'),
            'version' => 'unknown',
            'edition' => 'unknown',
            'licensed' => false,
        ];

        try {
            if (class_exists('\Statamic\Statamic')) {
                $info['version'] = \Statamic\Statamic::version();
                $info['edition'] = \Statamic\Statamic::pro() ? 'Pro' : 'Solo';
                $info['licensed'] = true; // If we can call these methods, it's likely licensed

                // Additional Statamic info
                $info['api_enabled'] = config('statamic.api.enabled', false);
                $info['cp_enabled'] = config('statamic.cp.enabled', true);
                $info['live_preview'] = config('statamic.live_preview.enabled', true);
                $info['static_caching'] = config('statamic.static_caching.strategy', 'null') !== 'null';
            }
        } catch (\Exception $e) {
            $info['error'] = 'Could not access Statamic information: ' . $e->getMessage();
        }

        return $info;
    }

    /**
     * Get Laravel framework information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getLaravelInfo(): array
    {
        $info = [
            'version' => app()->version(),
            'environment' => app()->environment(),
            'debug' => config('app.debug', false),
            'timezone' => config('app.timezone', 'UTC'),
            'locale' => config('app.locale', 'en'),
        ];

        return $info;
    }

    /**
     * Get storage and data persistence information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getStorageInfo(): array
    {
        $info = [
            'content_driver' => 'file', // Default assumption
            'database_connection' => config('database.default'),
            'cache_driver' => config('cache.default'),
            'session_driver' => config('session.driver'),
            'queue_driver' => config('queue.default'),
        ];

        try {
            // Check for Runway or other database-driven content
            if (class_exists('\DoubleThreeDigital\Runway\Runway')) {
                $info['runway_installed'] = true;
                $info['content_driver'] = 'mixed'; // File + Database
            }

            // Check Statamic's stache (file-based content store)
            if (class_exists('\Statamic\Stache\Stache')) {
                $info['stache_enabled'] = true;
                $info['stache_watcher'] = config('statamic.stache.watcher', true);
            }

        } catch (\Exception $e) {
            $info['storage_error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Get configuration information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getConfigurationInfo(): array
    {
        $config = [];

        try {
            // Statamic-specific configuration
            $config['editions'] = [
                'solo_features' => [
                    'unlimited_fields' => true,
                    'unlimited_asset_containers' => true,
                    'unlimited_blueprints' => true,
                    'git_integration' => true,
                ],
                'pro_features' => [
                    'unlimited_users' => \Statamic\Statamic::pro(),
                    'user_groups_roles' => \Statamic\Statamic::pro(),
                    'multi_site' => \Statamic\Statamic::pro(),
                    'rest_api' => \Statamic\Statamic::pro(),
                    'graphql' => \Statamic\Statamic::pro(),
                    'forms' => \Statamic\Statamic::pro(),
                ],
            ];

            $config['features'] = [
                'control_panel' => config('statamic.cp.enabled', true),
                'api' => config('statamic.api.enabled', false),
                'graphql' => config('statamic.graphql.enabled', false),
                'live_preview' => config('statamic.live_preview.enabled', true),
                'revisions' => config('statamic.revisions.enabled', false),
                'static_caching' => config('statamic.static_caching.strategy', 'null') !== 'null',
                'search' => config('statamic.search.driver', 'local'),
            ];

            $config['locales'] = config('statamic.sites.sites', []);
            $config['asset_containers'] = config('statamic.assets.containers', []);

        } catch (\Exception $e) {
            $config['error'] = 'Could not access configuration: ' . $e->getMessage();
        }

        return $config;
    }

    /**
     * Get environment and server information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getEnvironmentInfo(): array
    {
        $info = [
            'php_version' => PHP_VERSION,
            'php_extensions' => [
                'gd' => extension_loaded('gd'),
                'imagick' => extension_loaded('imagick'),
                'fileinfo' => extension_loaded('fileinfo'),
                'mbstring' => extension_loaded('mbstring'),
                'openssl' => extension_loaded('openssl'),
                'curl' => extension_loaded('curl'),
                'zip' => extension_loaded('zip'),
                'xml' => extension_loaded('xml'),
                'json' => extension_loaded('json'),
            ],
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];

        return $info;
    }

    /**
     * Get cache configuration and status.
     */
    /**
     * @return array<string, mixed>
     */
    private function getCacheInfo(): array
    {
        $info = [
            'default_driver' => config('cache.default'),
            'stores' => config('cache.stores', []),
            'statamic_cache' => [
                'stache' => config('statamic.stache.stores', []),
                'static_caching' => [
                    'strategy' => config('statamic.static_caching.strategy', 'null'),
                    'enabled' => config('statamic.static_caching.strategy', 'null') !== 'null',
                ],
                'image_cache' => config('statamic.assets.image_manipulation.cache', true),
            ],
        ];

        // Try to get cache status
        try {
            $info['cache_working'] = \Cache::store()->put('statamic_mcp_test', 'test', 1);
        } catch (\Exception $e) {
            $info['cache_error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Get content and sites information.
     */
    /**
     * @return array<string, mixed>
     */
    private function getContentInfo(): array
    {
        $info = [
            'sites' => [],
            'collections' => [],
            'taxonomies' => [],
            'asset_containers' => [],
            'navigation' => [],
            'forms' => [],
            'globals' => [],
        ];

        try {
            // Multi-site information
            if (class_exists('\Statamic\Facades\Site')) {
                $info['sites'] = \Statamic\Facades\Site::all()->map(function ($site) {
                    return [
                        'handle' => $site->handle(),
                        'name' => $site->name(),
                        'url' => $site->url(),
                        'locale' => $site->locale(),
                        'default' => $site->default(),
                    ];
                })->toArray();

                $info['multi_site'] = count($info['sites']) > 1;
            }

            // Collections information
            if (class_exists('\Statamic\Facades\Collection')) {
                $info['collections'] = \Statamic\Facades\Collection::all()->map(function ($collection) {
                    return [
                        'handle' => $collection->handle(),
                        'title' => $collection->title(),
                        'route' => $collection->route(),
                        'blueprints' => $collection->entryBlueprints()->map(fn ($item) => $item->handle())->toArray(),
                        'sites' => $collection->sites()->toArray(),
                        'dated' => $collection->dated(),
                        'orderable' => $collection->orderable(),
                    ];
                })->toArray();
            }

            // Taxonomies information
            if (class_exists('\Statamic\Facades\Taxonomy')) {
                $info['taxonomies'] = \Statamic\Facades\Taxonomy::all()->map(function ($taxonomy) {
                    return [
                        'handle' => $taxonomy->handle(),
                        'title' => $taxonomy->title(),
                        'sites' => $taxonomy->sites()->toArray(),
                    ];
                })->toArray();
            }

            // Asset containers information
            if (class_exists('\Statamic\Facades\AssetContainer')) {
                $info['asset_containers'] = \Statamic\Facades\AssetContainer::all()->map(function ($container) {
                    return [
                        'handle' => $container->handle(),
                        'title' => $container->title(),
                        'disk' => $container->disk(),
                        'path' => $container->path(),
                        'private' => $container->private(),
                    ];
                })->toArray();
            }

            // Navigation information
            if (class_exists('\Statamic\Facades\Nav')) {
                $info['navigation'] = \Statamic\Facades\Nav::all()->map(function ($nav) {
                    return [
                        'handle' => $nav->handle(),
                        'title' => $nav->title(),
                        'sites' => $nav->sites(),
                    ];
                })->toArray();
            }

            // Forms information
            if (class_exists('\Statamic\Facades\Form')) {
                $info['forms'] = \Statamic\Facades\Form::all()->map(function ($form) {
                    return [
                        'handle' => $form->handle(),
                        'title' => $form->title(),
                        'honeypot' => $form->honeypot(),
                        'store' => $form->store(),
                    ];
                })->toArray();
            }

            // Globals information
            if (class_exists('\Statamic\Facades\GlobalSet')) {
                $info['globals'] = \Statamic\Facades\GlobalSet::all()->map(function ($global) {
                    return [
                        'handle' => $global->handle(),
                        'title' => $global->title(),
                        'sites' => $global->sites(),
                    ];
                })->toArray();
            }

        } catch (\Exception $e) {
            $info['content_error'] = 'Could not access content information: ' . $e->getMessage();
        }

        return $info;
    }
}
