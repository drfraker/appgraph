<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use AppGraph\Tests\TestCase;

class FindQueryEngineTest extends TestCase
{
    use BuildsQueryFixtureGraph;

    public function test_find_resolves_a_fuzzy_method_to_a_lean_node_card(): void
    {
        $result = $this->engine()->find('NoteService::save');

        $this->assertSame('find', $result['query']);
        $this->assertSame('NoteService::save', $result['target']);
        $this->assertSame('App\Services\NoteService::save', $result['resolved']);
        $this->assertSame($result['resolved'], $result['node']['id']);
        $this->assertArrayNotHasKey('metadata', $result['node']);
        $this->assertSame('calls', $result['out'][0]['type']);
        $this->assertSame('App\Http\Controllers\NoteController::update', $result['in'][0]['from']);
        $this->assertArrayNotHasKey('candidates', $result);
    }

    public function test_find_normalizes_class_at_method_targets(): void
    {
        $result = $this->engine()->find('App\Services\NoteService@save');

        $this->assertSame('App\Services\NoteService::save', $result['resolved']);
        $this->assertSame($result['resolved'], $result['node']['id']);
    }

    public function test_find_returns_resolver_candidates_before_ranked_substring_matches(): void
    {
        $result = $this->engine()->find('Tag');

        $this->assertSame(
            ['App\Models\Tag', 'App\Other\Tag'],
            array_slice(array_column($result['candidates'], 'id'), 0, 2),
        );
        $this->assertSame(
            ['id', 'type', 'label', 'file', 'line'],
            array_keys($result['candidates'][0]),
        );
        $this->assertArrayNotHasKey('resolved', $result);
        $this->assertArrayNotHasKey('status', $result);
    }

    public function test_find_hydrates_ambiguous_metadata_name_candidates_with_unrelated_ids(): void
    {
        $result = $this->engine([
            ['id' => 'route:GET:/alpha', 'type' => 'route', 'label' => 'GET /alpha', 'metadata' => ['name' => 'shared.dashboard']],
            ['id' => 'route:GET:/beta', 'type' => 'route', 'label' => 'GET /beta', 'metadata' => ['name' => 'shared.dashboard']],
        ])->find('shared.dashboard');

        $this->assertSame(
            ['route:GET:/alpha', 'route:GET:/beta'],
            array_column($result['candidates'], 'id'),
        );
    }

    public function test_find_reports_an_unobserved_target_without_turning_it_into_an_error(): void
    {
        $target = str_repeat('missing-target-', 40);
        $result = $this->engine()->find($target);

        $this->assertSame($target, $result['target']);
        $this->assertSame([], $result['candidates']);
        $this->assertSame('not_observed', $result['status']);
        $this->assertSame(
            'No graph node matched this target; graph coverage may be incomplete.',
            $result['hint'],
        );
        $this->assertArrayNotHasKey('resolved', $result);
        $this->assertArrayNotHasKey('truncated', $result);
    }

    public function test_find_preserves_resolver_truncation_even_when_candidate_limit_is_larger(): void
    {
        $nodes = [];

        for ($index = 1; $index <= 12; $index++) {
            $nodes[] = [
                'id' => sprintf('App\\Domain\\Candidate%02d', $index),
                'type' => 'class',
                'label' => 'Duplicate',
            ];
        }

        $result = $this->engine($nodes)->find('Duplicate', 50);

        $this->assertCount(12, $result['candidates']);
        $this->assertTrue($result['truncated']);
    }

    public function test_find_bounds_candidates_and_omits_empty_edge_lists_from_cards(): void
    {
        $ambiguous = $this->engine([
            ['id' => 'App\One\Thing', 'type' => 'class', 'label' => 'Thing'],
            ['id' => 'App\Two\Thing', 'type' => 'class', 'label' => 'Thing'],
        ])->find('Thing', 1);

        $this->assertCount(1, $ambiguous['candidates']);
        $this->assertTrue($ambiguous['truncated']);

        $resolved = $this->engine([
            ['id' => 'App\One\OnlyThing', 'type' => 'class', 'label' => 'OnlyThing'],
        ])->find('OnlyThing');

        $this->assertArrayNotHasKey('out', $resolved);
        $this->assertArrayNotHasKey('in', $resolved);
    }

    /** @param array<int, array<string, mixed>>|null $nodes */
    private function engine(?array $nodes = null): QueryEngine
    {
        $graph = $nodes === null
            ? $this->queryFixtureGraph()
            : ['meta' => [], 'nodes' => $nodes, 'edges' => []];

        return new QueryEngine(GraphIndex::fromArray($graph));
    }
}
