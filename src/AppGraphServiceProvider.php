<?php

namespace AppGraph;

use AppGraph\Commands\InstallCommand;
use AppGraph\Commands\QueryCommand;
use AppGraph\Commands\ScanCommand;
use AppGraph\Support\ContainerBindingRegistry;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Throwable;

class AppGraphServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/appgraph.php', 'appgraph');
        $this->app->singleton(ContainerBindingRegistry::class, function ($app): ContainerBindingRegistry {
            $namespace = null;

            try {
                $namespace = method_exists($app, 'getNamespace') ? $app->getNamespace() : null;
            } catch (Throwable) {
                // Namespace discovery is optional; known graph nodes still make
                // application bindings relevant without failing the scan.
            }

            return new ContainerBindingRegistry(
                $app,
                static fn (): string => (string) $app->environment(),
                $namespace,
            );
        });
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
