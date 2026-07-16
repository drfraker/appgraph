<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\LaravelExecutionSemantics;
use AppGraph\Tests\TestCase;

class LaravelExecutionSemanticsTest extends TestCase
{
    public function test_dispatch_analysis_fails_closed_when_causal_evidence_is_beyond_the_hard_cap(): void
    {
        $occurrences = array_fill(0, 100000, [
            'kind' => 'event',
            'causalExecutionProven' => false,
        ]);
        $occurrences[99999] = [
            'kind' => 'event',
            'causalExecutionProven' => true,
        ];
        $semantics = new LaravelExecutionSemantics(GraphIndex::fromArray([
            'nodes' => [],
            'edges' => [],
        ]));
        $startedAt = hrtime(true);

        $analysis = $semantics->dispatchAnalysis([
            'metadata' => [
                'dispatchOccurrences' => $occurrences,
                // An inconsistent ordinary aggregate must not override the
                // explicitly non-causal bounded prefix.
                'causalExecutionProven' => true,
                'dispatchKinds' => ['event'],
            ],
        ], ['type' => 'event']);
        $elapsedNanoseconds = hrtime(true) - $startedAt;

        $this->assertFalse($analysis['causalExecutionProven']);
        $this->assertSame([], $analysis['kinds']);
        $this->assertTrue($analysis['truncated']);
        $this->assertSame(
            LaravelExecutionSemantics::MAX_DISPATCH_OCCURRENCES,
            $analysis['examinedOccurrences'],
        );
        $this->assertLessThan(1_000_000_000, $elapsedNanoseconds);
    }

    public function test_dispatch_analysis_uses_a_safe_aggregate_without_scanning_an_unbounded_tail(): void
    {
        $occurrences = array_fill(0, 100000, [
            'kind' => 'unknown',
            'causalExecutionProven' => true,
        ]);
        $occurrences[99999] = [
            'kind' => 'event',
            'causalExecutionProven' => true,
        ];
        $event = 'App\\Events\\Published';
        $index = GraphIndex::fromArray([
            'nodes' => [['id' => $event, 'type' => 'event']],
            'edges' => [],
        ]);
        $semantics = new LaravelExecutionSemantics($index);
        $edge = [
            'type' => 'dispatches',
            'metadata' => [
                'dispatchOccurrences' => $occurrences,
                'causalExecutionProven' => true,
                'dispatchKinds' => ['event'],
            ],
        ];

        $analysis = $semantics->dispatchAnalysis($edge, $index->node($event));
        $transition = $semantics->transition([], $edge, $event, 'out');

        $this->assertTrue($analysis['causalExecutionProven']);
        $this->assertSame(['event'], $analysis['kinds']);
        $this->assertTrue($analysis['truncated']);
        $this->assertSame(
            LaravelExecutionSemantics::MAX_DISPATCH_OCCURRENCES,
            $analysis['examinedOccurrences'],
        );
        $this->assertSame('dispatch:event', $transition['statePartition']);
        $this->assertTrue($transition['metadataTruncated']);
    }

    public function test_truncated_kind_aggregates_do_not_fall_back_to_an_invented_target_role(): void
    {
        $semantics = new LaravelExecutionSemantics(GraphIndex::fromArray([
            'nodes' => [],
            'edges' => [],
        ]));
        $aggregate = array_fill(0, 100000, 'unknown');
        $aggregate[99999] = 'event';

        $analysis = $semantics->dispatchAnalysis([
            'metadata' => ['dispatchKinds' => $aggregate],
        ], ['type' => 'job']);

        $this->assertTrue($analysis['causalExecutionProven']);
        $this->assertSame([], $analysis['kinds']);
        $this->assertTrue($analysis['truncated']);
        $this->assertSame(0, $analysis['examinedOccurrences']);
    }

    public function test_forward_traversal_crosses_only_active_handlers_matching_the_dispatch_role(): void
    {
        $dispatcher = 'App\\Actions\\Publish::run';
        $dual = 'App\\Messages\\Published';
        $listener = 'App\\Listeners\\Announce::handle';
        $jobHandler = 'App\\Jobs\\Published::handle';
        $inactive = 'App\\Listeners\\Inactive::handle';
        $index = GraphIndex::fromArray([
            'nodes' => [
                ['id' => $dispatcher, 'type' => 'method'],
                ['id' => $dual, 'type' => 'event', 'metadata' => ['roles' => ['event', 'job']]],
                ['id' => $listener, 'type' => 'method'],
                ['id' => $jobHandler, 'type' => 'method'],
                ['id' => $inactive, 'type' => 'method'],
            ],
            'edges' => [
                [
                    'from' => $dispatcher,
                    'to' => $dual,
                    'type' => 'dispatches',
                    'metadata' => ['dispatchOccurrences' => [
                        ['kind' => 'event', 'causalExecutionProven' => true],
                        ['kind' => 'job', 'causalExecutionProven' => false],
                    ]],
                ],
                ['from' => $dual, 'to' => $listener, 'type' => 'handled_by', 'metadata' => ['kind' => 'listener']],
                ['from' => $dual, 'to' => $jobHandler, 'type' => 'handled_by', 'metadata' => ['kind' => 'job']],
                [
                    'from' => $dual,
                    'to' => $inactive,
                    'type' => 'handled_by',
                    'metadata' => ['kind' => 'listener', 'causalExecutionProven' => false],
                ],
            ],
        ]);
        $semantics = new LaravelExecutionSemantics($index);
        $truncated = false;

        $hits = $index->traverseFromSeeds(
            [['id' => $dispatcher]],
            $semantics->executionEdgeTypes(),
            direction: 'out',
            maxDepth: 2,
            limit: 20,
            resultNodeTypes: ['method'],
            transition: $semantics->transition(...),
            truncated: $truncated,
        );

        $this->assertSame([$dispatcher, $listener], array_column($hits, 'id'));
        $this->assertFalse($truncated);
    }

    public function test_reverse_traversal_keeps_dual_event_and_job_paths_partitioned(): void
    {
        $eventDispatcher = 'App\\Actions\\PublishEvent::run';
        $jobDispatcher = 'App\\Actions\\PublishJob::run';
        $dual = 'App\\Messages\\Published';
        $listener = 'App\\Listeners\\Announce::handle';
        $jobHandler = 'App\\Jobs\\Published::handle';
        $inactive = 'App\\Listeners\\Inactive::handle';
        $index = GraphIndex::fromArray([
            'nodes' => [
                ['id' => $eventDispatcher, 'type' => 'method'],
                ['id' => $jobDispatcher, 'type' => 'method'],
                ['id' => $dual, 'type' => 'event', 'metadata' => ['roles' => ['event', 'job']]],
                ['id' => $listener, 'type' => 'method'],
                ['id' => $jobHandler, 'type' => 'method'],
                ['id' => $inactive, 'type' => 'method'],
            ],
            'edges' => [
                [
                    'from' => $eventDispatcher,
                    'to' => $dual,
                    'type' => 'dispatches',
                    'metadata' => ['dispatchOccurrences' => [
                        ['kind' => 'event', 'causalExecutionProven' => true],
                        ['kind' => 'job', 'causalExecutionProven' => false],
                    ]],
                ],
                [
                    'from' => $jobDispatcher,
                    'to' => $dual,
                    'type' => 'dispatches',
                    'metadata' => ['dispatchOccurrences' => [
                        ['kind' => 'event', 'causalExecutionProven' => false],
                        ['kind' => 'job', 'causalExecutionProven' => true],
                    ]],
                ],
                ['from' => $dual, 'to' => $listener, 'type' => 'handled_by', 'metadata' => ['kind' => 'listener']],
                ['from' => $dual, 'to' => $jobHandler, 'type' => 'handled_by', 'metadata' => ['kind' => 'job']],
                [
                    'from' => $dual,
                    'to' => $inactive,
                    'type' => 'handled_by',
                    'metadata' => ['kind' => 'listener', 'causalExecutionProven' => false],
                ],
            ],
        ]);
        $semantics = new LaravelExecutionSemantics($index);

        $this->assertSame(
            [$listener, $eventDispatcher],
            array_column($this->reverseMethodHits($index, $semantics, $listener), 'id'),
        );
        $this->assertSame(
            [$jobHandler, $jobDispatcher],
            array_column($this->reverseMethodHits($index, $semantics, $jobHandler), 'id'),
        );
        $this->assertSame(
            [$inactive],
            array_column($this->reverseMethodHits($index, $semantics, $inactive), 'id'),
        );

        $eventState = $semantics->transition([], [
            'type' => 'handled_by',
            'metadata' => ['kind' => 'listener'],
        ], $dual, 'in');
        $jobState = $semantics->transition([], [
            'type' => 'handled_by',
            'metadata' => ['kind' => 'job'],
        ], $dual, 'in');

        $this->assertSame('dispatch:event', $eventState['statePartition']);
        $this->assertSame('dispatch:job', $jobState['statePartition']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reverseMethodHits(
        GraphIndex $index,
        LaravelExecutionSemantics $semantics,
        string $seed,
    ): array {
        $truncated = false;

        return $index->traverseFromSeeds(
            [['id' => $seed]],
            $semantics->executionEdgeTypes(),
            direction: 'in',
            maxDepth: 2,
            limit: 20,
            resultNodeTypes: ['method'],
            transition: $semantics->transition(...),
            truncated: $truncated,
        );
    }
}
