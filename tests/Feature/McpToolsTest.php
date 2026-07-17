<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Graph\Graph;
use AppGraph\Graph\GraphExporter;
use AppGraph\Mcp\AppGraphServer;
use AppGraph\Mcp\Tools\NodeTool;
use AppGraph\Mcp\Tools\OverviewTool;
use AppGraph\Mcp\Tools\QueryTool;
use AppGraph\Mcp\Tools\RefreshTool;
use AppGraph\Mcp\Tools\SearchTool;
use AppGraph\Storage\GraphStore;
use AppGraph\Support\ScanResultRegistry;
use AppGraph\Support\ScanRunner;
use AppGraph\Support\ScanFingerprint;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use AppGraph\Tests\Support\InProcessScanRunner;
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

        $this->app->singleton(
            ScanRunner::class,
            fn (): InProcessScanRunner => new InProcessScanRunner(app(ScanResultRegistry::class)),
        );

        // Most tool tests intentionally exercise the portable JSON fallback.
        // Tests that cover automatic authoritative generation opt in explicitly.
        config()->set('appgraph.mcp.auto_scan', 'off');

        (new GraphExporter())->exportData(
            $this->queryFixtureGraph(),
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'))
        );
    }

    public function test_server_registers_as_a_local_mcp_server(): void
    {
        $this->assertNotNull(\Laravel\Mcp\Facades\Mcp::getLocalServer('appgraph'));
    }

    public function test_server_exposes_only_the_focused_navigation_tools(): void
    {
        $tools = (new \ReflectionClass(AppGraphServer::class))->getDefaultProperties()['tools'];

        $this->assertSame([
            OverviewTool::class,
            SearchTool::class,
            NodeTool::class,
            QueryTool::class,
            RefreshTool::class,
        ], $tools);
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

    public function test_overview_tool_surfaces_scanner_warning_counts_and_exact_samples(): void
    {
        $graph = $this->queryFixtureGraph();
        $graph['meta']['warnings'] = [
            [
                'scanner' => 'routes',
                'reason' => 'controller_method_unproven',
                'class' => 'App\\Http\\Controllers\\NoteController',
                'file' => 'app/Http/Controllers/NoteController.php',
                'message' => 'The route controller method could not be proven.',
            ],
            [
                'scanner' => 'events',
                'reason' => 'listener_binding_target_unknown',
                'class' => 'App\\Listeners\\SendNotification',
                'file' => 'app/Listeners/SendNotification.php',
                'message' => 'The listener target could not be proven.',
            ],
        ];
        (new GraphExporter())->exportData(
            $graph,
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
        );

        AppGraphServer::tool(OverviewTool::class)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $payload = $json->toArray();

                $this->assertSame(2, $payload['scannerWarnings']['total']);
                $this->assertSame([
                    ['scanner' => 'events', 'count' => 1],
                    ['scanner' => 'routes', 'count' => 1],
                ], $payload['scannerWarnings']['byScanner']);
                $this->assertSame(
                    'App\\Listeners\\SendNotification',
                    $payload['scannerWarnings']['samples'][0]['fields']['class'],
                );
                $this->assertFalse($payload['scannerWarnings']['truncated']);
                $json->etc();
            });
    }

    public function test_search_tool_finds_nodes(): void
    {
        AppGraphServer::tool(SearchTool::class, ['term' => 'NoteService', 'type' => 'method'])
            ->assertOk()
            ->assertSee('AppGraph returned structured content for [search].')
            ->assertDontSee('App\Services\NoteService::helper')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('results.0.id', 'App\Services\NoteService::helper')
                ->where('results.1.id', 'App\Services\NoteService::save')
                ->etc());
    }

    public function test_long_authoritative_identity_round_trips_from_search_to_node_and_query(): void
    {
        $longId = 'App\\'.str_repeat('A', 600).'::run';
        $graph = $this->queryFixtureGraph();
        $graph['nodes'][] = [
            'id' => $longId,
            'type' => 'method',
            'label' => 'LongAgentIdentity::run',
            'file' => 'app/LongAgentIdentity.php',
            'line' => 10,
        ];
        $graph['edges'][] = [
            'from' => $longId,
            'to' => 'App\\Services\\NoteService::helper',
            'type' => 'calls',
            'confidence' => 1.0,
        ];
        (new GraphExporter())->exportData(
            $graph,
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
        );

        AppGraphServer::tool(SearchTool::class, ['term' => 'LongAgentIdentity'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('results.0.id', $longId)
                ->etc());

        AppGraphServer::tool(NodeTool::class, ['id' => $longId])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('node.id', $longId)
                ->etc());

        AppGraphServer::tool(QueryTool::class, [
            'query' => 'calls-from',
            'target' => $longId,
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('results.0.id', 'App\\Services\\NoteService::helper')
                ->etc());
    }

    public function test_search_tool_advertises_and_enforces_bounded_literal_inputs(): void
    {
        $schema = app(SearchTool::class)->toArray()['inputSchema'];

        $this->assertContains('term', $schema['required']);
        $this->assertSame(1, $schema['properties']['term']['minLength']);
        $this->assertSame(512, $schema['properties']['term']['maxLength']);
        $this->assertSame(1, $schema['properties']['type']['minLength']);
        $this->assertSame(128, $schema['properties']['type']['maxLength']);
        $this->assertSame(1, $schema['properties']['limit']['minimum']);
        $this->assertSame(200, $schema['properties']['limit']['maximum']);

        AppGraphServer::tool(SearchTool::class, [])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => '   '])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => "note\0service"])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => str_repeat('x', 513)])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => str_repeat('🙂', 513)])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => 'note', 'type' => '   '])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => 'note', 'type' => "method\0suffix"])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => 'note', 'type' => str_repeat('x', 129)])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => 'note', 'limit' => 0])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => 'note', 'limit' => 201])->assertHasErrors();
        AppGraphServer::tool(SearchTool::class, ['term' => 'note', 'limit' => '50'])->assertHasErrors();

        AppGraphServer::tool(SearchTool::class, [
            'term' => str_repeat('x', 512),
            'type' => str_repeat('x', 128),
            'limit' => 200,
        ])->assertOk();
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

    public function test_query_tool_rejects_inapplicable_and_unbounded_options(): void
    {
        $schema = app(QueryTool::class)->toArray()['inputSchema'];
        $this->assertSame(4096, $schema['properties']['target']['maxLength']);

        foreach ([
            ['query' => 'models', 'target' => 'notes'],
            ['query' => 'tables', 'depth' => 2],
            ['query' => 'writes-to', 'target' => 'notes', 'depth' => 2],
            ['query' => 'reads-from', 'target' => 'notes', 'min_confidence' => 0.5],
            ['query' => 'models', 'limit' => 0],
            ['query' => 'models', 'limit' => 201],
            ['query' => 'models', 'limit' => '50'],
            ['query' => 'flow-from', 'target' => 'notes.update', 'depth' => 0],
            ['query' => 'flow-from', 'target' => 'notes.update', 'depth' => 7],
            ['query' => 'flow-from', 'target' => 'notes.update', 'depth' => '4'],
            ['query' => 'flow-from', 'target' => 'notes.update', 'min_confidence' => -0.1],
            ['query' => 'flow-from', 'target' => 'notes.update', 'min_confidence' => 1.1],
            ['query' => 'flow-from', 'target' => 'notes.update', 'min_confidence' => '0.5'],
            ['query' => 'calls-from', 'target' => str_repeat('x', 4097)],
        ] as $arguments) {
            AppGraphServer::tool(QueryTool::class, $arguments)->assertHasErrors();
        }
    }

    public function test_missing_graph_is_generated_on_demand(): void
    {
        config()->set('appgraph.mcp.auto_scan', 'missing');
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

    public function test_authoritative_sqlite_generation_is_used_when_json_mirror_is_missing(): void
    {
        $data = $this->queryFixtureGraph();
        $data['meta']['scan'] = ['fingerprint' => 'sqlite-without-mirror', 'files' => []];
        $store = app(GraphStore::class);
        $generation = $store->publish($this->graphObject($data))['generation']['id'];
        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
        @unlink($path);
        config()->set('appgraph.mcp.auto_scan', 'missing');

        AppGraphServer::tool(OverviewTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('generation.id', $generation)
                ->where('counts.nodes', 12)
                ->etc());

        $this->assertSame($generation, $store->current()['id']);
        $this->assertCount(1, $store->generations()['generations']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_automatic_mode_replaces_legacy_json_with_an_authoritative_generation(): void
    {
        config()->set('appgraph.mcp.auto_scan', 'missing');
        $this->disableAllScanners();
        $this->assertNull(app(GraphStore::class)->current());

        AppGraphServer::tool(OverviewTool::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('generation.id')
                ->where('counts.nodes', 0)
                ->etc());

        $this->assertNotNull(app(GraphStore::class)->current());
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
                ->where('generationChanged', true)
                ->where('counts.nodes', 0)
                ->etc());
    }

    public function test_refresh_does_not_compare_fresh_child_runtime_evidence_to_the_long_lived_parent(): void
    {
        $scan = app(ScanFingerprint::class)->capture();
        $scan['runtimeEvidenceSession'] = str_repeat('a', 32);
        $scan['containerBindings'] = str_repeat('b', 64);
        $scan['laravelExecutionRegistry'] = str_repeat('c', 64);
        $scan['fingerprint'] = str_repeat('d', 64);
        $scan['configuration'] = str_repeat('e', 64);
        $scan['applicationEnvironment'] = 'fresh-child-environment';
        $graph = new Graph([
            'generatedAt' => now()->toISOString(),
            'appName' => 'Fresh child fixture',
            'scan' => $scan,
        ]);
        $result = app(GraphStore::class)->publish($graph);
        app()->instance(ScanRunner::class, new class($result) implements ScanRunner
        {
            /** @param array<string, mixed> $result */
            public function __construct(private array $result)
            {
            }

            public function run(?string $preserveGeneration = null): array
            {
                return $this->result;
            }
        });

        AppGraphServer::tool(RefreshTool::class)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $payload = $json->toArray();

                $this->assertFalse($payload['staleness']['stale']);
                $this->assertTrue($payload['staleness']['staticFingerprintMatches']);
                $this->assertFalse($payload['staleness']['runtimeEvidenceComparable']);
                $this->assertSame(
                    'unknown_cross_process',
                    $payload['staleness']['runtimeEvidenceFreshness'],
                );
                $this->assertSame(
                    'unknown_cross_process',
                    $payload['staleness']['effectiveConfigurationFreshness'],
                );
                $this->assertArrayNotHasKey(
                    'configurationChanged',
                    $payload['staleness'],
                );
                $this->assertArrayNotHasKey(
                    'containerBindingsChanged',
                    $payload['staleness'],
                );
                $json->etc();
            });
    }

    public function test_refresh_reuses_an_identical_generation(): void
    {
        $this->disableAllScanners();

        AppGraphServer::tool(RefreshTool::class)->assertOk();
        $store = app(GraphStore::class);
        $baseline = $store->current()['id'];

        AppGraphServer::tool(RefreshTool::class)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($baseline): void {
                $payload = $json->toArray();

                $this->assertSame($baseline, $payload['generation']['id']);
                $this->assertSame($baseline, $payload['previousGeneration']['id']);
                $this->assertFalse($payload['generationChanged']);
                $this->assertArrayNotHasKey('verification', $payload);
                $json->etc();
            });

        $this->assertSame($baseline, $store->current()['id']);
        $this->assertCount(1, $store->generations()['generations']);
    }

    public function test_stale_auto_scan_does_not_refresh_an_unchanged_runtime_aware_generation(): void
    {
        $this->disableAllScanners();
        config()->set('appgraph.mcp.auto_scan', 'stale');
        AppGraphServer::tool(RefreshTool::class)->assertOk();
        $store = app(GraphStore::class);
        $generation = $store->current()['id'];

        AppGraphServer::tool(OverviewTool::class)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($generation): void {
                $payload = $json->toArray();

                $this->assertSame($generation, $payload['generation']['id']);
                $this->assertFalse($payload['staleness']['stale']);
                $this->assertFalse($payload['staleness']['containerBindingsChanged']);
                $json->etc();
            });

        $this->assertCount(1, $store->generations()['generations']);
    }

    public function test_node_tool_advertises_and_enforces_strict_bounded_inputs(): void
    {
        $schema = app(NodeTool::class)->toArray()['inputSchema'];

        $this->assertFalse($schema['additionalProperties']);
        $this->assertContains('id', $schema['required']);
        $this->assertSame(4096, $schema['properties']['id']['maxLength']);
        $this->assertSame(200, $schema['properties']['limit']['maximum']);

        AppGraphServer::tool(NodeTool::class, [
            'id' => 'App\\Services\\NoteService::save',
            'limit' => 1,
            'full' => false,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $payload = $json->toArray();

                $this->assertCount(1, $payload['out']);
                $this->assertCount(1, $payload['in']);
                $this->assertTrue($payload['truncated']);
                $this->assertArrayNotHasKey('metadata', $payload['node']);
                $json->etc();
            });

        foreach ([
            ['id' => 'App\\Services\\NoteService::save', 'limit' => '1'],
            ['id' => 'App\\Services\\NoteService::save', 'limit' => 0],
            ['id' => 'App\\Services\\NoteService::save', 'limit' => 201],
            ['id' => 'App\\Services\\NoteService::save', 'full' => 'false'],
            ['id' => '   '],
            ['id' => "node\0suffix"],
            ['id' => str_repeat('x', 4097)],
        ] as $arguments) {
            AppGraphServer::tool(NodeTool::class, $arguments)->assertHasErrors();
        }
    }

    public function test_every_default_mcp_tool_rejects_unknown_input_fields(): void
    {
        foreach ([
            [OverviewTool::class, ['limit' => 1], 'limit'],
            [SearchTool::class, ['term' => 'Note', 'limt' => 1], 'limt'],
            [NodeTool::class, ['id' => 'table:notes', 'ful' => true], 'ful'],
            [QueryTool::class, ['query' => 'tables', 'depht' => 2], 'depht'],
            [RefreshTool::class, ['depth' => 2], 'depth'],
        ] as [$tool, $arguments, $unknown]) {
            $schema = app($tool)->toArray()['inputSchema'];
            $this->assertFalse($schema['additionalProperties']);

            AppGraphServer::tool($tool, $arguments)
                ->assertHasErrors()
                ->assertSee('Unknown AppGraph input field')
                ->assertSee("[{$unknown}]");
        }
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

    private function disableAllScanners(): void
    {
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }
    }
}
