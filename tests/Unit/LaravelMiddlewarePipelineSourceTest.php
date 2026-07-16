<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Support\LaravelMiddlewarePipeline;
use AppGraph\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Route;

class LaravelMiddlewarePipelineSourceTest extends TestCase
{
    private ClassLoader $loader;

    private string $directory;

    private string $namespace;

    private int $routeSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $token = 'T'.bin2hex(random_bytes(6));
        $this->directory = base_path('app/AppGraphMiddlewarePipeline/'.$token);
        $this->namespace = 'App\\AppGraphMiddlewarePipeline\\'.$token;
        mkdir($this->directory, 0775, true);

        $this->loader = new ClassLoader();
        $this->loader->addPsr4($this->namespace.'\\', $this->directory.'/');
        $this->loader->register(true);
    }

    protected function tearDown(): void
    {
        $this->loader->unregister();

        foreach (glob($this->directory.'/*.php') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
        @rmdir(dirname($this->directory));

        parent::tearDown();
    }

    public function test_it_recovers_static_and_attribute_middleware_from_an_unloaded_composer_controller(): void
    {
        $class = $this->namespace.'\\LiteralController';
        $this->write('LiteralController.php', str_replace('__NAMESPACE__', $this->namespace, <<<'PHP'
<?php

namespace __NAMESPACE__;

use Illuminate\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

#[MiddlewareAttribute('class-attribute', only: ['show'])]
final class LiteralController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'plain-static',
            new Middleware(['nested-one', 'nested-two'], only: ['show']),
            new Middleware('filtered-static', except: ['show']),
            \stdClass::class,
        ];
    }

    #[MiddlewareAttribute('method-attribute')]
    public function show(): void
    {
    }
}
PHP));

        $this->assertFalse(class_exists($class, false));
        $inspection = $this->inspect($class);

        $this->assertFalse(class_exists($class, false));
        $this->assertSame([
            'plain-static',
            'nested-one',
            'nested-two',
            \stdClass::class,
            'class-attribute',
            'method-attribute',
        ], $inspection['declared']);
        $this->assertNotContains(
            'controller_middleware_class_unloaded',
            array_column($inspection['diagnostics'], 'reason'),
        );
    }

    public function test_it_resolves_local_inheritance_and_trait_adaptations_without_loading_source(): void
    {
        $class = $this->namespace.'\\ComposedController';
        $this->write('ComposedController.php', str_replace('__NAMESPACE__', $this->namespace, <<<'PHP'
<?php

namespace __NAMESPACE__;

use Illuminate\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Illuminate\Routing\Controllers\HasMiddleware;

interface LocalMiddlewareContract extends HasMiddleware
{
}

trait LosingMiddleware
{
    public static function declarations(): array
    {
        return ['losing'];
    }
}

trait WinningMiddleware
{
    public static function declarations(): array
    {
        return ['winning'];
    }
}

#[MiddlewareAttribute('parent-class')]
abstract class ComposedParent implements LocalMiddlewareContract
{
    use LosingMiddleware, WinningMiddleware {
        WinningMiddleware::declarations insteadof LosingMiddleware;
        WinningMiddleware::declarations as public middleware;
        LosingMiddleware::declarations as private losingDeclarations;
    }

    #[MiddlewareAttribute('parent-method')]
    public function show(): void
    {
    }
}

#[MiddlewareAttribute('child-class')]
final class ComposedController extends ComposedParent
{
}
PHP));

        $inspection = $this->inspect($class);

        $this->assertFalse(class_exists($class, false));
        $this->assertSame([
            'winning',
            'parent-class',
            'child-class',
            'parent-method',
        ], $inspection['declared']);
        $this->assertNotContains(
            'controller_middleware_source_unavailable',
            array_column($inspection['diagnostics'], 'reason'),
        );
    }

    public function test_same_process_source_edits_replace_controller_middleware_facts(): void
    {
        $class = $this->namespace.'\\FreshController';
        $pipeline = app(LaravelMiddlewarePipeline::class);

        $this->writeFreshController('before');
        $before = $this->inspect($class, $pipeline);

        $this->writeFreshController('after');
        $after = $this->inspect($class, $pipeline);

        $this->assertFalse(class_exists($class, false));
        $this->assertSame(['before'], $before['declared']);
        $this->assertSame(['after'], $after['declared']);
    }

    public function test_dynamic_static_declarations_are_omitted_with_explicit_uncertainty(): void
    {
        $class = $this->namespace.'\\DynamicController';
        $this->write('DynamicController.php', str_replace('__NAMESPACE__', $this->namespace, <<<'PHP'
<?php

namespace __NAMESPACE__;

use Illuminate\Routing\Controllers\HasMiddleware;

final class DynamicController implements HasMiddleware
{
    public static function middleware(): array
    {
        return self::buildMiddleware();
    }

    private static function buildMiddleware(): array
    {
        return ['must-not-be-guessed'];
    }

    public function show(): void
    {
    }
}
PHP));

        $inspection = $this->inspect($class);

        $this->assertSame([], $inspection['declared']);
        $this->assertContains(
            'controller_middleware_return_not_literal',
            array_column($inspection['diagnostics'], 'reason'),
        );
    }

    public function test_source_recovery_has_a_deterministic_declaration_bound(): void
    {
        $class = $this->namespace.'\\BoundedController';
        $declarations = implode(",\n            ", array_map(
            static fn (int $index): string => "'middleware-{$index}'",
            range(1, 300),
        ));
        $source = str_replace(
            ['__NAMESPACE__', '__DECLARATIONS__'],
            [$this->namespace, $declarations],
            <<<'PHP'
<?php

namespace __NAMESPACE__;

use Illuminate\Routing\Controllers\HasMiddleware;

final class BoundedController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            __DECLARATIONS__
        ];
    }

    public function show(): void
    {
    }
}
PHP,
        );
        $this->write('BoundedController.php', $source);

        $inspection = $this->inspect($class);

        $this->assertCount(256, $inspection['declared']);
        $this->assertSame('middleware-1', $inspection['declared'][0]);
        $this->assertSame('middleware-256', $inspection['declared'][255]);
        $this->assertContains(
            'controller_middleware_declaration_limit',
            array_column($inspection['diagnostics'], 'reason'),
        );
    }

    public function test_unloaded_legacy_instance_middleware_remains_omitted_with_uncertainty(): void
    {
        $class = $this->namespace.'\\LegacyController';
        $this->write('LegacyController.php', str_replace('__NAMESPACE__', $this->namespace, <<<'PHP'
<?php

namespace __NAMESPACE__;

use Illuminate\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Illuminate\Routing\Controller;

#[MiddlewareAttribute('safe-attribute')]
final class LegacyController extends Controller
{
    public function __construct()
    {
        $this->middleware('instance-only');
    }

    public function show(): void
    {
    }
}
PHP));

        $inspection = $this->inspect($class);

        $this->assertFalse(class_exists($class, false));
        $this->assertSame(['safe-attribute'], $inspection['declared']);
        $this->assertContains(
            'legacy_controller_middleware_omitted',
            array_column($inspection['diagnostics'], 'reason'),
        );
    }

    public function test_composer_discovery_never_expands_source_inspection_outside_the_project(): void
    {
        $token = 'T'.bin2hex(random_bytes(6));
        $namespace = 'ExternalAppGraphMiddleware\\'.$token;
        $class = $namespace.'\\ExternalController';
        $directory = sys_get_temp_dir().'/appgraph-external-middleware-'.$token;
        mkdir($directory, 0775, true);
        $file = $directory.'/ExternalController.php';
        file_put_contents($file, str_replace('__NAMESPACE__', $namespace, <<<'PHP'
<?php

namespace __NAMESPACE__;

use Illuminate\Routing\Controllers\HasMiddleware;

final class ExternalController implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['outside-project'];
    }

    public function show(): void
    {
    }
}
PHP));
        $loader = new ClassLoader();
        $loader->addPsr4($namespace.'\\', $directory.'/');
        $loader->register(true);

        try {
            $inspection = $this->inspect($class);

            $this->assertFalse(class_exists($class, false));
            $this->assertSame([], $inspection['declared']);
            $this->assertContains(
                'controller_middleware_class_unloaded',
                array_column($inspection['diagnostics'], 'reason'),
            );
        } finally {
            $loader->unregister();
            @unlink($file);
            @rmdir($directory);
        }
    }

    /** @return array<string, mixed> */
    private function inspect(string $class, ?LaravelMiddlewarePipeline $pipeline = null): array
    {
        $route = Route::get(
            '/appgraph-source-middleware-'.$this->routeSequence++,
            [$class, 'show'],
        );

        return ($pipeline ?? app(LaravelMiddlewarePipeline::class))->inspect($route);
    }

    private function writeFreshController(string $middleware): void
    {
        $source = str_replace(
            ['__NAMESPACE__', '__MIDDLEWARE__'],
            [$this->namespace, $middleware],
            <<<'PHP'
<?php

namespace __NAMESPACE__;

use Illuminate\Routing\Controllers\HasMiddleware;

final class FreshController implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['__MIDDLEWARE__'];
    }

    public function show(): void
    {
    }
}
PHP,
        );

        $this->write('FreshController.php', $source);
    }

    private function write(string $file, string $source): void
    {
        file_put_contents($this->directory.'/'.$file, $source);
        clearstatcache(true, $this->directory.'/'.$file);
    }
}
