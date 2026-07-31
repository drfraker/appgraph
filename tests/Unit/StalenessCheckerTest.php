<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\StalenessChecker;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Support\ScanFingerprint;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;

class StalenessCheckerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/appgraph-staleness-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/app', 0775, true);
        mkdir($this->directory.'/tests', 0775, true);
        mkdir($this->directory.'/resources/js', 0775, true);
        file_put_contents($this->directory.'/app/Service.php', '<?php class Service {}');
        file_put_contents($this->directory.'/tests/ServiceTest.php', '<?php class ServiceTest {}');
        file_put_contents($this->directory.'/resources/js/app.ts', 'export const value = 1;');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_content_manifest_detects_changed_added_and_removed_inputs_without_mtime_reliance(): void
    {
        $files = new FileFinder($this->directory);
        $fingerprint = new ScanFingerprint($files);
        $recorded = $fingerprint->capture();
        $graphPath = $this->directory.'/appgraph.json';
        file_put_contents($graphPath, json_encode(['meta' => ['scan' => $recorded], 'nodes' => [], 'edges' => []]));

        $fresh = (new StalenessChecker($files, $fingerprint))->check($graphPath, $recorded);
        $this->assertFalse($fresh['stale']);
        $this->assertTrue($fresh['fingerprintMatches']);

        file_put_contents($this->directory.'/app/Service.php', '<?php class Service { public function changed() {} }');
        unlink($this->directory.'/tests/ServiceTest.php');
        file_put_contents($this->directory.'/resources/js/new.ts', 'export const added = true;');

        $stale = (new StalenessChecker($files, $fingerprint))->check($graphPath, $recorded);

        $this->assertTrue($stale['stale']);
        $this->assertFalse($stale['fingerprintMatches']);
        $this->assertSame(1, $stale['changedFiles']);
        $this->assertSame(1, $stale['addedFiles']);
        $this->assertSame(1, $stale['removedFiles']);
        $this->assertContains('app/Service.php', $stale['samplePaths']);
        $this->assertContains('resources/js/new.ts', $stale['samplePaths']);
        $this->assertContains('tests/ServiceTest.php', $stale['samplePaths']);
    }

    public function test_recorded_additional_scanner_inputs_remain_fresh_and_detect_changes(): void
    {
        $files = new FileFinder($this->directory);
        $fingerprint = new ScanFingerprint($files);
        $dumpDirectory = $this->directory.'/generated-outside-search-paths';
        $dump = $dumpDirectory.'/testing-default.sql';
        mkdir($dumpDirectory, 0775, true);
        file_put_contents($dump, 'CREATE TABLE notes (id INTEGER);');
        $recorded = $fingerprint->capture(additionalFiles: [
            'generated-outside-search-paths/testing-default.sql',
        ]);

        $this->assertSame(
            ['generated-outside-search-paths/testing-default.sql'],
            $recorded['additionalFiles'],
        );

        $checker = new StalenessChecker($files, $fingerprint);
        $fresh = $checker->check($this->directory.'/unused.json', $recorded);
        $this->assertFalse($fresh['stale']);
        $this->assertSame(0, $fresh['removedFiles']);

        file_put_contents($dump, 'CREATE TABLE notes (id INTEGER, title TEXT);');
        $changed = $checker->check($this->directory.'/unused.json', $recorded);
        $this->assertTrue($changed['stale']);
        $this->assertSame(1, $changed['changedFiles']);
    }

    public function test_live_schema_freshness_is_explicitly_unknown_after_the_scan_window(): void
    {
        $files = new FileFinder($this->directory);
        $fingerprint = new ScanFingerprint($files);
        $recorded = $fingerprint->capture();
        $recorded['databaseSchema'] = [
            'source' => 'live',
            'consistency' => 'matched_captured_before_after',
            'fingerprint' => str_repeat('a', 64),
        ];

        $freshness = (new StalenessChecker($files, $fingerprint))->check(
            $this->directory.'/unused.json',
            $recorded,
        );

        $this->assertFalse($freshness['stale']);
        $this->assertSame('unknown_after_scan', $freshness['databaseSchemaFreshness']);
        $this->assertTrue($freshness['databaseSchemaFreshnessUnknown']);
    }

    public function test_cross_process_runtime_evidence_is_unknown_without_making_fresh_source_stale(): void
    {
        $files = new FileFinder($this->directory);
        $container = new Container();
        $registry = new ContainerBindingRegistry($container, 'testing', 'App\\');
        $fingerprint = new ScanFingerprint($files, $registry);
        $recorded = $fingerprint->capture();
        $recorded['runtimeEvidenceSession'] = str_repeat('a', 32);
        $recorded['containerBindings'] = str_repeat('b', 64);
        $recorded['laravelExecutionRegistry'] = str_repeat('c', 64);
        $recorded['fingerprint'] = str_repeat('d', 64);
        $recorded['configuration'] = str_repeat('e', 64);
        $recorded['applicationEnvironment'] = 'fresh-child-environment';

        $freshness = (new StalenessChecker($files, $fingerprint))->check(
            $this->directory.'/unused.json',
            $recorded,
        );

        $this->assertFalse($freshness['stale']);
        $this->assertTrue($freshness['staticFingerprintMatches']);
        $this->assertFalse($freshness['runtimeEvidenceComparable']);
        $this->assertSame('unknown_cross_process', $freshness['runtimeEvidenceFreshness']);
        $this->assertSame(
            'unknown_cross_process',
            $freshness['effectiveConfigurationFreshness'],
        );
        $this->assertArrayNotHasKey('configurationChanged', $freshness);
        $this->assertArrayNotHasKey('environmentChanged', $freshness);
        $this->assertArrayNotHasKey('containerBindingsChanged', $freshness);
        $this->assertArrayNotHasKey('laravelExecutionRegistryChanged', $freshness);
    }

    public function test_all_php_configuration_files_are_manifest_inputs(): void
    {
        mkdir($this->directory.'/config', 0775, true);
        file_put_contents($this->directory.'/config/tenancy.php', '<?php return [\'central\' => true];');
        $files = new FileFinder($this->directory);
        $fingerprint = new ScanFingerprint($files);
        $recorded = $fingerprint->capture();

        $this->assertArrayHasKey('config/tenancy.php', $recorded['files']);

        file_put_contents($this->directory.'/config/tenancy.php', '<?php return [\'central\' => false];');
        $freshness = (new StalenessChecker($files, $fingerprint))->check(
            $this->directory.'/unused.json',
            $recorded,
        );

        $this->assertTrue($freshness['stale']);
        $this->assertSame(1, $freshness['changedFiles']);
    }

    public function test_lazy_deprecation_logging_configuration_is_not_a_graph_fingerprint_input(): void
    {
        $previousContainer = Container::getInstance();
        $container = new Container();
        $configuration = new Repository([
            'appgraph' => ['scan' => ['routes' => true]],
            'logging' => [
                'deprecations' => ['channel' => null, 'trace' => false],
                'channels' => ['single' => ['driver' => 'single']],
            ],
        ]);
        $container->instance('config', $configuration);
        Container::setInstance($container);

        try {
            $fingerprint = new ScanFingerprint(new FileFinder($this->directory));
            $before = $fingerprint->capture();

            $configuration->set('logging.deprecations.channel', 'deprecations');
            $configuration->set('logging.channels.deprecations', [
                'driver' => 'monolog',
                'handler' => 'Monolog\\Handler\\NullHandler',
            ]);
            $afterLaravelBootstrap = $fingerprint->capture();

            $this->assertSame($before['configuration'], $afterLaravelBootstrap['configuration']);
            $this->assertSame($before['fingerprint'], $afterLaravelBootstrap['fingerprint']);

            $configuration->set('appgraph.scan.routes', false);
            $graphConfigurationChanged = $fingerprint->capture();

            $this->assertNotSame($before['configuration'], $graphConfigurationChanged['configuration']);
            $this->assertNotSame($before['fingerprint'], $graphConfigurationChanged['fingerprint']);
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    public function test_runtime_container_binding_changes_invalidate_the_fingerprint(): void
    {
        $files = new FileFinder($this->directory);
        $container = new Container();
        $container->singleton('log', static fn () => new \stdClass());
        $container->alias('log', \stdClass::class);
        $registry = new ContainerBindingRegistry($container, 'testing', 'App\\');
        $fingerprint = new ScanFingerprint($files, $registry);
        $before = $fingerprint->capture();

        // PHP 8.5 causes Laravel's deprecation logger to materialize during
        // otherwise read-only command execution. That framework singleton is
        // not a graph input and must not create a fresh generation.
        $container->make('log');
        $loggerMaterialized = $fingerprint->capture();
        $this->assertSame($before['containerBindings'], $loggerMaterialized['containerBindings']);

        // Resolving an unrelated framework/package singleton varies with CLI
        // boot order and must not make every new process look stale.
        $container->instance('Illuminate\\UnrelatedRuntimeService', new \stdClass());
        $unrelated = $fingerprint->capture();
        $this->assertSame($before['containerBindings'], $unrelated['containerBindings']);

        // Framework services with string keys are also materialized lazily as
        // one Artisan process handles multiple commands.
        $container->instance('date', new \stdClass());
        $materialized = $fingerprint->capture();
        $this->assertSame($before['containerBindings'], $materialized['containerBindings']);

        $container->bind('runtime-service', 'App\\RuntimeService');
        $after = $fingerprint->capture();

        $this->assertNotSame($before['containerBindings'], $after['containerBindings']);
        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);

        $container->bind('log', 'Vendor\\RuntimeLogger');
        $vendorLogger = $fingerprint->capture();

        $this->assertNotSame($after['containerBindings'], $vendorLogger['containerBindings']);
        $this->assertNotSame($after['fingerprint'], $vendorLogger['fingerprint']);
    }

    public function test_booted_event_and_bus_registry_changes_invalidate_the_fingerprint_without_resolving_services(): void
    {
        $files = new FileFinder($this->directory);
        $container = new Container();
        $events = new EventDispatcher($container);
        $bus = new BusDispatcher($container);
        $container->instance('events', $events);
        $container->instance('Illuminate\\Contracts\\Bus\\Dispatcher', $bus);
        $registry = new ContainerBindingRegistry($container, 'testing', 'App\\');
        $fingerprint = new ScanFingerprint($files, $registry);
        $before = $fingerprint->capture();

        $events->listen('App\\Events\\Saved', 'App\\Listeners\\Notify@handle');
        $bus->map(['App\\Jobs\\Index' => 'App\\Handlers\\IndexHandler']);
        $after = $fingerprint->capture();

        $this->assertNotSame($before['laravelExecutionRegistry'], $after['laravelExecutionRegistry']);
        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
        $this->assertSame($before['containerBindings'], $after['containerBindings']);

        $graphPath = $this->directory.'/registry-graph.json';
        file_put_contents($graphPath, json_encode(['meta' => ['scan' => $before], 'nodes' => [], 'edges' => []]));
        $stale = (new StalenessChecker($files, $fingerprint))->check($graphPath, $before);

        $this->assertTrue($stale['stale']);
        $this->assertTrue($stale['laravelExecutionRegistryChanged']);
    }

    public function test_booted_router_changes_invalidate_the_execution_registry_fingerprint(): void
    {
        $files = new FileFinder($this->directory);
        $container = new Container();
        $events = new EventDispatcher($container);
        $router = new Router($events, $container);
        $container->instance('router', $router);
        $registry = new ContainerBindingRegistry($container, 'testing', 'App\\');
        $fingerprint = new ScanFingerprint($files, $registry);
        $before = $fingerprint->capture();

        $invoked = false;
        $serialized = false;
        $route = $router->get('/runtime-added', static function () use (&$invoked): string {
            $invoked = true;

            return 'ok';
        })->name('runtime-added')->middleware('auth');
        $route->setAction([
            ...$route->getAction(),
            'opaque' => new class($serialized)
            {
                public function __construct(private bool &$serialized)
                {
                }

                /** @return array<string, mixed> */
                public function __serialize(): array
                {
                    $this->serialized = true;

                    throw new \RuntimeException('Router fingerprinting must not serialize action objects.');
                }
            },
        ]);
        $after = $fingerprint->capture();

        $this->assertNotSame($before['laravelExecutionRegistry'], $after['laravelExecutionRegistry']);
        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
        $this->assertSame($before['containerBindings'], $after['containerBindings']);
        $this->assertFalse($invoked);
        $this->assertFalse($serialized);

        // LaravelIntrospection accepts a raw string `uses` action even when
        // getActionName() still reports "Closure". Hash the safe raw action so
        // that graph-relevant mutation cannot hide behind that display name.
        $route->setAction([
            ...$route->getAction(),
            'uses' => 'App\\Http\\Controllers\\RuntimeController@show',
        ]);
        $actionChanged = $fingerprint->capture();
        $this->assertNotSame($after['laravelExecutionRegistry'], $actionChanged['laravelExecutionRegistry']);

        $router->aliasMiddleware('auth', 'App\\Http\\Middleware\\Authenticate');
        $aliasChanged = $fingerprint->capture();

        $this->assertNotSame($actionChanged['laravelExecutionRegistry'], $aliasChanged['laravelExecutionRegistry']);
    }

    public function test_router_fingerprint_does_not_execute_application_route_collection_overrides(): void
    {
        $container = new Container();
        $events = new EventDispatcher($container);
        $router = new Router($events, $container);
        $collection = new class extends RouteCollection
        {
            public bool $enumerated = false;

            /** @return array<int, \Illuminate\Routing\Route> */
            public function getRoutes()
            {
                $this->enumerated = true;

                throw new \RuntimeException('Application collection override was executed.');
            }
        };
        (new \ReflectionClass(Router::class))->getProperty('routes')->setValue($router, $collection);
        $container->instance('router', $router);
        $registry = new ContainerBindingRegistry($container, 'testing', 'App\\');

        $before = $registry->executionRegistryFingerprint();
        $this->assertFalse($collection->enumerated);

        // Safe framework-owned middleware state is still represented even
        // when route enumeration itself has to remain unverified.
        $router->aliasMiddleware('auth', 'App\\Http\\Middleware\\Authenticate');
        $after = $registry->executionRegistryFingerprint();

        $this->assertFalse($collection->enumerated);
        $this->assertNotSame($before, $after);
    }

    public function test_aliases_and_string_key_vendor_targets_are_stable_fingerprint_inputs_without_execution(): void
    {
        $factoryInvoked = false;
        $container = new Container();
        $container->bind('vendor-sdk', 'Vendor\\One\\Client');
        $container->bind('unknown-factory', function () use (&$factoryInvoked): object {
            $factoryInvoked = true;

            return new \stdClass();
        });
        $container->alias('unknown-factory', 'unknown.alias');
        $registry = new ContainerBindingRegistry($container, 'testing', 'App\\');

        $first = $registry->fingerprint(true);
        $this->assertSame($first, $registry->fingerprint(true));

        $container->bind('vendor-sdk', 'Vendor\\Two\\Client');
        $targetChanged = $registry->fingerprint(true);
        $this->assertNotSame($first, $targetChanged);

        $container->alias('vendor-sdk', 'sdk.alias');
        $aliasAdded = $registry->fingerprint(true);
        $this->assertNotSame($targetChanged, $aliasAdded);
        $this->assertSame($aliasAdded, $registry->fingerprint(true));
        $this->assertFalse($factoryInvoked);
    }
}
