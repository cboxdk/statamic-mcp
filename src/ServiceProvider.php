<?php

namespace Cboxdk\StatamicMcp;

use Cboxdk\StatamicMcp\Console\InstallCommand;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function bootAddon(): void
    {
        // Only load routes if MCP is available
        if (class_exists('Laravel\Mcp\Server\Facades\Mcp')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/ai.php');
        }

        $this->publishes([
            __DIR__ . '/../config/statamic_mcp.php' => config_path('statamic_mcp.php'),
        ], 'statamic-mcp-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }

        // MCP tools are now registered in StatamicMcpServer class
    }

    /**
     * Register any application services.
     */
    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(
            __DIR__ . '/../config/statamic_mcp.php', 'statamic_mcp'
        );
    }
}
