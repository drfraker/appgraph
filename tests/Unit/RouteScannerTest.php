<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\RouteScanner;
use AppGraph\Tests\Fixtures\ProgressNote;
use AppGraph\Tests\Fixtures\ProgressNoteController;
use AppGraph\Tests\Fixtures\UpdateProgressNoteRequest;
use AppGraph\Tests\TestCase;
use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Attributes\Controllers\Middleware as MiddlewareAttribute;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware as ControllerMiddleware;
use Illuminate\Support\Facades\Route;

class RouteScannerTest extends TestCase
{
    public function test_it_scans_routes_and_controller_methods(): void
    {
        Route::put('/progress-notes/{note}', [ProgressNoteController::class, 'update'])
            ->name('progress-notes.update');

        $graph = new Graph();

        app(RouteScanner::class)->scan($graph);

        $array = $graph->toArray();

        $routeId = 'route:PUT:/progress-notes/{note}';
        $methodId = ProgressNoteController::class.'::update';

        $this->assertGraphHasNode($array, $routeId, 'route');
        $this->assertGraphHasNode($array, $methodId, 'method');
        $this->assertGraphHasNode($array, UpdateProgressNoteRequest::class, 'form_request');
        $this->assertGraphHasNode($array, ProgressNote::class, 'model');
        $this->assertGraphHasEdge($array, $routeId, $methodId, 'routes_to');
        $this->assertGraphHasEdge($array, $methodId, UpdateProgressNoteRequest::class, 'validates_with');
        $this->assertGraphHasEdge($array, $methodId, ProgressNote::class, 'uses_model');

        $method = $this->graphNode($array, $methodId);

        $this->assertSame('update(UpdateProgressNoteRequest $request, ProgressNote $note): array', $method['signature']);
        $this->assertSame('ProgressNoteController', $method['class']);
        $this->assertSame('update', $method['method']);
    }

    public function test_route_identity_includes_the_normalized_domain(): void
    {
        Route::domain('API.Example.COM')->get('/shared', [BoundRouteController::class, 'show']);
        Route::domain('Admin.Example.COM')->get('/shared', [OverrideRouteController::class, 'show']);
        Route::get('/shared', [BoundRouteController::class, 'show']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();
        $api = 'route:GET://api.example.com/shared';
        $admin = 'route:GET://admin.example.com/shared';
        $domainless = 'route:GET:/shared';

        $this->assertGraphHasNode($array, $api, 'route');
        $this->assertGraphHasNode($array, $admin, 'route');
        $this->assertGraphHasNode($array, $domainless, 'route');
        $this->assertGraphHasEdge($array, $api, BoundRouteController::class.'::show', 'routes_to');
        $this->assertGraphHasEdge($array, $admin, OverrideRouteController::class.'::show', 'routes_to');
        $this->assertSame('api.example.com', $this->graphNode($array, $api)['metadata']['domain']);
        $this->assertSame('//api.example.com/shared', $this->graphNode($array, $api)['metadata']['endpoint']);
        $this->assertSame('GET api.example.com/shared', $this->graphNode($array, $api)['label']);
    }

    public function test_same_process_rescan_reads_current_controller_source_instead_of_stale_reflection(): void
    {
        $directory = base_path('app/Http/Controllers');
        $file = $directory.'/AppGraphFreshRouteController.php';
        $class = 'App\\Http\\Controllers\\AppGraphFreshRouteController';
        @mkdir($directory, 0777, true);

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Controllers;

final class AppGraphFreshRouteController extends \Illuminate\Routing\Controller
{
    public function show(\AppGraph\Tests\Fixtures\UpdateProgressNoteRequest $request): array
    {
        return [];
    }
}
PHP);

        require_once $file;

        try {
            Route::get('/fresh-controller-source', [$class, 'show']);
            $scanner = app(RouteScanner::class);
            $before = new Graph();
            $scanner->scan($before);

            $methodId = $class.'::show';
            $beforeArray = $before->toArray();
            $this->assertSame(
                'show(UpdateProgressNoteRequest $request): array',
                $this->graphNode($beforeArray, $methodId)['signature'],
            );
            $this->assertGraphHasEdge(
                $beforeArray,
                $methodId,
                UpdateProgressNoteRequest::class,
                'validates_with',
            );

            file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Controllers;

final class AppGraphFreshRouteController extends \Illuminate\Routing\Controller
{
    public function show(int $after): int
    {
        return $after;
    }
}
PHP);

            $after = new Graph();
            $scanner->scan($after);
            $afterArray = $after->toArray();
            $method = $this->graphNode($afterArray, $methodId);

            $this->assertSame('show(int $after): int', $method['signature']);
            $this->assertSame('after', $method['inputs'][0]['name']);
            $this->assertSame('int', $method['inputs'][0]['type']);
            $this->assertNull($this->graphEdge(
                $afterArray,
                $methodId,
                UpdateProgressNoteRequest::class,
                'validates_with',
            ));

            file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Controllers;

final class AppGraphFreshRouteController extends \Illuminate\Routing\Controller
{
    public function replacement(): int
    {
        return 1;
    }
}
PHP);

            $removed = new Graph();
            $scanner->scan($removed);
            $removedArray = $removed->toArray();

            $this->assertNull($this->graphEdge(
                $removedArray,
                'route:GET:/fresh-controller-source',
                $methodId,
                'routes_to',
            ));
            $this->assertContains(
                'controller_method_not_found',
                array_column($removedArray['meta']['warnings'] ?? [], 'reason'),
            );
        } finally {
            @unlink($file);
        }
    }

    public function test_same_process_rescan_reads_current_middleware_entry_method_source(): void
    {
        $directory = base_path('app/Http/Middleware');
        $file = $directory.'/AppGraphFreshMiddleware.php';
        $class = 'App\\Http\\Middleware\\AppGraphFreshMiddleware';
        @mkdir($directory, 0777, true);

        file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Middleware;

final class AppGraphFreshMiddleware extends \AppGraph\Tests\Unit\ExternalMiddlewareParent
{
    public function handle(mixed $request, \Closure $next): mixed
    {
        return $next($request);
    }
}
PHP);

        require_once $file;

        try {
            app('router')->aliasMiddleware('fresh-source', $class);
            Route::get('/fresh-middleware-source', static fn (): string => 'ok')
                ->middleware('fresh-source');
            $scanner = app(RouteScanner::class);
            $before = new Graph();
            $scanner->scan($before);
            $routeId = 'route:GET:/fresh-middleware-source';

            $this->assertGraphHasEdge($before->toArray(), $routeId, $class.'::handle', 'passes_through');

            file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Middleware;

final class AppGraphFreshMiddleware extends \AppGraph\Tests\Unit\ExternalMiddlewareParent
{
    public function __invoke(mixed $request, \Closure $next, string $mode): mixed
    {
        return $next($request);
    }
}
PHP);

            $after = new Graph();
            $scanner->scan($after);
            $afterArray = $after->toArray();
            $methodId = $class.'::__invoke';

            $this->assertGraphHasEdge($afterArray, $routeId, $methodId, 'passes_through');
            $this->assertNull($this->graphEdge($afterArray, $routeId, $class.'::handle', 'passes_through'));
            $this->assertSame(
                '__invoke(mixed $request, Closure $next, string $mode): mixed',
                $this->graphNode($afterArray, $methodId)['signature'],
            );

            file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Middleware;

final class AppGraphFreshMiddleware extends \AppGraph\Tests\Unit\ExternalMiddlewareParent
{
}
PHP);

            $removed = new Graph();
            $scanner->scan($removed);
            $removedArray = $removed->toArray();

            $this->assertNull($this->graphEdge(
                $removedArray,
                $routeId,
                $class.'::handle',
                'passes_through',
            ));
            $this->assertNull($this->graphEdge(
                $removedArray,
                $routeId,
                $class.'::__invoke',
                'passes_through',
            ));
        } finally {
            @unlink($file);
        }
    }

    public function test_it_resolves_controller_and_middleware_methods_composed_from_nested_traits(): void
    {
        $directory = base_path('app/Http/Controllers');
        $file = $directory.'/AppGraphTraitRouteController.php';
        $controller = 'App\\Http\\Controllers\\AppGraphTraitRouteController';
        $middleware = 'App\\Http\\Controllers\\AppGraphTraitAliasMiddleware';
        $controllerTrait = 'App\\Http\\Controllers\\AppGraphNestedControllerAction';
        $middlewareTrait = 'App\\Http\\Controllers\\AppGraphNestedMiddlewareAction';
        @mkdir($directory, 0777, true);
        file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Controllers;

trait AppGraphNestedControllerAction
{
    public function show(): array
    {
        return [];
    }
}

trait AppGraphComposedControllerAction
{
    use AppGraphNestedControllerAction;
}

final class AppGraphTraitRouteController
{
    use AppGraphComposedControllerAction;
}

trait AppGraphNestedMiddlewareAction
{
    protected function process(mixed $request, \Closure $next): mixed
    {
        return $next($request);
    }
}

trait AppGraphComposedMiddlewareAction
{
    use AppGraphNestedMiddlewareAction;
}

final class AppGraphTraitAliasMiddleware
{
    use AppGraphComposedMiddlewareAction {
        process as public handle;
    }
}
PHP);
        require_once $file;

        try {
            app('router')->aliasMiddleware('trait-agent', $middleware);
            Route::get('/trait-controller', [$controller, 'SHOW'])
                ->middleware('trait-agent');
            $graph = new Graph();
            app(RouteScanner::class)->scan($graph);
            $array = $graph->toArray();
            $routeId = 'route:GET:/trait-controller';

            $this->assertGraphHasEdge($array, $routeId, $controllerTrait.'::show', 'routes_to');
            $this->assertSame(
                'SHOW',
                $this->graphEdge(
                    $array,
                    $routeId,
                    $controllerTrait.'::show',
                    'routes_to',
                )['metadata']['requestedMethod'],
            );
            $this->assertGraphHasEdge($array, $routeId, $middlewareTrait.'::process', 'passes_through');
            $middlewareEdge = $this->graphEdge(
                $array,
                $routeId,
                $middlewareTrait.'::process',
                'passes_through',
            );
            $occurrence = array_values($middlewareEdge['metadata']['occurrences'])[0];

            $this->assertSame('handle', $occurrence['invocation_method']);
            $this->assertSame($middlewareTrait, $occurrence['declaring_class']);
            $this->assertSame('handle', $occurrence['composed_as']);
            $this->assertSame('public', $occurrence['composed_visibility']);
            $this->assertSame('trait', $this->graphNode($array, $middlewareTrait)['type']);
            $this->assertSame(
                'protected',
                $this->graphNode($array, $middlewareTrait.'::process')['metadata']['visibility'],
            );
        } finally {
            @unlink($file);
        }
    }

    public function test_route_action_method_lookup_is_case_insensitive_but_emits_source_casing(): void
    {
        Route::get('/uppercase-action', [BoundRouteController::class, 'SHOW']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();
        $routeId = 'route:GET:/uppercase-action';
        $methodId = BoundRouteController::class.'::show';

        $this->assertGraphHasEdge($array, $routeId, $methodId, 'routes_to');
        $this->assertNull($this->graphEdge(
            $array,
            $routeId,
            BoundRouteController::class.'::SHOW',
            'routes_to',
        ));
        $this->assertSame('show', $this->graphNode($array, $methodId)['method']);
        $this->assertSame(
            'SHOW',
            $this->graphEdge($array, $routeId, $methodId, 'routes_to')['metadata']['requestedMethod'],
        );
    }

    public function test_local_controller_can_fall_back_to_reflection_at_an_external_parent_boundary(): void
    {
        $directory = base_path('app/Http/Controllers');
        $file = $directory.'/AppGraphExternalParentController.php';
        $class = 'App\\Http\\Controllers\\AppGraphExternalParentController';
        @mkdir($directory, 0777, true);
        file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Controllers;

final class AppGraphExternalParentController extends \Illuminate\Routing\Controller
{
}
PHP);
        require_once $file;

        try {
            Route::get('/external-parent-action', [$class, 'getMiddleware']);
            $graph = new Graph();
            app(RouteScanner::class)->scan($graph);

            $this->assertGraphHasEdge(
                $graph->toArray(),
                'route:GET:/external-parent-action',
                Controller::class.'::getMiddleware',
                'routes_to',
            );
        } finally {
            @unlink($file);
        }
    }

    public function test_inherited_handle_remains_preferred_over_local_invokable_middleware(): void
    {
        $directory = base_path('app/Http/Middleware');
        $file = $directory.'/AppGraphInheritedHandleMiddleware.php';
        $class = 'App\\Http\\Middleware\\AppGraphInheritedHandleMiddleware';
        @mkdir($directory, 0777, true);
        file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Middleware;

final class AppGraphInheritedHandleMiddleware extends \AppGraph\Tests\Unit\ExternalHandleMiddlewareParent
{
    public function __invoke(mixed $request, \Closure $next): mixed
    {
        return $next($request);
    }
}
PHP);
        require_once $file;

        try {
            app('router')->aliasMiddleware('inherited-handle-agent', $class);
            Route::get('/inherited-handle-middleware', static fn (): string => 'ok')
                ->middleware('inherited-handle-agent');
            $graph = new Graph();
            app(RouteScanner::class)->scan($graph);
            $array = $graph->toArray();
            $routeId = 'route:GET:/inherited-handle-middleware';

            $this->assertGraphHasEdge(
                $array,
                $routeId,
                ExternalHandleMiddlewareParent::class.'::handle',
                'passes_through',
            );
            $this->assertNull($this->graphEdge(
                $array,
                $routeId,
                $class.'::__invoke',
                'passes_through',
            ));
        } finally {
            @unlink($file);
        }
    }

    public function test_unloaded_composer_discoverable_project_middleware_is_read_from_source(): void
    {
        $directory = base_path('app/Http/Middleware');
        $file = $directory.'/ProjectMiddleware.php';
        $class = 'AppGraphUnloaded\\ProjectMiddleware';
        $loader = new ClassLoader();
        $loader->addPsr4('AppGraphUnloaded\\', $directory.'/');
        $loader->register(true);
        @mkdir($directory, 0777, true);
        file_put_contents($file, <<<'PHP'
<?php

namespace AppGraphUnloaded;

final class ProjectMiddleware
{
    public function handle(mixed $request, \Closure $next): mixed
    {
        return $next($request);
    }
}
PHP);

        try {
            $this->assertFalse(class_exists($class, false));
            app('router')->aliasMiddleware('unloaded-source-agent', $class);
            Route::get('/unloaded-source-middleware', static fn (): string => 'ok')
                ->middleware('unloaded-source-agent');
            $graph = new Graph();
            app(RouteScanner::class)->scan($graph);

            $this->assertFalse(class_exists($class, false));
            $this->assertGraphHasEdge(
                $graph->toArray(),
                'route:GET:/unloaded-source-middleware',
                $class.'::handle',
                'passes_through',
            );
        } finally {
            $loader->unregister();
            @unlink($file);
        }
    }

    public function test_non_public_and_abstract_controller_actions_are_not_claimed_executable(): void
    {
        Route::get('/protected-action', [ProtectedRouteController::class, 'show']);
        Route::get('/abstract-action', [AbstractRouteController::class, 'show']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();

        $this->assertNull($this->graphEdge(
            $array,
            'route:GET:/protected-action',
            ProtectedRouteController::class.'::show',
            'routes_to',
        ));
        $this->assertNull($this->graphEdge(
            $array,
            'route:GET:/abstract-action',
            AbstractRouteController::class.'::show',
            'routes_to',
        ));
        $reasons = array_column($array['meta']['warnings'] ?? [], 'reason');
        $this->assertContains('controller_method_not_public', $reasons);
        $this->assertContains('controller_class_not_concrete', $reasons);
    }

    public function test_missing_and_nonautoloadable_actions_are_suppressed_but_public_magic_actions_are_explicit(): void
    {
        $missingClass = 'AppGraph\\Tests\\Fixtures\\DefinitelyMissingRouteController';
        $directory = base_path('app/Http/Controllers');
        $file = $directory.'/AppGraphDynamicRouteController.php';
        $dynamicClass = 'App\\Http\\Controllers\\AppGraphDynamicRouteController';
        @mkdir($directory, 0777, true);
        file_put_contents($file, <<<'PHP'
<?php

namespace App\Http\Controllers;

final class AppGraphDynamicRouteController
{
    public function __call(string $method, array $parameters): array
    {
        return [];
    }
}
PHP);
        require_once $file;

        try {
            Route::get('/missing-action', [BoundRouteController::class, 'absent']);
            Route::get('/dynamic-action', [$dynamicClass, 'virtualAction']);
            Route::get('/nonautoloadable-action', [$missingClass, 'show']);

            $graph = new Graph();
            app(RouteScanner::class)->scan($graph);
            $array = $graph->toArray();

            $this->assertNull($this->graphEdge(
                $array,
                'route:GET:/missing-action',
                BoundRouteController::class.'::absent',
                'routes_to',
            ));
            $this->assertNull($this->graphEdge(
                $array,
                'route:GET:/nonautoloadable-action',
                $missingClass.'::show',
                'routes_to',
            ));
            $dynamic = $this->graphEdge(
                $array,
                'route:GET:/dynamic-action',
                $dynamicClass.'::virtualAction',
                'routes_to',
            );

            $this->assertNotNull($dynamic);
            $this->assertSame(0.5, $dynamic['confidence']);
            $this->assertTrue($dynamic['metadata']['dynamicAction']);
            $this->assertSame(
                'controller_dynamic_action_via_magic_call',
                $this->graphNode($array, $dynamicClass.'::virtualAction')['metadata']['reason'],
            );
        } finally {
            @unlink($file);
        }
    }

    public function test_optional_and_composite_controller_parameters_do_not_invent_container_dependencies(): void
    {
        Route::post('/optional-dependency', [DependencyShapeController::class, 'optional']);
        Route::post('/composite-dependency', [DependencyShapeController::class, 'composite']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();
        $optional = DependencyShapeController::class.'::optional';
        $composite = DependencyShapeController::class.'::composite';

        $this->assertNull($this->graphEdge($array, $optional, RequestedRouteRequest::class, 'validates_with'));
        $this->assertNull($this->graphEdge($array, $composite, RequestedRouteRequest::class, 'validates_with'));
        $this->assertNull($this->graphEdge($array, $composite, ProgressNote::class, 'uses_model'));
        $reasons = array_column($array['meta']['warnings'] ?? [], 'reason');
        $this->assertContains('optional_typed_parameter_uses_default', $reasons);
        $this->assertContains('composite_typed_parameter_not_container_resolved', $reasons);
    }

    public function test_it_bridges_the_resolved_middleware_pipeline_to_executable_methods(): void
    {
        MiddlewarePipelineController::$middlewareCalls = 0;
        $router = app('router');
        $router->aliasMiddleware('inherited-agent', InheritedAgentMiddleware::class);
        $router->aliasMiddleware('terminal-agent', TerminalAgentMiddleware::class);
        $router->aliasMiddleware('bound-agent', BoundAgentMiddlewareContract::class);
        $router->aliasMiddleware('factory-agent', FactoryAgentMiddlewareContract::class);
        $router->aliasMiddleware('missing-agent', 'AppGraph\\Tests\\Fixtures\\MissingAgentMiddleware');
        $router->aliasMiddleware('closure-agent', static fn (mixed $request, Closure $next): mixed => $next($request));
        $router->middlewareGroup('agent-stack', [
            'inherited-agent:route-first',
            'terminal-agent',
            'inherited-agent:route-second,extra',
        ]);
        app()->bind(BoundAgentMiddlewareContract::class, BoundAgentMiddleware::class);
        app()->bind(FactoryAgentMiddlewareContract::class, static function (): FactoryAgentMiddleware {
            throw new \RuntimeException('Middleware factories must not execute during graph scans.');
        });

        Route::get('/middleware-pipeline', [MiddlewarePipelineController::class, 'show'])
            ->middleware(['agent-stack', 'bound-agent', 'missing-agent', 'closure-agent', 'factory-agent']);

        $graph = new Graph();

        app(RouteScanner::class)->scan($graph);

        $array = $graph->toArray();
        $routeId = 'route:GET:/middleware-pipeline';
        $inheritedMethodId = AgentMiddlewareBase::class.'::handle';
        $terminalMethodId = TerminalAgentMiddleware::class.'::handle';

        $this->assertIsString(json_encode($array, JSON_THROW_ON_ERROR));
        $this->assertGraphHasEdge($array, $routeId, $inheritedMethodId, 'passes_through');
        $this->assertGraphHasEdge($array, $routeId, $terminalMethodId, 'passes_through');
        $this->assertGraphHasNode($array, InheritedAgentMiddleware::class, 'middleware');
        $this->assertGraphHasNode($array, AgentMiddlewareBase::class, 'middleware');
        $this->assertGraphHasNode($array, $inheritedMethodId, 'method');

        $route = $this->graphNode($array, $routeId);

        $this->assertSame(
            ['agent-stack', 'bound-agent', 'missing-agent', 'closure-agent', 'factory-agent', 'inherited-agent:controller'],
            $route['metadata']['middleware'],
        );
        $this->assertCount(8, $route['metadata']['resolved_middleware']);
        $this->assertGraphHasEdge($array, $routeId, BoundAgentMiddleware::class.'::handle', 'passes_through');
        $this->assertSame(0, MiddlewarePipelineController::$middlewareCalls);

        $boundEdge = $this->graphEdge($array, $routeId, BoundAgentMiddleware::class.'::handle', 'passes_through');
        $boundOccurrence = array_values($boundEdge['metadata']['occurrences'])[0];
        $this->assertSame(BoundAgentMiddlewareContract::class, $boundOccurrence['requested_class']);
        $this->assertSame('laravel_class_string_wrapper', $boundOccurrence['container_binding']['inference']);

        $factoryEdge = $this->graphEdge($array, $routeId, FactoryAgentMiddleware::class.'::handle', 'passes_through');
        $factoryOccurrence = array_values($factoryEdge['metadata']['occurrences'])[0];
        $this->assertSame(0.9, $factoryEdge['confidence']);
        $this->assertSame(0.9, $factoryOccurrence['confidence']);
        $this->assertSame('factory_declared_return_type', $factoryOccurrence['container_binding']['inference']);

        $edge = $this->graphEdge($array, $routeId, $inheritedMethodId, 'passes_through');
        $occurrences = array_values($edge['metadata']['occurrences']);

        $this->assertCount(3, $occurrences);
        $this->assertSame(
            [['route-first'], ['route-second', 'extra'], ['controller']],
            array_column($occurrences, 'parameters'),
        );
        $this->assertSame(['route', 'route', 'controller'], array_column($occurrences, 'scope'));
        $this->assertSame(['agent-stack', 'agent-stack', 'inherited-agent'], array_column($occurrences, 'declared_alias'));
        $this->assertSame(['inherited-agent', 'inherited-agent', 'inherited-agent'], array_column($occurrences, 'expanded_alias'));
        $this->assertSame([0, 2, 7], array_column($occurrences, 'position'));
        $this->assertSame(AgentMiddlewareBase::class, $occurrences[0]['declaring_class']);

        $warnings = $array['meta']['warnings'] ?? [];
        $reasons = array_column($warnings, 'reason');

        $this->assertContains('middleware_class_unloaded_or_unindexed', $reasons);
        $this->assertContains('middleware_closure', $reasons);
        $this->assertNull($this->graphEdge(
            $array,
            $routeId,
            'AppGraph\\Tests\\Fixtures\\MissingAgentMiddleware::handle',
            'passes_through',
        ));
    }

    public function test_it_recovers_literal_controller_middleware_and_exact_attributes_without_execution(): void
    {
        SafelyDescribedController::$middlewareCalls = 0;
        $router = app('router');
        $router->aliasMiddleware('inherited-agent', InheritedAgentMiddleware::class);
        $router->aliasMiddleware('terminal-agent', TerminalAgentMiddleware::class);

        Route::get('/safe-controller-middleware', [SafelyDescribedController::class, 'show']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();
        $routeId = 'route:GET:/safe-controller-middleware';

        $this->assertSame(0, SafelyDescribedController::$middlewareCalls);
        $this->assertGraphHasEdge(
            $array,
            $routeId,
            AgentMiddlewareBase::class.'::handle',
            'passes_through',
        );
        $this->assertGraphHasEdge(
            $array,
            $routeId,
            TerminalAgentMiddleware::class.'::handle',
            'passes_through',
        );
        $this->assertGraphHasEdge(
            $array,
            $routeId,
            BoundAgentMiddleware::class.'::handle',
            'passes_through',
        );

        $inherited = $this->graphEdge(
            $array,
            $routeId,
            AgentMiddlewareBase::class.'::handle',
            'passes_through',
        );
        $terminal = $this->graphEdge(
            $array,
            $routeId,
            TerminalAgentMiddleware::class.'::handle',
            'passes_through',
        );

        $this->assertSame(
            [['static']],
            array_column(array_values($inherited['metadata']['occurrences']), 'parameters'),
        );
        $this->assertSame(
            [['class'], ['method']],
            array_column(array_values($terminal['metadata']['occurrences']), 'parameters'),
        );
        $this->assertSame(
            ['controller', 'controller'],
            array_column(array_values($terminal['metadata']['occurrences']), 'scope'),
        );
        $this->assertNotContains(
            ['excluded'],
            array_column(array_values($terminal['metadata']['occurrences']), 'parameters'),
        );
    }

    public function test_it_reuses_a_precomputed_route_middleware_cache_without_reinvoking_the_controller(): void
    {
        MiddlewarePipelineController::$middlewareCalls = 0;
        app('router')->aliasMiddleware('inherited-agent', InheritedAgentMiddleware::class);
        $route = Route::get('/cached-controller-middleware', [MiddlewarePipelineController::class, 'show']);

        $route->gatherMiddleware();
        $this->assertSame(1, MiddlewarePipelineController::$middlewareCalls);
        MiddlewarePipelineController::$middlewareCalls = 0;

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();

        $this->assertSame(0, MiddlewarePipelineController::$middlewareCalls);
        $this->assertGraphHasEdge(
            $array,
            'route:GET:/cached-controller-middleware',
            AgentMiddlewareBase::class.'::handle',
            'passes_through',
        );
    }

    public function test_it_never_constructs_a_bound_legacy_controller_to_discover_middleware(): void
    {
        BoundLegacyController::$factoryCalls = 0;
        BoundLegacyController::$constructorCalls = 0;
        app()->bind(BoundLegacyController::class, static function () {
            BoundLegacyController::$factoryCalls++;

            return new BoundLegacyController();
        });
        Route::get('/legacy-controller-middleware', [BoundLegacyController::class, 'show']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();

        $this->assertSame(0, BoundLegacyController::$factoryCalls);
        $this->assertSame(0, BoundLegacyController::$constructorCalls);
        $this->assertContains(
            'legacy_controller_middleware_omitted',
            array_column($array['meta']['warnings'] ?? [], 'reason'),
        );
    }

    public function test_unloaded_middleware_is_not_autoloaded_for_resolution_or_reflection(): void
    {
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            if (str_starts_with($class, 'LazyMiddlewareSafety\\')) {
                $autoloaded[] = $class;
            }
        };
        spl_autoload_register($loader, true, true);

        try {
            app('router')->aliasMiddleware('lazy-agent', 'LazyMiddlewareSafety\\AgentMiddleware');
            Route::get('/lazy-middleware', [BoundRouteController::class, 'show'])
                ->middleware('lazy-agent');

            $graph = new Graph();
            app(RouteScanner::class)->scan($graph);
            $array = $graph->toArray();

            $this->assertSame([], $autoloaded);
            $this->assertNull($this->graphEdge(
                $array,
                'route:GET:/lazy-middleware',
                'LazyMiddlewareSafety\\AgentMiddleware::handle',
                'passes_through',
            ));
            $this->assertContains(
                'middleware_class_unloaded_or_unindexed',
                array_column($array['meta']['warnings'] ?? [], 'reason'),
            );
            $this->assertContains(
                'middleware_priority_unresolved',
                array_column($array['meta']['warnings'] ?? [], 'reason'),
            );
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function test_it_routes_to_the_container_resolved_controller_override(): void
    {
        app()->bind(BoundRouteController::class, OverrideRouteController::class);
        Route::get('/bound-controller', [BoundRouteController::class, 'show']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();
        $routeId = 'route:GET:/bound-controller';
        $target = OverrideRouteController::class.'::show';

        $this->assertGraphHasEdge($array, $routeId, $target, 'routes_to');
        $this->assertNull($this->graphEdge(
            $array,
            $routeId,
            BoundRouteController::class.'::show',
            'routes_to',
        ));

        $edge = $this->graphEdge($array, $routeId, $target, 'routes_to');
        $this->assertSame(BoundRouteController::class, $edge['metadata']['requestedController']);
        $this->assertSame(OverrideRouteController::class, $edge['metadata']['runtimeController']);
        $this->assertSame('laravel_class_string_wrapper', $edge['metadata']['container_binding']['inference']);
    }

    public function test_it_suppresses_an_unproven_controller_factory_target_without_running_it(): void
    {
        $factoryRan = false;
        app()->bind(BoundRouteController::class, function () use (&$factoryRan) {
            $factoryRan = true;

            return new OverrideRouteController();
        });
        Route::get('/unknown-controller', [BoundRouteController::class, 'show']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();
        $routeId = 'route:GET:/unknown-controller';

        $this->assertFalse($factoryRan);
        $this->assertNull($this->graphEdge(
            $array,
            $routeId,
            BoundRouteController::class.'::show',
            'routes_to',
        ));
        $this->assertContains(
            'controller_container_target_unknown',
            array_column($array['meta']['warnings'] ?? [], 'reason'),
        );
    }

    public function test_form_request_parameters_follow_concrete_container_bindings_and_suppress_unknown_or_instance_lifecycles(): void
    {
        $factoryRan = false;
        app()->bind(RequestedRouteRequest::class, RuntimeRouteRequest::class);
        app()->bind(UnknownRouteRequest::class, static function () use (&$factoryRan): object {
            $factoryRan = true;

            return new \stdClass();
        });
        app()->instance(ExistingRequestedRouteRequest::class, new ExistingRuntimeRouteRequest());

        Route::post('/bound-request', [FormRequestBindingController::class, 'bound']);
        Route::post('/shadowed-request/{request}', [FormRequestBindingController::class, 'shadowed']);
        Route::post('/unknown-request', [FormRequestBindingController::class, 'unknown']);
        Route::post('/existing-request', [FormRequestBindingController::class, 'existing']);

        $graph = new Graph();
        app(RouteScanner::class)->scan($graph);
        $array = $graph->toArray();
        $boundMethod = FormRequestBindingController::class.'::bound';
        $unknownMethod = FormRequestBindingController::class.'::unknown';
        $existingMethod = FormRequestBindingController::class.'::existing';
        $shadowedMethod = FormRequestBindingController::class.'::shadowed';

        $this->assertFalse($factoryRan);
        $this->assertGraphHasEdge($array, $boundMethod, RuntimeRouteRequest::class, 'validates_with');
        $this->assertNull($this->graphEdge($array, $boundMethod, RequestedRouteRequest::class, 'validates_with'));
        $this->assertGraphHasEdge($array, RequestedRouteRequest::class, RuntimeRouteRequest::class, 'resolves_to');
        $bound = $this->graphEdge($array, $boundMethod, RuntimeRouteRequest::class, 'validates_with');
        $this->assertSame(RequestedRouteRequest::class, $bound['metadata']['requestedRequest']);
        $this->assertSame(RuntimeRouteRequest::class, $bound['metadata']['runtimeRequest']);
        $this->assertNull($this->graphEdge($array, $shadowedMethod, RequestedRouteRequest::class, 'validates_with'));
        $this->assertGraphHasEdge($array, $shadowedMethod, RuntimeRouteRequest::class, 'validates_with');
        $this->assertTrue($this->graphEdge(
            $array,
            $shadowedMethod,
            RuntimeRouteRequest::class,
            'validates_with',
        )['metadata']['sharesRouteParameterName']);

        $this->assertNull($this->graphEdge($array, $unknownMethod, UnknownRouteRequest::class, 'validates_with'));
        $this->assertNull($this->graphEdge($array, $existingMethod, ExistingRuntimeRouteRequest::class, 'validates_with'));
        $this->assertGraphHasEdge(
            $array,
            ExistingRequestedRouteRequest::class,
            ExistingRuntimeRouteRequest::class,
            'resolves_to',
        );
        $warningReasons = array_column($array['meta']['warnings'] ?? [], 'reason');
        $this->assertContains('form_request_container_target_unknown', $warningReasons);
        $this->assertContains('form_request_existing_instance_bypasses_lifecycle', $warningReasons);
        $this->assertNotContains('route_parameter_shadows_form_request', $warningReasons);
    }
}

class AgentMiddlewareBase
{
    public function handle(mixed $request, Closure $next, string ...$parameters): mixed
    {
        return $next($request);
    }
}

class ExternalMiddlewareParent
{
}

class ExternalHandleMiddlewareParent
{
    public function handle(mixed $request, Closure $next): mixed
    {
        return $next($request);
    }
}

class InheritedAgentMiddleware extends AgentMiddlewareBase
{
}

class TerminalAgentMiddleware
{
    public function handle(mixed $request, Closure $next): mixed
    {
        return $next($request);
    }
}

interface BoundAgentMiddlewareContract
{
}

class BoundAgentMiddleware implements BoundAgentMiddlewareContract
{
    public function handle(mixed $request, Closure $next): mixed
    {
        return $next($request);
    }
}

interface FactoryAgentMiddlewareContract
{
}

class FactoryAgentMiddleware implements FactoryAgentMiddlewareContract
{
    public function handle(mixed $request, Closure $next): mixed
    {
        return $next($request);
    }
}

class MiddlewarePipelineController implements HasMiddleware
{
    public static int $middlewareCalls = 0;

    public static function middleware(): array
    {
        self::$middlewareCalls++;

        return ['inherited-agent:controller'];
    }

    public function show(): array
    {
        return [];
    }
}

#[MiddlewareAttribute('terminal-agent:class')]
class SafelyDescribedController implements HasMiddleware
{
    public static int $middlewareCalls = 0;

    public static function middleware(): array
    {
        self::$middlewareCalls++;

        return [
            new ControllerMiddleware('inherited-agent:static', only: ['show']),
            new ControllerMiddleware('terminal-agent:excluded', except: ['show']),
            BoundAgentMiddleware::class,
        ];
    }

    #[MiddlewareAttribute('terminal-agent:method')]
    public function show(): array
    {
        return [];
    }
}

class BoundLegacyController extends Controller
{
    public static int $factoryCalls = 0;

    public static int $constructorCalls = 0;

    public function __construct()
    {
        self::$constructorCalls++;
        $this->middleware('terminal-agent');
    }

    public function show(): array
    {
        return [];
    }
}

class BoundRouteController
{
    public function show(): array
    {
        return [];
    }
}

class ProtectedRouteController
{
    protected function show(): array
    {
        return [];
    }
}

abstract class AbstractRouteController
{
    public function show(): array
    {
        return [];
    }
}

class DependencyShapeController
{
    public function optional(?RequestedRouteRequest $request = null): array
    {
        return [];
    }

    public function composite(RequestedRouteRequest|ProgressNote $dependency): array
    {
        return [];
    }
}

class OverrideRouteController extends BoundRouteController
{
    public function show(): array
    {
        return ['override'];
    }
}

class RequestedRouteRequest extends FormRequest
{
    public function rules(): array
    {
        return ['requested' => 'required'];
    }
}

class RuntimeRouteRequest extends RequestedRouteRequest
{
    public function rules(): array
    {
        return ['runtime' => 'required'];
    }
}

class UnknownRouteRequest extends FormRequest
{
}

class ExistingRequestedRouteRequest extends FormRequest
{
}

class ExistingRuntimeRouteRequest extends ExistingRequestedRouteRequest
{
}

class FormRequestBindingController
{
    public function bound(RequestedRouteRequest $request): array
    {
        return [];
    }

    public function unknown(UnknownRouteRequest $request): array
    {
        return [];
    }

    public function shadowed(RequestedRouteRequest $request): array
    {
        return [];
    }

    public function existing(ExistingRequestedRouteRequest $request): array
    {
        return [];
    }
}
