<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\SearchPayload;
use AppGraph\Tests\TestCase;

class SearchPayloadTest extends TestCase
{
    public function test_it_projects_nodes_to_stable_source_coordinates(): void
    {
        $nodes = [
            [
                'id' => 'App\\Services\\NoteService::save',
                'type' => 'method',
                'label' => 'NoteService::save',
                'file' => 'app/Services/NoteService.php',
                'line' => 15,
                'endLine' => 27,
                'metadata' => ['name' => 'not-agent-facing'],
            ],
            [
                'id' => 'table:notes',
                'type' => 'table',
                'label' => 'notes',
                'file' => null,
            ],
        ];

        $this->assertSame([
            [
                'id' => 'App\\Services\\NoteService::save',
                'type' => 'method',
                'label' => 'NoteService::save',
                'file' => 'app/Services/NoteService.php',
                'line' => 15,
                'endLine' => 27,
            ],
            [
                'id' => 'table:notes',
                'type' => 'table',
                'label' => 'notes',
            ],
        ], SearchPayload::projectNodes($nodes));
    }

    public function test_store_payload_uses_the_same_projection(): void
    {
        $payload = SearchPayload::fromStoreResult('notes', [
            'generation' => [
                'id' => 42,
                'generatedAt' => '2026-07-21T12:00:00Z',
            ],
            'results' => [[
                'id' => 'route:GET:/notes',
                'type' => 'route',
                'label' => 'GET /notes',
                'metadata' => ['name' => 'notes.index'],
            ]],
            'truncated' => false,
        ]);

        $this->assertSame([[
            'id' => 'route:GET:/notes',
            'type' => 'route',
            'label' => 'GET /notes',
        ]], $payload['results']);
    }
}
