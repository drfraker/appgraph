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

    public function test_ranked_edge_selectors_merge_types_by_confidence_and_report_omitted_edges(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => [],
            'edges' => [
                ['from' => 'Root', 'to' => 'A', 'type' => 'calls', 'confidence' => 0.1],
                ['from' => 'Root', 'to' => 'Z', 'type' => 'dispatches', 'confidence' => 0.9],
                ['from' => 'Y', 'to' => 'Root', 'type' => 'calls', 'confidence' => 0.8],
                ['from' => 'X', 'to' => 'Root', 'type' => 'dispatches', 'confidence' => 1.0],
            ],
        ];
        $index = GraphIndex::fromArray($graph);
        $truncated = false;

        $out = $index->rankedEdgesFrom('Root', ['calls', 'dispatches'], 1, $truncated);

        $this->assertSame(['Z'], array_column($out, 'to'));
        $this->assertTrue($truncated);

        $truncated = false;
        $in = $index->rankedEdgesTo('Root', ['calls', 'dispatches'], 2, $truncated);

        $this->assertSame(['X', 'Y'], array_column($in, 'from'));
        $this->assertFalse($truncated);

        $truncated = false;
        $this->assertSame([], $index->rankedEdgesFrom('Root', ['calls'], 0, $truncated));
        $this->assertTrue($truncated);

        $truncated = false;
        $this->assertSame([], $index->rankedEdgesFrom('Root', ['missing'], 0, $truncated));
        $this->assertFalse($truncated);
    }

    public function test_ranked_edge_selection_stays_bounded_on_high_fanout_adjacency(): void
    {
        $edges = [];

        for ($index = 0; $index < 10000; $index++) {
            $edges[] = [
                'from' => 'Root',
                'to' => sprintf('N%05d', $index),
                'type' => 'calls',
                'confidence' => $index / 10000,
            ];
        }

        $truncated = false;
        $selected = GraphIndex::fromArray([
            'meta' => [],
            'nodes' => [],
            'edges' => $edges,
        ])->rankedEdgesFrom('Root', ['calls'], 2, $truncated);

        $this->assertSame(['N09999', 'N09998'], array_column($selected, 'to'));
        $this->assertTrue($truncated);
    }

    public function test_ranked_source_edges_merge_weighted_adjacencies_globally_and_deterministically(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => [],
            'edges' => [
                ['from' => 'Direct', 'to' => 'DirectModel', 'type' => 'uses_model', 'confidence' => 0.75],
                ['from' => 'Service', 'to' => 'ServiceModel', 'type' => 'uses_model', 'confidence' => 0.95],
                ['from' => 'Helper', 'to' => 'HelperModel', 'type' => 'uses_model', 'confidence' => 1.0],
            ],
        ];
        $sources = [
            ['id' => 'Direct', 'confidence' => 1.0, 'depth' => 0, 'path' => ['Direct']],
            ['id' => 'Service', 'confidence' => 0.8, 'depth' => 1, 'path' => ['Direct', 'Service']],
            ['id' => 'Helper', 'confidence' => 0.72, 'depth' => 2, 'path' => ['Direct', 'Service', 'Helper']],
        ];
        $truncated = false;

        $selected = GraphIndex::fromArray($graph)->rankedEdgesFromSources(
            $sources,
            ['uses_model'],
            2,
            $truncated,
        );

        $this->assertSame(['Service', 'Direct'], array_column($selected, 'source'));
        $this->assertSame(['ServiceModel', 'DirectModel'], array_column(array_column($selected, 'edge'), 'to'));
        $this->assertTrue($truncated);

        $reordered = $graph;
        $reordered['edges'] = array_reverse($reordered['edges']);
        $reorderedTruncated = false;
        $this->assertSame(
            $selected,
            GraphIndex::fromArray($reordered)->rankedEdgesFromSources(
                array_reverse($sources),
                ['uses_model'],
                2,
                $reorderedTruncated,
            ),
        );
        $this->assertTrue($reorderedTruncated);
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

    public function test_traverse_marks_depth_limited_results_as_truncated_including_at_depth_zero(): void
    {
        $index = GraphIndex::fromArray($this->queryFixtureGraph());
        $truncated = false;

        $shallow = $index->traverse(
            'App\Http\Controllers\NoteController::update',
            ['calls'],
            maxDepth: 1,
            truncated: $truncated,
        );

        $this->assertSame(['App\Services\NoteService::save'], array_column($shallow, 'id'));
        $this->assertTrue($truncated);

        $truncated = false;
        $none = $index->traverse(
            'App\Http\Controllers\NoteController::update',
            ['calls'],
            maxDepth: 0,
            truncated: $truncated,
        );

        $this->assertSame([], $none);
        $this->assertTrue($truncated);
    }

    public function test_depth_truncation_ignores_cycles_and_ineligible_transitions(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'method', 'label' => $id],
                ['A', 'B', 'C', 'D'],
            ),
            'edges' => [
                ['from' => 'A', 'to' => 'B', 'type' => 'calls', 'confidence' => 1.0],
                ['from' => 'B', 'to' => 'A', 'type' => 'calls', 'confidence' => 1.0],
                ['from' => 'B', 'to' => 'C', 'type' => 'calls', 'confidence' => 0.2],
                ['from' => 'B', 'to' => 'D', 'type' => 'calls', 'confidence' => 1.0],
            ],
        ];
        $truncated = false;

        $results = GraphIndex::fromArray($graph)->traverseFromSeeds(
            [['id' => 'A']],
            ['calls'],
            maxDepth: 1,
            minConfidence: 0.5,
            transition: static fn (
                array $state,
                array $edge,
                string $neighborId,
                string $direction,
            ): bool => $neighborId !== 'D',
            truncated: $truncated,
        );

        $this->assertSame(['A', 'B'], array_column($results, 'id'));
        $this->assertFalse($truncated);
    }

    public function test_depth_truncation_distinguishes_the_same_node_in_different_execution_partitions(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'method', 'label' => $id],
                ['Entry', 'DualRoleJob', 'Listener'],
            ),
            'edges' => [
                [
                    'from' => 'Entry',
                    'to' => 'DualRoleJob',
                    'type' => 'dispatches',
                    'confidence' => 1.0,
                    'metadata' => ['partition' => 'dispatch:event'],
                ],
                [
                    'from' => 'DualRoleJob',
                    'to' => 'Listener',
                    'type' => 'handled_by',
                    'confidence' => 1.0,
                    'metadata' => ['partition' => ''],
                ],
                [
                    'from' => 'Listener',
                    'to' => 'DualRoleJob',
                    'type' => 'dispatches',
                    'confidence' => 1.0,
                    'metadata' => ['partition' => 'dispatch:job'],
                ],
            ],
        ];
        $transition = static fn (
            array $state,
            array $edge,
            string $neighborId,
            string $direction,
        ): array => ['statePartition' => $edge['metadata']['partition']];
        $truncated = false;

        GraphIndex::fromArray($graph)->traverseFromSeeds(
            [['id' => 'Entry']],
            ['dispatches', 'handled_by'],
            maxDepth: 2,
            transition: $transition,
            truncated: $truncated,
        );

        $this->assertTrue($truncated);

        $graph['edges'][2]['metadata']['partition'] = 'dispatch:event';
        $truncated = false;

        GraphIndex::fromArray($graph)->traverseFromSeeds(
            [['id' => 'Entry']],
            ['dispatches', 'handled_by'],
            maxDepth: 2,
            transition: $transition,
            truncated: $truncated,
        );

        $this->assertFalse($truncated);
    }

    public function test_seeded_traversal_hard_bounds_examined_transitions_deterministically(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => [
                ['id' => 'A', 'type' => 'entry', 'label' => 'A'],
                ['id' => 'B', 'type' => 'result', 'label' => 'B'],
                ['id' => 'C', 'type' => 'result', 'label' => 'C'],
            ],
            'edges' => [
                ['from' => 'A', 'to' => 'C', 'type' => 'calls', 'confidence' => 1.0],
                ['from' => 'A', 'to' => 'B', 'type' => 'calls', 'confidence' => 1.0],
            ],
        ];
        $index = GraphIndex::fromArray($graph);
        $truncated = false;

        $none = $index->traverse(
            'A',
            ['calls'],
            truncated: $truncated,
            maxTransitions: 0,
        );

        $this->assertSame([], $none);
        $this->assertTrue($truncated);

        $truncated = false;

        $bounded = $index->traverseFromSeeds(
            [['id' => 'A']],
            ['calls'],
            resultNodeTypes: ['result'],
            truncated: $truncated,
            maxTransitions: 1,
        );

        $this->assertSame(['B'], array_column($bounded, 'id'));
        $this->assertTrue($truncated);

        $truncated = false;
        $complete = $index->traverseFromSeeds(
            [['id' => 'A']],
            ['calls'],
            resultNodeTypes: ['result'],
            truncated: $truncated,
            maxTransitions: 2,
        );

        $this->assertSame(['B', 'C'], array_column($complete, 'id'));
        $this->assertFalse($truncated);
    }

    public function test_transition_budget_prefers_strong_edges_and_frontier_states_over_lexical_order(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => [
                ['id' => 'Start', 'type' => 'entry', 'label' => 'Start'],
                ['id' => 'A', 'type' => 'result', 'label' => 'A'],
                ['id' => 'Z', 'type' => 'result', 'label' => 'Z'],
                ['id' => 'ASeed', 'type' => 'entry', 'label' => 'ASeed'],
                ['id' => 'ZSeed', 'type' => 'entry', 'label' => 'ZSeed'],
            ],
            'edges' => [
                ['from' => 'Start', 'to' => 'A', 'type' => 'calls', 'confidence' => 0.1],
                ['from' => 'Start', 'to' => 'Z', 'type' => 'calls', 'confidence' => 0.9],
                ['from' => 'ASeed', 'to' => 'A', 'type' => 'calls', 'confidence' => 0.2],
                ['from' => 'ZSeed', 'to' => 'Z', 'type' => 'calls', 'confidence' => 1.0],
            ],
        ];
        $index = GraphIndex::fromArray($graph);
        $truncated = false;

        $edgeRanked = $index->traverse(
            'Start',
            ['calls'],
            truncated: $truncated,
            maxTransitions: 1,
        );

        $this->assertSame(['Z'], array_column($edgeRanked, 'id'));
        $this->assertTrue($truncated);

        $truncated = false;
        $stateRanked = $index->traverseFromSeeds(
            [
                ['id' => 'ASeed', 'confidence' => 1.0],
                ['id' => 'ZSeed', 'confidence' => 0.8],
            ],
            ['calls'],
            resultNodeTypes: ['result'],
            truncated: $truncated,
            maxTransitions: 1,
        );

        $this->assertSame(['Z'], array_column($stateRanked, 'id'));
        $this->assertSame(0.8, $stateRanked[0]['confidence']);
        $this->assertTrue($truncated);
    }

    public function test_transition_budget_is_best_first_across_newly_discovered_frontier_states(): void
    {
        $graph = [
            'meta' => [],
            'nodes' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'method', 'label' => $id],
                ['Start', 'A', 'B', 'Z'],
            ),
            'edges' => [
                ['from' => 'Start', 'to' => 'B', 'type' => 'calls', 'confidence' => 0.01],
                ['from' => 'A', 'to' => 'Z', 'type' => 'calls', 'confidence' => 1.0],
                ['from' => 'Start', 'to' => 'A', 'type' => 'calls', 'confidence' => 1.0],
            ],
        ];
        $truncated = false;

        $bounded = GraphIndex::fromArray($graph)->traverse(
            'Start',
            ['calls'],
            maxDepth: 2,
            truncated: $truncated,
            maxTransitions: 2,
        );

        $this->assertSame(['A', 'Z'], array_column($bounded, 'id'));
        $this->assertSame(['Start', 'A', 'Z'], $bounded[1]['path']);
        $this->assertTrue($truncated);

        $reordered = $graph;
        $reordered['edges'] = array_reverse($reordered['edges']);
        $reorderedTruncated = false;
        $this->assertSame(
            $bounded,
            GraphIndex::fromArray($reordered)->traverse(
                'Start',
                ['calls'],
                maxDepth: 2,
                truncated: $reorderedTruncated,
                maxTransitions: 2,
            ),
        );
        $this->assertTrue($reorderedTruncated);

        $reverseGraph = $graph;
        $reverseGraph['edges'][] = ['from' => 'B', 'to' => 'Z', 'type' => 'calls', 'confidence' => 0.01];
        $reverseTruncated = false;
        $reverse = GraphIndex::fromArray($reverseGraph)->traverse(
            'Z',
            ['calls'],
            direction: 'in',
            maxDepth: 2,
            truncated: $reverseTruncated,
            maxTransitions: 2,
        );

        $this->assertSame(['A', 'Start'], array_column($reverse, 'id'));
        $this->assertSame(['Z', 'A', 'Start'], $reverse[1]['path']);
        $this->assertTrue($reverseTruncated);

        $completeTruncated = false;
        $complete = GraphIndex::fromArray($graph)->traverse(
            'Start',
            ['calls'],
            maxDepth: 2,
            truncated: $completeTruncated,
            maxTransitions: 3,
        );

        $this->assertSame(['A', 'Z', 'B'], array_column($complete, 'id'));
        $this->assertFalse($completeTruncated);
    }

    public function test_best_first_transition_queue_handles_a_wide_frontier_without_rescanning_it(): void
    {
        $nodes = [['id' => 'Root', 'type' => 'entry', 'label' => 'Root']];
        $edges = [];
        $width = 2000;

        for ($index = 0; $index < $width; $index++) {
            $branch = sprintf('Branch%04d', $index);
            $leaf = sprintf('Leaf%04d', $index);
            $nodes[] = ['id' => $branch, 'type' => 'branch', 'label' => $branch];
            $nodes[] = ['id' => $leaf, 'type' => 'leaf', 'label' => $leaf];
            $edges[] = ['from' => 'Root', 'to' => $branch, 'type' => 'calls', 'confidence' => 1.0];
            $edges[] = ['from' => $branch, 'to' => $leaf, 'type' => 'calls', 'confidence' => 1.0];
        }

        $truncated = false;
        $results = GraphIndex::fromArray([
            'meta' => [],
            'nodes' => $nodes,
            'edges' => array_reverse($edges),
        ])->traverseFromSeeds(
            [['id' => 'Root']],
            ['calls'],
            maxDepth: 2,
            limit: $width,
            resultNodeTypes: ['leaf'],
            truncated: $truncated,
            maxTransitions: $width * 2,
        );

        $this->assertCount($width, $results);
        $this->assertSame('Leaf0000', $results[0]['id']);
        $this->assertSame('Leaf1999', $results[array_key_last($results)]['id']);
        $this->assertFalse($truncated);
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

    public function test_seeded_traversal_caps_and_sanitizes_supplied_edge_histories_with_explicit_omissions(): void
    {
        $history = [];

        for ($edge = 0; $edge < 100; $edge++) {
            $history[] = [
                'from' => "Node{$edge}",
                'to' => 'Node'.($edge + 1),
                'type' => 'calls',
                'confidence' => 0.9,
                'unbounded' => str_repeat('discard-me', 100),
                'metadata' => [
                    'unbounded' => str_repeat('discard-me', 100),
                    'evidence' => [
                        ['file' => 'app/Action.php', 'line' => $edge + 1, 'rule' => 'call', 'secret' => 'discard-me'],
                        ['source' => 'reflection'],
                        ['syntax' => 'method_call'],
                    ],
                ],
            ];
        }

        $results = GraphIndex::fromArray([
            'meta' => [],
            'nodes' => [
                ['id' => 'Seed', 'type' => 'method', 'label' => 'Seed'],
            ],
            'edges' => [],
        ])->traverseFromSeeds(
            [['id' => 'Seed', 'edges' => $history]],
            [],
            includeEdges: true,
            maxEvidencePerEdge: 1,
        );

        $this->assertCount(1, $results);
        $this->assertCount(64, $results[0]['edges']);
        $this->assertSame(36, $results[0]['edgesOmitted']);
        $this->assertSame([
            'from' => 'Node0',
            'to' => 'Node1',
            'type' => 'calls',
            'confidence' => 0.9,
            'evidence' => [[
                'file' => 'app/Action.php',
                'line' => 1,
                'rule' => 'call',
            ]],
            'evidenceOmitted' => 2,
        ], $results[0]['edges'][0]);
        $this->assertArrayNotHasKey('metadata', $results[0]['edges'][0]);
        $this->assertArrayNotHasKey('unbounded', $results[0]['edges'][0]);
    }

    public function test_seeded_traversal_preserves_exact_evidence_files_or_omits_the_record(): void
    {
        $exactFile = str_repeat('nested/', 100).'Action.php';
        $oversizedFile = str_repeat('x', 4097);
        $history = [[
            'from' => 'Seed',
            'to' => 'Target',
            'type' => 'calls',
            'metadata' => [
                'evidence' => [
                    ['file' => $exactFile, 'line' => 10, 'rule' => 'exact_source'],
                    ['file' => $oversizedFile, 'line' => 11, 'rule' => 'oversized_source'],
                ],
            ],
        ]];

        $results = GraphIndex::fromArray([
            'meta' => [],
            'nodes' => [
                ['id' => 'Seed', 'type' => 'method', 'label' => 'Seed'],
            ],
            'edges' => [],
        ])->traverseFromSeeds(
            [['id' => 'Seed', 'edges' => $history]],
            [],
            includeEdges: true,
            maxEvidencePerEdge: 2,
        );

        $edge = $results[0]['edges'][0];

        $this->assertSame($exactFile, $edge['evidence'][0]['file']);
        $this->assertSame(1, $edge['evidenceOmitted']);
        $this->assertCount(1, $edge['evidence']);
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

    public function test_resolve_id_bounds_large_ambiguity_buckets(): void
    {
        $nodes = [];

        for ($index = 0; $index < 12; $index++) {
            $nodes[] = [
                'id' => sprintf('App\\Shared\\Candidate%02d', $index),
                'type' => 'class',
                'label' => 'Shared',
            ];
        }

        $resolved = GraphIndex::fromArray(['nodes' => $nodes, 'edges' => []])->resolveId('Shared');

        $this->assertNull($resolved['id']);
        $this->assertCount(10, $resolved['candidates']);
        $this->assertTrue($resolved['candidatesTruncated']);
    }
}
