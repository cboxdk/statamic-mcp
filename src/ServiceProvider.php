<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp;

use Cboxdk\StatamicMcp\Auth\AuthServiceProvider;
use Cboxdk\StatamicMcp\Console\InstallCommand;
use Cboxdk\StatamicMcp\Console\MigrateStoreCommand;
use Cboxdk\StatamicMcp\Console\PruneAuditCommand;
use Cboxdk\StatamicMcp\Console\PruneExpiredTokensCommand;
use Cboxdk\StatamicMcp\Console\PruneOAuthCommand;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Http\Middleware\AuthenticateForMcp;
use Cboxdk\StatamicMcp\Http\Middleware\EnsureSecureTransport;
use Cboxdk\StatamicMcp\Http\Middleware\HandleMcpCors;
use Cboxdk\StatamicMcp\Http\Middleware\RequireMcpPermission;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Drivers\BuiltInOAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Drivers\DatabaseOAuthDriver;
use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    /** @phpstan-ignore property.phpDocType, property.defaultValue (Parent type is list<string> but registerVite() accepts associative arrays) */
    protected $vite = [
        'input' => ['resources/js/addon.js'],
        'publicDirectory' => 'resources/dist',
    ];

    public function bootAddon(): void
    {
        // Register views for OAuth consent screen
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'statamic-mcp');

        // Only load routes if MCP is available
        if (class_exists('Laravel\Mcp\Facades\Mcp')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/ai.php');

            // Register per-token rate limiter
            RateLimiter::for('mcp', function (Request $request) {
                /** @var int $maxAttempts */
                $maxAttempts = config('statamic.mcp.rate_limit.max_attempts', 60);

                // Use token ID if available, fall back to IP
                $mcpToken = $request->attributes->get('mcp_token');
                if ($mcpToken instanceof McpTokenData) {
                    return Limit::perMinute($maxAttempts)->by('mcp_token:' . $mcpToken->id);
                }

                return Limit::perMinute($maxAttempts)->by('mcp_ip:' . ($request->ip() ?? 'unknown'));
            });

            // Register web MCP endpoint if enabled
            $this->registerWebMcp();
        }

        // Register OAuth routes (independent of MCP availability)
        $this->registerOAuthRoutes();

        $this->publishes([
            __DIR__ . '/../config/statamic/mcp.php' => config_path('statamic/mcp.php'),
        ], 'statamic-mcp-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/tokens' => database_path('migrations'),
            __DIR__ . '/../database/migrations/audit' => database_path('migrations'),
        ], 'statamic-mcp-migrations');

        // Conditionally load migrations based on configured storage drivers
        $tokensDriver = config('statamic.mcp.stores.tokens');
        if (is_string($tokensDriver) && is_a($tokensDriver, DatabaseTokenStore::class, true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/tokens');
        }

        $auditDriver = config('statamic.mcp.stores.audit');
        if (is_string($auditDriver) && is_a($auditDriver, DatabaseAuditStore::class, true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/audit');
        }

        // Conditionally load OAuth migrations when database driver is configured
        $oauthDriver = config('statamic.mcp.oauth.driver');
        if (is_string($oauthDriver) && is_a($oauthDriver, DatabaseOAuthDriver::class, true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/oauth');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                MigrateStoreCommand::class,
                PruneExpiredTokensCommand::class,
                PruneAuditCommand::class,
                PruneOAuthCommand::class,
            ]);
        }

        $this->registerPermissions();
        $this->registerCpNavigation();
        $this->registerDashboardRoutes();
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

        // Register audit store from configured driver
        $this->app->singleton(AuditStore::class, function (Application $app): AuditStore {
            /** @var class-string<AuditStore> $driver */
            $driver = config('statamic.mcp.stores.audit', FileAuditStore::class);

            /** @var AuditStore $store */
            $store = $app->make($driver);

            return $store;
        });

        // Register OAuth driver
        $this->app->singleton(OAuthDriver::class, function (Application $app): OAuthDriver {
            /** @var class-string<OAuthDriver>|null $driver */
            $driver = config('statamic.mcp.oauth.driver');

            if (is_string($driver) && class_exists($driver)) {
                /** @var OAuthDriver $instance */
                $instance = $app->make($driver);

                return $instance;
            }

            /** @var OAuthDriver $instance */
            $instance = $app->make(BuiltInOAuthDriver::class);

            return $instance;
        });

        // Register auth services
        $this->app->register(AuthServiceProvider::class);
    }

    /**
     * Register hierarchical MCP permissions.
     */
    protected function registerPermissions(): void
    {
        Permission::group('mcp', 'MCP', function () {
            Permission::register('view mcp dashboard')
                ->label('View MCP Dashboard')
                ->description('Access the MCP dashboard and connection setup');

            Permission::register('manage mcp tokens', function ($permission) {
                $permission
                    ->label('Manage Own MCP Tokens')
                    ->description('Create, view, and revoke own MCP tokens')
                    ->children([
                        Permission::make('create mcp tokens')
                            ->label('Create MCP Tokens'),
                        Permission::make('revoke mcp tokens')
                            ->label('Revoke Own MCP Tokens'),
                    ]);
            });

            Permission::register('manage all mcp tokens', function ($permission) {
                $permission
                    ->label('Manage All MCP Tokens')
                    ->description('View and manage all users\' MCP tokens')
                    ->children([
                        Permission::make('view all mcp tokens')
                            ->label('View All MCP Tokens'),
                        Permission::make('revoke all mcp tokens')
                            ->label('Revoke All MCP Tokens'),
                    ]);
            });

            Permission::register('view mcp audit log')
                ->label('View MCP Audit Log')
                ->description('View MCP tool call activity and audit logs');

            Permission::register('manage mcp settings')
                ->label('Manage MCP Settings')
                ->description('Configure MCP addon settings');
        });
    }

    /**
     * Register the MCP navigation item in the Control Panel.
     */
    protected function registerCpNavigation(): void
    {
        /** @var bool $dashboardEnabled */
        $dashboardEnabled = config('statamic.mcp.dashboard.enabled', true);

        if (! $dashboardEnabled) {
            return;
        }

        Nav::extend(function ($nav): void {
            $nav->tools('MCP')
                ->route('statamic-mcp.dashboard')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>')
                ->can('view mcp dashboard')
                ->children([
                    /** @phpstan-ignore method.nonObject (Nav::item returns NavItem) */
                    Nav::item('Admin')
                        ->route('statamic-mcp.admin')
                        ->can('manage all mcp tokens'),
                ]);
        });
    }

    /**
     * Register Control Panel routes for the MCP dashboard.
     */
    protected function registerDashboardRoutes(): void
    {
        /** @var bool $dashboardEnabled */
        $dashboardEnabled = config('statamic.mcp.dashboard.enabled', true);

        if (! $dashboardEnabled) {
            return;
        }

        Statamic::pushCpRoutes(function (): void {
            Route::prefix('mcp')
                ->name('statamic-mcp.')
                ->group(__DIR__ . '/../routes/cp.php');
        });
    }

    /**
     * Register OAuth discovery and registration routes.
     */
    protected function registerOAuthRoutes(): void
    {
        if (! config('statamic.mcp.oauth.enabled', true)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/oauth.php');
    }

    /**
     * Register web MCP endpoint if enabled in configuration.
     */
    protected function registerWebMcp(): void
    {
        if (! config('statamic.mcp.web.enabled', false)) {
            return;
        }

        /** @var string $path */
        $path = config('statamic.mcp.web.path', '/mcp/statamic');

        // Register web MCP endpoint with security + auth middleware
        Mcp::web($path, StatamicMcpServer::class)
            ->middleware([
                HandleMcpCors::class,
                EnsureSecureTransport::class,
                AuthenticateForMcp::class,
                'throttle:mcp',
                RequireMcpPermission::class,
            ]);
    }
}
