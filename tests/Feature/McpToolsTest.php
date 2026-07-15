<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Graph\GraphExporter;
use AppGraph\Mcp\AppGraphServer;
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
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }

        AppGraphServer::tool(OverviewTool::class)->assertOk();

        $this->assertFileExists($path);
    }

    public function test_refresh_tool_rebuilds_the_graph_explicitly(): void
    {
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies'] as $scanner) {
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
