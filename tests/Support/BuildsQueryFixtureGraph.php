<?php

namespace AppGraph\Tests\Support;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;

trait BuildsQueryFixtureGraph
{
    /**
     * A small synthetic export shaped like a real appgraph.json: one route into a
     * controller, a service-layer call chain with a cycle, two models, two tables,
     * a column, and a form request.
     *
     * @return array<string, mixed>
     */
    protected function queryFixtureGraph(): array
    {
        return [
            'meta' => [
                'generatedAt' => '2026-06-09T00:00:00.000000Z',
                'appName' => 'Query Fixture App',
                'laravelVersion' => '12.0.0',
                'appgraphVersion' => '0.4.1',
            ],
            'nodes' => [
                ['id' => 'route:PUT:/notes/{note}', 'type' => 'route', 'label' => 'PUT /notes/{note}', 'metadata' => ['name' => 'notes.update']],
                ['id' => 'App\Http\Controllers\NoteController::update', 'type' => 'method', 'label' => 'NoteController::update', 'file' => 'app/Http/Controllers/NoteController.php', 'line' => 20],
                ['id' => 'App\Http\Controllers\NoteController::index', 'type' => 'method', 'label' => 'NoteController::index', 'file' => 'app/Http/Controllers/NoteController.php', 'line' => 10],
                ['id' => 'App\Services\NoteService::save', 'type' => 'method', 'label' => 'NoteService::save', 'file' => 'app/Services/NoteService.php', 'line' => 15],
                ['id' => 'App\Services\NoteService::helper', 'type' => 'method', 'label' => 'NoteService::helper', 'file' => 'app/Services/NoteService.php', 'line' => 30],
                ['id' => 'App\Models\Note', 'type' => 'model', 'label' => 'Note', 'file' => 'app/Models/Note.php', 'line' => 9, 'metadata' => ['table' => 'notes']],
                ['id' => 'App\Models\Tag', 'type' => 'model', 'label' => 'Tag', 'file' => 'app/Models/Tag.php', 'line' => 7],
                ['id' => 'App\Other\Tag', 'type' => 'model', 'label' => 'Tag', 'file' => 'app/Other/Tag.php', 'line' => 5],
                ['id' => 'App\Http\Requests\UpdateNoteRequest', 'type' => 'form_request', 'label' => 'UpdateNoteRequest', 'file' => 'app/Http/Requests/UpdateNoteRequest.php', 'line' => 8],
                ['id' => 'table:notes', 'type' => 'table', 'label' => 'notes'],
                ['id' => 'table:tags', 'type' => 'table', 'label' => 'tags'],
                ['id' => 'column:notes.title', 'type' => 'column', 'label' => 'notes.title'],
            ],
            'edges' => [
                ['from' => 'route:PUT:/notes/{note}', 'to' => 'App\Http\Controllers\NoteController::update', 'type' => 'routes_to', 'confidence' => 1.0],
                ['from' => 'App\Http\Controllers\NoteController::update', 'to' => 'App\Http\Requests\UpdateNoteRequest', 'type' => 'validates_with', 'confidence' => 1.0],
                ['from' => 'App\Http\Controllers\NoteController::update', 'to' => 'App\Models\Note', 'type' => 'uses_model', 'confidence' => 0.9],
                ['from' => 'App\Http\Controllers\NoteController::update', 'to' => 'App\Services\NoteService::save', 'type' => 'calls', 'confidence' => 1.0],
                ['from' => 'App\Services\NoteService::save', 'to' => 'App\Services\NoteService::helper', 'type' => 'calls', 'confidence' => 0.9],
                ['from' => 'App\Services\NoteService::helper', 'to' => 'App\Services\NoteService::save', 'type' => 'calls', 'confidence' => 0.8],
                ['from' => 'App\Services\NoteService::save', 'to' => 'table:notes', 'type' => 'writes', 'confidence' => 0.85, 'metadata' => [
                    'operations' => [
                        '16:create:model_table' => ['line' => 16, 'operation' => 'create'],
                        '18:save:model_table' => ['line' => 18, 'operation' => 'save', 'fieldCoverage' => 'unknown'],
                    ],
                ]],
                ['from' => 'App\Http\Controllers\NoteController::index', 'to' => 'table:notes', 'type' => 'reads', 'confidence' => 0.9, 'metadata' => [
                    'operations' => [
                        '11:get:model_table' => ['line' => 11, 'operation' => 'get'],
                    ],
                ]],
                ['from' => 'App\Models\Note', 'to' => 'table:notes', 'type' => 'uses_table', 'confidence' => 1.0],
                ['from' => 'App\Models\Tag', 'to' => 'table:tags', 'type' => 'uses_table', 'confidence' => 0.85],
                ['from' => 'App\Models\Note', 'to' => 'App\Models\Tag', 'type' => 'belongs_to_many', 'confidence' => 0.75],
                ['from' => 'table:notes', 'to' => 'column:notes.title', 'type' => 'has_column', 'confidence' => 1.0],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    protected function graphObject(array $data): Graph
    {
        $graph = new Graph(is_array($data['meta'] ?? null) ? $data['meta'] : []);

        foreach ($data['nodes'] ?? [] as $node) {
            $attributes = $node;
            unset($attributes['id'], $attributes['type'], $attributes['label']);
            $graph->addNode(Node::make(
                (string) $node['id'],
                (string) $node['type'],
                (string) ($node['label'] ?? $node['id']),
                $attributes,
            ));
        }

        foreach ($data['edges'] ?? [] as $edge) {
            $graph->addEdge(new Edge(
                (string) $edge['from'],
                (string) $edge['to'],
                (string) $edge['type'],
                (float) ($edge['confidence'] ?? 1.0),
                is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [],
            ));
        }

        return $graph;
    }
}
