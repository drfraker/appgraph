<?php

namespace AppGraph\Commands;

use AppGraph\AppGraph;
use AppGraph\Graph\Graph;
use AppGraph\Graph\GraphExporter;
use AppGraph\Graph\OverviewBuilder;
use AppGraph\Scanners\CallScanner;
use AppGraph\Scanners\ContainerBindingScanner;
use AppGraph\Scanners\DatabaseSchemaScanner;
use AppGraph\Scanners\DataFlowScanner;
use AppGraph\Scanners\EventFlowScanner;
use AppGraph\Scanners\FrontendRouteScanner;
use AppGraph\Scanners\FormRequestScanner;
use AppGraph\Scanners\ModelScanner;
use AppGraph\Scanners\PolicyScanner;
use AppGraph\Scanners\RouteScanner;
use AppGraph\Scanners\SideEffectScanner;
use AppGraph\Scanners\TestScanner;
use AppGraph\Storage\GraphStore;
use AppGraph\Support\ContainerBindingRegistry;
use AppGraph\Support\MemoryLimit;
use AppGraph\Support\PhpFileFacts;
use AppGraph\Support\ScanFingerprint;
use AppGraph\Support\ScanLock;
use AppGraph\Support\ScanResultRegistry;
use AppGraph\Support\SourceFileObservations;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ScanCommand extends Command
{
    private const MAX_EXACT_REFERENCE_CHARACTERS = 4096;

    private const MAX_EXACT_REFERENCE_BYTES = 16384;

    protected $signature = 'appgraph:scan
        {--output= : JSON output path. Relative paths resolve from the Laravel base path.}
        {--include-db : Include database schema scanning, even if disabled in config.}
        {--include-routes : Include route scanning, even if disabled in config.}
        {--overview : Write the overview projection, even if disabled in config.}
        {--no-overview : Skip writing the overview projection.}
        {--result-token= : Internal correlation token for MCP refresh result delivery.}
        {--result-file= : Internal private result channel for a fresh-process MCP refresh.}
        {--preserve-generation= : Internal numeric verification baseline retained through publication.}
        {--lock-owner= : Internal owner token for refresh-scoped scan locking.}
        {--pretty : Pretty-print the JSON output.}';

    protected $description = 'Publish an immutable SQLite AppGraph generation and attempt optional JSON and overview mirrors.';

    public function handle(
        RouteScanner $routeScanner,
        ModelScanner $modelScanner,
        DatabaseSchemaScanner $databaseSchemaScanner,
        FormRequestScanner $formRequestScanner,
        CallScanner $callScanner,
        DataFlowScanner $dataFlowScanner,
        EventFlowScanner $eventFlowScanner,
        SideEffectScanner $sideEffectScanner,
        FrontendRouteScanner $frontendRouteScanner,
        TestScanner $testScanner,
        PolicyScanner $policyScanner,
        ContainerBindingScanner $containerBindingScanner,
        ContainerBindingRegistry $containerBindings,
        PhpFileFacts $phpFileFacts,
        ScanFingerprint $scanFingerprint,
        GraphExporter $exporter,
        GraphStore $store,
        ScanLock $scanLock,
        ScanResultRegistry $scanResults,
        SourceFileObservations $sourceObservations,
    ): int {
        $acquiredLockToken = null;

        try {
            $resultToken = $this->option('result-token');

            if ($resultToken !== null
                && $resultToken !== ''
                && (! is_string($resultToken) || preg_match('/^[a-f0-9]{32}$/D', $resultToken) !== 1)) {
                throw new RuntimeException('The internal AppGraph scan result token is invalid.');
            }

            $resultFile = $this->validatedResultFile(
                $this->option('result-file'),
                is_string($resultToken) ? $resultToken : null,
                $store,
            );

            $preserveGeneration = $this->option('preserve-generation');

            if ($preserveGeneration !== null && $preserveGeneration !== '') {
                $preserveGeneration = is_string($preserveGeneration) ? trim($preserveGeneration) : '';

                if (strlen($preserveGeneration) > 19
                    || preg_match('/^[1-9]\d*$/D', $preserveGeneration) !== 1) {
                    throw new RuntimeException('The internal AppGraph verification baseline is invalid.');
                }
            } else {
                $preserveGeneration = null;
            }

            $lockOwner = $this->option('lock-owner');

            if ($lockOwner !== null && $lockOwner !== '') {
                $lockOwner = is_string($lockOwner) ? trim($lockOwner) : '';

                if (preg_match('/^[a-f0-9]{32}$/D', $lockOwner) !== 1) {
                    throw new RuntimeException('The internal AppGraph scan lock owner is invalid.');
                }
            } else {
                $lockOwner = null;
            }

            $acquiredLockToken = $scanLock->acquire($lockOwner);

            if ($preserveGeneration !== null) {
                // This runs under the scan lock and before any scanner work, so
                // the caller cannot accidentally use a generation created by
                // this refresh as its alleged pre-edit baseline.
                $store->verifyGeneration($preserveGeneration);
            }

            $path = $this->outputPath();
            $overviewPath = $this->shouldWriteOverview() ? $this->overviewPath($path) : null;
            $this->assertDistinctArtifactPaths(
                $store->path(),
                $scanLock->path(),
                $path,
                $overviewPath,
                $resultFile,
            );
            MemoryLimit::ensure(config('appgraph.memory_limit', '256M'));
            $sourceObservations->reset();
            $phpFileFacts->resetStats();
            $containerBindings->refresh();
            // Freeze one read-only booted-container snapshot for every scanner and
            // for the generation fingerprint. No later scanner may execute factories
            // merely to make the snapshot more specific.
            $containerBindings->bindings();

            $graph = new Graph([
                'generatedAt' => now()->toISOString(),
                'appName' => config('app.name'),
                'laravelVersion' => app()->version(),
                'appgraphVersion' => AppGraph::VERSION,
            ]);

            $includeRoutes = (bool) config('appgraph.scan.routes', true) || (bool) $this->option('include-routes');
            $includeDatabase = (bool) config('appgraph.scan.database', true) || (bool) $this->option('include-db');
            $includeModels = (bool) config('appgraph.scan.models', true);
            $includeFormRequests = (bool) config('appgraph.scan.form_requests', true);
            $includeCalls = (bool) config('appgraph.scan.calls', true);
            $includeDataFlow = (bool) config('appgraph.scan.data_flow', true);
            $includeEvents = (bool) config('appgraph.scan.events', true);
            $includeSideEffects = (bool) config('appgraph.scan.side_effects', true);
            $includeFrontend = (bool) config('appgraph.scan.frontend', true);
            $includeTests = (bool) config('appgraph.scan.tests', true);
            $includePolicies = (bool) config('appgraph.scan.policies', true);
            $includeContainerBindings = (bool) config('appgraph.scan.container_bindings', true);

            // Explicit schema-dump generation is the one scanner phase allowed to
            // write a scan input. Run it before the consistency fingerprint so the
            // generation records exactly the dump that was parsed.
            $databaseScanned = false;
            $additionalFingerprintFiles = [];

            if ($includeDatabase
                && config('appgraph.database.source', 'dump') === 'dump'
                && (bool) config('appgraph.database.dump.run', false)) {
                $this->runScanner($graph, 'database', fn () => $databaseSchemaScanner->scan($graph));
                $databaseScanned = true;
                $additionalFingerprintFiles = $databaseSchemaScanner->generatedDumpPaths();
            }

            $liveSchemaBefore = null;
            $liveSchemaSnapshot = null;
            $liveSchemaEvidence = null;

            if ($includeDatabase && config('appgraph.database.source', 'dump') === 'live') {
                try {
                    $connection = config('appgraph.database.connection');
                    $liveSchemaSnapshot = $databaseSchemaScanner->captureLiveSchema(
                        is_string($connection) ? $connection : null,
                    );
                    $liveSchemaBefore = $databaseSchemaScanner->liveSchemaSnapshotFingerprint(
                        $liveSchemaSnapshot,
                    );
                } catch (Throwable $throwable) {
                    $liveSchemaEvidence = [
                        'source' => 'live',
                        'consistency' => 'unverified',
                        'reason' => mb_strcut(mb_substr($throwable->getMessage(), 0, 512), 0, 2048, 'UTF-8'),
                    ];
                    $graph->addWarning([
                        'scanner' => 'database_schema_consistency',
                        'message' => 'Live database schema consistency could not be captured before scanning.',
                        'class' => $throwable::class,
                    ]);
                    // Do not independently re-read and publish an uncorrelated
                    // live schema after the consistency capture failed.
                    $databaseScanned = true;
                }
            }

            $initialFingerprint = $scanFingerprint->capture(
                refreshRuntimeEvidence: false,
                additionalFiles: $additionalFingerprintFiles,
            );

            if ($includeRoutes) {
                $this->runScanner($graph, 'routes', fn () => $routeScanner->scan($graph));
            }

            if ($includeDatabase && ! $databaseScanned) {
                if ($liveSchemaSnapshot !== null) {
                    $this->runScanner(
                        $graph,
                        'database',
                        fn () => $databaseSchemaScanner->scanLiveSnapshot($graph, $liveSchemaSnapshot),
                    );
                } else {
                    $this->runScanner($graph, 'database', fn () => $databaseSchemaScanner->scan($graph));
                }
            }

            if ($includeModels) {
                $this->runScanner($graph, 'models', fn () => $modelScanner->scan($graph));
            }

            if ($includeFormRequests) {
                $this->runScanner($graph, 'form_requests', fn () => $formRequestScanner->scan($graph));
            }

            if ($includeEvents) {
                $this->runScanner($graph, 'events', fn () => $eventFlowScanner->scan($graph));
            }

            if ($includeCalls) {
                $this->runScanner($graph, 'calls', fn () => $callScanner->scan($graph));
            }

            if ($includeDataFlow) {
                $this->runScanner($graph, 'data_flow', fn () => $dataFlowScanner->scan($graph));
            }

            if ($includeSideEffects) {
                $this->runScanner($graph, 'side_effects', fn () => $sideEffectScanner->scan($graph));
            }

            if ($includeFrontend) {
                $this->runScanner($graph, 'frontend', fn () => $frontendRouteScanner->scan($graph));
            }

            if ($includeTests) {
                $this->runScanner($graph, 'tests', fn () => $testScanner->scan($graph));
            }

            if ($includePolicies) {
                $this->runScanner($graph, 'policies', fn () => $policyScanner->scan($graph));
            }

            if ($includeContainerBindings) {
                $this->runScanner($graph, 'container_bindings', fn () => $containerBindingScanner->scan($graph));
            }

            if ($liveSchemaBefore !== null) {
                $connection = config('appgraph.database.connection');
                $liveSchemaAfterSnapshot = $databaseSchemaScanner->captureLiveSchema(
                    is_string($connection) ? $connection : null,
                );
                $liveSchemaAfter = $databaseSchemaScanner->liveSchemaSnapshotFingerprint(
                    $liveSchemaAfterSnapshot,
                );
                $observedLiveSchema = $databaseSchemaScanner->liveSchemaSnapshotFingerprint(
                    $liveSchemaSnapshot ?? [],
                );

                if (! hash_equals($liveSchemaBefore, $observedLiveSchema)
                    || ! hash_equals($observedLiveSchema, $liveSchemaAfter)) {
                    throw new RuntimeException(
                        'AppGraph live database schema changed while the scan was running. No generation was published; run `php artisan appgraph:scan` again.'
                    );
                }

                $liveSchemaEvidence = [
                    'source' => 'live',
                    'consistency' => 'matched_captured_before_after',
                    'fingerprint' => $liveSchemaAfter,
                ];
            }

            $finalFingerprint = $scanFingerprint->capture(
                refreshRuntimeEvidence: false,
                additionalFiles: $additionalFingerprintFiles,
            );

            if (! hash_equals($initialFingerprint['fingerprint'], $finalFingerprint['fingerprint'])) {
                throw new RuntimeException(
                    'AppGraph source inputs changed while the scan was running. No generation was published; run `php artisan appgraph:scan` again.'
                );
            }

            $this->assertObservedSourcesMatchManifest(
                $sourceObservations->hashes(),
                is_array($finalFingerprint['files'] ?? null) ? $finalFingerprint['files'] : [],
            );

            if ($liveSchemaEvidence !== null) {
                $finalFingerprint['databaseSchema'] = $liveSchemaEvidence;

                if (($liveSchemaEvidence['consistency'] ?? null) === 'matched_captured_before_after') {
                    $finalFingerprint['generationFingerprint'] = hash(
                        'sha256',
                        "appgraph-scan-live-schema\0"
                            .$finalFingerprint['fingerprint']."\0"
                            .$liveSchemaEvidence['fingerprint'],
                    );
                }
            }

            $graph->addMeta([
                'scan' => $finalFingerprint,
                'analysis' => [
                    'phpFileFacts' => $phpFileFacts->stats(),
                ],
            ]);

            $storeResult = $store->publish($graph, $preserveGeneration);
            $pretty = (bool) $this->option('pretty');
            $generation = $storeResult['generation']['id'];
            $verb = $storeResult['created'] ? 'published' : 'reused';
            $this->info("AppGraph generation {$generation} {$verb} in the authoritative SQLite store.");
            $mirrorWarnings = [];
            $mirrorsSafe = true;

            try {
                // Re-resolve actual inodes after first-use SQLite creation. A
                // mirror must never replace the committed store or a sidecar.
                $this->assertDistinctArtifactPaths(
                    $store->path(),
                    $scanLock->path(),
                    $path,
                    $overviewPath,
                    $resultFile,
                );
            } catch (Throwable $throwable) {
                $mirrorsSafe = false;
                $warning = $this->mirrorWarning(
                    'mirrors',
                    $path,
                    'Optional mirrors were skipped after publication because artifact paths no longer resolved to distinct files: '.$throwable->getMessage(),
                );
                $mirrorWarnings[] = $warning;
                $this->warn($warning['message']);
            }

            try {
                if (! $mirrorsSafe) {
                    throw new RuntimeException('Optional mirror publication was disabled by the post-commit artifact identity check.');
                }

                $exporter->export($graph, $path, $pretty);
                $this->info("AppGraph JSON mirror written to {$path}");
            } catch (Throwable $throwable) {
                if ($mirrorsSafe) {
                    $warning = $this->mirrorWarning(
                        'json',
                        $path,
                        "AppGraph generation {$generation} remains committed, but the optional JSON mirror could not be written: {$throwable->getMessage()}",
                    );
                    $mirrorWarnings[] = $warning;
                    $this->warn($warning['message']);
                }
            }

            if ($overviewPath !== null && $mirrorsSafe) {
                try {
                    $overview = new OverviewBuilder(
                        routeDepth: (int) config('appgraph.overview.route_summary.depth', 6),
                        routeMethodLimit: (int) config('appgraph.overview.route_summary.method_limit', 32),
                        routeItemLimit: (int) config('appgraph.overview.route_summary.item_limit', 12),
                        routeTransitionLimit: (int) config('appgraph.overview.route_summary.transition_limit', 5000),
                    );
                    $exporter->exportData($overview->build($graph), $overviewPath, $pretty);

                    $this->info("AppGraph overview written to {$overviewPath}");
                } catch (Throwable $throwable) {
                    $warning = $this->mirrorWarning(
                        'overview',
                        $overviewPath,
                        "AppGraph generation {$generation} remains committed, but the optional overview could not be written: {$throwable->getMessage()}",
                    );
                    $mirrorWarnings[] = $warning;
                    $this->warn($warning['message']);
                }
            }

            $storeResult['mirrorWarnings'] = $mirrorWarnings;

            if ($resultToken !== null && $resultToken !== '') {
                $scanResults->record($resultToken, $storeResult);

                if ($resultFile !== null) {
                    $this->writeResultFile($resultFile, $resultToken, $storeResult);
                }
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        } finally {
            if ($acquiredLockToken !== null) {
                $scanLock->release($acquiredLockToken);
            }
        }
    }

    private function shouldWriteOverview(): bool
    {
        if ((bool) $this->option('no-overview')) {
            return false;
        }

        return (bool) config('appgraph.overview.enabled', true) || (bool) $this->option('overview');
    }

    private function validatedResultFile(mixed $value, ?string $token, GraphStore $store): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)
            || $token === null
            || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1
            || basename($value) !== '.scan-result-'.$token.'.json'
            || is_link($value)
            || ! is_file($value)) {
            throw new RuntimeException('The internal AppGraph scan result channel is invalid.');
        }

        $resultDirectory = realpath(dirname($value));
        $storeDirectory = realpath(dirname($store->path()));

        if ($resultDirectory === false
            || $storeDirectory === false
            || ! hash_equals($storeDirectory, $resultDirectory)) {
            throw new RuntimeException('The internal AppGraph scan result channel is outside the store directory.');
        }

        return $value;
    }

    /** @param array<string, mixed> $result */
    private function writeResultFile(string $path, string $token, array $result): void
    {
        $before = lstat($path);
        $handle = @fopen($path, 'r+b');

        if (! is_array($before) || $handle === false) {
            throw new RuntimeException('The private AppGraph scan result channel could not be opened.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('The private AppGraph scan result channel could not be locked.');
            }

            $opened = fstat($handle);

            if (! is_array($opened)
                || ($opened['mode'] & 0170000) !== 0100000
                || ($opened['mode'] & 0777) !== 0600
                || $opened['dev'] !== $before['dev']
                || $opened['ino'] !== $before['ino']) {
                throw new RuntimeException('The private AppGraph scan result channel changed identity.');
            }

            $json = json_encode(
                ['token' => $token, 'result' => $result],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if (strlen($json) > 1048576 || ! ftruncate($handle, 0) || ! rewind($handle)) {
                throw new RuntimeException('The private AppGraph scan result channel could not be prepared.');
            }

            $written = 0;
            $length = strlen($json);

            while ($written < $length) {
                $bytes = fwrite($handle, substr($json, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('The private AppGraph scan result could not be written completely.');
                }

                $written += $bytes;
            }

            if (! fflush($handle)) {
                throw new RuntimeException('The private AppGraph scan result could not be flushed.');
            }

            $after = lstat($path);

            if (! is_array($after)
                || $after['dev'] !== $opened['dev']
                || $after['ino'] !== $opened['ino']) {
                throw new RuntimeException('The private AppGraph scan result channel was replaced.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function runScanner(Graph $graph, string $name, callable $scanner): void
    {
        try {
            $scanner();
        } catch (Throwable $throwable) {
            $message = $this->boundedDiagnostic($throwable->getMessage(), 512, 2048);
            $graph->addWarning([
                'scanner' => $name,
                'message' => $message,
                'class' => $throwable::class,
            ]);

            $this->warn("AppGraph {$name} scanner skipped: {$message}");
        }
    }

    private function outputPath(): string
    {
        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            return $this->isAbsolutePath($output) ? $output : base_path($output);
        }

        return storage_path(config('appgraph.output_path', 'appgraph/appgraph.json'));
    }

    private function overviewPath(string $mainPath): string
    {
        $configured = config('appgraph.overview.path', 'overview.json');
        $configured = is_string($configured) && $configured !== '' ? $configured : 'overview.json';

        if ($this->isAbsolutePath($configured)) {
            return $configured;
        }

        // A bare filename is placed next to the main output file so that an --output
        // relocation keeps the two files together; a relative path resolves from base.
        if (str_contains($configured, '/')) {
            return base_path($configured);
        }

        return dirname($mainPath).'/'.$configured;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:[\/\\\\]/i', $path) === 1;
    }

    private function assertDistinctArtifactPaths(
        string $storePath,
        string $lockPath,
        string $jsonPath,
        ?string $overviewPath,
        ?string $resultFile = null,
    ): void {
        $paths = [
            'SQLite store' => $this->pathIdentity($storePath),
            'SQLite WAL' => $this->pathIdentity($storePath.'-wal'),
            'SQLite shared memory' => $this->pathIdentity($storePath.'-shm'),
            'SQLite rollback journal' => $this->pathIdentity($storePath.'-journal'),
            'scan lock' => $this->pathIdentity($lockPath),
            'JSON mirror' => $this->pathIdentity($jsonPath),
        ];

        if ($overviewPath !== null) {
            $paths['overview'] = $this->pathIdentity($overviewPath);
        }

        if ($resultFile !== null) {
            $paths['fresh-process result'] = $this->pathIdentity($resultFile);
        }

        if (count(array_unique($paths)) !== count($paths)) {
            throw new RuntimeException(
                'AppGraph SQLite store, sidecars, scan lock, JSON mirror, overview, and result paths must be distinct files.'
            );
        }
    }

    private function pathIdentity(string $path): string
    {
        if (is_link($path)) {
            $target = readlink($path);

            if (is_string($target) && $target !== '') {
                $path = $this->isAbsolutePath($target)
                    ? $target
                    : dirname($path).'/'.$target;
            }
        }

        $path = $this->normalizePathLexically($path);
        $resolved = realpath($path);

        if ($resolved !== false) {
            $stat = @stat($resolved);

            if (is_array($stat) && isset($stat['dev'], $stat['ino'])) {
                return 'inode:'.$stat['dev'].':'.$stat['ino'];
            }

            $identity = str_replace('\\', '/', $resolved);
        } else {
            // Resolve the longest existing ancestor, then append nonexistent
            // components. This canonicalizes aliases such as macOS /var ->
            // /private/var even when an intermediate or leaf does not exist.
            $probe = $path;
            $tail = [];
            $resolvedPrefix = false;

            while (true) {
                $resolvedPrefix = realpath($probe);

                if ($resolvedPrefix !== false) {
                    break;
                }

                $parent = dirname($probe);

                if ($parent === $probe) {
                    break;
                }

                array_unshift($tail, basename($probe));
                $probe = $parent;
            }

            $prefix = $resolvedPrefix !== false
                ? str_replace('\\', '/', $resolvedPrefix)
                : $this->normalizePathLexically($probe);
            $identity = rtrim($prefix, '/');

            if ($tail !== []) {
                $identity .= '/'.implode('/', $tail);
            }
        }

        // Windows and the default macOS filesystem are case-insensitive. Being
        // conservative on a case-sensitive macOS volume may reject two unusual
        // paths, but never risks publishing a mirror over the store or lock.
        if (PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Darwin') {
            $identity = strtolower($identity);
        }

        return 'path:'.$identity;
    }

    private function normalizePathLexically(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = str_starts_with($path, '/')
            ? '/'
            : (preg_match('/^[A-Z]:\//i', $path) === 1 ? strtoupper(substr($path, 0, 2)).'/' : '');
        $remainder = $prefix === '/'
            ? substr($path, 1)
            : ($prefix !== '' ? substr($path, 3) : $path);
        $segments = [];

        foreach (explode('/', $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return rtrim($prefix, '/').'/'.implode('/', $segments);
    }

    /**
     * @return array{
     *     artifact: string,
     *     path?: string,
     *     pathOmitted?: true,
     *     pathBytes?: int,
     *     pathCharacters?: int,
     *     message: string
     * }
     */
    private function mirrorWarning(string $artifact, string $path, string $message): array
    {
        $warning = [
            'artifact' => $artifact,
            'message' => $this->boundedDiagnostic($message, 512, 2048),
        ];

        if ($this->isBoundedExactReference($path)) {
            $warning['path'] = $path;

            return $warning;
        }

        $warning['pathOmitted'] = true;
        $warning['pathBytes'] = strlen($path);

        if (preg_match('//u', $path) === 1) {
            $warning['pathCharacters'] = mb_strlen($path);
        }

        return $warning;
    }

    private function isBoundedExactReference(string $value): bool
    {
        return $value !== ''
            && ! str_contains($value, "\0")
            && strlen($value) <= self::MAX_EXACT_REFERENCE_BYTES
            && mb_strlen($value) <= self::MAX_EXACT_REFERENCE_CHARACTERS
            && preg_match('//u', $value) === 1;
    }

    private function boundedDiagnostic(string $value, int $characters, int $bytes): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = mb_substr($value, 0, $characters);

        return strlen($value) > $bytes
            ? mb_strcut($value, 0, $bytes, 'UTF-8')
            : $value;
    }

    /**
     * @param array<string, array<int, string>> $observed
     * @param array<string, string> $manifest
     */
    private function assertObservedSourcesMatchManifest(array $observed, array $manifest): void
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/');

        foreach ($observed as $file => $hashes) {
            if (count($hashes) !== 1) {
                throw new RuntimeException(
                    'AppGraph read a source file at multiple content hashes while scanning. No generation was published; run `php artisan appgraph:scan` again.'
                );
            }

            $normalized = str_replace('\\', '/', $file);
            $relative = str_starts_with($normalized, $base.'/')
                ? substr($normalized, strlen($base) + 1)
                : $normalized;
            $manifestHash = $manifest[$relative] ?? null;

            if (! is_string($manifestHash) || ! hash_equals($manifestHash, $hashes[0])) {
                throw new RuntimeException(
                    "AppGraph source [{$relative}] did not match the final scan manifest. No generation was published; run `php artisan appgraph:scan` again."
                );
            }
        }
    }
}
