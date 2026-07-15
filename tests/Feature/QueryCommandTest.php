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
