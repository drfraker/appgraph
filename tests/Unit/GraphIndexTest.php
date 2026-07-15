<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\GraphExporter;
use AppGraph\Query\GraphIndex;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use AppGraph\Tests\TestCase;

class GraphIndexTest extends TestCase
{
    use BuildsQueryFixtureGraph;

    public function test_it_indexes_nodes_and_edges(): void
    {
        $index = GraphIndex::fromArray($this->queryFixtureGraph());

        $this->assertSame('model', $index->node('App\Models\Note')['type']);
        $this->assertCount(3, $index->nodesOfType('model'));
        $this->assertSame(3, $index->countsByNodeType()['model']);
        $this->assertSame(3, $index->countsByEdgeType()['calls']);
        $this->assertCount(1, $index->edgesFrom('App\Models\Note', ['uses_table']));
        $this->assertCount(1, $index->edgesTo('table:notes', ['writes']));
    }

    public function test_load_observes_an_atomic_replacement_within_the_same_timestamp_window(): void
    {
        $directory = sys_get_temp_dir().'/appgraph-index-'.bin2hex(random_bytes(4));
        $path = $directory.'/graph.json';
        mkdir($directory, 0775, true);
        $exporter = new GraphExporter();

        try {
            $exporter->exportData(['meta' => [], 'nodes' => [
                ['id' => 'first', 'type' => 'class', 'label' => 'first'],
            ], 'edges' => []], $path);
            $first = GraphIndex::load($path);
            $this->assertNotNull($first->node('first'));

            $exporter->exportData(['meta' => [], 'nodes' => [
                ['id' => 'other', 'type' => 'class', 'label' => 'other'],
            ], 'edges' => []], $path);
            $second = GraphIndex::load($path);

            $this->assertNull($second->node('first'));
            $this->assertNotNull($second->node('other'));
        } finally {
            GraphIndex::forget($path);
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_traverse_walks_calls_with_cycle_guard_and_confidence(): void
    {
        $index = GraphIndex::fromArray($this->queryFixtureGraph());

        $results = $index->traverse('App\Http\Controllers\NoteController::update', ['calls'], 'out');

        $this->assertSame(
            ['App\Services\NoteService::save', 'App\Services\NoteService::helper'],
            array_column($results, 'id')
        );
        $this->assertSame([1, 2], array_column($results, 'depth'));
        $this->assertSame(0.9, $results[1]['confidence']);
        $this->assertSame('App\Services\NoteService::save', $results[1]['via']);
    }

    public function test_traverse_respects_depth_min_confidence_and_limit(): void
    {
        $index = GraphIndex::fromArray($this->queryFixtureGraph());

        $shallow = $index->traverse('App\Http\Controllers\NoteController::update', ['calls'], 'out', maxDepth: 1);
        $this->assertSame(['App\Services\NoteService::save'], array_column($shallow, 'id'));

        $confident = $index->traverse('App\Services\NoteService::save', ['calls'], 'in', minConfidence: 0.9);
        $this->assertSame(['App\Http\Controllers\NoteController::update'], array_column($confident, 'id'));

        $truncated = false;
        $limited = $index->traverse('App\Services\NoteService::save', ['calls'], 'in', limit: 1, truncated: $truncated);
        $this->assertCount(1, $limited);
        $this->assertTrue($truncated);
        $this->assertSame('App\Http\Controllers\NoteController::update', $limited[0]['id']);
    }

    public function test_traverse_replaces_a_weak_shallow_visit_with_a_stronger_ranked_path(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'method', 'label' => $id],
                ['A', 'B', 'T'],
            ),
            'edges' => [
                ['from' => 'A', 'to' => 'T', 'type' => 'calls', 'confidence' => 0.2],
                ['from' => 'A', 'to' => 'B', 'type' => 'calls', 'confidence' => 0.95],
                ['from' => 'B', 'to' => 'T', 'type' => 'calls', 'confidence' => 0.95],
                ['from' => 'T', 'to' => 'A', 'type' => 'calls', 'confidence' => 1.0],
            ],
        ];

        $results = GraphIndex::fromArray($graph)->traverse('A', ['calls']);
        $target = collect($results)->firstWhere('id', 'T');

        $this->assertSame(['B', 'T'], array_column($results, 'id'));
        $this->assertSame(2, $target['depth']);
        $this->assertSame(0.9025, $target['confidence']);
        $this->assertSame('B', $target['via']);
        $this->assertSame(['A', 'B', 'T'], $target['path']);
    }

    public function test_seeded_traversal_ranks_one_causal_graph_filters_intermediate_nodes_and_limits_results(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => [
                ['id' => 'Action', 'type' => 'method', 'label' => 'Action'],
                ['id' => 'Middleware', 'type' => 'method', 'label' => 'Middleware'],
                ['id' => 'Event', 'type' => 'event', 'label' => 'Event'],
                ['id' => 'Handler', 'type' => 'method', 'label' => 'Handler'],
            ],
            'edges' => [
                ['from' => 'Action', 'to' => 'Event', 'type' => 'dispatches', 'confidence' => 0.8],
                ['from' => 'Event', 'to' => 'Handler', 'type' => 'handled_by', 'confidence' => 0.75],
                ['from' => 'Middleware', 'to' => 'Handler', 'type' => 'calls', 'confidence' => 0.9],
                ['from' => 'Handler', 'to' => 'Action', 'type' => 'calls', 'confidence' => 1.0],
            ],
        ];
        $truncated = false;

        $results = GraphIndex::fromArray($graph)->traverseFromSeeds(
            [
                ['id' => 'Action'],
                ['id' => 'Middleware', 'confidence' => 0.5],
            ],
            ['calls', 'dispatches', 'handled_by'],
            maxDepth: 4,
            limit: 2,
            resultNodeTypes: ['method'],
            truncated: $truncated,
        );

        $this->assertTrue($truncated);
        $this->assertSame(['Action', 'Handler'], array_column($results, 'id'));
        $this->assertNotContains('Event', array_column($results, 'id'));
        $this->assertSame(2, $results[1]['depth']);
        $this->assertSame(0.6, $results[1]['confidence']);
        $this->assertSame('Event', $results[1]['via']);

        $filteredSeeds = GraphIndex::fromArray($graph)->traverseFromSeeds(
            [['id' => 'Middleware', 'confidence' => 0.5]],
            ['calls'],
            minConfidence: 0.6,
            resultNodeTypes: ['method'],
        );

        $this->assertSame([], $filteredSeeds);
    }

    public function test_top_paths_returns_distinct_complete_paths_in_rank_order_and_ignores_cycles(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'method', 'label' => $id],
                ['A', 'B', 'C', 'T'],
            ),
            // Deliberately place the weakest path first to prove input order is irrelevant.
            'edges' => [
                ['from' => 'A', 'to' => 'T', 'type' => 'calls', 'confidence' => 0.3],
                ['from' => 'C', 'to' => 'T', 'type' => 'calls', 'confidence' => 0.8],
                ['from' => 'A', 'to' => 'C', 'type' => 'calls', 'confidence' => 0.9],
                ['from' => 'B', 'to' => 'T', 'type' => 'calls', 'confidence' => 0.95],
                ['from' => 'A', 'to' => 'B', 'type' => 'calls', 'confidence' => 0.95],
                ['from' => 'T', 'to' => 'A', 'type' => 'calls', 'confidence' => 1.0],
            ],
        ];

        $truncated = false;
        $paths = GraphIndex::fromArray($graph)->topPaths(
            'A',
            'T',
            ['calls'],
            maxDepth: 4,
            limit: 3,
            truncated: $truncated,
        );

        $this->assertSame([
            ['A', 'B', 'T'],
            ['A', 'C', 'T'],
            ['A', 'T'],
        ], array_column($paths, 'nodes'));
        $this->assertSame([0.9025, 0.72, 0.3], array_column($paths, 'confidence'));
        $this->assertSame([2, 2, 1], array_column($paths, 'depth'));
        $this->assertCount(2, $paths[0]['edges']);
        $this->assertFalse($truncated);

        $reordered = $graph;
        $reordered['edges'] = array_reverse($reordered['edges']);
        $this->assertSame(
            array_column($paths, 'nodes'),
            array_column(GraphIndex::fromArray($reordered)->topPaths('A', 'T', ['calls']), 'nodes')
        );

        $reverse = GraphIndex::fromArray($graph)->topPaths('T', 'A', ['calls'], direction: 'in');
        $this->assertSame(['T', 'B', 'A'], $reverse[0]['nodes']);

        $limited = GraphIndex::fromArray($graph)->topPaths('A', 'T', ['calls'], limit: 2, truncated: $truncated);
        $this->assertCount(2, $limited);
        $this->assertTrue($truncated);
    }

    public function test_top_paths_hard_bounds_generated_states_on_dense_graphs(): void
    {
        $nodes = ['A', 'T'];
        $edges = [];

        for ($index = 0; $index < 6; $index++) {
            $branch = 'B'.$index;
            $nodes[] = $branch;
            $edges[] = ['from' => 'A', 'to' => $branch, 'type' => 'calls', 'confidence' => 1.0];
            $edges[] = ['from' => $branch, 'to' => 'T', 'type' => 'calls', 'confidence' => 1.0];
        }

        $graph = [
            'meta' => [],
            'nodes' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'method', 'label' => $id],
                $nodes,
            ),
            'edges' => array_reverse($edges),
        ];
        $truncated = false;
        $paths = GraphIndex::fromArray($graph)->topPaths(
            'A',
            'T',
            ['calls'],
            maxDepth: 3,
            limit: 10,
            maxStates: 10,
            truncated: $truncated,
        );

        $this->assertTrue($truncated);
        $this->assertCount(3, $paths);
        $this->assertSame([
            ['A', 'B0', 'T'],
            ['A', 'B1', 'T'],
            ['A', 'B2', 'T'],
        ], array_column($paths, 'nodes'));
    }

    public function test_top_paths_spends_a_tight_state_budget_on_the_strongest_edges(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'method', 'label' => $id],
                ['A', 'B', 'C', 'T'],
            ),
            'edges' => [
                ['from' => 'A', 'to' => 'B', 'type' => 'calls', 'confidence' => 0.1],
                ['from' => 'A', 'to' => 'C', 'type' => 'calls', 'confidence' => 0.2],
                ['from' => 'A', 'to' => 'T', 'type' => 'calls', 'confidence' => 1.0],
            ],
        ];
        $truncated = false;
        $paths = GraphIndex::fromArray($graph)->topPaths(
            'A',
            'T',
            ['calls'],
            maxStates: 3,
            truncated: $truncated,
        );

        $this->assertTrue($truncated);
        $this->assertSame([['A', 'T']], array_column($paths, 'nodes'));
    }

    public function test_search_matches_id_and_label_case_insensitively(): void
    {
        $index = GraphIndex::fromArray($this->queryFixtureGraph());

        $this->assertSame(
            ['App\Models\Note', 'App\Models\Tag'],
            array_column($index->search('models', 'model'), 'id')
        );

        $this->assertSame(
            ['App\Models\Tag', 'App\Other\Tag'],
            array_column($index->search('tag', 'model'), 'id')
        );

        $this->assertSame(['table:notes'], array_column($index->search('NOTES', 'table'), 'id'));
    }

    public function test_resolve_id_handles_exact_fuzzy_and_ambiguous_targets(): void
    {
        $index = GraphIndex::fromArray($this->queryFixtureGraph());

        $this->assertSame('App\Models\Note', $index->resolveId('App\Models\Note')['id']);
        $this->assertSame('table:notes', $index->resolveId('notes')['id']);
        $this->assertSame('column:notes.title', $index->resolveId('notes.title')['id']);
        $this->assertSame('route:PUT:/notes/{note}', $index->resolveId('notes.update')['id']);
        $this->assertSame('route:PUT:/notes/{note}', $index->resolveId('PUT /notes/{note}')['id']);
        $this->assertSame(
            'App\Http\Controllers\NoteController::update',
            $index->resolveId('NoteController::update')['id']
        );
        $this->assertSame(
            'App\Http\Controllers\NoteController::update',
            $index->resolveId('NoteController@update')['id']
        );
        $this->assertSame('App\Models\Note', $index->resolveId('Note')['id']);

        $ambiguous = $index->resolveId('Tag');
        $this->assertNull($ambiguous['id']);
        $this->assertSame(['App\Models\Tag', 'App\Other\Tag'], $ambiguous['candidates']);

        $this->assertNull($index->resolveId('DoesNotExist')['id']);
        $this->assertSame([], $index->resolveId('DoesNotExist')['candidates']);
    }
}
