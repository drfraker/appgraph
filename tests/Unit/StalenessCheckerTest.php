<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\StalenessChecker;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\FileFinder;
use AppGraph\Support\ScanFingerprint;
use Illuminate\Container\Container;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
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

    public function test_runtime_container_binding_changes_invalidate_the_fingerprint(): void
    {
        $files = new FileFinder($this->directory);
        $container = new Container();
        $registry = new ContainerBindingRegistry($container, 'testing', 'App\\');
        $fingerprint = new ScanFingerprint($files, $registry);
        $before = $fingerprint->capture();

        // Resolving an unrelated framework/package singleton varies with CLI
        // boot order and must not make every new process look stale.
        $container->instance('Illuminate\\UnrelatedRuntimeService', new \stdClass());
        $unrelated = $fingerprint->capture();
        $this->assertSame($before['containerBindings'], $unrelated['containerBindings']);

        $container->bind('runtime-service', 'App\\RuntimeService');
        $after = $fingerprint->capture();

        $this->assertNotSame($before['containerBindings'], $after['containerBindings']);
        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
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
