<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Graph\Graph;
use AppGraph\Graph\GraphExporter;
use AppGraph\Mcp\AppGraphServer;
use AppGraph\Mcp\Tools\ContextTool;
use AppGraph\Mcp\Tools\DiffTool;
use AppGraph\Mcp\Tools\GenerationsTool;
use AppGraph\Mcp\Tools\NodeTool;
use AppGraph\Mcp\Tools\OverviewTool;
use AppGraph\Mcp\Tools\QueryTool;
use AppGraph\Mcp\Tools\RefreshTool;
use AppGraph\Mcp\Tools\SearchTool;
use AppGraph\Mcp\Tools\VerifyChangeTool;
use AppGraph\Storage\ChangeVerifier;
use AppGraph\Storage\GenerationDiffer;
use AppGraph\Storage\GraphStore;
use AppGraph\Support\ScanLock;
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

    public function test_context_tool_surfaces_scanner_warning_counts_and_exact_samples(): void
    {
        $graph = $this->queryFixtureGraph();
        $graph['meta']['warnings'] = [[
            'scanner' => 'calls',
            'reason' => 'receiver_type_unknown',
            'class' => 'App\\Services\\NoteService',
            'file' => 'app/Services/NoteService.php',
            'message' => 'The receiver type could not be resolved.',
        ]];
        (new GraphExporter())->exportData(
            $graph,
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
        );

        AppGraphServer::tool(ContextTool::class, [
            'task' => 'Update the note workflow safely',
            'targets' => ['notes.update'],
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $payload = $json->toArray();

                $this->assertSame(1, $payload['scannerWarnings']['total']);
                $this->assertSame(
                    [['scanner' => 'calls', 'count' => 1]],
                    $payload['scannerWarnings']['byScanner'],
                );
                $this->assertSame(
                    'app/Services/NoteService.php',
                    $payload['scannerWarnings']['samples'][0]['fields']['file'],
                );
                $this->assertSame(0, $payload['scannerWarnings']['omittedSamples']);
                $json->etc();
            });
    }

    public function test_json_fallback_cannot_claim_an_immutable_generation_baseline(): void
    {
        $graph = $this->queryFixtureGraph();
        $graph['meta']['generation'] = ['id' => '1', 'current' => true];
        (new GraphExporter())->exportData(
            $graph,
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
        );

        AppGraphServer::tool(ContextTool::class, [
            'task' => 'Update the note workflow safely',
            'targets' => ['notes.update'],
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): void {
                $payload = $json->toArray();

                $this->assertArrayNotHasKey('generation', $payload);
                $this->assertSame(
                    'legacy_json_without_immutable_generation',
                    $payload['generationUnavailable']['reason'],
                );
                $this->assertArrayNotHasKey('baselineGeneration', $payload['verification']);
                $json->etc();
            });
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
        $this->assertSame(4096, $schema['properties']['targets']['items']['maxLength']);
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
            ['task' => 'Update notes', 'targets' => [str_repeat('x', 4097)]],
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

        AppGraphServer::tool(ContextTool::class, [
            'task' => 'Inspect the explicitly selected method',
            'targets' => [$longId],
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('seeds.0.id', $longId)
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

    public function test_refresh_can_verify_against_an_explicit_baseline(): void
    {
        $data = $this->queryFixtureGraph();
        $data['meta']['scan'] = [
            'fingerprint' => 'refresh-baseline',
            'configuration' => 'baseline-config',
            'applicationEnvironment' => 'testing',
            'files' => [],
        ];
        $baseline = app(GraphStore::class)->publish($this->graphObject($data))['generation']['id'];

        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }

        AppGraphServer::tool(RefreshTool::class, [
            'baseline_generation' => $baseline,
            'targets' => ['route:PUT:/notes/{note}'],
            'limit' => 20,
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('previousGeneration.id', $baseline)
                ->where('generationChanged', true)
                ->where('verification.query', 'verify-change')
                ->etc());
    }

    public function test_refresh_reuses_an_identical_generation_and_reports_noop_verification(): void
    {
        $this->disableAllScanners();

        AppGraphServer::tool(RefreshTool::class)->assertOk();
        $store = app(GraphStore::class);
        $baseline = $store->current()['id'];

        AppGraphServer::tool(RefreshTool::class, [
            'baseline_generation' => $baseline,
        ])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($baseline): void {
                $payload = $json->toArray();

                $this->assertSame($baseline, $payload['generation']['id']);
                $this->assertSame($baseline, $payload['previousGeneration']['id']);
                $this->assertFalse($payload['generationChanged']);
                $this->assertSame($baseline, $payload['verification']['baseline']['id']);
                $this->assertSame($baseline, $payload['verification']['generation']['id']);
                $this->assertTrue($payload['verification']['diff']['sameGeneration']);
                $this->assertSame(
                    'no_new_generation_published_with_uncertainties',
                    $payload['verification']['assessment']['status'],
                );
                $this->assertContains(
                    'no_new_generation_published',
                    array_column($payload['verification']['uncertainties'], 'code'),
                );
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

    public function test_refresh_rejects_verification_options_without_a_baseline_before_scanning(): void
    {
        $this->disableAllScanners();

        foreach ([
            ['targets' => ['notes.update']],
            ['changed_files' => ['app/Note.php']],
            ['limit' => 10],
        ] as $arguments) {
            AppGraphServer::tool(RefreshTool::class, $arguments)
                ->assertHasErrors()
                ->assertSee('require baseline_generation');
            $this->assertNull(app(GraphStore::class)->current());
        }
    }

    public function test_refresh_holds_scan_lock_through_pinned_verification(): void
    {
        config()->set('appgraph.store.retained_generations', 2);
        $store = app(GraphStore::class);
        $before = $this->queryFixtureGraph();
        $before['meta']['scan'] = ['fingerprint' => 'before', 'files' => []];
        $baseline = $store->publish($this->graphObject($before))['generation']['id'];
        $intervening = $this->queryFixtureGraph();
        $intervening['meta']['scan'] = ['fingerprint' => 'intervening', 'files' => []];
        $intervening['nodes'][0]['label'] = 'Intervening';
        $store->publish($this->graphObject($intervening));
        $this->disableAllScanners();
        $lock = app(ScanLock::class);
        $verifier = new class($store, new GenerationDiffer($store), base_path(), $lock) extends ChangeVerifier
        {
            public bool $competingWriterWasBlocked = false;

            public function __construct(
                GraphStore $store,
                GenerationDiffer $differ,
                string $basePath,
                private ScanLock $sharedLock,
            ) {
                parent::__construct($store, $differ, $basePath);
            }

            public function verify(
                string $baseline,
                string $generation = 'current',
                array $targets = [],
                array $changedFiles = [],
                int $depth = 4,
                float $minConfidence = 0.0,
                int $limit = 50,
                bool $confirmedRefreshReuse = false,
            ): array {
                $competitor = new ScanLock($this->sharedLock->path(), timeoutMs: 0);

                try {
                    $owner = $competitor->acquire();
                    $competitor->release($owner);
                } catch (\RuntimeException) {
                    $this->competingWriterWasBlocked = true;
                }

                return parent::verify(
                    $baseline,
                    $generation,
                    $targets,
                    $changedFiles,
                    $depth,
                    $minConfidence,
                    $limit,
                    $confirmedRefreshReuse,
            );
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

    public function test_context_tool_requires_json_numeric_types(): void
    {
        foreach ([
            ['token_budget' => '1024'],
            ['depth' => '3'],
            ['min_confidence' => '0.5'],
        ] as $argument) {
            AppGraphServer::tool(ContextTool::class, [
                'task' => 'Inspect note updates',
                ...$argument,
            ])->assertHasErrors();
        }
    }

    public function test_every_mcp_tool_rejects_unknown_input_fields(): void
    {
        foreach ([
            [OverviewTool::class, ['limit' => 1], 'limit'],
            [ContextTool::class, ['task' => 'Inspect notes', 'tokn_budget' => 1024], 'tokn_budget'],
            [SearchTool::class, ['term' => 'Note', 'limt' => 1], 'limt'],
            [NodeTool::class, ['id' => 'table:notes', 'ful' => true], 'ful'],
            [QueryTool::class, ['query' => 'tables', 'depht' => 2], 'depht'],
            [GenerationsTool::class, ['limt' => 1], 'limt'],
            [DiffTool::class, ['from_generation' => '1', 'generation' => '2'], 'generation'],
            [VerifyChangeTool::class, ['baseline_generation' => '1', 'to_generation' => '2'], 'to_generation'],
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

    public function test_mcp_list_inputs_reject_json_objects(): void
    {
        foreach ([
            [ContextTool::class, ['task' => 'Inspect notes', 'targets' => ['named' => 'notes.update']]],
            [ContextTool::class, ['task' => 'Inspect notes', 'changed_files' => ['named' => 'app/Note.php']]],
            [DiffTool::class, ['from_generation' => '1', 'categories' => ['named' => 'nodes']]],
            [VerifyChangeTool::class, ['baseline_generation' => '1', 'targets' => ['named' => 'notes.update']]],
            [VerifyChangeTool::class, ['baseline_generation' => '1', 'changed_files' => ['named' => 'app/Note.php']]],
            [RefreshTool::class, ['targets' => ['named' => 'notes.update']]],
            [RefreshTool::class, ['changed_files' => ['named' => 'app/Note.php']]],
        ] as [$tool, $arguments]) {
            AppGraphServer::tool($tool, $arguments)->assertHasErrors();
        }
    }
        };
        app()->instance(ChangeVerifier::class, $verifier);

        AppGraphServer::tool(RefreshTool::class, ['baseline_generation' => $baseline])
            ->assertOk();

        $this->assertTrue($verifier->competingWriterWasBlocked);
        $this->assertSame($baseline, $store->generation($baseline)['id']);
        $this->assertCount(3, $store->generations(limit: 10)['generations']);
    }

    public function test_refresh_rejects_a_nonexistent_pre_edit_baseline_without_publishing(): void
    {
        $this->disableAllScanners();

        AppGraphServer::tool(RefreshTool::class, ['baseline_generation' => '1'])
            ->assertHasErrors();
        $this->assertNull(app(GraphStore::class)->current());

        AppGraphServer::tool(RefreshTool::class)->assertOk();
        $first = app(GraphStore::class)->current()['id'];

        AppGraphServer::tool(RefreshTool::class, ['baseline_generation' => '2'])
            ->assertHasErrors()
            ->assertSee('not found');

        $this->assertSame($first, app(GraphStore::class)->current()['id']);
        $this->assertCount(1, app(GraphStore::class)->generations()['generations']);
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

    public function test_generation_tools_expose_bounded_diff_and_verification_workflow(): void
    {
        $store = app(GraphStore::class);
        $beforeData = $this->queryFixtureGraph();
        $beforeData['meta']['scan'] = [
            'fingerprint' => 'mcp-before',
            'configuration' => 'same',
            'applicationEnvironment' => 'testing',
            'files' => ['app/Services/NoteService.php' => hash('sha256', 'before')],
        ];
        $before = $store->publish($this->graphObject($beforeData))['generation']['id'];
        $afterData = $beforeData;
        $afterData['meta']['scan']['fingerprint'] = 'mcp-after';
        $afterData['meta']['scan']['files']['app/Services/NoteService.php'] = hash('sha256', 'after');
        $afterData['nodes'][] = [
            'id' => 'App\Services\NoteService::audit',
            'type' => 'method',
            'label' => 'NoteService::audit',
            'file' => 'app/Services/NoteService.php',
            'line' => 50,
        ];
        $after = $store->publish($this->graphObject($afterData))['generation']['id'];

        AppGraphServer::tool(GenerationsTool::class, ['limit' => 10])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('currentGeneration', $after)
                ->where('generations.1.id', $before)
                ->etc());

        AppGraphServer::tool(DiffTool::class, [
            'from_generation' => $before,
            'to_generation' => $after,
            'categories' => ['nodes'],
            'limit' => 10,
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('query', 'diff')
                ->where('counts.overall.added', 1)
                ->etc());

        AppGraphServer::tool(VerifyChangeTool::class, [
            'baseline_generation' => $before,
            'generation' => $after,
            'targets' => ['App\Services\NoteService::save'],
            'changed_files' => ['app/Services/NoteService.php'],
            'limit' => 10,
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('query', 'verify-change')
                ->where('sourceCoverage.requestedFiles.0.manifestStatus', 'changed')
                ->etc());

        AppGraphServer::tool(ContextTool::class, [
            'task' => 'Inspect the note service',
            'targets' => ['App\Services\NoteService::save'],
        ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('generation.id', $after)
                ->where('verification.baselineGeneration.id', $after)
                ->etc());
    }

    public function test_generation_tool_schemas_and_runtime_bounds_are_explicit(): void
    {
        $generations = app(GenerationsTool::class)->toArray()['inputSchema'];
        $diff = app(DiffTool::class)->toArray()['inputSchema'];
        $verify = app(VerifyChangeTool::class)->toArray()['inputSchema'];
        $refresh = app(RefreshTool::class)->toArray()['inputSchema'];

        $this->assertSame(100, $generations['properties']['limit']['maximum']);
        $this->assertSame(19, $generations['properties']['before_generation']['maxLength']);
        $this->assertContains('from_generation', $diff['required']);
        $this->assertSame(19, $diff['properties']['from_generation']['maxLength']);
        $this->assertSame(19, $diff['properties']['to_generation']['maxLength']);
        $this->assertSame(7, $diff['properties']['categories']['maxItems']);
        $this->assertTrue($diff['properties']['categories']['uniqueItems']);
        $this->assertContains('baseline_generation', $verify['required']);
        $this->assertSame(19, $verify['properties']['baseline_generation']['maxLength']);
        $this->assertSame(19, $verify['properties']['generation']['maxLength']);
        $this->assertSame(4096, $verify['properties']['targets']['items']['maxLength']);
        $this->assertSame(50, $verify['properties']['changed_files']['maxItems']);
        $this->assertSame(200, $verify['properties']['limit']['maximum']);
        $this->assertSame(19, $refresh['properties']['baseline_generation']['maxLength']);
        $this->assertSame(4096, $refresh['properties']['targets']['items']['maxLength']);

        AppGraphServer::tool(GenerationsTool::class, ['limit' => 101])->assertHasErrors();
        AppGraphServer::tool(GenerationsTool::class, ['limit' => '10'])->assertHasErrors();
        AppGraphServer::tool(DiffTool::class, [])->assertHasErrors();
        AppGraphServer::tool(DiffTool::class, [
            'from_generation' => '1',
            'categories' => ['nodes', 'nodes'],
        ])->assertHasErrors();
        AppGraphServer::tool(VerifyChangeTool::class, [
            'baseline_generation' => '1',
            'depth' => 7,
        ])->assertHasErrors();
        AppGraphServer::tool(VerifyChangeTool::class, [
            'baseline_generation' => '1',
            'min_confidence' => '0.5',
        ])->assertHasErrors();

        foreach (['previous', 'current', '0', '01', str_repeat('9', 20)] as $invalid) {
            AppGraphServer::tool(GenerationsTool::class, [
                'before_generation' => $invalid,
            ])->assertHasErrors();
            AppGraphServer::tool(DiffTool::class, [
                'from_generation' => $invalid,
            ])->assertHasErrors();
            AppGraphServer::tool(VerifyChangeTool::class, [
                'baseline_generation' => $invalid,
            ])->assertHasErrors();
            AppGraphServer::tool(RefreshTool::class, [
                'baseline_generation' => $invalid,
            ])->assertHasErrors();
        }
    }

    private function disableAllScanners(): void
    {
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }
    }
}
