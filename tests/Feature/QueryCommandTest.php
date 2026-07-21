<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Graph\GraphExporter;
use AppGraph\Storage\GraphStore;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use AppGraph\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class QueryCommandTest extends TestCase
{
    use BuildsQueryFixtureGraph;

    private string $graphPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->graphPath = storage_path('appgraph-query-test/appgraph.json');
        (new GraphExporter())->exportData($this->queryFixtureGraph(), $this->graphPath);
    }

    public function test_overview_query_outputs_compact_json(): void
    {
        $payload = $this->runQuery(['query' => 'overview']);

        $this->assertSame('overview', $payload['query']);
        $this->assertSame('Query Fixture App', $payload['meta']['appName']);
        $this->assertSame(12, $payload['counts']['nodes']);
        $this->assertArrayHasKey('staleness', $payload);
    }

    public function test_impact_query_resolves_fuzzy_targets(): void
    {
        $payload = $this->runQuery([
            'query' => 'impact-of',
            'target' => 'notes.title',
            '--pretty' => true,
        ]);

        $this->assertSame('route:PUT:/notes/{note}', $payload['routes'][0]['id']);
        $this->assertContains(
            'App\Http\Controllers\NoteController::update',
            array_column($payload['methods'], 'id')
        );
    }

    public function test_column_writer_query_preserves_field_certainty(): void
    {
        $payload = $this->runQuery([
            'query' => 'writes-to',
            'target' => 'notes.title',
        ]);

        $this->assertSame('column:notes.title', $payload['column']);
        $this->assertSame('possible', $payload['matches']['possible'][0]['match']);
        $this->assertSame(0, $payload['counts']['proven']);
        $this->assertSame(1, $payload['counts']['possible']);
        $this->assertArrayNotHasKey('results', $payload);
    }

    public function test_flow_from_route_name_returns_a_feature_slice(): void
    {
        $payload = $this->runQuery([
            'query' => 'flow-from',
            'target' => 'notes.update',
        ]);

        $this->assertSame('App\Http\Controllers\NoteController::update', $payload['entrypoint']);
        $this->assertSame(
            ['App\Http\Requests\UpdateNoteRequest'],
            array_column($payload['formRequests'], 'id')
        );
        $this->assertSame('notes', $payload['dataAccess'][0]['table']);
    }

    public function test_context_for_task_accepts_task_targets_changed_files_and_budgets(): void
    {
        $payload = $this->runQuery([
            'query' => 'context-for-task',
            'target' => 'Update the note workflow safely',
            '--context-target' => ['notes.update'],
            '--changed-file' => ['app/Services/NoteService.php'],
            '--token-budget' => '1024',
            '--depth' => '3',
            '--min-confidence' => '0.5',
        ]);

        $this->assertSame('context-for-task', $payload['query']);
        $this->assertSame('Update the note workflow safely', $payload['task']);
        $this->assertContains(
            'route:PUT:/notes/{note}',
            array_column($payload['seeds'], 'id'),
        );
    }

    public function test_json_generation_metadata_is_never_exposed_as_a_verification_baseline(): void
    {
        $graph = $this->queryFixtureGraph();
        $graph['meta']['generation'] = ['id' => '1', 'current' => true];
        (new GraphExporter())->exportData($graph, $this->graphPath);

        $payload = $this->runQuery([
            'query' => 'context-for-task',
            'target' => 'Update the note workflow safely',
            '--context-target' => ['notes.update'],
        ]);

        $this->assertArrayNotHasKey('generation', $payload);
        $this->assertSame(
            'legacy_json_without_immutable_generation',
            $payload['generationUnavailable']['reason'],
        );
        $this->assertArrayNotHasKey('baselineGeneration', $payload['verification']);
    }

    public function test_context_for_task_cli_preserves_valid_utf8_at_bounded_label_and_evidence_boundaries(): void
    {
        $boundaryText = str_repeat('x', 510).'🙂tail';
        $expectedPrefix = str_repeat('x', 510);
        $graph = $this->queryFixtureGraph();
        $graph['nodes'][0]['label'] = $boundaryText;
        $graph['edges'][0]['metadata']['evidence'] = [[
            'file' => 'routes/web.php',
            'line' => 12,
            'rule' => $boundaryText,
        ]];
        (new GraphExporter())->exportData($graph, $this->graphPath);

        $payload = $this->runQuery([
            'query' => 'context-for-task',
            'target' => 'Inspect the note route',
            '--context-target' => ['route:PUT:/notes/{note}'],
        ]);
        $route = collect($payload['seeds'])->firstWhere('id', 'route:PUT:/notes/{note}');
        $path = collect($payload['paths'])->first(
            static fn (array $candidate): bool => in_array(
                'App\Http\Controllers\NoteController::update',
                $candidate['nodes'] ?? [],
                true,
            ),
        );

        $this->assertSame($expectedPrefix, $route['label']);
        $this->assertSame($expectedPrefix, $path['edges'][0]['evidence'][0]['rule']);
        $this->assertSame(1, preg_match('//u', $route['label']));
        $this->assertSame(1, preg_match('//u', $path['edges'][0]['evidence'][0]['rule']));
        $this->assertLessThanOrEqual(512, strlen($route['label']));
        $this->assertLessThanOrEqual(512, strlen($path['edges'][0]['evidence'][0]['rule']));
    }

    public function test_context_for_task_rejects_invalid_scalar_options_with_json_errors(): void
    {
        $cases = [
            ['--token-budget' => 'abc'],
            ['--token-budget' => '511'],
            ['--token-budget' => '16001'],
            ['--depth' => '1.5'],
            ['--depth' => '0'],
            ['--depth' => '7'],
            ['--min-confidence' => 'nope'],
            ['--min-confidence' => '-0.01'],
            ['--min-confidence' => '1.01'],
        ];

        foreach ($cases as $options) {
            $payload = $this->runQuery([
                'query' => 'context-for-task',
                'target' => 'Update notes',
                ...$options,
            ], expectedExitCode: 1);

            $this->assertArrayHasKey('error', $payload);
        }
    }

    public function test_context_for_task_rejects_invalid_task_and_list_options(): void
    {
        $payload = $this->runQuery([
            'query' => 'context-for-task',
            'target' => '   ',
        ], expectedExitCode: 1);
        $this->assertStringContainsString('nonblank task description', $payload['error']);

        $payload = $this->runQuery([
            'query' => 'context-for-task',
            'target' => str_repeat('x', 4001),
        ], expectedExitCode: 1);
        $this->assertStringContainsString('4000 characters', $payload['error']);

        $payload = $this->runQuery([
            'query' => 'context-for-task',
            'target' => str_repeat('🙂', 3000),
        ]);
        $this->assertSame('context-for-task', $payload['query']);

        $invalidLists = [
            ['--context-target' => array_fill(0, 11, 'notes.update')],
            ['--context-target' => ['notes.update', ' notes.update ']],
            ['--context-target' => [str_repeat('x', 4097)]],
            ['--changed-file' => array_map(static fn (int $i): string => "app/File{$i}.php", range(1, 51))],
            ['--changed-file' => ['app/Note.php', ' app/Note.php ']],
            ['--changed-file' => [str_repeat('x', 1025)]],
        ];

        foreach ($invalidLists as $options) {
            $payload = $this->runQuery([
                'query' => 'context-for-task',
                'target' => 'Update notes',
                ...$options,
            ], expectedExitCode: 1);

            $this->assertArrayHasKey('error', $payload);
        }
    }

    public function test_context_for_task_uses_bounded_configured_defaults(): void
    {
        config()->set('appgraph.query.context', [
            'token_budget' => 1024,
            'depth' => 2,
            'min_confidence' => 0.5,
        ]);

        $payload = $this->runQuery([
            'query' => 'context-for-task',
            'target' => 'Inspect notes',
            '--context-target' => ['notes.update'],
        ]);

        $this->assertSame(1024, $payload['budget']['requestedTokens']);
        $this->assertSame(1024, $payload['budget']['effectiveTokens']);
    }

    public function test_limit_and_min_confidence_options_are_applied(): void
    {
        $node = $this->runQuery([
            'query' => 'node',
            'target' => 'NoteService::save',
            '--limit' => '1',
        ]);

        $this->assertCount(1, $node['out']);
        $this->assertCount(1, $node['in']);
        $this->assertTrue($node['truncated']);

        $payload = $this->runQuery([
            'query' => 'callers-of',
            'target' => 'NoteService::save',
            '--limit' => '1',
        ]);

        $this->assertCount(1, $payload['results']);
        $this->assertTrue($payload['truncated']);

        $payload = $this->runQuery([
            'query' => 'callers-of',
            'target' => 'NoteService::save',
            '--min-confidence' => '0.9',
        ]);

        $this->assertSame(
            ['App\Http\Controllers\NoteController::update'],
            array_column($payload['results'], 'id')
        );
    }

    public function test_unresolvable_target_fails_with_candidates_envelope(): void
    {
        $payload = $this->runQuery([
            'query' => 'callers-of',
            'target' => 'Tag',
        ], expectedExitCode: 1);

        $this->assertStringContainsString('Could not resolve [Tag]', $payload['error']);
        $this->assertSame(['App\Models\Tag', 'App\Other\Tag'], $payload['candidates']);
    }

    public function test_missing_graph_fails_with_scan_hint(): void
    {
        $payload = $this->runQuery([
            'query' => 'overview',
            '--input' => storage_path('appgraph-query-test/missing.json'),
        ], expectedExitCode: 1);

        $this->assertStringContainsString('appgraph:scan', $payload['error']);
    }

    public function test_unknown_query_and_missing_target_fail(): void
    {
        $payload = $this->runQuery(['query' => 'bogus'], expectedExitCode: 1);
        $this->assertStringContainsString('Unknown query [bogus]', $payload['error']);

        $payload = $this->runQuery(['query' => 'writes-to'], expectedExitCode: 1);
        $this->assertStringContainsString('requires a target', $payload['error']);

        $payload = $this->runQuery(['query' => 'context-for-task'], expectedExitCode: 1);
        $this->assertStringContainsString('requires a nonblank task description', $payload['error']);
    }

    public function test_generation_queries_list_diff_and_verify_explicit_snapshots(): void
    {
        $store = app(GraphStore::class);
        $beforeData = $this->queryFixtureGraph();
        $beforeData['meta']['scan'] = [
            'fingerprint' => 'before',
            'configuration' => 'same-config',
            'applicationEnvironment' => 'testing',
            'files' => ['app/Services/NoteService.php' => hash('sha256', 'before')],
        ];
        $before = $store->publish($this->graphObject($beforeData))['generation']['id'];
        $afterData = $beforeData;
        $afterData['meta']['scan']['fingerprint'] = 'after';
        $afterData['meta']['scan']['files']['app/Services/NoteService.php'] = hash('sha256', 'after');
        $afterData['nodes'][] = [
            'id' => 'App\Services\NoteService::audit',
            'type' => 'method',
            'label' => 'NoteService::audit',
            'file' => 'app/Services/NoteService.php',
            'line' => 50,
        ];
        $afterData['edges'][] = [
            'from' => 'App\Services\NoteService::save',
            'to' => 'App\Services\NoteService::audit',
            'type' => 'calls',
            'confidence' => 1.0,
        ];
        $after = $store->publish($this->graphObject($afterData))['generation']['id'];

        $generations = $this->runStoreQuery(['query' => 'generations']);
        $this->assertSame($after, $generations['currentGeneration']);
        $this->assertSame([$after, $before], array_column($generations['generations'], 'id'));

        $diff = $this->runStoreQuery([
            'query' => 'diff',
            '--from-generation' => $before,
            '--to-generation' => $after,
        ]);
        $this->assertSame('diff', $diff['query']);
        $this->assertSame(2, $diff['counts']['overall']['added']);

        $verification = $this->runStoreQuery([
            'query' => 'verify-change',
            '--from-generation' => $before,
            '--to-generation' => $after,
            '--context-target' => ['App\Services\NoteService::save'],
            '--changed-file' => ['app/Services/NoteService.php'],
        ]);
        $this->assertSame('verify-change', $verification['query']);
        $this->assertSame('changed', $verification['sourceCoverage']['requestedFiles'][0]['manifestStatus']);
        $this->assertGreaterThan(0, $verification['targetScope']['changesInScope']['total']);
    }

    public function test_default_search_uses_the_generation_store_and_explicit_input_stays_json_only(): void
    {
        $data = $this->queryFixtureGraph();
        $data['meta']['scan'] = ['fingerprint' => 'search', 'files' => []];
        $generation = app(GraphStore::class)->publish($this->graphObject($data))['generation']['id'];
        $stored = $this->runStoreQuery([
            'query' => 'search',
            'target' => 'NoteService',
            '--type' => 'method',
        ]);
        $json = $this->runQuery([
            'query' => 'search',
            'target' => 'NoteService',
            '--type' => 'method',
        ]);

        $this->assertSame($generation, $stored['revision']);
        $this->assertArrayNotHasKey('generation', $stored);
        $this->assertArrayNotHasKey('searchBackend', $stored);
        $this->assertSame(
            array_column($json['results'], 'id'),
            array_column($stored['results'], 'id'),
        );
        $this->assertArrayNotHasKey('generation', $json);
    }

    public function test_default_queries_use_authoritative_sqlite_without_a_json_mirror(): void
    {
        $data = $this->queryFixtureGraph();
        $data['meta']['scan'] = ['fingerprint' => 'sqlite-cli', 'files' => []];
        $generation = app(GraphStore::class)->publish($this->graphObject($data))['generation']['id'];
        @unlink($this->graphPath);

        $overview = $this->runStoreQuery(['query' => 'overview']);
        $search = $this->runStoreQuery([
            'query' => 'search',
            'target' => 'NoteService',
            '--type' => 'method',
        ]);

        $this->assertSame($generation, $overview['revision']);
        $this->assertArrayNotHasKey('generation', $overview);
        $this->assertArrayNotHasKey('graphAgeSeconds', $overview);
        $this->assertSame(12, $overview['counts']['nodes']);
        $this->assertSame($generation, $search['revision']);
        $this->assertArrayNotHasKey('generation', $search);
        $this->assertSame(
            ['App\Services\NoteService::save', 'App\Services\NoteService::helper'],
            array_column($search['results'], 'id'),
        );
        $this->assertFileDoesNotExist($this->graphPath);
    }

    public function test_search_query_rejects_unbounded_or_nonliteral_inputs_for_store_and_json(): void
    {
        foreach ([
            ['target' => '   '],
            ['target' => "note\0service"],
            ['target' => str_repeat('x', 513)],
            ['target' => str_repeat('🙂', 513)],
            ['target' => 'note', '--type' => '   '],
            ['target' => 'note', '--type' => "method\0suffix"],
            ['target' => 'note', '--type' => str_repeat('x', 129)],
            ['target' => 'note', '--limit' => '0'],
            ['target' => 'note', '--limit' => '201'],
            ['target' => 'note', '--limit' => '1.5'],
        ] as $arguments) {
            $payload = $this->runQuery(['query' => 'search', ...$arguments], expectedExitCode: 1);
            $this->assertArrayHasKey('error', $payload);

            $payload = $this->runStoreQuery(['query' => 'search', ...$arguments], expectedExitCode: 1);
            $this->assertArrayHasKey('error', $payload);
        }
    }

    public function test_traversal_query_rejects_unbounded_numeric_options(): void
    {
        foreach ([
            ['--limit' => '0'],
            ['--limit' => '201'],
            ['--limit' => '1.5'],
            ['--depth' => '0'],
            ['--depth' => '7'],
            ['--depth' => '1.5'],
            ['--min-confidence' => '-0.01'],
            ['--min-confidence' => '1.01'],
            ['--min-confidence' => 'nope'],
        ] as $option) {
            $payload = $this->runQuery([
                'query' => 'flow-from',
                'target' => 'notes.update',
                ...$option,
            ], expectedExitCode: 1);

            $this->assertStringContainsString('between', $payload['error']);
        }
    }

    public function test_historical_queries_reject_json_input_and_require_explicit_baselines(): void
    {
        $missingBaseline = $this->runStoreQuery(['query' => 'diff'], expectedExitCode: 1);
        $this->assertStringContainsString('--from-generation', $missingBaseline['error']);

        $jsonHistory = $this->runStoreQuery([
            'query' => 'generations',
            '--input' => $this->graphPath,
        ], expectedExitCode: 1);
        $this->assertStringContainsString('cannot use --input JSON', $jsonHistory['error']);

        $irrelevant = $this->runStoreQuery([
            'query' => 'overview',
            '--from-generation' => '1',
        ], expectedExitCode: 1);
        $this->assertStringContainsString('not valid for the [overview]', $irrelevant['error']);

        $data = $this->queryFixtureGraph();
        $data['meta']['scan'] = ['fingerprint' => 'numeric-contracts', 'files' => []];
        $generation = app(GraphStore::class)->publish($this->graphObject($data))['generation']['id'];

        foreach (['previous', 'current', '0', '01', str_repeat('9', 20)] as $invalid) {
            $diff = $this->runStoreQuery([
                'query' => 'diff',
                '--from-generation' => $invalid,
            ], expectedExitCode: 1);
            $this->assertStringContainsString('positive numeric generation id', $diff['error']);

            $verify = $this->runStoreQuery([
                'query' => 'verify-change',
                '--from-generation' => $invalid,
            ], expectedExitCode: 1);
            $this->assertStringContainsString('positive numeric generation id', $verify['error']);

            $page = $this->runStoreQuery([
                'query' => 'generations',
                '--before-generation' => $invalid,
            ], expectedExitCode: 1);
            $this->assertStringContainsString('positive numeric generation id', $page['error']);
        }

        $invalidComparison = $this->runStoreQuery([
            'query' => 'verify-change',
            '--from-generation' => $generation,
            '--to-generation' => 'previous',
        ], expectedExitCode: 1);
        $this->assertStringContainsString('positive numeric generation id', $invalidComparison['error']);
    }

    public function test_query_specific_options_are_never_silently_ignored(): void
    {
        foreach ([
            ['--context-target' => ['notes.update']],
            ['--changed-file' => ['app/Note.php']],
            ['--token-budget' => '1024'],
            ['--type' => 'method'],
            ['--full' => true],
            ['--depth' => '2'],
            ['--min-confidence' => '0.5'],
            ['--limit' => '10'],
        ] as $option) {
            $payload = $this->runQuery([
                'query' => 'overview',
                ...$option,
            ], expectedExitCode: 1);

            $this->assertStringContainsString('not valid for the [overview]', $payload['error']);
        }

        $target = $this->runQuery([
            'query' => 'overview',
            'target' => 'notes.update',
        ], expectedExitCode: 1);
        $this->assertStringContainsString('does not accept a target', $target['error']);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function runQuery(array $arguments, int $expectedExitCode = 0): array
    {
        $arguments['--input'] ??= $this->graphPath;

        $exitCode = Artisan::call('appgraph:query', $arguments);
        $this->assertSame($expectedExitCode, $exitCode);

        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function runStoreQuery(array $arguments, int $expectedExitCode = 0): array
    {
        $exitCode = Artisan::call('appgraph:query', $arguments);
        $this->assertSame($expectedExitCode, $exitCode);

        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }
}
