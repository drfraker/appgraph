<?php

namespace AppGraph\Tests\Feature;

use AppGraph\Graph\Graph;
use AppGraph\Graph\GraphExporter;
use AppGraph\Mcp\AppGraphServer;
use AppGraph\Mcp\Tools\FindTool;
use AppGraph\Mcp\Tools\RefreshTool;
use AppGraph\Mcp\Tools\SliceTool;
use AppGraph\Storage\GraphStore;
use AppGraph\Support\ScanResultRegistry;
use AppGraph\Support\ScanRunner;
use AppGraph\Tests\Support\BuildsQueryFixtureGraph;
use AppGraph\Tests\Support\InProcessScanRunner;
use AppGraph\Tests\TestCase;
use Illuminate\Testing\Fluent\AssertableJson;

class ResponseBudgetTest extends TestCase
{
    use BuildsQueryFixtureGraph;

    /** @var array<int, string> */
    private array $fixtureDirectories = [];

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

        config()->set('appgraph.mcp.auto_scan', 'off');
        config()->set('appgraph.mcp.legacy_tools', false);

        (new GraphExporter())->exportData(
            $this->queryFixtureGraph(),
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureDirectories as $directory) {
            (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_resolved_find_card_stays_within_its_response_budget(): void
    {
        $payload = $this->structuredContent(FindTool::class, [
            'target' => 'App\\Services\\NoteService::save',
        ]);

        $this->assertNoNumericConfidence($payload);

        // Observed baseline on the standard query fixture: 764 bytes.
        $this->assertPayloadFits($payload, 1536);
    }

    public function test_ambiguous_find_candidates_stay_within_their_response_budget(): void
    {
        $payload = $this->structuredContent(FindTool::class, ['target' => 'Tag']);

        $this->assertNoNumericConfidence($payload);

        // Observed baseline on the standard query fixture: 500 bytes.
        $this->assertPayloadFits($payload, 1024);
    }

    public function test_default_slice_stays_within_its_response_budget(): void
    {
        [$graph, $staleFile] = $this->sliceFixture();
        (new GraphExporter())->exportData(
            $graph,
            storage_path(config('appgraph.output_path', 'appgraph/appgraph.json')),
        );
        file_put_contents(base_path($staleFile), "changed service source\n");

        $payload = $this->structuredContent(SliceTool::class, [
            'anchors' => ['route:notes.update', 'file:'.$staleFile],
            'read_budget' => 1000,
        ]);

        $this->assertNoNumericConfidence($payload);
        $this->assertGreaterThanOrEqual(3, count($payload['read']));
        $this->assertSame('stale', $payload['freshness']['state']);
        $this->assertNotEmpty($payload['tests']['mapped']);
        $this->assertNotEmpty($payload['gaps']);
        $this->assertNotEmpty($payload['omitted']);
        $this->assertTrue($payload['truncated']);

        // Observed baseline on the mixed-freshness, multi-layer fixture: 2,458 bytes.
        $this->assertPayloadFits($payload, 5120);
    }

    public function test_refresh_no_op_receipt_stays_within_its_response_budget(): void
    {
        $this->disableAllScanners();
        $this->structuredContent(RefreshTool::class);

        $payload = $this->structuredContent(RefreshTool::class);

        // Observed baseline for an unchanged generation: 73 bytes.
        $this->assertPayloadFits($payload, 160);
    }

    public function test_refresh_change_receipt_stays_within_its_response_budget(): void
    {
        $store = app(GraphStore::class);
        $store->publish($this->graphObject($this->queryFixtureGraph()));

        $modified = $this->queryFixtureGraph();
        $modified['nodes'][] = [
            'id' => 'App\\Services\\NoteService::added',
            'type' => 'method',
            'label' => 'NoteService::added',
            'file' => 'app/Services/NoteService.php',
            'line' => 50,
        ];
        $modified['edges'][] = [
            'from' => 'App\\Services\\NoteService::save',
            'to' => 'App\\Services\\NoteService::added',
            'type' => 'calls',
            'confidence' => 1.0,
        ];
        array_push(
            $modified['nodes'],
            ['id' => 'route:GET:/added', 'type' => 'route', 'label' => 'GET /added', 'metadata' => ['name' => 'added.show']],
            ['id' => 'App\\Policies\\NotePolicy::view', 'type' => 'method', 'label' => 'NotePolicy::view'],
            ['id' => 'App\\Jobs\\IndexAddedNote', 'type' => 'job', 'label' => 'IndexAddedNote'],
            ['id' => 'test:Tests\\Feature\\AddedNoteTest::test_show', 'type' => 'test', 'label' => 'AddedNoteTest::test_show'],
        );
        array_push(
            $modified['edges'],
            ['from' => 'route:GET:/added', 'to' => 'App\\Services\\NoteService::added', 'type' => 'routes_to', 'confidence' => 1.0],
            ['from' => 'App\\Services\\NoteService::added', 'to' => 'table:notes', 'type' => 'writes', 'confidence' => 0.85],
            ['from' => 'App\\Services\\NoteService::added', 'to' => 'App\\Policies\\NotePolicy::view', 'type' => 'authorizes_via', 'confidence' => 0.9],
            ['from' => 'App\\Services\\NoteService::added', 'to' => 'App\\Jobs\\IndexAddedNote', 'type' => 'dispatches', 'confidence' => 0.9],
            ['from' => 'test:Tests\\Feature\\AddedNoteTest::test_show', 'to' => 'route:GET:/added', 'type' => 'tests_route', 'confidence' => 1.0],
        );

        app()->instance(ScanRunner::class, new class($store, $this->graphObject($modified)) implements ScanRunner
        {
            public function __construct(private GraphStore $store, private Graph $graph)
            {
            }

            public function run(): array
            {
                return $this->store->publish($this->graph);
            }
        });

        $payload = $this->structuredContent(RefreshTool::class);
        $this->assertSame(
            ['authorization', 'queues', 'routes', 'tests', 'writes'],
            array_values(array_intersect(
                ['authorization', 'queues', 'routes', 'tests', 'writes'],
                array_keys($payload['counts']['byCategory']),
            )),
        );

        // Observed baseline for a category-rich semantic diff: 4,061 bytes.
        $this->assertPayloadFits($payload, 8192);
    }

    /**
     * @param class-string<\Laravel\Mcp\Server\Tool> $tool
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function structuredContent(string $tool, array $arguments = []): array
    {
        $payload = null;

        AppGraphServer::tool($tool, $arguments)
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use (&$payload): void {
                $payload = $json->toArray();
                $json->etc();
            });

        $this->assertIsArray($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadFits(array $payload, int $ceiling): void
    {
        $bytes = strlen($this->encodePayload($payload));

        $this->assertLessThanOrEqual(
            $ceiling,
            $bytes,
            "Structured content is {$bytes} bytes; the fixture ceiling is {$ceiling} bytes.",
        );
    }

    /** @param array<string, mixed> $payload */
    private function assertNoNumericConfidence(array $payload): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/"confidence"\s*:\s*-?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?/',
            $this->encodePayload($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    private function encodePayload(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function disableAllScanners(): void
    {
        foreach (['routes', 'database', 'models', 'calls', 'data_flow', 'form_requests', 'events', 'side_effects', 'frontend', 'tests', 'policies', 'container_bindings'] as $scanner) {
            config()->set("appgraph.scan.{$scanner}", false);
        }
    }

    /** @return array{array<string, mixed>, string} */
    private function sliceFixture(): array
    {
        $directory = 'storage/framework/testing/appgraph-budget-'.bin2hex(random_bytes(4));
        $this->fixtureDirectories[] = base_path($directory);
        $files = [
            'request' => $directory.'/UpdateNoteRequest.php',
            'controller' => $directory.'/NoteController.php',
            'service' => $directory.'/NoteService.php',
            'model' => $directory.'/Note.php',
            'test' => $directory.'/NoteUpdateTest.php',
            'huge' => $directory.'/HugeDependency.php',
        ];

        if (! is_dir(base_path($directory))) {
            mkdir(base_path($directory), 0775, true);
        }

        foreach ($files as $label => $file) {
            $lines = $label === 'huge' ? 1200 : 40;
            file_put_contents(base_path($file), implode('', array_map(
                static fn (int $line): string => sprintf("fixture line %04d\n", $line),
                range(1, $lines),
            )));
        }

        $manifest = [];

        foreach ($files as $file) {
            $manifest[$file] = hash_file('sha256', base_path($file));
        }

        return [[
            'meta' => [
                'generatedAt' => '2026-07-21T18:00:00Z',
                'analysis' => [
                    'callResolution' => [
                        'byCaller' => [
                            'App\\Services\\NoteService::save' => [
                                'count' => 2,
                                'byReason' => ['receiver_type_unknown' => 2],
                            ],
                        ],
                    ],
                ],
                'scan' => [
                    'algorithm' => 'sha256',
                    'files' => $manifest,
                ],
            ],
            'nodes' => [
                ['id' => 'route:PUT:/notes/{note}', 'type' => 'route', 'label' => 'PUT /notes/{note}', 'metadata' => ['name' => 'notes.update']],
                ['id' => 'App\\Http\\Requests\\UpdateNoteRequest', 'type' => 'form_request', 'label' => 'UpdateNoteRequest', 'file' => $files['request'], 'line' => 3, 'endLine' => 30],
                ['id' => 'App\\Http\\Controllers\\NoteController::update', 'type' => 'method', 'label' => 'NoteController::update', 'file' => $files['controller'], 'line' => 3, 'endLine' => 35],
                ['id' => 'App\\Services\\NoteService::save', 'type' => 'method', 'label' => 'NoteService::save', 'file' => $files['service'], 'line' => 3, 'endLine' => 35],
                ['id' => 'App\\Models\\Note', 'type' => 'model', 'label' => 'Note', 'file' => $files['model'], 'line' => 3, 'endLine' => 30],
                ['id' => 'test:Tests\\Feature\\NoteUpdateTest::test_update', 'type' => 'test', 'label' => 'NoteUpdateTest::test_update', 'file' => $files['test'], 'line' => 3, 'endLine' => 30],
                ['id' => 'App\\Services\\HugeDependency::run', 'type' => 'method', 'label' => 'HugeDependency::run', 'file' => $files['huge'], 'line' => 1, 'endLine' => 1200],
                ['id' => 'table:notes', 'type' => 'table', 'label' => 'notes'],
            ],
            'edges' => [
                ['from' => 'route:PUT:/notes/{note}', 'to' => 'App\\Http\\Controllers\\NoteController::update', 'type' => 'routes_to', 'confidence' => 1.0],
                ['from' => 'App\\Http\\Controllers\\NoteController::update', 'to' => 'App\\Http\\Requests\\UpdateNoteRequest', 'type' => 'validates_with', 'confidence' => 1.0],
                ['from' => 'App\\Http\\Controllers\\NoteController::update', 'to' => 'App\\Services\\NoteService::save', 'type' => 'calls', 'confidence' => 1.0],
                ['from' => 'App\\Http\\Controllers\\NoteController::update', 'to' => 'App\\Models\\Note', 'type' => 'uses_model', 'confidence' => 0.9],
                ['from' => 'App\\Services\\NoteService::save', 'to' => 'App\\Services\\HugeDependency::run', 'type' => 'calls', 'confidence' => 0.8],
                ['from' => 'App\\Services\\NoteService::save', 'to' => 'table:notes', 'type' => 'writes', 'confidence' => 0.85],
                ['from' => 'test:Tests\\Feature\\NoteUpdateTest::test_update', 'to' => 'route:PUT:/notes/{note}', 'type' => 'tests_route', 'confidence' => 1.0],
            ],
        ], $files['controller']];
    }
}
