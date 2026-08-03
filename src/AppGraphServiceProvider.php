<?php

namespace AppGraph;

use AppGraph\Commands\InstallCommand;
use AppGraph\Commands\QueryCommand;
use AppGraph\Commands\ScanCommand;
use AppGraph\Query\StalenessChecker;
use AppGraph\Runtime\JsonRuntimeEvidenceStore;
use AppGraph\Runtime\RuntimeEvidenceRegistry;
use AppGraph\Scanners\TestScanner;
use AppGraph\Storage\GraphStore;
use AppGraph\Support\CallableSemanticRegistry;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Support\FreshScanRunner;
use AppGraph\Support\PhpFileFacts;
use AppGraph\Support\ScanFingerprint;
use AppGraph\Support\ScanLock;
use AppGraph\Support\ScanResultRegistry;
use AppGraph\Support\ScanRunner;
use AppGraph\Support\SourceFileObservations;
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
        $this->app->singleton(FileFinder::class, static fn (): FileFinder => new FileFinder());
        $this->app->singleton(ScanFingerprint::class, function ($app): ScanFingerprint {
            return new ScanFingerprint(
                $app->make(FileFinder::class),
                $app->make(ContainerBindingRegistry::class),
            );
        });
        $this->app->singleton(StalenessChecker::class, function ($app): StalenessChecker {
            return new StalenessChecker(
                $app->make(FileFinder::class),
                $app->make(ScanFingerprint::class),
            );
        });
        $this->app->singleton(SourceFileObservations::class);
        $this->app->singleton(JsonRuntimeEvidenceStore::class, function ($app): JsonRuntimeEvidenceStore {
            $configuredPath = $app['config']->get(
                'appgraph.runtime_evidence.path',
                'appgraph/runtime-evidence.json',
            );
            $path = is_string($configuredPath) && trim($configuredPath) !== ''
                ? trim($configuredPath)
                : 'appgraph/runtime-evidence.json';
            $path = $this->isAbsolutePath($path) ? $path : $app->storagePath($path);

            return new JsonRuntimeEvidenceStore($path, $app->basePath());
        });
        $this->app->singleton(PhpFileFacts::class, function ($app): PhpFileFacts {
            $persistent = (bool) $app['config']->get('appgraph.php_facts.persistent_cache', true);
            $configuredPath = $app['config']->get(
                'appgraph.php_facts.cache_path',
                'appgraph/cache/php-facts',
            );
            $cacheDirectory = null;

            if ($persistent && is_string($configuredPath) && trim($configuredPath) !== '') {
                $cacheDirectory = $this->isAbsolutePath($configuredPath)
                    ? $configuredPath
                    : $app->storagePath($configuredPath);
            }

            return new PhpFileFacts(
                cacheDirectory: $cacheDirectory,
                sourceObservations: $app->make(SourceFileObservations::class),
            );
        });
        $this->app->singleton(CallableSemanticRegistry::class, function ($app): CallableSemanticRegistry {
            $configuredCallables = $app['config']->get('appgraph.extensions.callables', []);

            return new CallableSemanticRegistry(
                is_array($configuredCallables) ? $configuredCallables : [],
            );
        });
        $this->app->bind(TestScanner::class, function ($app): TestScanner {
            return new TestScanner(
                $app->make(FileFinder::class),
                $app->make(PhpFileFacts::class),
                $app->make(CallableSemanticRegistry::class),
            );
        });
        $this->app->singleton(GraphStore::class, function ($app): GraphStore {
            $configuredPath = $app['config']->get('appgraph.store.path', 'appgraph/appgraph.sqlite');
            $path = is_string($configuredPath) && trim($configuredPath) !== ''
                ? trim($configuredPath)
                : 'appgraph/appgraph.sqlite';
            $path = $this->isAbsolutePath($path) ? $path : $app->storagePath($path);

            return new GraphStore(
                path: $path,
                retainedGenerations: (int) $app['config']->get('appgraph.store.retained_generations', 10),
                busyTimeoutMs: (int) $app['config']->get('appgraph.store.busy_timeout_ms', 5000),
                enableFts: (bool) $app['config']->get('appgraph.store.fts', true),
            );
        });
        $this->app->singleton(ScanLock::class, function ($app): ScanLock {
            $store = $app->make(GraphStore::class);

            return new ScanLock(
                dirname($store->path()).'/.scan.lock',
                (int) $app['config']->get('appgraph.store.lock_timeout_ms', 30000),
            );
        });
        $this->app->singleton(ScanResultRegistry::class);
        $this->app->singleton(ScanRunner::class, FreshScanRunner::class);
    }

    public function boot(): void
    {
        RuntimeEvidenceRegistry::armLaravel($this->app);

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

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
