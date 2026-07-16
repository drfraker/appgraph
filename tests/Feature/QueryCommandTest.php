<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Graph\GraphExporter;
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
        $this->assertSame('possible', $payload['results'][0]['match']);
        $this->assertSame(0, $payload['counts']['proven']);
        $this->assertSame(1, $payload['counts']['possible']);
    }

    public function test_flow_from_route_name_returns_a_feature_slice(): void
    {
        $payload = $this->runQuery([
            'query' => 'flow-from',
            'target' => 'notes.update',
        ]);

        $this->assertSame('App\Http\Controllers\NoteController::update', $payload['entrypoint']['id']);
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
            ['--context-target' => [str_repeat('x', 513)]],
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
}
