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
        $this->assertSame($byTable['results'], $engine->writesTo('notes.title')['results']);
    }

    public function test_reads_from_lists_reader_methods(): void
    {
        $reads = $this->engine()->readsFrom('notes');

        $this->assertSame(
            ['App\Http\Controllers\NoteController::index'],
            array_column($reads['results'], 'id')
        );
        $this->assertSame(['get'], $reads['results'][0]['ops']);
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
