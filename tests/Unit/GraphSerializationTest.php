<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use PHPUnit\Framework\TestCase;

class GraphSerializationTest extends TestCase
{
    public function test_to_array_omits_null_and_empty_fields(): void
    {
        $array = Node::make('table:users', 'table', 'users')->toArray();

        $this->assertSame(['id', 'type', 'label'], array_keys($array));
        $this->assertArrayNotHasKey('namespace', $array);
        $this->assertArrayNotHasKey('endLine', $array);
        $this->assertArrayNotHasKey('signature', $array);
        $this->assertArrayNotHasKey('inputs', $array);
        $this->assertArrayNotHasKey('metadata', $array);
    }

    public function test_node_serializes_and_merges_an_optional_end_line(): void
    {
        $graph = new Graph();
        $graph->addNode(Node::make('A::m', 'method', 'A::m', [
            'file' => 'app/A.php',
            'line' => 10,
        ]));
        $graph->addNode(Node::make('A::m', 'method', 'A::m', [
            'endLine' => 24,
        ]));

        $node = $graph->node('A::m');

        $this->assertNotNull($node);
        $this->assertSame(24, $node->endLine);
        $this->assertSame(24, $node->toArray()['endLine']);
    }

    public function test_edge_to_array_omits_empty_metadata(): void
    {
        $array = (new Edge('A::m', 'B::n', 'calls'))->toArray();

        $this->assertSame(['from', 'to', 'type', 'confidence'], array_keys($array));
        $this->assertArrayNotHasKey('metadata', $array);
    }

    public function test_node_merge_unions_source_ids_without_duplicates(): void
    {
        $graph = new Graph();

        $graph->addNode(Node::make('column:users.id', 'column', 'users.id', [
            'metadata' => ['table' => 'users', 'sources' => ['central']],
        ]));
        $graph->addNode(Node::make('column:users.id', 'column', 'users.id', [
            'metadata' => ['sources' => ['tenant-5']],
        ]));
        // Re-adding an existing source id must not duplicate it.
        $graph->addNode(Node::make('column:users.id', 'column', 'users.id', [
            'metadata' => ['sources' => ['central']],
        ]));

        $node = $graph->node('column:users.id');

        $this->assertNotNull($node);
        $this->assertSame(['central', 'tenant-5'], $node->metadata['sources']);
        $this->assertSame('users', $node->metadata['table']);
    }

    public function test_edge_merge_unions_distinct_operations_and_keeps_max_confidence(): void
    {
        $graph = new Graph();

        $graph->addEdge(new Edge('A::m', 'table:t', 'writes', 0.9, [
            'targetRole' => 'model_table',
            'operations' => ['10:insert:model_table' => ['line' => 10, 'operation' => 'insert']],
        ]));
        $graph->addEdge(new Edge('A::m', 'table:t', 'writes', 0.5, [
            'targetRole' => 'model_table',
            'operations' => ['12:update:model_table' => ['line' => 12, 'operation' => 'update']],
        ]));

        $edge = $graph->edges()[0] ?? null;

        $this->assertNotNull($edge);
        $this->assertSame(0.9, $edge->confidence);
        $this->assertSame(
            ['10:insert:model_table', '12:update:model_table'],
            array_keys($edge->metadata['operations'])
        );
    }

    public function test_edge_merge_preserves_evidence_from_every_call_site(): void
    {
        $graph = new Graph();
        $graph->addNode(Node::make('A::m', 'method', 'A::m', [
            'file' => 'app/A.php',
            'line' => 5,
        ]));
        $graph->addEdge(new Edge('A::m', 'B::n', 'calls', 0.9, [
            'line' => 10,
            'inference' => 'typed_parameter',
            'syntax' => 'method_call',
        ]));
        $graph->addEdge(new Edge('A::m', 'B::n', 'calls', 0.8, [
            'line' => 20,
            'inference' => 'container_helper',
            'syntax' => 'method_call',
        ]));

        $edge = $graph->edges()[0] ?? null;

        $this->assertNotNull($edge);
        $this->assertCount(2, $edge->metadata['evidence']);
        $this->assertSame([10, 20], array_column(array_values($edge->metadata['evidence']), 'line'));
        $this->assertSame(
            ['typed_parameter', 'container_helper'],
            array_column(array_values($edge->metadata['evidence']), 'inference')
        );
    }
}
