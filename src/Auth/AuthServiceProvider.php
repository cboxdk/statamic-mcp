<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Services\ClientConfigGenerator;
use Cboxdk\StatamicMcp\Services\StatsService;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind TokenStore contract to configured driver
        $this->app->singleton(TokenStore::class, function (Application $app): TokenStore {
            /** @var class-string<TokenStore> $driver */
            $driver = config('statamic.mcp.stores.tokens', FileTokenStore::class);

            /** @var TokenStore $store */
            $store = $app->make($driver);

            return $store;
        });

        $this->app->singleton(TokenService::class, function (Application $app): TokenService {
            /** @var TokenStore $store */
            $store = $app->make(TokenStore::class);

            return new TokenService($store);
        });
        $this->app->singleton(StatsService::class);
        $this->app->singleton(ClientConfigGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('mcp', function (Application $app, string $name, array $config): McpTokenGuard {
            /** @var TokenService $tokenService */
            $tokenService = $app->make(TokenService::class);

            /** @var Request $request */
            $request = $app->make('request');

            return new McpTokenGuard($tokenService, $request);
        });
    }
}
