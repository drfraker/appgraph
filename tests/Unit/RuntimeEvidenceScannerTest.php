<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Query\GraphIndex;
use AppGraph\Query\TaskContextPlanner;
use AppGraph\Runtime\JsonRuntimeEvidenceStore;
use AppGraph\Runtime\RuntimeEvidenceSnapshot;
use AppGraph\Runtime\RuntimeSourceEvidence;
use AppGraph\Runtime\RuntimeTestEvidence;
use AppGraph\Scanners\RuntimeEvidenceScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Support\SourceFileObservations;
use PHPUnit\Framework\TestCase;

final class RuntimeEvidenceScannerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_it_imports_verified_file_and_symbol_coverage_with_runtime_targets(): void
    {
        $project = $this->project();
        $testPath = 'tests/Feature/NoteTest.php';
        $sourcePath = 'app/Services/NoteService.php';
        $bladePath = 'resources/views/notes/show.blade.php';
        $pagePath = 'resources/js/Pages/Notes/Show.vue';
        $testSource = "<?php\n// note feature test\n";
        $source = <<<'PHP'
<?php
namespace App\Services;
class NoteService
{
    public function save(): void
    {
        echo 'saved';
    }
}
PHP;
        $blade = "<h1>Note</h1>\n";
        $this->write($project, $testPath, $testSource);
        $this->write($project, $sourcePath, $source);
        $this->write($project, $bladePath, $blade);
        $this->write($project, $pagePath, "<template><h1>Note</h1></template>\n");

        $runtimeTest = RuntimeTestEvidence::create(
            id: 'tests/Feature/NoteTest.php::saves a note',
            file: $testPath,
            testFileSha256: hash('sha256', $testSource),
            sessionId: 'session-a',
            capturedAt: '2026-07-31T12:00:00Z',
            sources: [
                RuntimeSourceEvidence::create($sourcePath, hash('sha256', $source), [[5, 7]]),
                RuntimeSourceEvidence::create($bladePath, hash('sha256', $blade), []),
            ],
            tables: ['notes'],
            blades: [$bladePath],
            inertiaComponents: ['Notes/Show'],
        );
        [$scanner, $observations, $snapshotPath] = $this->scanner(
            $project,
            RuntimeEvidenceSnapshot::create(
                'latest-writer-session',
                '2026-07-31T12:00:01Z',
                [$runtimeTest],
            ),
        );
        $graph = new Graph();
        $graph->addNode(Node::make('App\\Services\\NoteService::save', 'method', 'save', [
            'file' => $sourcePath,
            'line' => 5,
            'endLine' => 8,
        ]));

        $inputs = $scanner->inputFiles();
        $scanner->scan($graph);

        $expectedInputs = [
            $project.'/'.$sourcePath,
            $project.'/'.$bladePath,
            $project.'/'.$pagePath,
            $project.'/'.$testPath,
            $snapshotPath,
        ];
        sort($expectedInputs);
        $this->assertSame($expectedInputs, $inputs);
        $this->assertNotNull($graph->node('test_file:'.$testPath));
        $this->assertNotNull($graph->node('source_file:'.$sourcePath));
        $this->assertNotNull($graph->node('source_file:'.$bladePath));
        $fileCoverage = $this->edge(
            $graph,
            'test_file:'.$testPath,
            'source_file:'.$sourcePath,
            'runtime_covers',
        );
        $symbolCoverage = $this->edge(
            $graph,
            'test_file:'.$testPath,
            'App\\Services\\NoteService::save',
            'runtime_covers',
        );
        $this->assertNotNull($fileCoverage);
        $this->assertSame(1.0, $fileCoverage->confidence);
        $this->assertSame('verified', $fileCoverage->metadata['freshness']);
        $this->assertSame('file', $fileCoverage->metadata['targetGranularity']);
        $this->assertNotNull($symbolCoverage);
        $this->assertSame('symbol', $symbolCoverage->metadata['targetGranularity']);
        $this->assertSame(
            ['start' => 5, 'end' => 8],
            $symbolCoverage->metadata['targetSpan'],
        );
        $symbolObservation = array_values($symbolCoverage->metadata['runtimeObservations'])[0];
        $this->assertSame([[5, 7]], $symbolObservation['ranges']);
        $this->assertNotNull($this->edge(
            $graph,
            'test_file:'.$testPath,
            'table:notes',
            'runtime_uses_table',
        ));
        $this->assertSame(0.7, $this->edge(
            $graph,
            'test_file:'.$testPath,
            'inertia_component:Notes/Show',
            'runtime_renders_inertia',
        )?->confidence);
        $this->assertSame($pagePath, $graph->node('inertia_component:Notes/Show')?->file);
        $bladeEdge = $this->edge(
            $graph,
            'test_file:'.$testPath,
            'source_file:'.$bladePath,
            'runtime_renders_blade',
        );
        $this->assertNotNull($bladeEdge);
        $this->assertSame('verified', $bladeEdge->metadata['freshness']);
        $analysis = $graph->meta()['analysis']['runtimeEvidence'];
        $this->assertSame('imported', $analysis['status']);
        $this->assertSame(1, $analysis['schemaVersion']);
        $this->assertSame('latest-writer-session', $analysis['latestWriter']['sessionId']);
        $this->assertSame(3, $analysis['freshness']['verified']);
        $this->assertSame(2, $analysis['freshness']['unverified']);

        $observed = $observations->hashes();
        $this->assertArrayHasKey($snapshotPath, $observed);
        $this->assertArrayHasKey($project.'/'.$testPath, $observed);
        $this->assertArrayHasKey($project.'/'.$sourcePath, $observed);
        $this->assertArrayHasKey($project.'/'.$bladePath, $observed);

        $plan = (new TaskContextPlanner(GraphIndex::fromArray($graph->toArray()), $project))->plan(
            '',
            changedFiles: [$pagePath],
        );
        $this->assertSame($testPath, $plan['verification']['mappedTests'][0]['file'] ?? null);
    }

    public function test_global_import_limits_preserve_primary_edges_and_report_truncation(): void
    {
        $project = $this->project();
        $testPath = 'tests/Feature/BoundedATest.php';
        $sourcePath = 'app/BoundedA.php';
        $extraSourcePath = 'app/BoundedZ.php';
        $bladePath = 'resources/views/bounded.blade.php';
        $testSource = "<?php\n";
        $source = "<?php\nclass BoundedA {}\n";
        $this->write($project, $testPath, $testSource);
        $this->write($project, 'tests/Feature/BoundedBTest.php', $testSource);
        $this->write($project, $sourcePath, $source);
        $this->write($project, $extraSourcePath, $source);
        $this->write($project, $bladePath, "bounded\n");
        $first = RuntimeTestEvidence::create(
            id: 'BoundedATest::test_it',
            file: $testPath,
            testFileSha256: hash('sha256', $testSource),
            sessionId: 'bounded',
            capturedAt: '2026-07-31T12:00:00Z',
            sources: [
                RuntimeSourceEvidence::create($sourcePath, hash('sha256', $source), [[2, 2]]),
                RuntimeSourceEvidence::create($extraSourcePath, hash('sha256', $source), [[2, 2]]),
            ],
            tables: ['bounded'],
            blades: [$bladePath],
            inertiaComponents: ['Bounded/Show'],
        );
        $second = RuntimeTestEvidence::create(
            id: 'BoundedBTest::test_it',
            file: 'tests/Feature/BoundedBTest.php',
            testFileSha256: hash('sha256', $testSource),
            sessionId: 'bounded',
            capturedAt: '2026-07-31T12:00:00Z',
        );
        [$scanner] = $this->scanner(
            $project,
            RuntimeEvidenceSnapshot::create('bounded', '2026-07-31T12:00:01Z', [$first, $second]),
            [
                'processedTests' => 1,
                'sourceObservations' => 1,
                'edgeBuckets' => 4,
                'storedObservations' => 2,
                'symbolProjections' => 10,
            ],
        );
        $graph = new Graph();
        $graph->addNode(Node::make('BoundedA', 'class', 'BoundedA', [
            'file' => $sourcePath,
            'line' => 2,
            'endLine' => 2,
        ]));

        $scanner->inputFiles();
        $scanner->scan($graph);

        $this->assertNotNull($this->edge($graph, 'test_file:'.$testPath, 'source_file:'.$sourcePath, 'runtime_covers'));
        $this->assertNotNull($this->edge($graph, 'test_file:'.$testPath, 'table:bounded', 'runtime_uses_table'));
        $this->assertNotNull($this->edge($graph, 'test_file:'.$testPath, 'source_file:'.$bladePath, 'runtime_renders_blade'));
        $this->assertNotNull($this->edge($graph, 'test_file:'.$testPath, 'inertia_component:Bounded/Show', 'runtime_renders_inertia'));
        $this->assertNull($this->edge($graph, 'test_file:'.$testPath, 'BoundedA', 'runtime_covers'));
        $analysis = $graph->meta()['analysis']['runtimeEvidence'];
        $this->assertSame(1, $analysis['testsProcessed']);
        $this->assertSame(1, $analysis['sourceObservationsProcessed']);
        $this->assertSame(2, $analysis['storedObservationCount']);
        $this->assertSame([
            'processedTests' => 1,
            'sourceObservations' => 1,
            'edgeBuckets' => 1,
            'storedObservations' => 2,
            'symbolProjections' => 1,
        ], $analysis['truncation']);
        $this->assertNotEmpty(array_filter(
            $graph->meta()['warnings'],
            static fn (array $warning): bool => str_contains($warning['message'], 'deterministic safety limits'),
        ));
    }

    public function test_global_symbol_projection_limit_is_deterministic(): void
    {
        $project = $this->project();
        $testPath = 'tests/Feature/SymbolTest.php';
        $sourcePath = 'app/Symbols.php';
        $testSource = "<?php\n";
        $source = "<?php\nclass First {}\nclass Second {}\n";
        $this->write($project, $testPath, $testSource);
        $this->write($project, $sourcePath, $source);
        $runtimeTest = RuntimeTestEvidence::create(
            id: 'SymbolTest::test_it',
            file: $testPath,
            testFileSha256: hash('sha256', $testSource),
            sessionId: 'symbols',
            capturedAt: '2026-07-31T12:00:00Z',
            sources: [RuntimeSourceEvidence::create($sourcePath, hash('sha256', $source), [[2, 3]])],
        );
        [$scanner] = $this->scanner(
            $project,
            RuntimeEvidenceSnapshot::create('symbols', '2026-07-31T12:00:01Z', [$runtimeTest]),
            ['symbolProjections' => 1],
        );
        $graph = new Graph();
        $graph->addNode(Node::make('First', 'class', 'First', ['file' => $sourcePath, 'line' => 2, 'endLine' => 2]));
        $graph->addNode(Node::make('Second', 'class', 'Second', ['file' => $sourcePath, 'line' => 3, 'endLine' => 3]));

        $scanner->inputFiles();
        $scanner->scan($graph);

        $this->assertNotNull($this->edge($graph, 'test_file:'.$testPath, 'First', 'runtime_covers'));
        $this->assertNull($this->edge($graph, 'test_file:'.$testPath, 'Second', 'runtime_covers'));
        $analysis = $graph->meta()['analysis']['runtimeEvidence'];
        $this->assertSame(1, $analysis['symbolProjectionsImported']);
        $this->assertSame(1, $analysis['truncation']['symbolProjections']);
    }

    public function test_it_omits_relationships_whose_source_hash_is_stale(): void
    {
        $project = $this->project();
        $testPath = 'tests/Feature/StaleTest.php';
        $sourcePath = 'app/StaleService.php';
        $testSource = "<?php\n";
        $source = "<?php\nclass StaleService {}\n";
        $this->write($project, $testPath, $testSource);
        $this->write($project, $sourcePath, $source);
        $runtimeTest = RuntimeTestEvidence::create(
            id: 'StaleTest::test_it',
            file: $testPath,
            testFileSha256: hash('sha256', $testSource),
            sessionId: 'session-stale',
            capturedAt: '2026-07-31T12:00:00Z',
            sources: [RuntimeSourceEvidence::create($sourcePath, str_repeat('a', 64), [[2, 2]])],
        );
        [$scanner] = $this->scanner(
            $project,
            RuntimeEvidenceSnapshot::create('session-stale', '2026-07-31T12:00:01Z', [$runtimeTest]),
        );
        $graph = new Graph();
        $graph->addNode(Node::make('StaleService', 'class', 'StaleService', [
            'file' => $sourcePath,
            'line' => 2,
            'endLine' => 2,
        ]));

        $scanner->inputFiles();
        $scanner->scan($graph);

        $this->assertNull($this->edge(
            $graph,
            'test_file:'.$testPath,
            'source_file:'.$sourcePath,
            'runtime_covers',
        ));
        $this->assertNull($this->edge(
            $graph,
            'test_file:'.$testPath,
            'StaleService',
            'runtime_covers',
        ));
        $this->assertNull($graph->node('test_file:'.$testPath));
        $this->assertNull($graph->node('source_file:'.$sourcePath));
        $this->assertSame(1, $graph->meta()['analysis']['runtimeEvidence']['omitted']['stale']);
        $this->assertSame('runtime_evidence', $graph->meta()['warnings'][0]['scanner']);
    }

    public function test_it_imports_hashless_evidence_as_unverified_at_reduced_confidence(): void
    {
        $project = $this->project();
        $testPath = 'tests/Feature/LegacyTest.php';
        $sourcePath = 'app/LegacyService.php';
        $this->write($project, $testPath, "<?php\n");
        $this->write($project, $sourcePath, "<?php\nclass LegacyService {}\n");
        $runtimeTest = RuntimeTestEvidence::create(
            id: 'LegacyTest::test_it',
            file: $testPath,
            testFileSha256: null,
            sessionId: 'session-legacy',
            capturedAt: '2026-07-31T12:00:00Z',
            sources: [RuntimeSourceEvidence::create($sourcePath, null, [[2, 2]])],
        );
        [$scanner] = $this->scanner(
            $project,
            RuntimeEvidenceSnapshot::create('session-legacy', '2026-07-31T12:00:01Z', [$runtimeTest]),
        );
        $graph = new Graph();

        $scanner->inputFiles();
        $scanner->scan($graph);

        $edge = $this->edge(
            $graph,
            'test_file:'.$testPath,
            'source_file:'.$sourcePath,
            'runtime_covers',
        );
        $this->assertNotNull($edge);
        $this->assertSame(0.7, $edge->confidence);
        $this->assertSame('unverified', $edge->metadata['freshness']);
        $this->assertSame(1, $graph->meta()['analysis']['runtimeEvidence']['freshness']['unverified']);
        $this->assertStringContainsString('reduced confidence', $graph->meta()['warnings'][0]['message']);
    }

    public function test_a_missing_snapshot_is_silent_and_does_not_change_the_graph(): void
    {
        $project = $this->project();
        $snapshotPath = $project.'/runtime-evidence.json';
        $scanner = new RuntimeEvidenceScanner(
            new JsonRuntimeEvidenceStore($snapshotPath, $project),
            new FileFinder($project),
            new SourceFileObservations(),
        );
        $graph = new Graph(['existing' => true]);
        $graph->addNode(Node::make('Existing', 'class', 'Existing'));
        $before = $graph->toArray();

        $this->assertSame([$snapshotPath], $scanner->inputFiles());
        $this->assertSame($graph, $scanner->scan($graph));
        $this->assertSame($before, $graph->toArray());
    }

    public function test_an_unsupported_snapshot_schema_is_non_fatal_and_reported(): void
    {
        $project = $this->project();
        $snapshotPath = $project.'/runtime-evidence.json';
        file_put_contents($snapshotPath, json_encode([
            'schema' => 99,
            'session' => [
                'id' => 'future-session',
                'generated_at' => '2026-07-31T12:00:00Z',
            ],
            'tests' => [],
        ], JSON_THROW_ON_ERROR));
        $observations = new SourceFileObservations();
        $scanner = new RuntimeEvidenceScanner(
            new JsonRuntimeEvidenceStore($snapshotPath, $project),
            new FileFinder($project),
            $observations,
        );
        $graph = new Graph();

        $scanner->inputFiles();
        $scanner->scan($graph);

        $this->assertSame([], $graph->nodes());
        $this->assertSame([], $graph->edges());
        $analysis = $graph->meta()['analysis']['runtimeEvidence'];
        $this->assertSame('invalid', $analysis['status']);
        $this->assertSame('InvalidArgumentException', basename(str_replace('\\', '/', $analysis['error']['class'])));
        $this->assertStringContainsString('Unsupported runtime evidence snapshot schema', $analysis['error']['message']);
        $this->assertSame('runtime_evidence', $graph->meta()['warnings'][0]['scanner']);
        $this->assertArrayHasKey($snapshotPath, $observations->hashes());
    }

    /**
     * @return array{0: RuntimeEvidenceScanner, 1: SourceFileObservations, 2: string}
     */
    private function scanner(string $project, RuntimeEvidenceSnapshot $snapshot, array $limits = []): array
    {
        $snapshotPath = $project.'/runtime-evidence.json';
        $store = new JsonRuntimeEvidenceStore($snapshotPath, $project);
        $store->write($snapshot);
        $observations = new SourceFileObservations();

        return [
            new RuntimeEvidenceScanner($store, new FileFinder($project), $observations, $limits),
            $observations,
            $snapshotPath,
        ];
    }

    private function project(): string
    {
        $path = sys_get_temp_dir().'/appgraph-runtime-scanner-'.bin2hex(random_bytes(6));
        mkdir($path, 0775, true);
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    private function write(string $project, string $relative, string $contents): void
    {
        $path = $project.'/'.$relative;
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $contents);
    }

    private function edge(Graph $graph, string $from, string $to, string $type): ?Edge
    {
        foreach ($graph->edges() as $edge) {
            if ($edge->from === $from && $edge->to === $to && $edge->type === $type) {
                return $edge;
            }
        }

        return null;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
