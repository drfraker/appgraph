<?php

namespace AppGraph;

use AppGraph\Commands\InstallCommand;
use AppGraph\Commands\QueryCommand;
use AppGraph\Commands\ScanCommand;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class AppGraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/appgraph.php', 'appgraph');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ScanCommand::class,
            QueryCommand::class,
            InstallCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/appgraph.php' => config_path('appgraph.php'),
        ], 'appgraph-config');

        // laravel/mcp is a hard dependency, so the server always registers
        // unless explicitly disabled via config.
        if (config('appgraph.mcp.enabled', true)) {
            Mcp::local('appgraph', \AppGraph\Mcp\AppGraphServer::class);
        }
    }
}
