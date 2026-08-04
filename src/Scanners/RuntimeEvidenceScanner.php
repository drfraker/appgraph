<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Runtime\JsonRuntimeEvidenceStore;
use AppGraph\Runtime\RuntimeEvidenceSnapshot;
use AppGraph\Runtime\RuntimeSourceEvidence;
use AppGraph\Runtime\RuntimeTestEvidence;
use AppGraph\Support\FileFinder;
use AppGraph\Support\SourceFileObservations;
use InvalidArgumentException;
use Throwable;

/**
 * Imports optional runtime observations after static scanning has completed.
 *
 * Runtime relationships are evidence, not declarations. A relationship is
 * therefore omitted when either endpoint file is missing or its recorded hash
 * is stale. Older snapshots without hashes remain usable at reduced confidence
 * and are always labelled unverified.
 */
final class RuntimeEvidenceScanner
{
    private const VERIFIED_CONFIDENCE = 1.0;

    private const UNVERIFIED_CONFIDENCE = 0.7;

    private const MAX_EDGE_OBSERVATIONS = 32;

    private const MAX_RANGES_PER_OBSERVATION = 64;

    private const MAX_SYMBOL_TARGETS_PER_SOURCE = 512;

    /**
     * These limits bound the in-memory graph expansion independently of the
     * persisted snapshot's JSON and schema bounds. Constructor overrides may
     * only lower them, which keeps test fixtures injectable without weakening
     * the production ceiling.
     */
    private const DEFAULT_LIMITS = [
        'processedTests' => 10_000,
        'sourceObservations' => 20_000,
        'edgeBuckets' => 12_000,
        'storedObservations' => 4_096,
        'symbolProjections' => 4_096,
    ];

    /** @var list<string> */
    private const INERTIA_PAGE_DIRECTORIES = [
        'resources/js/Pages',
        'resources/js/pages',
        'assets/js/Pages',
        'assets/js/pages',
        'assets/Pages',
        'assets/pages',
    ];

    /** @var list<string> */
    private const INERTIA_PAGE_EXTENSIONS = [
        'vue',
        'tsx',
        'jsx',
        'ts',
        'js',
        'svelte',
        'mts',
        'cts',
        'mjs',
        'cjs',
    ];

    private bool $prepared = false;

    private ?RuntimeEvidenceSnapshot $snapshot = null;

    private ?string $snapshotHash = null;

    /** @var array{class: string, message: string}|null */
    private ?array $preparationError = null;

    /** @var list<string> */
    private array $preparedInputFiles = [];

    /** @var array<string, array{exists: bool, hash: ?string, reason: ?string}> */
    private array $currentFiles = [];

    /** @var array<string, string> */
    private array $inertiaPageFiles = [];

    /** @var array{processedTests: int, sourceObservations: int, edgeBuckets: int, storedObservations: int, symbolProjections: int} */
    private readonly array $limits;

    /**
     * @param array<string, int>|null $limits Test-only lower scanner ceilings.
     */
    public function __construct(
        private readonly JsonRuntimeEvidenceStore $store,
        private readonly FileFinder $files,
        private readonly SourceFileObservations $sourceObservations,
        ?array $limits = null,
    ) {
        $this->limits = $this->normaliseLimits($limits ?? []);
    }

    public function inputPath(): ?string
    {
        return $this->enabled() ? $this->store->path() : null;
    }

    public function reset(): void
    {
        $this->prepared = false;
        $this->snapshot = null;
        $this->snapshotHash = null;
        $this->preparationError = null;
        $this->preparedInputFiles = [];
        $this->currentFiles = [];
        $this->inertiaPageFiles = [];
    }

    /**
     * Read and validate the snapshot once, then return every local file whose
     * bytes can affect the import. ScanCommand fingerprints these paths before
     * and after scanning, while SourceFileObservations binds the exact bytes
     * consumed here to that manifest.
     *
     * @return list<string>
     */
    public function inputFiles(): array
    {
        $this->prepare();

        return $this->preparedInputFiles;
    }

    public function scan(Graph $graph): Graph
    {
        $this->prepare();

        if (! $this->enabled() || (! is_file($this->store->path()) && $this->preparationError === null)) {
            return $graph;
        }

        if ($this->preparationError !== null || $this->snapshot === null) {
            $error = $this->preparationError ?? [
                'class' => 'RuntimeException',
                'message' => 'Runtime evidence snapshot could not be loaded.',
            ];
            $graph->addMeta([
                'analysis' => [
                    'runtimeEvidence' => [
                        'status' => 'invalid',
                        'snapshotPath' => $this->displayPath($this->store->path()),
                        'snapshotSha256' => $this->snapshotHash,
                        'error' => $error,
                    ],
                ],
            ]);
            $graph->addWarning([
                'scanner' => 'runtime_evidence',
                'message' => 'Runtime evidence snapshot was present but invalid and was not imported: '.$error['message'],
                'class' => $error['class'],
            ]);

            return $graph;
        }

        $allTests = $this->snapshot->tests();
        $tests = array_slice($allTests, 0, $this->limits['processedTests']);
        $semanticNodes = $this->semanticNodesByFile($graph);
        $edgeBuckets = [];
        $testNodeFreshness = [];
        $sourceNodeFreshness = [];
        $testFiles = [];
        $sourceFiles = [];
        $storedObservationCount = 0;
        $sourceObservationCount = 0;
        $stats = [
            'testsSeen' => count($allTests),
            'testsProcessed' => count($tests),
            'sourceObservationsProcessed' => 0,
            'storedObservationCount' => 0,
            'symbolProjectionsImported' => 0,
            'observationsImported' => 0,
            'freshness' => $this->emptyFreshnessCounts(),
            'omitted' => ['stale' => 0, 'missing' => 0],
            'symbolProjectionTruncated' => 0,
            'limits' => $this->limits,
            'truncation' => [
                'processedTests' => count($allTests) - count($tests),
                'sourceObservations' => 0,
                'edgeBuckets' => 0,
                'storedObservations' => 0,
                'symbolProjections' => 0,
            ],
        ];

        // Import primary file and framework-target relationships before symbol
        // projection can consume the global edge budget.
        foreach ($tests as $test) {
            $testPath = $test->file();
            $testFreshness = $this->freshness($test->testFileSha256(), $this->currentFile($testPath));
            $this->increment($testNodeFreshness[$testPath], $testFreshness);
            $sourcesByFile = [];

            foreach ($this->orderedSources($test) as $source) {
                if ($sourceObservationCount >= $this->limits['sourceObservations']) {
                    $stats['truncation']['sourceObservations']++;

                    continue;
                }

                $sourceObservationCount++;
                $sourcesByFile[$source->file()] = $source;
                $sourcePath = $source->file();

                if ($source->ranges() === []) {
                    // Empty-range sources are hash carriers for blade
                    // observations; the blades loop below counts their node
                    // freshness exactly once.
                    continue;
                }

                $sourceFreshness = $this->freshness($source->sha256(), $this->currentFile($sourcePath));
                $this->increment($sourceNodeFreshness[$sourcePath], $sourceFreshness);
                $relationshipFreshness = $this->combineFreshness($testFreshness, $sourceFreshness);

                if ($this->eligible($relationshipFreshness, $stats)) {
                    $accepted = $this->appendEdge(
                        $edgeBuckets,
                        $this->fileNodeId('test_file', $testPath),
                        $this->fileNodeId('source_file', $sourcePath),
                        'runtime_covers',
                        'file',
                        $relationshipFreshness,
                        $this->sourceObservation($test, $source, $relationshipFreshness),
                        ['sourceFile' => $sourcePath],
                        $stats,
                        $storedObservationCount,
                    );

                    if ($accepted) {
                        $testFiles[$testPath] = true;
                        $sourceFiles[$sourcePath] = true;
                        $this->increment($stats['freshness'], $relationshipFreshness);
                        $stats['observationsImported']++;
                    }
                }
            }

            foreach ($test->tables() as $table) {
                $freshness = $this->combineFreshness($testFreshness, 'unverified');

                if (! $this->eligible($freshness, $stats)) {
                    continue;
                }

                $tableId = 'table:'.$table;
                $accepted = $this->appendEdge(
                    $edgeBuckets,
                    $this->fileNodeId('test_file', $testPath),
                    $tableId,
                    'runtime_uses_table',
                    'runtime_target',
                    $freshness,
                    $this->testObservation($test, $freshness),
                    [],
                    $stats,
                    $storedObservationCount,
                );

                if ($accepted) {
                    $testFiles[$testPath] = true;
                    $this->addTargetNode($graph, $tableId, 'table', $table);
                    $this->increment($stats['freshness'], $freshness);
                    $stats['observationsImported']++;
                }
            }

            foreach ($test->inertiaComponents() as $component) {
                $freshness = $this->combineFreshness($testFreshness, 'unverified');

                if (! $this->eligible($freshness, $stats)) {
                    continue;
                }

                $componentId = 'inertia_component:'.$component;
                $accepted = $this->appendEdge(
                    $edgeBuckets,
                    $this->fileNodeId('test_file', $testPath),
                    $componentId,
                    'runtime_renders_inertia',
                    'runtime_target',
                    $freshness,
                    $this->testObservation($test, $freshness),
                    [],
                    $stats,
                    $storedObservationCount,
                );

                if ($accepted) {
                    $testFiles[$testPath] = true;
                    $this->addTargetNode(
                        $graph,
                        $componentId,
                        'inertia_component',
                        $component,
                        $this->inertiaPageFiles[$component] ?? null,
                    );
                    $this->increment($stats['freshness'], $freshness);
                    $stats['observationsImported']++;
                }
            }

            foreach ($test->blades() as $bladePath) {
                $bladeSource = $sourcesByFile[$bladePath] ?? null;
                $bladeFreshness = $bladeSource instanceof RuntimeSourceEvidence
                    ? $this->freshness($bladeSource->sha256(), $this->currentFile($bladePath))
                    : $this->freshness(null, $this->currentFile($bladePath));
                $this->increment($sourceNodeFreshness[$bladePath], $bladeFreshness);
                $freshness = $this->combineFreshness($testFreshness, $bladeFreshness);

                if (! $this->eligible($freshness, $stats)) {
                    continue;
                }

                $observation = $bladeSource instanceof RuntimeSourceEvidence
                    ? $this->sourceObservation($test, $bladeSource, $freshness)
                    : $this->testObservation($test, $freshness) + ['sourceFile' => $bladePath];
                $accepted = $this->appendEdge(
                    $edgeBuckets,
                    $this->fileNodeId('test_file', $testPath),
                    $this->fileNodeId('source_file', $bladePath),
                    'runtime_renders_blade',
                    'file',
                    $freshness,
                    $observation,
                    ['sourceFile' => $bladePath],
                    $stats,
                    $storedObservationCount,
                );

                if ($accepted) {
                    $testFiles[$testPath] = true;
                    $sourceFiles[$bladePath] = true;
                    $this->increment($stats['freshness'], $freshness);
                    $stats['observationsImported']++;
                }
            }
        }

        $stats['sourceObservationsProcessed'] = $sourceObservationCount;

        // Rewalk the same bounded, deterministic source prefix for symbol
        // projection. This keeps the primary relationships above intact while
        // avoiding a second large in-memory queue.
        $projectionSourceCount = 0;

        foreach ($tests as $test) {
            $testPath = $test->file();
            $testFreshness = $this->freshness($test->testFileSha256(), $this->currentFile($testPath));

            foreach ($this->orderedSources($test) as $source) {
                if ($projectionSourceCount >= $this->limits['sourceObservations']) {
                    continue;
                }

                $projectionSourceCount++;
                $sourcePath = $source->file();
                $sourceFreshness = $this->freshness($source->sha256(), $this->currentFile($sourcePath));
                $relationshipFreshness = $this->combineFreshness($testFreshness, $sourceFreshness);
                $testNodeId = $this->fileNodeId('test_file', $testPath);

                if ($source->ranges() === []
                    || in_array($relationshipFreshness, ['stale', 'missing'], true)
                    || ! isset($edgeBuckets[$this->edgeBucketKey(
                        $testNodeId,
                        $this->fileNodeId('source_file', $sourcePath),
                        'runtime_covers',
                    )])) {
                    continue;
                }

                $observation = $this->sourceObservation($test, $source, $relationshipFreshness);
                $projected = 0;

                foreach ($semanticNodes[$sourcePath] ?? [] as $semanticNode) {
                    $ranges = $this->intersectedRanges(
                        $source->ranges(),
                        $semanticNode->line ?? 1,
                        $semanticNode->endLine ?? $semanticNode->line ?? 1,
                    );

                    if ($ranges === []) {
                        continue;
                    }

                    if ($projected >= self::MAX_SYMBOL_TARGETS_PER_SOURCE) {
                        $stats['symbolProjectionTruncated']++;
                        $stats['truncation']['symbolProjections']++;

                        break;
                    }

                    if ($stats['symbolProjectionsImported'] >= $this->limits['symbolProjections']) {
                        $stats['symbolProjectionTruncated']++;
                        $stats['truncation']['symbolProjections']++;

                        break 3;
                    }

                    $symbolObservation = $observation;
                    $symbolObservation['ranges'] = array_slice($ranges, 0, self::MAX_RANGES_PER_OBSERVATION);
                    $symbolObservation['rangeCount'] = count($ranges);
                    $accepted = $this->appendEdge(
                        $edgeBuckets,
                        $testNodeId,
                        $semanticNode->id,
                        'runtime_covers',
                        'symbol',
                        $relationshipFreshness,
                        $symbolObservation,
                        [
                            'sourceFile' => $sourcePath,
                            'targetSpan' => [
                                'start' => $semanticNode->line,
                                'end' => $semanticNode->endLine ?? $semanticNode->line,
                            ],
                        ],
                        $stats,
                        $storedObservationCount,
                    );

                    if (! $accepted) {
                        $stats['symbolProjectionTruncated']++;
                        $stats['truncation']['symbolProjections']++;

                        break 3;
                    }

                    $this->increment($stats['freshness'], $relationshipFreshness);
                    $stats['observationsImported']++;
                    $stats['symbolProjectionsImported']++;
                    $projected++;
                }
            }
        }

        $stats['storedObservationCount'] = $storedObservationCount;

        foreach (array_keys($testFiles) as $path) {
            $this->addFileNode($graph, 'test_file', $path, $testNodeFreshness[$path] ?? []);
        }

        foreach (array_keys($sourceFiles) as $path) {
            $this->addFileNode($graph, 'source_file', $path, $sourceNodeFreshness[$path] ?? []);
        }

        ksort($edgeBuckets);
        $edgesByType = [];

        foreach ($edgeBuckets as $bucket) {
            $freshness = $this->aggregateFreshness($bucket['freshness']);
            $metadata = [
                'source' => 'runtime_evidence',
                'rule' => $this->ruleFor($bucket['type']),
                'schemaVersion' => $this->snapshot->schemaVersion(),
                'snapshotSha256' => $this->snapshotHash,
                'targetGranularity' => $bucket['targetGranularity'],
                'freshness' => $freshness,
                'freshnessCounts' => $bucket['freshness'],
                'observationCount' => $bucket['observationCount'],
                'runtimeObservations' => $bucket['observations'],
                ...$bucket['metadata'],
            ];

            if ($bucket['observationCount'] > count($bucket['observations'])) {
                $metadata['observationsTruncated'] = $bucket['observationCount'] - count($bucket['observations']);
            }

            $graph->addEdge(new Edge(
                $bucket['from'],
                $bucket['to'],
                $bucket['type'],
                ($bucket['freshness']['verified'] ?? 0) > 0
                    ? self::VERIFIED_CONFIDENCE
                    : self::UNVERIFIED_CONFIDENCE,
                $metadata,
            ));
            $this->increment($edgesByType, $bucket['type']);
        }

        ksort($edgesByType);
        ksort($stats['freshness']);
        $graph->addMeta([
            'analysis' => [
                'runtimeEvidence' => [
                    'status' => 'imported',
                    'schemaVersion' => $this->snapshot->schemaVersion(),
                    'snapshotPath' => $this->displayPath($this->store->path()),
                    'snapshotSha256' => $this->snapshotHash,
                    // A merged snapshot can contain per-test observations from
                    // several sessions. This top-level identity is only the
                    // latest writer; each edge retains its test's own session.
                    'latestWriter' => [
                        'sessionId' => $this->snapshot->sessionId(),
                        'generatedAt' => $this->snapshot->generatedAt(),
                    ],
                    'testsSeen' => $stats['testsSeen'],
                    'testsProcessed' => $stats['testsProcessed'],
                    'sourceObservationsProcessed' => $stats['sourceObservationsProcessed'],
                    'testFileCount' => count($testFiles),
                    'sourceFileCount' => count($sourceFiles),
                    'edgeCount' => count($edgeBuckets),
                    'edgesByType' => $edgesByType,
                    'storedObservationCount' => $stats['storedObservationCount'],
                    'symbolProjectionsImported' => $stats['symbolProjectionsImported'],
                    'observationsImported' => $stats['observationsImported'],
                    'freshness' => $stats['freshness'],
                    'omitted' => $stats['omitted'],
                    'symbolProjectionTruncated' => $stats['symbolProjectionTruncated'],
                    'limits' => $stats['limits'],
                    'truncation' => $stats['truncation'],
                ],
            ],
        ]);

        if (($stats['omitted']['stale'] ?? 0) > 0 || ($stats['omitted']['missing'] ?? 0) > 0) {
            $graph->addWarning([
                'scanner' => 'runtime_evidence',
                'message' => sprintf(
                    'Runtime evidence omitted %d stale and %d missing-file relationship observations.',
                    $stats['omitted']['stale'],
                    $stats['omitted']['missing'],
                ),
            ]);
        }

        if (($stats['freshness']['unverified'] ?? 0) > 0) {
            $graph->addWarning([
                'scanner' => 'runtime_evidence',
                'message' => sprintf(
                    'Runtime evidence imported %d unverified relationship observations at reduced confidence because a content hash was unavailable.',
                    $stats['freshness']['unverified'],
                ),
            ]);
        }

        if ($stats['symbolProjectionTruncated'] > 0) {
            $graph->addWarning([
                'scanner' => 'runtime_evidence',
                'message' => 'Runtime evidence symbol projection was bounded by scanner safety limits; file-level coverage was retained.',
            ]);
        }

        if (array_sum($stats['truncation']) > 0) {
            $graph->addWarning([
                'scanner' => 'runtime_evidence',
                'message' => sprintf(
                    'Runtime evidence import hit deterministic safety limits (tests: %d, sources: %d, edge buckets: %d, stored observations: %d, symbol projections: %d); retained evidence remains usable but incomplete.',
                    $stats['truncation']['processedTests'],
                    $stats['truncation']['sourceObservations'],
                    $stats['truncation']['edgeBuckets'],
                    $stats['truncation']['storedObservations'],
                    $stats['truncation']['symbolProjections'],
                ),
                'truncation' => $stats['truncation'],
            ]);
        }

        return $graph;
    }

    /**
     * @param array<string, int> $overrides
     * @return array{processedTests: int, sourceObservations: int, edgeBuckets: int, storedObservations: int, symbolProjections: int}
     */
    private function normaliseLimits(array $overrides): array
    {
        $limits = self::DEFAULT_LIMITS;

        foreach ($overrides as $name => $value) {
            if (! is_string($name)
                || ! array_key_exists($name, self::DEFAULT_LIMITS)
                || ! is_int($value)
                || $value < 0
                || $value > self::DEFAULT_LIMITS[$name]) {
                throw new InvalidArgumentException('Runtime evidence scanner limits are invalid.');
            }

            $limits[$name] = $value;
        }

        return $limits;
    }

    /** @return list<RuntimeSourceEvidence> */
    private function orderedSources(RuntimeTestEvidence $test): array
    {
        $sources = $test->sources();
        $blades = array_fill_keys($test->blades(), true);

        usort($sources, static fn (RuntimeSourceEvidence $left, RuntimeSourceEvidence $right): int => [
            isset($blades[$left->file()]) ? 0 : 1,
            $left->file(),
        ] <=> [
            isset($blades[$right->file()]) ? 0 : 1,
            $right->file(),
        ]);

        return $sources;
    }

    private function resolveInertiaPageFile(string $component): ?string
    {
        $component = str_replace('\\', '/', trim($component));
        $segments = explode('/', $component);
        $projectRoot = realpath($this->files->basePath());

        if ($projectRoot === false) {
            return null;
        }

        $projectRoot = str_replace('\\', '/', rtrim($projectRoot, '/'));

        if ($component === ''
            || str_starts_with($component, '/')
            || preg_match('/^[A-Za-z]:\//', $component) === 1
            || preg_match('//u', $component) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $component) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)) {
            return null;
        }

        $extension = strtolower(pathinfo($component, PATHINFO_EXTENSION));
        $componentCandidates = in_array($extension, self::INERTIA_PAGE_EXTENSIONS, true)
            ? [$component]
            : array_map(
                static fn (string $candidate): string => $component.'.'.$candidate,
                self::INERTIA_PAGE_EXTENSIONS,
            );

        foreach (self::INERTIA_PAGE_DIRECTORIES as $directory) {
            foreach ($componentCandidates as $candidate) {
                $relative = $directory.'/'.$candidate;
                $absolute = $this->files->absolutePath($relative);
                $real = realpath($absolute);

                if ($real === false || ! is_file($real)) {
                    continue;
                }

                // Requiring the resolved path to map back to the candidate
                // prevents a page symlink from escaping the project root.
                $real = str_replace('\\', '/', $real);

                if (str_starts_with($real, $projectRoot.'/')
                    && substr($real, strlen($projectRoot) + 1) === $relative) {
                    return $relative;
                }
            }
        }

        return null;
    }

    private function prepare(): void
    {
        if ($this->prepared) {
            return;
        }

        $this->prepared = true;

        if ($this->enabled()) {
            // Keep the configured path in both endpoint fingerprints even when
            // the optional file is currently absent. If a collector publishes
            // it during scanning, the final fingerprint will detect the change.
            $this->preparedInputFiles[] = $this->store->path();
        }

        if (! $this->enabled() || ! is_file($this->store->path())) {
            return;
        }

        $size = @filesize($this->store->path());

        if (is_int($size) && $size > RuntimeEvidenceSnapshot::MAX_JSON_BYTES) {
            $this->preparationError = [
                'class' => 'LengthException',
                'message' => 'Runtime evidence snapshot exceeds the maximum JSON size.',
            ];

            return;
        }

        $json = @file_get_contents($this->store->path());

        if ($json === false) {
            $this->preparationError = [
                'class' => 'RuntimeException',
                'message' => 'Runtime evidence snapshot could not be read.',
            ];

            return;
        }

        $this->snapshotHash = $this->sourceObservations->record($this->store->path(), $json);

        try {
            $this->snapshot = $this->store->readSource($json);
        } catch (Throwable $throwable) {
            $this->preparationError = [
                'class' => $throwable::class,
                'message' => $this->boundedMessage($throwable->getMessage()),
            ];

            return;
        }

        $sourceObservationCount = 0;

        foreach (array_slice($this->snapshot->tests(), 0, $this->limits['processedTests']) as $test) {
            $this->preparedInputFiles[] = $this->files->absolutePath($test->file());

            foreach ($this->orderedSources($test) as $source) {
                if ($sourceObservationCount >= $this->limits['sourceObservations']) {
                    continue;
                }

                $sourceObservationCount++;
                $this->preparedInputFiles[] = $this->files->absolutePath($source->file());
            }

            foreach ($test->blades() as $blade) {
                $this->preparedInputFiles[] = $this->files->absolutePath($blade);
            }

            foreach ($test->inertiaComponents() as $component) {
                $page = $this->resolveInertiaPageFile($component);

                if ($page !== null) {
                    $this->inertiaPageFiles[$component] = $page;
                    $this->preparedInputFiles[] = $this->files->absolutePath($page);
                }
            }
        }

        $this->preparedInputFiles = array_values(array_unique($this->preparedInputFiles));
        sort($this->preparedInputFiles);
    }

    /** @return array{exists: bool, hash: ?string, reason: ?string} */
    private function currentFile(string $path): array
    {
        if (isset($this->currentFiles[$path])) {
            return $this->currentFiles[$path];
        }

        $absolute = $this->files->absolutePath($path);

        if (! is_file($absolute)) {
            return $this->currentFiles[$path] = [
                'exists' => false,
                'hash' => null,
                'reason' => 'missing',
            ];
        }

        $source = @file_get_contents($absolute);

        if ($source === false) {
            return $this->currentFiles[$path] = [
                'exists' => false,
                'hash' => null,
                'reason' => 'unreadable',
            ];
        }

        return $this->currentFiles[$path] = [
            'exists' => true,
            'hash' => $this->sourceObservations->record($absolute, $source),
            'reason' => null,
        ];
    }

    /**
     * @param array{exists: bool, hash: ?string, reason: ?string} $current
     */
    private function freshness(?string $expectedHash, array $current): string
    {
        if (! $current['exists'] || ! is_string($current['hash'])) {
            return 'missing';
        }

        if ($expectedHash === null) {
            return 'unverified';
        }

        return hash_equals($expectedHash, $current['hash']) ? 'verified' : 'stale';
    }

    private function combineFreshness(string ...$states): string
    {
        if (in_array('missing', $states, true)) {
            return 'missing';
        }

        if (in_array('stale', $states, true)) {
            return 'stale';
        }

        return in_array('unverified', $states, true) ? 'unverified' : 'verified';
    }

    /** @param array<string, mixed> $stats */
    private function eligible(string $freshness, array &$stats): bool
    {
        if ($freshness === 'stale' || $freshness === 'missing') {
            $this->increment($stats['freshness'], $freshness);
            $this->increment($stats['omitted'], $freshness);

            return false;
        }

        return true;
    }

    /**
     * @return array<string, list<Node>>
     */
    private function semanticNodesByFile(Graph $graph): array
    {
        $byFile = [];

        foreach ($graph->nodes() as $node) {
            if (in_array($node->type, ['test_file', 'source_file'], true)
                || ! is_string($node->file)
                || $node->file === ''
                || ! is_int($node->line)
                || $node->line < 1) {
                continue;
            }

            $file = $this->normaliseGraphFile($node->file);

            if ($file === null) {
                continue;
            }

            $byFile[$file][] = $node;
        }

        foreach ($byFile as &$nodes) {
            usort($nodes, static fn (Node $left, Node $right): int => [
                $left->line,
                $left->endLine ?? $left->line,
                $left->id,
            ] <=> [
                $right->line,
                $right->endLine ?? $right->line,
                $right->id,
            ]);
        }
        unset($nodes);

        return $byFile;
    }

    private function normaliseGraphFile(string $file): ?string
    {
        $file = str_replace('\\', '/', $file);
        $relative = $this->files->relativePath($file);

        if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/')) {
            return null;
        }

        while (str_starts_with($relative, './')) {
            $relative = substr($relative, 2);
        }

        return $relative === '' ? null : $relative;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     * @return list<array{0: int, 1: int}>
     */
    private function intersectedRanges(array $ranges, int $start, int $end): array
    {
        $intersections = [];

        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if ($rangeEnd < $start) {
                continue;
            }

            if ($rangeStart > $end) {
                break;
            }

            $intersections[] = [max($rangeStart, $start), min($rangeEnd, $end)];
        }

        return $intersections;
    }

    /** @return array<string, mixed> */
    private function testObservation(RuntimeTestEvidence $test, string $freshness): array
    {
        return [
            'testId' => $test->id(),
            'testFile' => $test->file(),
            'sessionId' => $test->sessionId(),
            'capturedAt' => $test->capturedAt(),
            'freshness' => $freshness,
        ];
    }

    /** @return array<string, mixed> */
    private function sourceObservation(
        RuntimeTestEvidence $test,
        RuntimeSourceEvidence $source,
        string $freshness,
    ): array {
        $ranges = $source->ranges();

        return $this->testObservation($test, $freshness) + [
            'sourceFile' => $source->file(),
            'ranges' => array_slice($ranges, 0, self::MAX_RANGES_PER_OBSERVATION),
            'rangeCount' => count($ranges),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @param array<string, mixed> $observation
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $stats
     */
    private function appendEdge(
        array &$buckets,
        string $from,
        string $to,
        string $type,
        string $targetGranularity,
        string $freshness,
        array $observation,
        array $metadata,
        array &$stats,
        int &$storedObservationCount,
    ): bool {
        $key = $this->edgeBucketKey($from, $to, $type);

        if (! isset($buckets[$key]) && count($buckets) >= $this->limits['edgeBuckets']) {
            $stats['truncation']['edgeBuckets']++;

            return false;
        }

        $buckets[$key] ??= [
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'targetGranularity' => $targetGranularity,
            'freshness' => $this->emptyFreshnessCounts(),
            'observationCount' => 0,
            'observations' => [],
            'metadata' => $metadata,
        ];
        $buckets[$key]['observationCount']++;
        $this->increment($buckets[$key]['freshness'], $freshness);
        $observationKey = substr(hash(
            'sha256',
            json_encode($observation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ), 0, 16);

        if (! isset($buckets[$key]['observations'][$observationKey])) {
            if (count($buckets[$key]['observations']) >= self::MAX_EDGE_OBSERVATIONS
                || $storedObservationCount >= $this->limits['storedObservations']) {
                $stats['truncation']['storedObservations']++;
            } else {
                $buckets[$key]['observations'][$observationKey] = $observation;
                ksort($buckets[$key]['observations']);
                $storedObservationCount++;
            }
        }

        return true;
    }

    private function edgeBucketKey(string $from, string $to, string $type): string
    {
        return implode("\0", [$from, $to, $type]);
    }

    /** @param array<string, int> $freshnessCounts */
    private function addFileNode(Graph $graph, string $type, string $path, array $freshnessCounts): void
    {
        foreach (array_keys($freshnessCounts) as $state) {
            if (($freshnessCounts[$state] ?? 0) === 0) {
                unset($freshnessCounts[$state]);
            }
        }
        ksort($freshnessCounts);
        $graph->addNode(Node::make($this->fileNodeId($type, $path), $type, basename($path), [
            'file' => $path,
            'line' => 1,
            'metadata' => [
                'source' => 'runtime_evidence',
                'schemaVersion' => $this->snapshot?->schemaVersion(),
                'freshness' => $this->aggregateFreshness($freshnessCounts),
                'freshnessCounts' => $freshnessCounts,
            ],
        ]));
    }

    private function addTargetNode(
        Graph $graph,
        string $id,
        string $type,
        string $label,
        ?string $file = null,
    ): void
    {
        $graph->addNode(Node::make($id, $type, $label, [
            'file' => $file,
            'line' => $file === null ? null : 1,
            'metadata' => [
                'source' => 'runtime_evidence',
                'schemaVersion' => $this->snapshot?->schemaVersion(),
            ],
        ]));
    }

    private function fileNodeId(string $type, string $path): string
    {
        $id = $type.':'.$path;

        if (strlen($id) <= 16384 && mb_strlen($id) <= 4096) {
            return $id;
        }

        return $type.':sha256:'.hash('sha256', $path);
    }

    /** @return array{verified: int, unverified: int, stale: int, missing: int} */
    private function emptyFreshnessCounts(): array
    {
        return [
            'verified' => 0,
            'unverified' => 0,
            'stale' => 0,
            'missing' => 0,
        ];
    }

    /** @param array<string, int> $counts */
    private function aggregateFreshness(array $counts): string
    {
        $states = array_keys(array_filter($counts, static fn (int $count): bool => $count > 0));

        if ($states === []) {
            return 'unknown';
        }

        return count($states) === 1 ? $states[0] : 'mixed';
    }

    /** @param array<string, int>|null $counts */
    private function increment(?array &$counts, string $key): void
    {
        $counts ??= [];
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function ruleFor(string $type): string
    {
        return match ($type) {
            'runtime_covers' => 'executed_source_ranges',
            'runtime_uses_table' => 'database_query_observed',
            'runtime_renders_blade' => 'blade_render_observed',
            'runtime_renders_inertia' => 'inertia_render_observed',
            default => 'runtime_observation',
        };
    }

    private function enabled(): bool
    {
        try {
            return ! function_exists('config') || (bool) config('appgraph.runtime_evidence.enabled', true);
        } catch (Throwable) {
            return true;
        }
    }

    private function displayPath(string $path): string
    {
        $display = $this->files->relativePath($path) ?? $path;

        if ($display !== ''
            && ! str_contains($display, "\0")
            && strlen($display) <= 4096
            && preg_match('//u', $display) === 1
            && preg_match('/[\x00-\x1F\x7F]/u', $display) !== 1) {
            return $display;
        }

        return '[path omitted: sha256 '.hash('sha256', $path).']';
    }

    private function boundedMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? '';
        $message = mb_substr($message, 0, 512);

        return strlen($message) <= 2048 ? $message : mb_strcut($message, 0, 2048, 'UTF-8');
    }
}
