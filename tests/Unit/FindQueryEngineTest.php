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
        $this->assertSame('inferred', $result['out'][1]['uncertainty']);
        $this->assertSame('App\Http\Controllers\NoteController::update', $result['in'][0]['from']);
        $this->assertArrayNotHasKey('candidates', $result);
        $this->assertStringNotContainsString('"confidence"', json_encode($result, JSON_THROW_ON_ERROR));
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

    public function test_find_replaces_numeric_edge_confidence_with_uncertainty_buckets_and_recorded_reasons(): void
    {
        $target = 'App\Target::run';
        $nodes = [
            ['id' => $target, 'type' => 'method', 'label' => 'Target::run'],
            ['id' => 'App\Certain::run', 'type' => 'method', 'label' => 'Certain::run'],
            ['id' => 'App\Inferred::run', 'type' => 'method', 'label' => 'Inferred::run'],
            ['id' => 'App\Low::run', 'type' => 'method', 'label' => 'Low::run'],
        ];
        $edges = [
            ['from' => $target, 'to' => 'App\Certain::run', 'type' => 'calls', 'confidence' => 0.9],
            ['from' => $target, 'to' => 'App\Inferred::run', 'type' => 'calls', 'confidence' => 0.6, 'metadata' => ['ambiguity' => 'interface_dispatch']],
            ['from' => $target, 'to' => 'App\Low::run', 'type' => 'calls', 'confidence' => 0.59, 'metadata' => ['inference' => 'receiver_type_fallback']],
        ];
        $engine = new QueryEngine(GraphIndex::fromArray([
            'meta' => [],
            'nodes' => $nodes,
            'edges' => $edges,
        ]));

        $find = $engine->find($target);
        $out = array_column($find['out'], null, 'to');

        $this->assertArrayNotHasKey('uncertainty', $out['App\Certain::run']);
        $this->assertSame('inferred', $out['App\Inferred::run']['uncertainty']);
        $this->assertSame('interface_dispatch', $out['App\Inferred::run']['uncertaintyReason']);
        $this->assertSame('low', $out['App\Low::run']['uncertainty']);
        $this->assertSame('receiver_type_fallback', $out['App\Low::run']['uncertaintyReason']);
        $this->assertStringNotContainsString('"confidence"', json_encode($find, JSON_THROW_ON_ERROR));

        $legacyNode = $engine->node($target);
        $this->assertSame(0.9, $legacyNode['out'][0]['confidence']);
    }

    public function test_find_falls_back_to_all_token_matching_for_multi_word_targets(): void
    {
        $result = $this->engine([
            ['id' => 'App\Observers\AppointmentObserver', 'type' => 'class', 'label' => 'AppointmentObserver', 'file' => 'app/Observers/AppointmentObserver.php', 'line' => 11],
            ['id' => 'App\Models\Appointment', 'type' => 'model', 'label' => 'Appointment', 'file' => 'app/Models/Appointment.php', 'line' => 9],
        ])->find('appointment observer');

        $this->assertSame(
            ['App\Observers\AppointmentObserver'],
            array_column($result['candidates'] ?? [], 'id'),
        );
    }

    public function test_find_prefers_the_route_when_a_view_shares_its_dotted_name(): void
    {
        $engine = new QueryEngine(GraphIndex::fromArray([
            'meta' => [],
            'nodes' => [
                ['id' => 'route:GET:/notes/{note}', 'type' => 'route', 'label' => 'GET /notes/{note}', 'metadata' => ['name' => 'notes.show']],
                ['id' => 'view:notes.show', 'type' => 'view', 'label' => 'notes.show', 'file' => 'resources/views/notes/show.blade.php', 'metadata' => ['name' => 'notes.show']],
            ],
            'edges' => [],
        ]));

        $result = $engine->find('notes.show');

        $this->assertSame('route:GET:/notes/{note}', $result['resolved']);
        $this->assertSame('view:notes.show', $engine->find('view:notes.show')['resolved']);
    }

    public function test_find_resolves_a_dotted_blade_view_name_to_its_view_node(): void
    {
        $engine = new QueryEngine(GraphIndex::fromArray([
            'meta' => [],
            'nodes' => [
                ['id' => 'App\Http\Controllers\NoteController::show', 'type' => 'method', 'label' => 'NoteController::show', 'file' => 'app/Http/Controllers/NoteController.php', 'line' => 30],
                ['id' => 'view:notes.show', 'type' => 'view', 'label' => 'notes.show', 'file' => 'resources/views/notes/show.blade.php', 'line' => 1, 'endLine' => 12, 'metadata' => ['name' => 'notes.show', 'engine' => 'blade']],
            ],
            'edges' => [
                ['from' => 'App\Http\Controllers\NoteController::show', 'to' => 'view:notes.show', 'type' => 'renders', 'confidence' => 0.95],
            ],
        ]));

        $result = $engine->find('notes.show');

        $this->assertSame('view:notes.show', $result['resolved']);
        $this->assertSame('view', $result['node']['type']);
        $this->assertSame('resources/views/notes/show.blade.php', $result['node']['file']);
        $this->assertSame('App\Http\Controllers\NoteController::show', $result['in'][0]['from']);
        $this->assertSame('renders', $result['in'][0]['type']);
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
