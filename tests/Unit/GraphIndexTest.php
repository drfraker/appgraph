<?php

namespace AppGraph\Tests\Unit;

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
