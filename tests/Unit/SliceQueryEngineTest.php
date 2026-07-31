<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Query\GraphIndex;
use AppGraph\Query\QueryEngine;
use AppGraph\Query\UnresolvedAnchorException;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class SliceQueryEngineTest extends TestCase
{
    private string $project;

    /** @var array<string, string> */
    private array $files = [
        'app/Http/Middleware/Authenticate.php' => 'middleware',
        'app/Http/Requests/UpdateNoteRequest.php' => 'request',
        'app/Policies/NotePolicy.php' => 'policy',
        'app/Http/Controllers/NoteController.php' => 'controller',
        'app/Services/NoteService.php' => 'service',
        'app/Models/Note.php' => 'model',
        'resources/js/Notes/Edit.vue' => 'frontend',
        'tests/Feature/NoteUpdateTest.php' => 'test',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = sys_get_temp_dir().'/appgraph-slice-'.bin2hex(random_bytes(4));
        mkdir($this->project, 0775, true);

        foreach ($this->files as $file => $label) {
            $this->write($file, $this->source($label));
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->project);

        parent::tearDown();
    }

    public function test_slice_resolves_typed_anchors_and_folds_file_anchors(): void
    {
        $slice = $this->engine()->slice([
            'route:notes.update',
            'route:PUT:/notes/{note}',
            'table:notes',
            'column:notes.title',
            'class:App\Models\Note',
            'method:NoteController::update',
            'file:app/Services/NoteService.php',
        ], []);

        $this->assertSame([
            ['input' => 'route:notes.update', 'resolved' => 'route:PUT:/notes/{note}'],
            ['input' => 'route:PUT:/notes/{note}', 'resolved' => 'route:PUT:/notes/{note}'],
            ['input' => 'table:notes', 'resolved' => 'table:notes'],
            ['input' => 'column:notes.title', 'resolved' => 'column:notes.title'],
            ['input' => 'class:App\Models\Note', 'resolved' => 'App\Models\Note'],
            ['input' => 'method:NoteController::update', 'resolved' => 'App\Http\Controllers\NoteController::update'],
            ['input' => 'file:app/Services/NoteService.php', 'resolved' => 'file:app/Services/NoteService.php'],
        ], $slice['anchors']);
        $this->assertContains('app/Services/NoteService.php', array_column($slice['read'], 'file'));
        $this->assertSame('current', $slice['freshness']['state']);
    }

    public function test_slice_orders_reading_phases_and_preserves_closed_reason_codes(): void
    {
        $slice = $this->engine()->slice(['route:notes.update'], []);
        $orders = array_column($slice['read'], 'order', 'file');

        $this->assertSame(10, $orders['app/Http/Middleware/Authenticate.php']);
        $this->assertSame(20, $orders['app/Http/Requests/UpdateNoteRequest.php']);
        $this->assertSame(30, $orders['app/Policies/NotePolicy.php']);
        $this->assertSame(40, $orders['app/Http/Controllers/NoteController.php']);
        $this->assertSame(50, $orders['app/Services/NoteService.php']);
        $this->assertSame(60, $orders['app/Models/Note.php']);
        $this->assertSame(80, $orders['resources/js/Notes/Edit.vue']);
        $this->assertSame(90, $orders['tests/Feature/NoteUpdateTest.php']);
        $this->assertSame(array_values($orders), array_values(array_unique(array_values($orders))));

        $request = $this->readEntry($slice, 'app/Http/Requests/UpdateNoteRequest.php');
        $this->assertContains('downstream_validates_with', $request['why']);
        $this->assertSame(
            ['tests/Feature/NoteUpdateTest.php'],
            $slice['tests']['mapped'],
        );
        $this->assertSame(
            ["php artisan test 'tests/Feature/NoteUpdateTest.php'"],
            $slice['tests']['commands'],
        );
        $this->assertArrayNotHasKey('runtimeEvidence', $slice['tests']);
        $this->assertRecursiveArrayNotHasKey('confidence', $slice);
    }

    public function test_slice_exposes_runtime_test_evidence_with_file_granularity(): void
    {
        $graph = $this->graph(true);
        $graph['nodes'][] = [
            'id' => 'source_file:app/Services/NoteService.php',
            'type' => 'source_file',
            'label' => 'app/Services/NoteService.php',
            'file' => 'app/Services/NoteService.php',
        ];
        $graph['nodes'][] = [
            'id' => 'test_file:tests/Feature/NoteUpdateTest.php',
            'type' => 'test_file',
            'label' => 'tests/Feature/NoteUpdateTest.php',
            'file' => 'tests/Feature/NoteUpdateTest.php',
        ];
        $graph['edges'][] = [
            'from' => 'test_file:tests/Feature/NoteUpdateTest.php',
            'to' => 'source_file:app/Services/NoteService.php',
            'type' => 'runtime_covers',
            'confidence' => 1.0,
        ];
        $graph['edges'][] = [
            'from' => 'test_file:tests/Feature/NoteUpdateTest.php',
            'to' => 'App\Services\NoteService::save',
            'type' => 'runtime_covers',
            'confidence' => 1.0,
        ];
        $graph['edges'][] = [
            'from' => 'test_file:tests/Feature/NoteUpdateTest.php',
            'to' => 'table:notes',
            'type' => 'runtime_uses_table',
            'confidence' => 1.0,
        ];
        $slice = (new QueryEngine(GraphIndex::fromArray($graph), basePath: $this->project))
            ->slice(['route:notes.update'], []);

        $this->assertSame(['tests/Feature/NoteUpdateTest.php'], $slice['tests']['mapped']);
        $this->assertSame([[
            'testFile' => 'tests/Feature/NoteUpdateTest.php',
            'provenance' => 'appgraph_runtime',
            'granularity' => 'file',
            'sources' => [['file' => 'app/Services/NoteService.php']],
            'targets' => [
                ['id' => 'App\Services\NoteService::save', 'relationship' => 'runtime_covers'],
                ['id' => 'table:notes', 'relationship' => 'runtime_uses_table'],
            ],
        ]], $slice['tests']['runtimeEvidence']);
        $this->assertSame(
            ["php artisan test 'tests/Feature/NoteUpdateTest.php'"],
            $slice['tests']['commands'],
        );
    }

    public function test_slice_freshness_is_current_stale_or_unverified_per_answer(): void
    {
        $engine = $this->engine();
        $current = $engine->slice(['route:notes.update'], []);
        $this->assertSame('current', $current['freshness']['state']);

        $this->write('app/Services/NoteService.php', $this->source('changed-service'));
        $stale = $engine->slice(['route:notes.update'], []);
        $service = $this->readEntry($stale, 'app/Services/NoteService.php');
        $controller = $this->readEntry($stale, 'app/Http/Controllers/NoteController.php');

        $this->assertSame('stale', $stale['freshness']['state']);
        $this->assertSame(['app/Services/NoteService.php'], $stale['freshness']['files']);
        $this->assertTrue($service['stale']);
        $this->assertFalse($service['spans'][0]['exact']);
        $this->assertArrayNotHasKey('stale', $controller);

        $unverified = $this->engine(withManifest: false)->slice(['route:notes.update'], []);
        $this->assertSame('unverified', $unverified['freshness']['state']);
        $this->assertSame(count($unverified['read']), $unverified['freshness']['unverified']);
    }

    public function test_slice_compresses_uncertainties_and_reports_budget_omissions(): void
    {
        $slice = $this->engine()->slice(['route:notes.update'], [], 512);
        $gaps = array_column($slice['gaps'], null, 'code');

        $this->assertSame(2, $gaps['unresolved_calls']['count']);
        $this->assertSame('App\Services\NoteService::save', $gaps['unresolved_calls']['at']);
        $this->assertSame(512, $slice['budget']['requestedTokens']);
        $this->assertLessThanOrEqual(512, $slice['budget']['usedTokens']);
        $this->assertTrue($slice['truncated']);
        $this->assertNotEmpty($slice['omitted']);
    }

    public function test_slice_rejects_ambiguous_or_wrongly_typed_anchors_with_candidates(): void
    {
        try {
            $this->engine()->slice(['class:Tag'], []);
            $this->fail('Expected ambiguous anchor resolution to fail.');
        } catch (UnresolvedAnchorException $exception) {
            $this->assertSame('class:Tag', $exception->anchor);
            $this->assertSame(['App\Models\Tag', 'App\Other\Tag'], $exception->candidates);
        }

        try {
            $this->engine()->slice(['method:Note'], []);
            $this->fail('Expected typed anchor mismatch to fail.');
        } catch (UnresolvedAnchorException $exception) {
            $this->assertSame(['App\Models\Note'], $exception->candidates);
        }
    }

    private function engine(bool $withManifest = true): QueryEngine
    {
        return new QueryEngine(GraphIndex::fromArray($this->graph($withManifest)), basePath: $this->project);
    }

    /** @return array<string, mixed> */
    private function graph(bool $withManifest): array
    {
        $meta = [
            'generatedAt' => '2026-07-21T18:00:00Z',
            'analysis' => [
                'callResolution' => [
                    'byCaller' => [
                        'App\Services\NoteService::save' => [
                            'count' => 2,
                            'byReason' => ['receiver_type_unknown' => 2],
                        ],
                    ],
                ],
            ],
        ];

        if ($withManifest) {
            $meta['scan'] = [
                'algorithm' => 'sha256',
                'files' => array_map(
                    fn (string $_label, string $file): string => hash_file('sha256', $this->project.'/'.$file),
                    $this->files,
                    array_keys($this->files),
                ),
            ];
            $meta['scan']['files'] = array_combine(array_keys($this->files), $meta['scan']['files']);
        }

        return [
            'meta' => $meta,
            'nodes' => [
                ['id' => 'route:PUT:/notes/{note}', 'type' => 'route', 'label' => 'PUT /notes/{note}', 'metadata' => ['name' => 'notes.update']],
                $this->node('App\Http\Middleware\Authenticate::handle', 'method', 'app/Http/Middleware/Authenticate.php', 5, 20),
                $this->node('App\Http\Requests\UpdateNoteRequest', 'form_request', 'app/Http/Requests/UpdateNoteRequest.php', 5, 25),
                $this->node('App\Policies\NotePolicy::update', 'method', 'app/Policies/NotePolicy.php', 5, 20),
                $this->node('App\Http\Controllers\NoteController::update', 'method', 'app/Http/Controllers/NoteController.php', 20, 50),
                $this->node('App\Services\NoteService::save', 'method', 'app/Services/NoteService.php', 10, 40),
                $this->node('App\Models\Note', 'model', 'app/Models/Note.php', 5, 30),
                $this->node('frontend:resources/js/Notes/Edit.vue', 'frontend', 'resources/js/Notes/Edit.vue', 1, 10),
                $this->node('test:Tests\Feature\NoteUpdateTest::test_update', 'test', 'tests/Feature/NoteUpdateTest.php', 5, 30),
                ['id' => 'App\Models\Tag', 'type' => 'model', 'label' => 'Tag'],
                ['id' => 'App\Other\Tag', 'type' => 'model', 'label' => 'Tag'],
                ['id' => 'table:notes', 'type' => 'table', 'label' => 'notes'],
                ['id' => 'column:notes.title', 'type' => 'column', 'label' => 'notes.title'],
            ],
            'edges' => [
                ['from' => 'route:PUT:/notes/{note}', 'to' => 'App\Http\Middleware\Authenticate::handle', 'type' => 'passes_through', 'confidence' => 1.0],
                ['from' => 'route:PUT:/notes/{note}', 'to' => 'App\Http\Controllers\NoteController::update', 'type' => 'routes_to', 'confidence' => 1.0],
                ['from' => 'App\Http\Controllers\NoteController::update', 'to' => 'App\Http\Requests\UpdateNoteRequest', 'type' => 'validates_with', 'confidence' => 1.0],
                ['from' => 'App\Http\Controllers\NoteController::update', 'to' => 'App\Policies\NotePolicy::update', 'type' => 'authorizes_via', 'confidence' => 0.9],
                ['from' => 'App\Http\Controllers\NoteController::update', 'to' => 'App\Services\NoteService::save', 'type' => 'calls', 'confidence' => 1.0],
                ['from' => 'App\Http\Controllers\NoteController::update', 'to' => 'App\Models\Note', 'type' => 'uses_model', 'confidence' => 0.9],
                ['from' => 'App\Models\Note', 'to' => 'table:notes', 'type' => 'uses_table', 'confidence' => 1.0],
                ['from' => 'App\Services\NoteService::save', 'to' => 'table:notes', 'type' => 'writes', 'confidence' => 0.85],
                ['from' => 'table:notes', 'to' => 'column:notes.title', 'type' => 'has_column', 'confidence' => 1.0],
                ['from' => 'frontend:resources/js/Notes/Edit.vue', 'to' => 'route:PUT:/notes/{note}', 'type' => 'consumes_route', 'confidence' => 0.98],
                ['from' => 'test:Tests\Feature\NoteUpdateTest::test_update', 'to' => 'route:PUT:/notes/{note}', 'type' => 'tests_route', 'confidence' => 1.0],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function node(string $id, string $type, string $file, int $line, int $endLine): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'label' => class_basename(strstr($id, '::', true) ?: $id),
            'file' => $file,
            'line' => $line,
            'endLine' => $endLine,
        ];
    }

    private function source(string $label): string
    {
        return implode('', array_map(
            static fn (int $line): string => sprintf("%s line %03d\n", $label, $line),
            range(1, 120),
        ));
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->project.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $contents);
    }

    /** @param array<string, mixed> $slice @return array<string, mixed> */
    private function readEntry(array $slice, string $file): array
    {
        foreach ($slice['read'] as $entry) {
            if (($entry['file'] ?? null) === $file) {
                return $entry;
            }
        }

        $this->fail("Missing slice read entry [{$file}].");
    }

    private function assertRecursiveArrayNotHasKey(string $key, mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        $this->assertArrayNotHasKey($key, $value);

        foreach ($value as $child) {
            $this->assertRecursiveArrayNotHasKey($key, $child);
        }
    }
}
