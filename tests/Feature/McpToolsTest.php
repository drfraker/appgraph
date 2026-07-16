<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Graph\GraphExporter;
use AppGraph\Mcp\AppGraphServer;
use AppGraph\Mcp\Tools\ContextTool;
use AppGraph\Mcp\Tools\OverviewTool;
use AppGraph\Mcp\Tools\QueryTool;
use AppGraph\Mcp\Tools\RefreshTool;
use AppGraph\Mcp\Tools\SearchTool;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use AppGraph\Tests\TestCase;
use Illuminate\Testing\Fluent\AssertableJson;

class McpToolsTest extends TestCase
{
    use BuildsQueryFixtureGraph;

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            \Laravel\Mcp\Server\McpServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new GraphExporter())->exportData(
            $this->queryFixtureGraph(),
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'))
        );
    }

    public function test_server_registers_as_a_local_mcp_server(): void
    {
        $this->assertNotNull(\Laravel\Mcp\Facades\Mcp::getLocalServer('appgraph'));
    }

    public function test_overview_tool_reports_counts_and_staleness(): void
    {
        AppGraphServer::tool(OverviewTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('query', 'overview')
                ->where('counts.nodes', 12)
                ->where('meta.appName', 'Query Fixture App')
                ->has('staleness.newerSourceFiles')
                ->etc());
    }

    public function test_context_tool_compiles_task_specific_context(): void
    {
        AppGraphServer::tool(ContextTool::class, [
            'task' => 'Update the note workflow safely',
            'targets' => ['notes.update'],
            'changed_files' => ['app/Services/NoteService.php'],
            'token_budget' => 1024,
            'depth' => 3,
            'min_confidence' => 0.5,
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('query', 'context-for-task')
                ->where('task', 'Update the note workflow safely')
                ->has('seeds')
                ->etc());
    }

    public function test_context_tool_preserves_valid_utf8_at_bounded_label_and_evidence_boundaries(): void
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
        (new GraphExporter())->exportData(
            $graph,
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
        );

        AppGraphServer::tool(ContextTool::class, [
            'task' => 'Inspect the note route',
            'targets' => ['route:PUT:/notes/{note}'],
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($expectedPrefix): void {
                $payload = json_decode(
                    json_encode($json->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
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
                $json->etc();
            });
    }

    public function test_context_tool_advertises_explicit_input_bounds(): void
    {
        $schema = app(ContextTool::class)->toArray()['inputSchema'];

        $this->assertContains('task', $schema['required']);
        $this->assertSame(1, $schema['properties']['task']['minLength']);
        $this->assertSame(4000, $schema['properties']['task']['maxLength']);
        $this->assertSame(10, $schema['properties']['targets']['maxItems']);
        $this->assertTrue($schema['properties']['targets']['uniqueItems']);
        $this->assertSame(512, $schema['properties']['targets']['items']['maxLength']);
        $this->assertSame(50, $schema['properties']['changed_files']['maxItems']);
        $this->assertSame(1024, $schema['properties']['changed_files']['items']['maxLength']);
        $this->assertSame(512, $schema['properties']['token_budget']['minimum']);
        $this->assertSame(16000, $schema['properties']['token_budget']['maximum']);
        $this->assertSame(1, $schema['properties']['depth']['minimum']);
        $this->assertSame(6, $schema['properties']['depth']['maximum']);
        $this->assertSame(0, $schema['properties']['min_confidence']['minimum']);
        $this->assertSame(1, $schema['properties']['min_confidence']['maximum']);
    }

    public function test_context_tool_enforces_runtime_validation_bounds(): void
    {
        $invalidArguments = [
            [],
            ['task' => '   '],
            ['task' => str_repeat('x', 4001)],
            ['task' => 'Update notes', 'targets' => array_fill(0, 11, 'notes.update')],
            ['task' => 'Update notes', 'targets' => ['notes.update', 'notes.update']],
            ['task' => 'Update notes', 'targets' => [str_repeat('x', 513)]],
            ['task' => 'Update notes', 'changed_files' => array_map(static fn (int $i): string => "app/File{$i}.php", range(1, 51))],
            ['task' => 'Update notes', 'changed_files' => ['app/Note.php', 'app/Note.php']],
            ['task' => 'Update notes', 'changed_files' => [str_repeat('x', 1025)]],
            ['task' => 'Update notes', 'token_budget' => 511],
            ['task' => 'Update notes', 'token_budget' => 16001],
            ['task' => 'Update notes', 'depth' => 0],
            ['task' => 'Update notes', 'depth' => 7],
            ['task' => 'Update notes', 'min_confidence' => -0.01],
            ['task' => 'Update notes', 'min_confidence' => 1.01],
        ];

        foreach ($invalidArguments as $arguments) {
            AppGraphServer::tool(ContextTool::class, $arguments)->assertHasErrors();
        }

        AppGraphServer::tool(ContextTool::class, ['task' => str_repeat('🙂', 3000)])
            ->assertOk();
    }

    public function test_search_tool_finds_nodes(): void
    {
        AppGraphServer::tool(SearchTool::class, ['term' => 'NoteService', 'type' => 'method'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('results.0.id', 'App\Services\NoteService::helper')
                ->where('results.1.id', 'App\Services\NoteService::save')
                ->etc());
    }

    public function test_query_tool_runs_traversals_with_fuzzy_targets(): void
    {
        AppGraphServer::tool(QueryTool::class, ['query' => 'writes-to', 'target' => 'notes'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('table', 'table:notes')
                ->where('results.0.id', 'App\Services\NoteService::save')
                ->etc());

        AppGraphServer::tool(QueryTool::class, ['query' => 'writes-to', 'target' => 'notes.title'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('column', 'column:notes.title')
                ->where('results.0.match', 'possible')
                ->where('counts.possible', 1)
                ->etc());

        AppGraphServer::tool(QueryTool::class, ['query' => 'impact-of', 'target' => 'notes.title'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('routes.0.id', 'route:PUT:/notes/{note}')
                ->etc());

        AppGraphServer::tool(QueryTool::class, ['query' => 'flow-from', 'target' => 'notes.update'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('entrypoint.id', 'App\Http\Controllers\NoteController::update')
                ->where('formRequests.0.id', 'App\Http\Requests\UpdateNoteRequest')
                ->where('dataAccess.0.table', 'notes')
                ->etc());
    }

    public function test_query_tool_surfaces_resolution_errors(): void
    {
        AppGraphServer::tool(QueryTool::class, ['query' => 'callers-of', 'target' => 'Tag'])
            ->assertHasErrors()
            ->assertSee('Did you mean');

        AppGraphServer::tool(QueryTool::class, ['query' => 'writes-to'])
            ->assertHasErrors();
    }

    public function test_missing_graph_is_generated_on_demand(): void
    {
        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
        @unlink($path);
        $this->assertFileDoesNotExist($path);

        // Keep the auto-scan fast and deterministic: scanners off, just meta.
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }

        AppGraphServer::tool(OverviewTool::class)->assertOk();

        $this->assertFileExists($path);
    }

    public function test_refresh_tool_rebuilds_the_graph_explicitly(): void
    {
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }

        AppGraphServer::tool(RefreshTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('query', 'overview')
                ->where('refreshed', true)
                ->where('counts.nodes', 0)
                ->etc());
    }

    public function test_auto_scan_off_errors_when_graph_is_missing(): void
    {
        config()->set('appgraph.mcp.auto_scan', 'off');

        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
        @unlink($path);

        AppGraphServer::tool(OverviewTool::class)
            ->assertHasErrors()
            ->assertSee('appgraph:scan');
    }
}
