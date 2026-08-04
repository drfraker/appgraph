<?php

namespace AppGraph\Tests\Feature;

use AppGraph\AppGraph;
use AppGraph\Graph\Graph;
use AppGraph\Graph\GraphExporter;
use AppGraph\Mcp\AppGraphServer;
use AppGraph\Mcp\Tools\FindTool;
use AppGraph\Mcp\Tools\NodeTool;
use AppGraph\Mcp\Tools\OverviewTool;
use AppGraph\Mcp\Tools\QueryTool;
use AppGraph\Mcp\Tools\RefreshTool;
use AppGraph\Mcp\Tools\SearchTool;
use AppGraph\Mcp\Tools\SliceTool;
use AppGraph\Storage\GraphStore;
use AppGraph\Support\ScanResultRegistry;
use AppGraph\Support\ScanRunner;
use AppGraph\Support\ScanFingerprint;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use AppGraph\Tests\Support\InProcessScanRunner;
use AppGraph\Tests\TestCase;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class McpToolsTest extends TestCase
{
    use BuildsQueryFixtureGraph;

    /** @var array<int, string> */
    private array $sliceFixtureDirectories = [];

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
        config()->set('appgraph.mcp.legacy_tools', true);

        (new GraphExporter())->exportData(
            $this->queryFixtureGraph(),
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'))
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->sliceFixtureDirectories as $directory) {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_server_registers_as_a_local_mcp_server(): void
    {
        $this->assertNotNull(\Laravel\Mcp\Facades\Mcp::getLocalServer('appgraph'));
    }

    public function test_server_exposes_only_the_focused_navigation_tools(): void
    {
        config()->set('appgraph.mcp.legacy_tools', false);

        $server = new AppGraphServer(new FakeTransporter);
        $server->start();
        $context = $server->createContext();

        $this->assertSame([
            FindTool::class,
            SliceTool::class,
            RefreshTool::class,
        ], $context->tools()
            ->map(fn (Tool $tool): string => $tool::class)
            ->all());
        $this->assertLessThanOrEqual(4, count(preg_split('/\R/', trim($context->instructions)) ?: []));
        $this->assertStringContainsString('appgraph_find', $context->instructions);
        $this->assertStringContainsString('appgraph_slice', $context->instructions);
        $this->assertStringContainsString('appgraph_refresh', $context->instructions);
        $this->assertStringContainsString('not_observed', $context->instructions);
        $this->assertSame(
            (new \ReflectionClass(AppGraphServer::class))->getDefaultProperties()['instructions'],
            $context->instructions,
        );
    }

    public function test_server_can_opt_in_to_the_legacy_navigation_tools(): void
    {
        config()->set('appgraph.mcp.legacy_tools', true);

        $server = new AppGraphServer(new FakeTransporter);
        $server->start();

        $this->assertSame([
            FindTool::class,
            SliceTool::class,
            RefreshTool::class,
            OverviewTool::class,
            SearchTool::class,
            NodeTool::class,
            QueryTool::class,
        ], $server->createContext()->tools()
            ->map(fn (Tool $tool): string => $tool::class)
            ->all());
    }

    public function test_server_and_tool_metadata_keep_pre_attribute_mcp_fallbacks(): void
    {
        $serverProperties = (new \ReflectionClass(AppGraphServer::class))->getDefaultProperties();

        $this->assertSame('AppGraph', $serverProperties['name']);
        $this->assertSame(AppGraph::VERSION, $serverProperties['version']);
        $this->assertLessThanOrEqual(
            4,
            count(preg_split('/\R/', trim($serverProperties['instructions'])) ?: []),
        );

        foreach ([
            FindTool::class,
            SliceTool::class,
            RefreshTool::class,
            OverviewTool::class,
            SearchTool::class,
            NodeTool::class,
            QueryTool::class,
        ] as $toolClass) {
            $properties = (new \ReflectionClass($toolClass))->getDefaultProperties();
            $tool = app($toolClass)->toArray();

            $this->assertSame($tool['name'], $properties['name']);
            $this->assertSame($tool['description'], $properties['description']);
        }
    }

    public function test_find_tool_advertises_strict_bounded_inputs_and_returns_a_resolved_node_card(): void
    {
        $schema = app(FindTool::class)->toArray()['inputSchema'];

        $this->assertFalse($schema['additionalProperties']);
        $this->assertContains('target', $schema['required']);
        $this->assertSame(1, $schema['properties']['target']['minLength']);
        $this->assertSame(4096, $schema['properties']['target']['maxLength']);
        $this->assertSame(1, $schema['properties']['limit']['minimum']);
        $this->assertSame(200, $schema['properties']['limit']['maximum']);
        $this->assertArrayNotHasKey('full', $schema['properties']);

        AppGraphServer::tool(FindTool::class, [
            'target' => 'App\\Services\\NoteService@save',
            'limit' => 1,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $payload = $json->toArray();

                $this->assertSame('find', $payload['query']);
                $this->assertSame('App\\Services\\NoteService@save', $payload['target']);
                $this->assertSame('App\\Services\\NoteService::save', $payload['resolved']);
                $this->assertSame('App\\Services\\NoteService::save', $payload['node']['id']);
                $this->assertArrayNotHasKey('metadata', $payload['node']);
                $this->assertCount(1, $payload['out']);
                $this->assertCount(1, $payload['in']);
                $this->assertTrue($payload['truncated']);
                $json->etc();
            });

        foreach ([
            [],
            ['target' => '   '],
            ['target' => "NoteService\0save"],
            ['target' => str_repeat('x', 4097)],
            ['target' => 'NoteService::save', 'limit' => 0],
            ['target' => 'NoteService::save', 'limit' => 201],
            ['target' => 'NoteService::save', 'limit' => '50'],
        ] as $arguments) {
            AppGraphServer::tool(FindTool::class, $arguments)->assertHasErrors();
        }
    }

    public function test_find_tool_returns_exact_candidate_rows_for_an_ambiguous_target(): void
    {
        AppGraphServer::tool(FindTool::class, ['target' => 'Tag'])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $payload = $json->toArray();

                $this->assertSame('find', $payload['query']);
                $this->assertSame('Tag', $payload['target']);
                $this->assertSame([
                    'App\\Models\\Tag',
                    'App\\Other\\Tag',
                ], array_slice(array_column($payload['candidates'], 'id'), 0, 2));
                $this->assertSame(
                    ['model', 'model'],
                    array_slice(array_column($payload['candidates'], 'type'), 0, 2),
                );
                $this->assertArrayNotHasKey('resolved', $payload);
                $this->assertArrayNotHasKey('status', $payload);
                $json->etc();
            });
    }

    public function test_find_tool_treats_an_unobserved_target_as_a_successful_lookup(): void
    {
        AppGraphServer::tool(FindTool::class, ['target' => 'DefinitelyMissingGraphIdentity'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('query', 'find')
                ->where('target', 'DefinitelyMissingGraphIdentity')
                ->where('candidates', [])
                ->where('status', 'not_observed')
                ->whereType('hint', 'string')
                ->missing('resolved')
                ->etc());
    }

    public function test_slice_tool_returns_a_bounded_fresh_read_plan(): void
    {
        [$graph, $file] = $this->sliceToolFixture();
        (new GraphExporter())->exportData(
            $graph,
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
        );
        $schema = app(SliceTool::class)->toArray()['inputSchema'];

        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(20, $schema['properties']['anchors']['maxItems']);
        $this->assertSame(4096, $schema['properties']['anchors']['items']['maxLength']);
        $this->assertSame(50, $schema['properties']['files']['maxItems']);
        $this->assertSame(512, $schema['properties']['read_budget']['minimum']);
        $this->assertSame(16000, $schema['properties']['read_budget']['maximum']);
        $this->assertSame(6, $schema['properties']['depth']['maximum']);

        AppGraphServer::tool(SliceTool::class, [
            'anchors' => ['method:NoteWriter::save'],
            'read_budget' => 512,
            'depth' => 1,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($file): void {
                $payload = $json->toArray();

                $this->assertSame('slice', $payload['query']);
                $this->assertSame('current', $payload['freshness']['state']);
                $this->assertSame('App\Services\NoteWriter::save', $payload['anchors'][0]['resolved']);
                $this->assertSame($file, $payload['read'][0]['file']);
                $this->assertContains('explicit_target', $payload['read'][0]['why']);
                $this->assertLessThanOrEqual(512, $payload['budget']['usedTokens']);
                $this->assertArrayNotHasKey('confidence', $payload['read'][0]);
                $json->etc();
            });
    }

    public function test_slice_tool_returns_a_structured_unresolved_anchor_error(): void
    {
        AppGraphServer::tool(SliceTool::class, ['anchors' => ['class:Tag']])
            ->assertHasErrors()
            ->assertSee('Candidates')
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('query', 'slice')
                ->where('code', 'unresolved_anchor')
                ->where('anchor', 'class:Tag')
                ->where('candidates', ['App\Models\Tag', 'App\Other\Tag'])
                ->etc());
    }

    public function test_slice_tool_enforces_strict_bounded_inputs(): void
    {
        foreach ([
            [],
            ['anchors' => []],
            ['files' => []],
            ['anchors' => ['   ']],
            ['anchors' => ["method:Note\0Writer"]],
            ['anchors' => str_repeat('x', 10)],
            ['anchors' => array_fill(0, 21, 'method:NoteWriter::save')],
            ['files' => ['app/Note.php', 'app/Note.php']],
            ['files' => [str_repeat('x', 1025)]],
            ['anchors' => ['method:NoteWriter::save'], 'read_budget' => '512'],
            ['anchors' => ['method:NoteWriter::save'], 'read_budget' => 511],
            ['anchors' => ['method:NoteWriter::save'], 'depth' => 0],
            ['anchors' => ['method:NoteWriter::save'], 'depth' => '4'],
        ] as $arguments) {
            AppGraphServer::tool(SliceTool::class, $arguments)->assertHasErrors();
        }
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
                $this->assertArrayNotHasKey('truncated', $payload['scannerWarnings']);
                $this->assertArrayNotHasKey('bounds', $payload['scannerWarnings']);
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
                ->where('results.0.id', 'App\Services\NoteService::save')
                ->where('results.1.id', 'App\Services\NoteService::helper')
                ->etc());
    }

    public function test_long_authoritative_identity_round_trips_from_find_search_node_and_query(): void
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

        AppGraphServer::tool(FindTool::class, ['target' => $longId])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('target', $longId)
                ->where('resolved', $longId)
                ->where('node.id', $longId)
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
                ->where('matches.possible.0.match', 'possible')
                ->where('counts.possible', 1)
                ->missing('results')
                ->etc());

        AppGraphServer::tool(QueryTool::class, ['query' => 'impact-of', 'target' => 'notes.title'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('routes.0.id', 'route:PUT:/notes/{note}')
                ->etc());

        AppGraphServer::tool(QueryTool::class, ['query' => 'flow-from', 'target' => 'notes.update'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('entrypoint', 'App\Http\Controllers\NoteController::update')
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
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'views', 'tests', 'policies', 'container_bindings'] as $scanner) {
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
                ->where('revision', $generation)
                ->where('counts.nodes', 12)
                ->missing('generation')
                ->missing('graphAgeSeconds')
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
                ->whereType('revision', 'string')
                ->where('counts.nodes', 0)
                ->missing('generation')
                ->etc());

        $this->assertNotNull(app(GraphStore::class)->current());
    }

    public function test_refresh_tool_rebuilds_the_graph_explicitly(): void
    {
        $this->disableAllScanners();

        AppGraphServer::tool(RefreshTool::class)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $payload = $json->toArray();

                $this->assertSame('refresh', $payload['query']);
                $this->assertTrue($payload['changed']);
                $this->assertTrue($payload['firstGeneration']);
                $this->assertIsString($payload['revision']);
                $this->assertSame(0, $payload['counts']['nodes']);
                $this->assertArrayNotHasKey('refreshed', $payload);
                $this->assertArrayNotHasKey('generationChanged', $payload);
                $json->etc();
            });
    }

    public function test_overview_does_not_compare_fresh_child_runtime_evidence_to_the_long_lived_parent(): void
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
        app(GraphStore::class)->publish($graph);

        AppGraphServer::tool(OverviewTool::class)
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

                $this->assertSame($baseline, $payload['revision']);
                $this->assertSame($baseline, $payload['previousRevision']);
                $this->assertFalse($payload['changed']);
                $this->assertArrayNotHasKey('comparison', $payload);
                $this->assertArrayNotHasKey('counts', $payload);
                $this->assertArrayNotHasKey('changes', $payload);
                $this->assertArrayNotHasKey('firstGeneration', $payload);
                $json->etc();
            });

        $this->assertSame($baseline, $store->current()['id']);
        $this->assertCount(1, $store->generations()['generations']);
    }

    public function test_refresh_returns_a_change_receipt_between_generations(): void
    {
        $store = app(GraphStore::class);
        $baseline = $store->publish($this->graphObject($this->queryFixtureGraph()))['generation']['id'];

        $modified = $this->queryFixtureGraph();
        $modified['nodes'][] = [
            'id' => 'App\Services\NoteService::added',
            'type' => 'method',
            'label' => 'NoteService::added',
            'file' => 'app/Services/NoteService.php',
            'line' => 50,
        ];
        $modified['edges'][] = [
            'from' => 'App\Services\NoteService::save',
            'to' => 'App\Services\NoteService::added',
            'type' => 'calls',
            'confidence' => 1.0,
        ];
        app()->instance(ScanRunner::class, new class($store, $this->graphObject($modified)) implements ScanRunner
        {
            public function __construct(private GraphStore $store, private Graph $graph)
            {
            }

            public function run(?string $preserveGeneration = null): array
            {
                return $this->store->publish($this->graph);
            }
        });

        $payload = null;
        AppGraphServer::tool(RefreshTool::class)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use (&$payload): void {
                $payload = $json->toArray();
                $json->etc();
            });

        $this->assertSame('refresh', $payload['query']);
        $this->assertSame($store->current()['id'], $payload['revision']);
        $this->assertSame($baseline, $payload['previousRevision']);
        $this->assertNotSame($baseline, $payload['revision']);
        $this->assertTrue($payload['changed']);
        $this->assertTrue($payload['comparison']['graphChanged']);
        $this->assertGreaterThanOrEqual(1, $payload['counts']['overall']['total']);
        $this->assertNotEmpty($payload['changes']);
        $this->assertArrayNotHasKey('firstGeneration', $payload);
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

                $this->assertSame($generation, $payload['revision']);
                $this->assertFalse($payload['staleness']['stale']);
                $this->assertFalse($payload['staleness']['containerBindingsChanged']);
                $this->assertArrayNotHasKey('generation', $payload);
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
            [SliceTool::class, ['files' => ['app/Note.php'], 'budegt' => 512], 'budegt'],
            [FindTool::class, ['target' => 'table:notes', 'full' => true], 'full'],
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

    public function test_auto_scan_off_errors_with_refresh_guidance_when_graph_is_missing(): void
    {
        config()->set('appgraph.mcp.auto_scan', 'off');

        $path = storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
        @unlink($path);

        AppGraphServer::tool(OverviewTool::class)
            ->assertHasErrors()
            ->assertSee('appgraph_refresh')
            ->assertSee('appgraph:scan');

        AppGraphServer::tool(FindTool::class, ['target' => 'NoteService::save'])
            ->assertHasErrors()
            ->assertSee('appgraph_refresh')
            ->assertSee('appgraph:scan');
    }

    private function disableAllScanners(): void
    {
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'views', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }
    }

    /** @return array{array<string, mixed>, string} */
    private function sliceToolFixture(): array
    {
        $directory = 'storage/framework/testing/appgraph-slice-'.bin2hex(random_bytes(4));
        $file = $directory.'/NoteWriter.php';
        $absolute = base_path($file);
        $this->sliceFixtureDirectories[] = base_path($directory);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        file_put_contents($absolute, implode('', array_map(
            static fn (int $line): string => sprintf("line %03d\n", $line),
            range(1, 40),
        )));

        return [[
            'meta' => [
                'generatedAt' => '2026-07-21T18:00:00Z',
                'scan' => [
                    'algorithm' => 'sha256',
                    'files' => [$file => hash_file('sha256', $absolute)],
                ],
            ],
            'nodes' => [[
                'id' => 'App\Services\NoteWriter::save',
                'type' => 'method',
                'label' => 'NoteWriter::save',
                'file' => $file,
                'line' => 3,
                'endLine' => 20,
            ]],
            'edges' => [],
        ], $file];
    }
}
