<?php

namespace Cboxdk\StatamicMcp;

use Cboxdk\StatamicMcp\Console\InstallCommand;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function bootAddon(): void
    {
        // Only load routes if MCP is available
        if (class_exists('Laravel\Mcp\Facades\Mcp')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/ai.php');

            // Register web MCP endpoint if enabled
            $this->registerWebMcp();
        }

        $this->publishes([
            __DIR__ . '/../config/statamic/mcp.php' => config_path('statamic/mcp.php'),
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

        // Merge config file
        $this->mergeConfigFrom(
            __DIR__ . '/../config/statamic/mcp.php', 'statamic.mcp'
        );
    }

    /**
     * Register web MCP endpoint if enabled in configuration.
     */
    protected function registerWebMcp(): void
    {
        if (! config('statamic.mcp.web.enabled', false)) {
            return;
        }

        $path = config('statamic.mcp.web.path', '/mcp/statamic');
        $middleware = config('statamic.mcp.web.middleware', ['auth:statamic', 'throttle:60,1']);

        // Register web MCP endpoint with middleware
        \Laravel\Mcp\Facades\Mcp::web($path, StatamicMcpServer::class)
            ->middleware($middleware);
    }
}
