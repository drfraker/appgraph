<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;
use AppGraph\Query\UnresolvedTargetException;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use AppGraph\Tests\TestCase;

class QueryEngineTest extends TestCase
{
    use BuildsQueryFixtureGraph;

    private function engine(): QueryEngine
    {
        return new QueryEngine(GraphIndex::fromArray($this->queryFixtureGraph()));
    }

    public function test_overview_reports_meta_and_counts(): void
    {
        $overview = $this->engine()->overview();

        $this->assertSame('overview', $overview['query']);
        $this->assertSame('Query Fixture App', $overview['meta']['appName']);
        $this->assertSame('2026-06-09T00:00:00.000000Z', $overview['generatedAt']);
        $this->assertIsInt($overview['graphAgeSeconds']);
        $this->assertSame(12, $overview['counts']['nodes']);
        $this->assertSame(12, $overview['counts']['edges']);
        $this->assertSame(3, $overview['counts']['byNodeType']['model']);
        $this->assertSame(3, $overview['counts']['byEdgeType']['calls']);
        $this->assertArrayNotHasKey('target', $overview);
    }

    public function test_overview_separates_unresolved_calls_from_framework_boundaries(): void
    {
        $graph = $this->queryFixtureGraph();
        $graph['meta']['analysis']['callResolution'] = [
            'unresolvedCount' => 1,
            'byReason' => ['receiver_type_unknown' => 1],
            'boundaryCount' => 4,
            'boundaryByReason' => ['framework_boundary' => 4],
        ];

        $overview = (new QueryEngine(GraphIndex::fromArray($graph)))->overview();

        $this->assertSame(1, $overview['analysis']['callResolution']['unresolvedCount']);
        $this->assertSame(4, $overview['analysis']['callResolution']['boundaryCount']);
        $this->assertSame(
            ['framework_boundary' => 4],
            $overview['analysis']['callResolution']['boundaryByReason']
        );
    }

    public function test_writes_to_resolves_tables_models_and_columns(): void
    {
        $engine = $this->engine();

        $byTable = $engine->writesTo('notes');

        $this->assertSame('table:notes', $byTable['table']);
        $this->assertSame('App\Services\NoteService::save', $byTable['results'][0]['id']);
        $this->assertSame(['create', 'save'], $byTable['results'][0]['ops']);
        $this->assertSame('app/Services/NoteService.php', $byTable['results'][0]['file']);
        $this->assertArrayNotHasKey('truncated', $byTable);

        $this->assertSame($byTable['results'], $engine->writesTo('App\Models\Note')['results']);

        $byColumn = $engine->writesTo('notes.title');
        $this->assertSame('column:notes.title', $byColumn['column']);
        $this->assertSame('title', $byColumn['field']);
        $this->assertSame('possible', $byColumn['results'][0]['match']);
        $this->assertSame(['create', 'save'], $byColumn['results'][0]['possibleOps']);
    }

    public function test_reads_from_lists_reader_methods(): void
    {
        $engine = $this->engine();
        $reads = $engine->readsFrom('notes');

        $this->assertSame(
            ['App\Http\Controllers\NoteController::index'],
            array_column($reads['results'], 'id')
        );
        $this->assertSame(['get'], $reads['results'][0]['ops']);

        $columnReads = $engine->readsFrom('notes.title');
        $this->assertSame('possible', $columnReads['results'][0]['match']);
        $this->assertSame(0, $columnReads['counts']['proven']);
        $this->assertSame(1, $columnReads['counts']['possible']);
    }

    public function test_column_access_separates_proven_possible_and_excluded_operations(): void
    {
        $graph = $this->queryFixtureGraph();

        foreach ($graph['edges'] as &$edge) {
            if ($edge['from'] === 'App\Services\NoteService::save' && $edge['type'] === 'writes') {
                $edge['metadata']['operations'] = [
                    '16:create:model_table' => [
                        'line' => 16,
                        'operation' => 'create',
                        'fields' => ['meta.signed_at', 'title'],
                        'fieldCoverage' => 'complete',
                    ],
                    '17:update:model_table' => [
                        'line' => 17,
                        'operation' => 'update',
                        'fields' => ['body'],
                        'fieldCoverage' => 'complete',
                    ],
                    '18:save:model_table' => [
                        'line' => 18,
                        'operation' => 'save',
                        'fieldCoverage' => 'unknown',
                    ],
                ];
            }
        }
        unset($edge);

        $graph['nodes'][] = [
            'id' => 'App\Services\BodyService::save',
            'type' => 'method',
            'label' => 'BodyService::save',
            'file' => 'app/Services/BodyService.php',
            'line' => 10,
        ];
        $graph['nodes'][] = ['id' => 'column:notes.meta', 'type' => 'column', 'label' => 'notes.meta'];
        $graph['edges'][] = [
            'from' => 'table:notes',
            'to' => 'column:notes.meta',
            'type' => 'has_column',
            'confidence' => 1.0,
        ];
        $graph['edges'][] = [
            'from' => 'App\Services\BodyService::save',
            'to' => 'table:notes',
            'type' => 'writes',
            'confidence' => 0.9,
            'metadata' => ['operations' => [
                '11:update:model_table' => [
                    'line' => 11,
                    'operation' => 'update',
                    'fields' => ['body'],
                    'fieldCoverage' => 'complete',
                ],
            ]],
        ];

        $result = (new QueryEngine(GraphIndex::fromArray($graph)))->writesTo('notes.title');

        $this->assertSame(['proven' => 1, 'possible' => 0, 'excluded' => 1], $result['counts']);
        $this->assertSame('App\Services\NoteService::save', $result['matches']['proven'][0]['id']);
        $this->assertSame(['create'], $result['matches']['proven'][0]['provenOps']);
        $this->assertSame(['save'], $result['matches']['proven'][0]['possibleOps']);
        $this->assertSame(['update'], $result['matches']['proven'][0]['excludedOps']);
        $this->assertSame('App\Services\BodyService::save', $result['matches']['excluded'][0]['id']);
        $this->assertSame([], $result['matches']['possible']);

        $nested = (new QueryEngine(GraphIndex::fromArray($graph)))->writesTo('notes.meta');
        $this->assertSame('proven', $nested['results'][0]['match']);
        $this->assertSame(['create'], $nested['results'][0]['provenOps']);
    }

    public function test_column_access_treats_legacy_coverage_as_unknown_and_whole_rows_as_proven(): void
    {
        $graph = $this->queryFixtureGraph();
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => 'App\\Services\\LegacyWriter::save', 'type' => 'method', 'label' => 'LegacyWriter::save'],
            ['id' => 'App\\Services\\RowDeleter::delete', 'type' => 'method', 'label' => 'RowDeleter::delete'],
            ['id' => 'App\\Services\\WildcardReader::read', 'type' => 'method', 'label' => 'WildcardReader::read'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            [
                'from' => 'App\\Services\\LegacyWriter::save',
                'to' => 'table:notes',
                'type' => 'writes',
                'confidence' => 1.0,
                'metadata' => ['operations' => [[
                    'operation' => 'update',
                    'fields' => ['body'],
                ]]],
            ],
            [
                'from' => 'App\\Services\\RowDeleter::delete',
                'to' => 'table:notes',
                'type' => 'writes',
                'confidence' => 1.0,
                'metadata' => ['operations' => [[
                    'operation' => 'delete',
                    'fields' => [],
                    'fieldCoverage' => 'whole_row',
                ]]],
            ],
            [
                'from' => 'App\\Services\\WildcardReader::read',
                'to' => 'table:notes',
                'type' => 'reads',
                'confidence' => 1.0,
                'metadata' => ['operations' => [[
                    'operation' => 'get',
                    'fields' => ['*'],
                    'fieldCoverage' => 'whole_row',
                ]]],
            ],
        ];
        $engine = new QueryEngine(GraphIndex::fromArray($graph));

        $writes = $engine->writesTo('notes.title');
        $this->assertSame('proven', collect($writes['results'])->firstWhere('id', 'App\\Services\\RowDeleter::delete')['match']);
        $this->assertSame('possible', collect($writes['results'])->firstWhere('id', 'App\\Services\\LegacyWriter::save')['match']);
        $this->assertNotContains('App\\Services\\LegacyWriter::save', array_column($writes['matches']['excluded'], 'id'));

        $reads = $engine->readsFrom('notes.title');
        $this->assertSame('proven', collect($reads['results'])->firstWhere('id', 'App\\Services\\WildcardReader::read')['match']);
    }

    public function test_callers_of_traverses_call_graph_upstream(): void
    {
        $callers = $this->engine()->callersOf('NoteService::save');

        $this->assertSame(
            ['App\Http\Controllers\NoteController::update', 'App\Services\NoteService::helper'],
            array_column($callers['results'], 'id')
        );
        $this->assertArrayNotHasKey('via', $callers['results'][0]);
    }

    public function test_flow_from_route_maps_the_feature_execution_slice(): void
    {
        $flow = $this->engine()->flowFrom('notes.update');

        $this->assertSame('flow-from', $flow['query']);
        $this->assertSame('route:PUT:/notes/{note}', $flow['route']['id']);
        $this->assertSame('PUT /notes/{note}', $flow['route']['label']);
        $this->assertSame('notes.update', $flow['route']['name']);
        $this->assertSame('App\Http\Controllers\NoteController::update', $flow['entrypoint']['id']);
        $this->assertSame(0, $flow['entrypoint']['depth']);
        $this->assertSame(
            [
                'App\Http\Controllers\NoteController::update',
                'App\Services\NoteService::save',
                'App\Services\NoteService::helper',
            ],
            array_column($flow['methods'], 'id')
        );
        $this->assertSame(['App\Http\Requests\UpdateNoteRequest'], array_column($flow['formRequests'], 'id'));
        $this->assertSame(['App\Models\Note'], array_column($flow['models'], 'id'));
        $this->assertSame('notes', $flow['dataAccess'][0]['table']);
        $this->assertSame('write', $flow['dataAccess'][0]['access']);
        $this->assertSame(['create', 'save'], $flow['dataAccess'][0]['ops']);
        $this->assertArrayNotHasKey('dispatches', $flow);
        $this->assertSame('route_has_no_mapped_tests', $flow['analysisWarnings'][0]['reason']);
    }

    public function test_flow_from_route_runs_middleware_as_a_ranked_entrypoint_and_ignores_container_resolution_edges(): void
    {
        $graph = $this->queryFixtureGraph();
        $route = 'route:PUT:/notes/{note}';
        $middleware = 'App\Http\Middleware\Authenticate::handle';
        $audit = 'App\Services\RequestAudit::record';
        $notExecutable = 'App\Services\ContainerTarget::execute';
        $cache = 'side-effect:cache:default:request-audit';
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $middleware, 'type' => 'method', 'label' => 'Authenticate::handle'],
            ['id' => $audit, 'type' => 'method', 'label' => 'RequestAudit::record'],
            ['id' => $notExecutable, 'type' => 'method', 'label' => 'ContainerTarget::execute'],
            ['id' => $cache, 'type' => 'cache', 'label' => 'default:request-audit'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            ['from' => $route, 'to' => $middleware, 'type' => 'passes_through', 'confidence' => 0.8],
            ['from' => $middleware, 'to' => $audit, 'type' => 'calls', 'confidence' => 0.5],
            ['from' => $audit, 'to' => $cache, 'type' => 'writes_cache', 'confidence' => 0.5],
            ['from' => $middleware, 'to' => $notExecutable, 'type' => 'resolves_to', 'confidence' => 1.0],
        ];
        $engine = new QueryEngine(GraphIndex::fromArray($graph));

        $flow = $engine->flowFrom('notes.update');
        $methods = collect($flow['methods'])->keyBy('id');

        $this->assertSame(0, $methods[$middleware]['depth']);
        $this->assertSame(0.8, $methods[$middleware]['confidence']);
        $this->assertSame($route, $methods[$middleware]['via']);
        $this->assertSame(1, $methods[$audit]['depth']);
        $this->assertSame(0.4, $methods[$audit]['confidence']);
        $this->assertArrayNotHasKey($notExecutable, $methods);
        $this->assertSame(0.2, collect($flow['sideEffects'])->firstWhere('id', $cache)['confidence']);

        $bounded = $engine->flowFrom('notes.update', depth: 0);
        $this->assertNotContains($audit, array_column($bounded['methods'], 'id'));

        $confident = $engine->flowFrom('notes.update', minConfidence: 0.81);
        $this->assertNotContains($middleware, array_column($confident['methods'], 'id'));
    }

    public function test_flow_from_always_reserves_the_route_action_when_middleware_fills_the_limit(): void
    {
        $graph = $this->queryFixtureGraph();
        $route = 'route:PUT:/notes/{note}';
        $action = 'App\Http\Controllers\NoteController::update';
        $middleware = 'AA\Middleware::handle';
        $graph['nodes'][] = ['id' => $middleware, 'type' => 'method', 'label' => 'AA Middleware'];
        $graph['edges'][] = [
            'from' => $route,
            'to' => $middleware,
            'type' => 'passes_through',
            'confidence' => 1.0,
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update', limit: 1);

        $this->assertTrue($flow['truncated']);
        $this->assertSame($action, $flow['entrypoint']['id']);
        $this->assertSame([$action], array_column($flow['methods'], 'id'));
    }

    public function test_flow_from_retains_the_routes_to_confidence_on_the_action_seed(): void
    {
        $graph = $this->queryFixtureGraph();

        foreach ($graph['edges'] as &$edge) {
            if ($edge['type'] === 'routes_to') {
                $edge['confidence'] = 0.7;
            }
        }
        unset($edge);

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');

        $this->assertSame(0.7, $flow['route']['actionConfidence']);
        $this->assertSame(0.7, $flow['entrypoint']['confidence']);
    }

    public function test_flow_from_crosses_form_request_lifecycle_hooks_into_their_downstream_effects(): void
    {
        $graph = $this->queryFixtureGraph();
        $request = 'App\Http\Requests\UpdateNoteRequest';
        $rules = $request.'::rules';
        $sanitizer = 'App\Services\RuleSanitizer::sanitize';
        $external = 'side-effect:external:https://rules.example';

        foreach ($graph['edges'] as &$edge) {
            if ($edge['type'] === 'validates_with' && $edge['to'] === $request) {
                $edge['confidence'] = 0.75;
            }
        }
        unset($edge);

        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $rules, 'type' => 'method', 'label' => 'UpdateNoteRequest::rules'],
            ['id' => $sanitizer, 'type' => 'method', 'label' => 'RuleSanitizer::sanitize'],
            ['id' => $external, 'type' => 'external', 'label' => 'rules.example'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            ['from' => $request, 'to' => $rules, 'type' => 'framework_invokes', 'confidence' => 0.8],
            ['from' => $rules, 'to' => $sanitizer, 'type' => 'calls', 'confidence' => 0.5],
            ['from' => $sanitizer, 'to' => $external, 'type' => 'calls_external', 'confidence' => 0.8],
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');
        $methods = collect($flow['methods'])->keyBy('id');
        $effect = collect($flow['sideEffects'])->firstWhere('id', $external);

        $this->assertSame(2, $methods[$rules]['depth']);
        $this->assertSame(0.6, $methods[$rules]['confidence']);
        $this->assertSame($request, $methods[$rules]['via']);
        $this->assertSame(3, $methods[$sanitizer]['depth']);
        $this->assertSame(0.3, $methods[$sanitizer]['confidence']);
        $this->assertSame(3, $effect['depth']);
        $this->assertSame(0.24, $effect['confidence']);
    }

    public function test_flow_from_crosses_dispatched_events_into_listener_execution_and_keeps_compact_listener_output(): void
    {
        $graph = $this->queryFixtureGraph();
        $action = 'App\Http\Controllers\NoteController::update';
        $event = 'App\Events\NoteSaved';
        $listener = 'App\Listeners\SendNotification::handle';
        $notifier = 'App\Services\Notifier::send';
        $external = 'side-effect:external:https://notify.example';
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $event, 'type' => 'event', 'label' => 'NoteSaved'],
            ['id' => $listener, 'type' => 'method', 'label' => 'SendNotification::handle'],
            ['id' => $notifier, 'type' => 'method', 'label' => 'Notifier::send'],
            ['id' => $external, 'type' => 'external', 'label' => 'notify.example'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            ['from' => $action, 'to' => $event, 'type' => 'dispatches', 'confidence' => 0.8],
            ['from' => $event, 'to' => $listener, 'type' => 'handled_by', 'confidence' => 0.75, 'metadata' => ['kind' => 'listener', 'queued' => true]],
            ['from' => $listener, 'to' => $event, 'type' => 'listens_to', 'confidence' => 0.75, 'metadata' => ['queued' => true]],
            ['from' => $action, 'to' => $listener, 'type' => 'calls', 'confidence' => 0.4],
            ['from' => $listener, 'to' => $notifier, 'type' => 'calls', 'confidence' => 0.5],
            ['from' => $notifier, 'to' => $external, 'type' => 'calls_external', 'confidence' => 0.8],
        ];
        $engine = new QueryEngine(GraphIndex::fromArray($graph));

        $flow = $engine->flowFrom('notes.update');
        $methods = collect($flow['methods'])->keyBy('id');
        $dispatch = collect($flow['dispatches'])->firstWhere('id', $event);

        // The stronger two-edge Laravel path outranks the shallower weak call.
        $this->assertSame(2, $methods[$listener]['depth']);
        $this->assertSame(0.6, $methods[$listener]['confidence']);
        $this->assertSame($event, $methods[$listener]['via']);
        $this->assertSame(3, $methods[$notifier]['depth']);
        $this->assertSame(0.3, $methods[$notifier]['confidence']);
        $this->assertSame(0.24, collect($flow['sideEffects'])->firstWhere('id', $external)['confidence']);
        $this->assertSame($listener, $dispatch['listeners'][0]['id']);
        $this->assertTrue($dispatch['listeners'][0]['queued']);

        $bounded = $engine->flowFrom('notes.update', depth: 1, minConfidence: 0.5);
        $this->assertNotContains($listener, array_column($bounded['methods'], 'id'));

        $limited = $engine->flowFrom('notes.update', limit: 2);
        $this->assertTrue($limited['truncated']);
        $this->assertCount(2, $limited['methods']);
        $this->assertSame($action, $limited['entrypoint']['id']);
    }

    public function test_flow_from_compact_listener_output_excludes_registered_but_non_executing_listeners(): void
    {
        $graph = $this->queryFixtureGraph();
        $action = 'App\\Http\\Controllers\\NoteController::update';
        $event = 'App\\Events\\ListenerGateEvent';
        $active = 'App\\Listeners\\ActiveListener::handle';
        $shouldNotQueue = 'App\\Listeners\\ShouldNotQueueListener::handle';
        $inactive = 'App\\Listeners\\InactiveListener::handle';
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $event, 'type' => 'event', 'label' => 'ListenerGateEvent'],
            ['id' => $active, 'type' => 'method', 'label' => 'ActiveListener::handle'],
            ['id' => $shouldNotQueue, 'type' => 'method', 'label' => 'ShouldNotQueueListener::handle'],
            ['id' => $inactive, 'type' => 'method', 'label' => 'InactiveListener::handle'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            ['from' => $action, 'to' => $event, 'type' => 'dispatches', 'confidence' => 1.0],
            [
                'from' => $event,
                'to' => $active,
                'type' => 'handled_by',
                'confidence' => 0.9,
                'metadata' => ['kind' => 'listener', 'queued' => true],
            ],
            [
                'from' => $event,
                'to' => $inactive,
                'type' => 'handled_by',
                'confidence' => 0.8,
                'metadata' => ['kind' => 'listener', 'causalExecutionProven' => false],
            ],
            ['from' => $active, 'to' => $event, 'type' => 'listens_to', 'confidence' => 0.9],
            [
                'from' => $shouldNotQueue,
                'to' => $event,
                'type' => 'listens_to',
                'confidence' => 1.0,
                'metadata' => [
                    'queued' => true,
                    'shouldQueueDecision' => 'never',
                    'causalExecutionProven' => true,
                ],
            ],
            [
                'from' => $inactive,
                'to' => $event,
                'type' => 'listens_to',
                'confidence' => 0.8,
                'metadata' => ['causalExecutionProven' => false],
            ],
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');
        $dispatch = collect($flow['dispatches'])->firstWhere('id', $event);

        $this->assertSame([$active], array_column($dispatch['listeners'], 'id'));
        $this->assertTrue($dispatch['listeners'][0]['queued']);
        $this->assertContains($active, array_column($flow['methods'], 'id'));
        $this->assertNotContains($shouldNotQueue, array_column($flow['methods'], 'id'));
        $this->assertNotContains($inactive, array_column($flow['methods'], 'id'));
    }

    public function test_flow_from_crosses_dispatched_jobs_into_handlers_and_their_downstream_effects(): void
    {
        $graph = $this->queryFixtureGraph();
        $action = 'App\Http\Controllers\NoteController::update';
        $job = 'App\Jobs\IndexNote';
        $handler = $job.'::handle';
        $indexer = 'App\Services\SearchIndexer::index';
        $cache = 'side-effect:cache:default:search-index';
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $job, 'type' => 'job', 'label' => 'IndexNote'],
            ['id' => $handler, 'type' => 'method', 'label' => 'IndexNote::handle'],
            ['id' => $indexer, 'type' => 'method', 'label' => 'SearchIndexer::index'],
            ['id' => $cache, 'type' => 'cache', 'label' => 'default:search-index'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            ['from' => $action, 'to' => $job, 'type' => 'dispatches', 'confidence' => 0.9],
            ['from' => $job, 'to' => $handler, 'type' => 'handled_by', 'confidence' => 0.8, 'metadata' => ['kind' => 'job']],
            ['from' => $handler, 'to' => $indexer, 'type' => 'calls', 'confidence' => 0.5],
            ['from' => $indexer, 'to' => $cache, 'type' => 'writes_cache', 'confidence' => 0.7],
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');
        $methods = collect($flow['methods'])->keyBy('id');
        $dispatch = collect($flow['dispatches'])->firstWhere('id', $job);

        $this->assertSame(2, $methods[$handler]['depth']);
        $this->assertSame(0.72, $methods[$handler]['confidence']);
        $this->assertSame($job, $methods[$handler]['via']);
        $this->assertSame(3, $methods[$indexer]['depth']);
        $this->assertSame(0.36, $methods[$indexer]['confidence']);
        $this->assertSame(0.252, collect($flow['sideEffects'])->firstWhere('id', $cache)['confidence']);
        $this->assertArrayNotHasKey('listeners', $dispatch);
    }

    public function test_flow_from_does_not_cross_a_declaration_only_handler_bridge(): void
    {
        $graph = $this->queryFixtureGraph();
        $action = 'App\\Http\\Controllers\\NoteController::update';
        $event = 'App\\Events\\DeclaredOnly';
        $listener = 'App\\Listeners\\InactiveListener::handle';
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $event, 'type' => 'event', 'label' => 'DeclaredOnly'],
            ['id' => $listener, 'type' => 'method', 'label' => 'InactiveListener::handle'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            ['from' => $action, 'to' => $event, 'type' => 'dispatches', 'confidence' => 1.0],
            [
                'from' => $event,
                'to' => $listener,
                'type' => 'handled_by',
                'confidence' => 0.6,
                'metadata' => [
                    'kind' => 'listener',
                    'causalExecutionProven' => false,
                ],
            ],
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');

        $this->assertNotContains($listener, array_column($flow['methods'], 'id'));
    }

    public function test_flow_from_reports_but_does_not_traverse_a_conditional_chain_member(): void
    {
        $graph = $this->queryFixtureGraph();
        $action = 'App\\Http\\Controllers\\NoteController::update';
        $job = 'App\\Jobs\\ConditionalChainMember';
        $handler = $job.'::handle';
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $job, 'type' => 'job', 'label' => 'ConditionalChainMember'],
            ['id' => $handler, 'type' => 'method', 'label' => 'ConditionalChainMember::handle'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            [
                'from' => $action,
                'to' => $job,
                'type' => 'dispatches',
                'confidence' => 0.7,
                'metadata' => [
                    'kind' => 'job',
                    'chained' => true,
                    'chainPosition' => 1,
                    'causalExecutionProven' => false,
                ],
            ],
            ['from' => $job, 'to' => $handler, 'type' => 'handled_by', 'confidence' => 1.0, 'metadata' => ['kind' => 'job']],
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');

        $this->assertSame($job, collect($flow['dispatches'])->firstWhere('id', $job)['id']);
        $this->assertNotContains($handler, array_column($flow['methods'], 'id'));
    }

    public function test_flow_from_preserves_mixed_dispatch_occurrences_without_fabricating_a_node_default(): void
    {
        $graph = $this->queryFixtureGraph();
        $action = 'App\\Http\\Controllers\\NoteController::update';
        $job = 'App\\Jobs\\MixedQueueJob';
        $graph['nodes'][] = [
            'id' => $job,
            'type' => 'job',
            'label' => 'MixedQueueJob',
            'metadata' => ['queue' => 'default-job-queue'],
        ];
        $graph['edges'][] = [
            'from' => $action,
            'to' => $job,
            'type' => 'dispatches',
            'confidence' => 0.9,
            'metadata' => [
                'kind' => 'job',
                'dispatchMode' => 'queued',
                'dispatchModes' => ['queued'],
                'dispatchMethod' => 'dispatch',
                'dispatchMethods' => ['dispatch'],
                'queues' => ['a', 'b'],
                'dispatchOccurrences' => [
                    'one' => ['kind' => 'job', 'mode' => 'queued', 'method' => 'dispatch', 'queue' => 'a'],
                    'two' => ['kind' => 'job', 'mode' => 'queued', 'method' => 'dispatch', 'queue' => 'b'],
                ],
            ],
        ];

        $dispatch = collect(
            (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update')['dispatches'],
        )->firstWhere('id', $job);

        $this->assertSame(['a', 'b'], $dispatch['queues']);
        $this->assertArrayNotHasKey('queue', $dispatch);
        $this->assertSame('queued', $dispatch['mode']);
        $this->assertCount(2, $dispatch['occurrences']);
    }

    public function test_flow_from_uses_dispatch_kind_to_select_a_dual_role_execution_bridge(): void
    {
        $graph = $this->queryFixtureGraph();
        $action = 'App\\Http\\Controllers\\NoteController::update';
        $dual = 'App\\Messages\\DualMessage';
        $listener = 'App\\Listeners\\DualListener::handle';
        $jobHandler = 'App\\Jobs\\DualMessage::handle';
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $dual, 'type' => 'event', 'label' => 'DualMessage', 'metadata' => ['roles' => ['event', 'job']]],
            ['id' => $listener, 'type' => 'method', 'label' => 'DualListener::handle'],
            ['id' => $jobHandler, 'type' => 'method', 'label' => 'DualMessage::handle'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            [
                'from' => $action,
                'to' => $dual,
                'type' => 'dispatches',
                'confidence' => 1.0,
                'metadata' => [
                    'kind' => 'event',
                    'dispatchKinds' => ['event', 'job'],
                    'dispatchOccurrences' => [
                        'event' => [
                            'kind' => 'event',
                            'mode' => 'sync',
                            'causalExecutionProven' => true,
                        ],
                        'job' => [
                            'kind' => 'job',
                            'mode' => 'queued',
                            'causalExecutionProven' => false,
                        ],
                    ],
                ],
            ],
            ['from' => $dual, 'to' => $listener, 'type' => 'handled_by', 'confidence' => 1.0, 'metadata' => ['kind' => 'listener']],
            ['from' => $dual, 'to' => $jobHandler, 'type' => 'handled_by', 'confidence' => 1.0, 'metadata' => ['kind' => 'job']],
            ['from' => $listener, 'to' => $dual, 'type' => 'listens_to', 'confidence' => 1.0],
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');
        $methodIds = array_column($flow['methods'], 'id');
        $dispatch = collect($flow['dispatches'])->firstWhere('id', $dual);

        $this->assertContains($listener, $methodIds);
        $this->assertNotContains($jobHandler, $methodIds);
        $this->assertSame('event', $dispatch['type']);
        $this->assertSame($listener, $dispatch['listeners'][0]['id']);
    }

    public function test_flow_from_does_not_cross_a_dispatch_when_all_occurrences_are_non_causal(): void
    {
        $graph = $this->queryFixtureGraph();
        $action = 'App\\Http\\Controllers\\NoteController::update';
        $dual = 'App\\Messages\\SuppressedDualMessage';
        $listener = 'App\\Listeners\\SuppressedDualListener::handle';
        $jobHandler = 'App\\Jobs\\SuppressedDualMessage::handle';
        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => $dual, 'type' => 'event', 'label' => 'SuppressedDualMessage', 'metadata' => ['roles' => ['event', 'job']]],
            ['id' => $listener, 'type' => 'method', 'label' => 'SuppressedDualListener::handle'],
            ['id' => $jobHandler, 'type' => 'method', 'label' => 'SuppressedDualMessage::handle'],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            [
                'from' => $action,
                'to' => $dual,
                'type' => 'dispatches',
                'confidence' => 1.0,
                'metadata' => [
                    'kind' => 'event',
                    'dispatchKinds' => ['event', 'job'],
                    'dispatchOccurrences' => [
                        'event' => ['kind' => 'event', 'causalExecutionProven' => false],
                        'job' => ['kind' => 'job', 'causalExecutionProven' => false],
                    ],
                ],
            ],
            ['from' => $dual, 'to' => $listener, 'type' => 'handled_by', 'confidence' => 1.0, 'metadata' => ['kind' => 'listener']],
            ['from' => $dual, 'to' => $jobHandler, 'type' => 'handled_by', 'confidence' => 1.0, 'metadata' => ['kind' => 'job']],
            ['from' => $listener, 'to' => $dual, 'type' => 'listens_to', 'confidence' => 1.0],
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');
        $dispatch = collect($flow['dispatches'])->firstWhere('id', $dual);
        $methodIds = array_column($flow['methods'], 'id');

        $this->assertSame($dual, $dispatch['id']);
        $this->assertArrayNotHasKey('listeners', $dispatch);
        $this->assertNotContains($listener, $methodIds);
        $this->assertNotContains($jobHandler, $methodIds);
    }

    public function test_flow_from_rejects_non_entrypoint_nodes(): void
    {
        $this->expectException(UnresolvedTargetException::class);

        $this->engine()->flowFrom('App\Models\Note');
    }

    public function test_flow_from_surfaces_middleware_fields_side_effects_consumers_tests_policies_and_resolution_warnings(): void
    {
        $graph = $this->queryFixtureGraph();

        foreach ($graph['nodes'] as &$node) {
            if ($node['id'] === 'route:PUT:/notes/{note}') {
                $node['metadata'] += [
                    'uri' => 'notes/{note}',
                    'methods' => ['PUT'],
                    'middleware' => ['web', 'auth', 'can:update,note'],
                ];
            }
        }
        unset($node);

        foreach ($graph['edges'] as &$edge) {
            if ($edge['from'] === 'App\Services\NoteService::save' && $edge['type'] === 'writes') {
                $edge['metadata']['operations']['18:save:model_table']['fields'] = ['meta.signed_at', 'title'];
            }
        }
        unset($edge);

        $graph['nodes'] = [
            ...$graph['nodes'],
            ['id' => 'frontend:resources/js/Note.vue', 'type' => 'frontend', 'label' => 'Note.vue', 'file' => 'resources/js/Note.vue'],
            ['id' => 'test:Tests\Feature\NoteTest::test_update', 'type' => 'test', 'label' => 'NoteTest::test_update', 'file' => 'tests/Feature/NoteTest.php'],
            ['id' => 'side-effect:cache:default:note:1', 'type' => 'cache', 'label' => 'default:note:1'],
            ['id' => 'App\Policies\NotePolicy::update', 'type' => 'method', 'label' => 'NotePolicy::update', 'file' => 'app/Policies/NotePolicy.php', 'line' => 12],
        ];
        $graph['edges'] = [
            ...$graph['edges'],
            ['from' => 'frontend:resources/js/Note.vue', 'to' => 'route:PUT:/notes/{note}', 'type' => 'consumes_route', 'confidence' => 0.98, 'metadata' => ['line' => 8]],
            ['from' => 'test:Tests\Feature\NoteTest::test_update', 'to' => 'route:PUT:/notes/{note}', 'type' => 'tests_route', 'confidence' => 1.0, 'metadata' => ['line' => 15]],
            ['from' => 'App\Services\NoteService::save', 'to' => 'side-effect:cache:default:note:1', 'type' => 'writes_cache', 'confidence' => 0.95, 'metadata' => ['operation' => 'forget', 'line' => 20]],
            ['from' => 'App\Http\Controllers\NoteController::update', 'to' => 'App\Policies\NotePolicy::update', 'type' => 'authorizes_via', 'confidence' => 0.9, 'metadata' => ['ability' => 'update']],
        ];
        $graph['meta']['analysis']['callResolution']['samples'][] = [
            'caller' => 'App\Services\NoteService::save',
            'file' => 'app/Services/NoteService.php',
            'line' => 22,
            'reason' => 'receiver_type_unknown',
            'method' => 'publish',
        ];

        $flow = (new QueryEngine(GraphIndex::fromArray($graph)))->flowFrom('notes.update');

        $this->assertSame(['web', 'auth', 'can:update,note'], $flow['route']['middleware']);
        $this->assertSame(['meta.signed_at', 'title'], $flow['dataAccess'][0]['fields']);
        $this->assertSame('writes_cache', $flow['sideEffects'][0]['effect']);
        $this->assertSame('frontend:resources/js/Note.vue', $flow['frontendConsumers'][0]['id']);
        $this->assertSame('test:Tests\Feature\NoteTest::test_update', $flow['tests'][0]['id']);
        $this->assertSame('App\Policies\NotePolicy::update', $flow['authorization'][0]['policy']);
        $this->assertSame('receiver_type_unknown', $flow['analysisWarnings'][0]['reason']);
    }

    public function test_impact_of_column_walks_up_to_routes(): void
    {
        $impact = $this->engine()->impactOf('notes.title');

        $methodIds = array_column($impact['methods'], 'id');
        $this->assertContains('App\Services\NoteService::save', $methodIds);
        $this->assertContains('App\Http\Controllers\NoteController::index', $methodIds);
        $this->assertContains('App\Http\Controllers\NoteController::update', $methodIds);

        $this->assertSame(['App\Models\Note'], array_column($impact['models'], 'id'));

        $this->assertSame('route:PUT:/notes/{note}', $impact['routes'][0]['id']);
        $this->assertSame('App\Http\Controllers\NoteController::update', $impact['routes'][0]['action']);
        $this->assertSame('PUT /notes/{note}', $impact['routes'][0]['label']);
    }

    public function test_routes_touching_filters_impact_to_routes(): void
    {
        $routes = $this->engine()->routesTouching('App\Models\Note');

        $this->assertSame(['route:PUT:/notes/{note}'], array_column($routes['results'], 'id'));
    }

    public function test_models_and_tables_summaries(): void
    {
        $engine = $this->engine();

        $models = $engine->models()['results'];
        $this->assertSame('App\Models\Note', $models[0]['id']);
        $this->assertSame('notes', $models[0]['table']);
        $this->assertSame(1, $models[0]['relationships']);
        $this->assertArrayNotHasKey('relationships', $models[1]);

        $tables = $engine->tables()['results'];
        $this->assertSame('table:notes', $tables[0]['id']);
        $this->assertSame(1, $tables[0]['columns']);
        $this->assertSame(1, $tables[0]['readers']);
        $this->assertSame(1, $tables[0]['writers']);
        $this->assertSame(['id' => 'table:tags'], $tables[1]);
    }

    public function test_node_strips_metadata_unless_full(): void
    {
        $engine = $this->engine();

        $lean = $engine->node('App\Models\Note');
        $this->assertArrayNotHasKey('metadata', $lean['node']);
        $this->assertSame('belongs_to_many', $lean['out'][0]['type']);
        $this->assertSame('App\Http\Controllers\NoteController::update', $lean['in'][0]['from']);

        $full = $engine->node('App\Models\Note', full: true);
        $this->assertSame('notes', $full['node']['metadata']['table']);
    }

    public function test_search_returns_compact_rows(): void
    {
        $results = $this->engine()->search('NoteService')['results'];

        $this->assertSame(
            ['App\Services\NoteService::helper', 'App\Services\NoteService::save'],
            array_column($results, 'id')
        );
        $this->assertSame(['id', 'type', 'label', 'file', 'line'], array_keys($results[0]));
    }

    public function test_unresolvable_target_throws_with_candidates(): void
    {
        try {
            $this->engine()->callersOf('Tag');
            $this->fail('Expected UnresolvedTargetException.');
        } catch (UnresolvedTargetException $exception) {
            $this->assertSame(['App\Models\Tag', 'App\Other\Tag'], $exception->candidates);
            $this->assertStringContainsString('Did you mean', $exception->getMessage());
        }
    }
}
