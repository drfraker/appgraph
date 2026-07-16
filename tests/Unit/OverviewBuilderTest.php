<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Graph\OverviewBuilder;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class OverviewBuilderTest extends TestCase
{
    public function test_it_builds_when_the_global_container_is_not_a_laravel_application(): void
    {
        $previous = Container::getInstance();
        Container::setInstance(new Container);

        try {
            $overview = (new OverviewBuilder)->build(new Graph);
        } finally {
            Container::setInstance($previous);
        }

        $this->assertSame([], $overview['routes']);
    }

    public function test_it_builds_a_bounded_overview_projection(): void
    {
        $graph = new Graph([
            'appName' => 'Demo',
            'appgraphVersion' => '0.2.0',
        ]);

        $graph->addNode(Node::make('route:PUT:/notes/{note}', 'route', 'PUT /notes/{note}', [
            'metadata' => ['name' => 'notes.update', 'uri' => '/notes/{note}'],
        ]));
        $graph->addNode(Node::make('App\\Http\\Controllers\\NoteController::update', 'method', 'NoteController::update'));
        $graph->addEdge(new Edge('route:PUT:/notes/{note}', 'App\\Http\\Controllers\\NoteController::update', 'routes_to'));

        // The controller method touches one model and two tables.
        $graph->addEdge(new Edge('App\\Http\\Controllers\\NoteController::update', 'App\\Models\\Note', 'uses_model'));
        $graph->addEdge(new Edge('App\\Http\\Controllers\\NoteController::update', 'table:notes', 'writes'));
        $graph->addEdge(new Edge('App\\Http\\Controllers\\NoteController::update', 'table:note_images', 'reads'));

        // The model resolves to a table and declares a relationship.
        $graph->addNode(Node::make('App\\Models\\Note', 'model', 'Note', ['metadata' => ['table' => 'notes']]));
        $graph->addEdge(new Edge('App\\Models\\Note', 'table:notes', 'uses_table'));
        $graph->addEdge(new Edge('App\\Models\\Note', 'App\\Models\\NoteImage', 'has_many'));

        // The controller dispatches an event with one listener.
        $graph->addEdge(new Edge('App\\Http\\Controllers\\NoteController::update', 'App\\Events\\NoteSaved', 'dispatches'));
        $graph->addEdge(new Edge('App\\Listeners\\SendNotification::handle', 'App\\Events\\NoteSaved', 'listens_to'));

        $overview = (new OverviewBuilder())->build($graph);

        $this->assertSame('0.2.0', $overview['meta']['appgraphVersion']);
        $this->assertSame('Demo', $overview['meta']['appName']);
        $this->assertSame([
            'maxDepth' => 6,
            'maxMethods' => 32,
            'maxRelatedFacts' => 1024,
            'maxItemsPerGroup' => 12,
            'maxTransitions' => 5000,
        ], $overview['meta']['routeTraversal']);

        $this->assertSame('notes', $overview['models']['App\\Models\\Note']);

        $this->assertSame(
            [['from' => 'App\\Models\\Note', 'to' => 'App\\Models\\NoteImage', 'type' => 'has_many']],
            $overview['relationships']
        );

        $this->assertSame(['method' => 1, 'model' => 1, 'route' => 1], $overview['counts']['byNodeType']);

        $route = $overview['routes'][0];
        $this->assertSame('PUT /notes/{note}', $route['route']);
        $this->assertSame('notes.update', $route['name']);
        $this->assertSame('App\\Http\\Controllers\\NoteController::update', $route['action']);
        $this->assertSame(['App\\Models\\Note'], $route['models']);
        $this->assertSame(['note_images', 'notes'], $route['tables']); // sorted

        $this->assertSame(
            ['App\\Events\\NoteSaved' => ['dispatchSites' => 1, 'listeners' => 1]],
            $overview['events']
        );
    }

    public function test_route_summaries_cross_calls_and_active_event_and_job_handlers(): void
    {
        $overview = (new OverviewBuilder())->build($this->causalRouteGraph());
        $route = $overview['routes'][0];

        $this->assertSame(['App\\Models\\Note'], $route['models']);
        $this->assertSame(['audit_logs', 'notes', 'search_documents'], $route['tables']);
        $this->assertSame(
            ['App\\Events\\NoteSaved', 'App\\Jobs\\IndexNote'],
            $route['dispatches'],
        );
        $this->assertNotContains('secret_records', $route['tables']);
        $this->assertArrayNotHasKey('truncated', $route);
    }

    public function test_route_summary_depth_is_bounded_across_execution_bridges(): void
    {
        $overview = (new OverviewBuilder(routeDepth: 4))->build($this->causalRouteGraph());
        $route = $overview['routes'][0];

        $this->assertContains('audit_logs', $route['tables']);
        $this->assertContains('notes', $route['tables']);
        $this->assertNotContains('search_documents', $route['tables']);
        $this->assertTrue($route['truncated']);
        $this->assertSame(4, $overview['meta']['routeTraversal']['maxDepth']);
    }

    public function test_route_summary_keeps_the_strongest_items_and_marks_truncation(): void
    {
        $route = 'route:POST:/ranked';
        $action = 'App\\Http\\Controllers\\RankedController::store';
        $graph = new Graph();
        $graph->addNode(Node::make($route, 'route', 'POST /ranked'));
        $graph->addNode(Node::make($action, 'method', 'RankedController::store'));
        $graph->addNode(Node::make('App\\Models\\Alpha', 'model', 'Alpha'));
        $graph->addNode(Node::make('App\\Models\\Zulu', 'model', 'Zulu'));
        $graph->addNode(Node::make('App\\Events\\Alpha', 'event', 'Alpha'));
        $graph->addNode(Node::make('App\\Events\\Zulu', 'event', 'Zulu'));
        $graph->addEdge(new Edge($route, $action, 'routes_to'));
        $graph->addEdge(new Edge($action, 'App\\Models\\Alpha', 'uses_model', 0.4));
        $graph->addEdge(new Edge($action, 'App\\Models\\Zulu', 'uses_model', 0.9));
        $graph->addEdge(new Edge($action, 'table:alpha', 'writes', 0.4));
        $graph->addEdge(new Edge($action, 'table:zulu', 'writes', 0.9));
        $graph->addEdge(new Edge($action, 'App\\Events\\Alpha', 'dispatches', 0.4, ['kind' => 'event']));
        $graph->addEdge(new Edge($action, 'App\\Events\\Zulu', 'dispatches', 0.9, ['kind' => 'event']));

        $overview = (new OverviewBuilder(routeMethodLimit: 1, routeItemLimit: 1))->build($graph);
        $summary = $overview['routes'][0];

        $this->assertSame(['App\\Models\\Zulu'], $summary['models']);
        $this->assertSame(['zulu'], $summary['tables']);
        $this->assertSame(['App\\Events\\Zulu'], $summary['dispatches']);
        $this->assertTrue($summary['truncated']);
    }

    public function test_route_method_limit_bounds_cycles_and_marks_truncation(): void
    {
        $route = 'route:GET:/cycle';
        $action = 'App\\Http\\Controllers\\CycleController::show';
        $strong = 'App\\Services\\StrongPath::run';
        $weak = 'App\\Services\\WeakPath::run';
        $graph = new Graph();

        foreach ([$action, $strong, $weak] as $method) {
            $graph->addNode(Node::make($method, 'method', $method));
        }

        $graph->addNode(Node::make($route, 'route', 'GET /cycle'));
        $graph->addEdge(new Edge($route, $action, 'routes_to'));
        $graph->addEdge(new Edge($action, $strong, 'calls', 0.9));
        $graph->addEdge(new Edge($action, $weak, 'calls', 0.4));
        $graph->addEdge(new Edge($strong, $action, 'calls', 0.9));
        $graph->addEdge(new Edge($strong, 'table:strong', 'reads', 0.9));
        $graph->addEdge(new Edge($weak, 'table:weak', 'reads', 1.0));

        $overview = (new OverviewBuilder(routeDepth: 12, routeMethodLimit: 2))->build($graph);
        $summary = $overview['routes'][0];

        $this->assertSame(['strong'], $summary['tables']);
        $this->assertTrue($summary['truncated']);
    }

    public function test_route_transition_limit_bounds_work_and_marks_omitted_context(): void
    {
        $route = 'route:POST:/bounded';
        $action = 'App\\Http\\Controllers\\BoundedController::store';
        $service = 'App\\Services\\BoundedService::run';
        $writer = 'App\\Services\\BoundedWriter::write';
        $graph = new Graph();

        foreach ([$action, $service, $writer] as $method) {
            $graph->addNode(Node::make($method, 'method', $method));
        }

        $graph->addNode(Node::make($route, 'route', 'POST /bounded'));
        $graph->addEdge(new Edge($route, $action, 'routes_to'));
        $graph->addEdge(new Edge($action, $service, 'calls'));
        $graph->addEdge(new Edge($service, $writer, 'calls'));
        $graph->addEdge(new Edge($writer, 'table:bounded_records', 'writes'));

        $bounded = (new OverviewBuilder(routeTransitionLimit: 1))->build($graph)['routes'][0];
        $complete = (new OverviewBuilder(routeTransitionLimit: 2))->build($graph)['routes'][0];

        $this->assertArrayNotHasKey('tables', $bounded);
        $this->assertTrue($bounded['truncated']);
        $this->assertSame(['bounded_records'], $complete['tables']);
        $this->assertArrayNotHasKey('truncated', $complete);
    }

    public function test_route_summary_omits_dispatches_without_causal_execution_evidence(): void
    {
        $route = 'route:POST:/conditional';
        $action = 'App\\Http\\Controllers\\ConditionalController::store';
        $job = 'App\\Jobs\\ConditionalJob';
        $malformed = 'App\\Jobs\\MalformedJob';
        $ordinaryMethod = 'App\\Services\\NotADispatchTarget::run';
        $graph = new Graph();
        $graph->addNode(Node::make($route, 'route', 'POST /conditional'));
        $graph->addNode(Node::make($action, 'method', 'ConditionalController::store'));
        $graph->addNode(Node::make($job, 'job', 'ConditionalJob'));
        $graph->addNode(Node::make($malformed, 'job', 'MalformedJob'));
        $graph->addNode(Node::make($ordinaryMethod, 'method', 'NotADispatchTarget::run'));
        $graph->addEdge(new Edge($route, $action, 'routes_to'));
        $graph->addEdge(new Edge($action, $job, 'dispatches', 1.0, [
            'kind' => 'job',
            'causalExecutionProven' => false,
        ]));
        $graph->addEdge(new Edge($action, $malformed, 'dispatches', 1.0, [
            'kind' => 'job',
            'dispatchOccurrences' => [[]],
        ]));
        $graph->addEdge(new Edge($action, $ordinaryMethod, 'dispatches'));
        $graph->addEdge(new Edge($ordinaryMethod, 'table:secret_records', 'writes'));

        $summary = (new OverviewBuilder())->build($graph)['routes'][0];

        $this->assertArrayNotHasKey('dispatches', $summary);
        $this->assertNotContains('secret_records', $summary['tables'] ?? []);
        $this->assertArrayNotHasKey('truncated', $summary);
    }

    public function test_duplicate_route_actions_preserve_last_registration_and_expose_alternatives(): void
    {
        $route = 'route:PUT:/duplicate';
        $first = 'App\\Http\\Controllers\\FirstController::update';
        $middle = 'App\\Http\\Controllers\\MiddleController::update';
        $last = 'App\\Http\\Controllers\\LastController::update';
        $graph = new Graph();
        $graph->addNode(Node::make($route, 'route', 'PUT /duplicate'));
        $graph->addNode(Node::make($first, 'method', 'FirstController::update'));
        $graph->addNode(Node::make($middle, 'method', 'MiddleController::update'));
        $graph->addNode(Node::make($last, 'method', 'LastController::update'));
        $graph->addEdge(new Edge($route, $first, 'routes_to'));
        $graph->addEdge(new Edge($route, $middle, 'routes_to'));
        $graph->addEdge(new Edge($route, $last, 'routes_to'));
        $graph->addEdge(new Edge($first, 'table:first_records', 'writes'));
        $graph->addEdge(new Edge($last, 'table:last_records', 'writes'));

        $summary = (new OverviewBuilder(routeItemLimit: 1))->build($graph)['routes'][0];

        $this->assertSame($last, $summary['action']);
        $this->assertSame([$first], $summary['actionAlternatives']);
        $this->assertSame(['last_records'], $summary['tables']);
        $this->assertTrue($summary['truncated']);
    }

    public function test_routes_without_an_action_and_with_a_dangling_action_remain_honest(): void
    {
        $graph = new Graph();
        $graph->addNode(Node::make('route:GET:/closure', 'route', 'GET /closure'));
        $graph->addNode(Node::make('route:GET:/dangling', 'route', 'GET /dangling'));
        $graph->addEdge(new Edge('route:GET:/dangling', 'MissingController::show', 'routes_to'));
        $graph->addEdge(new Edge('MissingController::show', 'table:known_records', 'reads'));

        $routes = collect((new OverviewBuilder())->build($graph)['routes'])->keyBy('route');
        $closure = $routes['GET /closure'];
        $dangling = $routes['GET /dangling'];

        $this->assertArrayNotHasKey('action', $closure);
        $this->assertArrayNotHasKey('truncated', $closure);
        $this->assertSame('MissingController::show', $dangling['action']);
        $this->assertSame(['known_records'], $dangling['tables']);
        $this->assertTrue($dangling['unresolvedAction']);
        $this->assertTrue($dangling['truncated']);
    }

    public function test_effective_constructor_limits_are_clamped_and_exposed(): void
    {
        $maximums = (new OverviewBuilder(
            routeDepth: PHP_INT_MAX,
            routeMethodLimit: PHP_INT_MAX,
            routeItemLimit: PHP_INT_MAX,
            routeTransitionLimit: PHP_INT_MAX,
        ))->build(new Graph());
        $minimums = (new OverviewBuilder(
            routeDepth: -1,
            routeMethodLimit: 0,
            routeItemLimit: 0,
            routeTransitionLimit: 0,
        ))->build(new Graph());

        $this->assertSame([
            'maxDepth' => 12,
            'maxMethods' => 256,
            'maxRelatedFacts' => 1024,
            'maxItemsPerGroup' => 64,
            'maxTransitions' => 50000,
        ], $maximums['meta']['routeTraversal']);
        $this->assertSame([
            'maxDepth' => 0,
            'maxMethods' => 1,
            'maxRelatedFacts' => 1024,
            'maxItemsPerGroup' => 1,
            'maxTransitions' => 1,
        ], $minimums['meta']['routeTraversal']);
    }

    public function test_route_summary_is_deterministic_across_insertion_order(): void
    {
        $forward = (new OverviewBuilder())->build($this->causalRouteGraph());
        $reverse = (new OverviewBuilder())->build($this->causalRouteGraph(reverse: true));

        $this->assertSame(
            json_encode($forward, JSON_THROW_ON_ERROR),
            json_encode($reverse, JSON_THROW_ON_ERROR),
        );
    }

    private function causalRouteGraph(bool $reverse = false): Graph
    {
        $route = 'route:PUT:/notes/{note}';
        $action = 'App\\Http\\Controllers\\NoteController::update';
        $service = 'App\\Services\\NoteService::save';
        $event = 'App\\Events\\NoteSaved';
        $listener = 'App\\Listeners\\AuditNote::handle';
        $audit = 'App\\Services\\NoteAudit::record';
        $job = 'App\\Jobs\\IndexNote';
        $jobHandler = $job.'::handle';
        $inactive = 'App\\Listeners\\InactiveNote::handle';
        $nodes = [
            [$route, 'route', 'PUT /notes/{note}', ['metadata' => ['name' => 'notes.update']]],
            [$action, 'method', 'NoteController::update', []],
            [$service, 'method', 'NoteService::save', []],
            [$event, 'event', 'NoteSaved', []],
            [$listener, 'method', 'AuditNote::handle', []],
            [$audit, 'method', 'NoteAudit::record', []],
            [$job, 'job', 'IndexNote', []],
            [$jobHandler, 'method', 'IndexNote::handle', []],
            [$inactive, 'method', 'InactiveNote::handle', []],
            ['App\\Models\\Note', 'model', 'Note', []],
            ['table:notes', 'table', 'notes', []],
            ['table:audit_logs', 'table', 'audit_logs', []],
            ['table:search_documents', 'table', 'search_documents', []],
            ['table:secret_records', 'table', 'secret_records', []],
        ];
        $edges = [
            [$route, $action, 'routes_to', 1.0, []],
            [$action, $service, 'calls', 0.95, []],
            [$service, 'App\\Models\\Note', 'uses_model', 0.9, []],
            [$service, 'table:notes', 'writes', 0.9, []],
            [$service, $event, 'dispatches', 0.9, ['kind' => 'event']],
            [$event, $listener, 'handled_by', 0.9, ['kind' => 'listener', 'causalExecutionProven' => true]],
            [$listener, $event, 'listens_to', 0.9, []],
            [$listener, $audit, 'calls', 0.8, []],
            [$audit, 'table:audit_logs', 'writes', 0.9, []],
            [$listener, $job, 'dispatches', 0.85, ['kind' => 'job']],
            [$job, $jobHandler, 'handled_by', 0.9, ['kind' => 'job', 'causalExecutionProven' => true]],
            [$jobHandler, 'table:search_documents', 'writes', 0.9, []],
            [$jobHandler, $service, 'calls', 0.5, []],
            [$event, $inactive, 'handled_by', 1.0, ['kind' => 'listener', 'causalExecutionProven' => false]],
            [$inactive, 'table:secret_records', 'writes', 1.0, []],
        ];

        if ($reverse) {
            $nodes = array_reverse($nodes);
            $edges = array_reverse($edges);
        }

        $graph = new Graph(['appName' => 'Causal App']);

        foreach ($nodes as [$id, $type, $label, $attributes]) {
            $graph->addNode(Node::make($id, $type, $label, $attributes));
        }

        foreach ($edges as [$from, $to, $type, $confidence, $metadata]) {
            $graph->addEdge(new Edge($from, $to, $type, $confidence, $metadata));
        }

        return $graph;
    }
}
