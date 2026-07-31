<?php

namespace AppGraph\Runtime;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use LengthException;

/**
 * Collection concepts are adapted from Pest v5.0.2's TIA recorder (MIT).
 * See THIRD_PARTY_NOTICES.md.
 *
 * This implementation is framework-neutral: a PHPUnit extension may register
 * tests from public event metadata, attach Laravel runtime links while each test
 * is active, then feed PHPUnit's final lineCoverage() map through
 * recordPhpUnitLineCoverage().
 */
final class RuntimeEvidenceCollector
{
    private readonly ProjectPathNormalizer $paths;

    private readonly string $sessionId;

    /** @var Closure(): (DateTimeInterface|string) */
    private readonly Closure $clock;

    /**
     * @var array<string, array{id: string, file: string, test_hash: ?string, captured_at: string}>
     */
    private array $tests = [];

    /** @var array<string, array<string, true>> */
    private array $keysByTestId = [];

    /** @var array<string, array<string, array<int, true>>> */
    private array $sourceLines = [];

    /** @var array<string, array<string, true>> */
    private array $tables = [];

    /** @var array<string, array<string, true>> */
    private array $blades = [];

    /** @var array<string, array<string, true>> */
    private array $inertiaComponents = [];

    /** @var array<string, string|null> */
    private array $fileHashes = [];

    private ?string $currentKey = null;

    /**
     * A test's active observation replaces its previous observation only when
     * the test reaches a complete finish. Skips, setup failures, and coverage
     * failures restore this checkpoint instead.
     *
     * @var array{
     *     key: string,
     *     id: string,
     *     test: ?array{id: string, file: string, test_hash: ?string, captured_at: string},
     *     source_lines: ?array<string, array<int, true>>,
     *     tables: ?array<string, true>,
     *     blades: ?array<string, true>,
     *     inertia_components: ?array<string, true>,
     *     key_registered: bool
     * }|null
     */
    private ?array $activeCheckpoint = null;

    /**
     * @param (Closure(): (DateTimeInterface|string))|null $clock
     */
    public function __construct(
        string $projectRoot,
        ?string $sessionId = null,
        ?Closure $clock = null,
    ) {
        $this->paths = new ProjectPathNormalizer($projectRoot);
        $environmentSession = getenv('APPGRAPH_RUNTIME_SESSION');

        if ($sessionId === null && is_string($environmentSession) && $environmentSession !== '') {
            $sessionId = $environmentSession;
        }

        $sessionId ??= 'php-'.getmypid().'-'.bin2hex(random_bytes(12));

        if ($sessionId === ''
            || strlen($sessionId) > RuntimeTestEvidence::MAX_SESSION_ID_BYTES
            || str_contains($sessionId, "\0")) {
            throw new InvalidArgumentException('Runtime evidence session id is invalid.');
        }

        $this->sessionId = $sessionId;
        $this->clock = $clock ?? static fn (): string => RuntimeTimestamp::now();
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Pin source hashes before test execution. PHPUnit exposes per-test line
     * coverage only after execution; pre-hashing prevents a file rewritten
     * during the suite from receiving old ranges paired with its new hash.
     *
     * @param iterable<string> $sourceFiles
     */
    public function primeSourceHashes(iterable $sourceFiles): void
    {
        foreach ($sourceFiles as $sourceFile) {
            if (! is_string($sourceFile)) {
                continue;
            }

            $file = $this->paths->relative($sourceFile);

            if ($file !== null) {
                $this->hashFor($file);
            }
        }
    }

    /**
     * Register a test without changing the active-test cursor.
     */
    public function registerTest(RuntimeTestMetadata $metadata): bool
    {
        return $this->register($metadata, replaceEvidence: false) !== null;
    }

    /**
     * Mark a test active for optional Laravel runtime link collectors.
     */
    public function beginTest(RuntimeTestMetadata $metadata): bool
    {
        $this->abortTest();

        $id = $metadata->id();
        $file = $this->paths->relative($metadata->file());

        if ($file === null || $id === '' || strlen($id) > RuntimeTestEvidence::MAX_ID_BYTES) {
            return false;
        }

        $key = $file."\0".$id;
        $this->activeCheckpoint = [
            'key' => $key,
            'id' => $id,
            'test' => $this->tests[$key] ?? null,
            'source_lines' => $this->sourceLines[$key] ?? null,
            'tables' => $this->tables[$key] ?? null,
            'blades' => $this->blades[$key] ?? null,
            'inertia_components' => $this->inertiaComponents[$key] ?? null,
            'key_registered' => isset($this->keysByTestId[$id][$key]),
        ];

        $this->currentKey = $this->register($metadata, replaceEvidence: true);

        return $this->currentKey !== null;
    }

    public function beginPhpUnitTest(object $test): bool
    {
        $metadata = RuntimeTestMetadata::fromPhpUnitTest($test);

        return $metadata !== null && $this->beginTest($metadata);
    }

    public function endTest(): void
    {
        $this->commitTest();
    }

    public function commitTest(): void
    {
        if ($this->currentKey !== null && isset($this->tests[$this->currentKey])) {
            $this->tests[$this->currentKey]['captured_at'] = $this->timestamp();
        }

        $this->currentKey = null;
        $this->activeCheckpoint = null;
    }

    public function abortTest(): void
    {
        $checkpoint = $this->activeCheckpoint;

        if ($checkpoint === null) {
            $this->currentKey = null;

            return;
        }

        $key = $checkpoint['key'];

        if ($checkpoint['test'] === null) {
            unset($this->tests[$key]);
        } else {
            $this->tests[$key] = $checkpoint['test'];
        }

        if ($checkpoint['source_lines'] === null) {
            unset($this->sourceLines[$key]);
        } else {
            $this->sourceLines[$key] = $checkpoint['source_lines'];
        }

        if ($checkpoint['tables'] === null) {
            unset($this->tables[$key]);
        } else {
            $this->tables[$key] = $checkpoint['tables'];
        }

        if ($checkpoint['blades'] === null) {
            unset($this->blades[$key]);
        } else {
            $this->blades[$key] = $checkpoint['blades'];
        }

        if ($checkpoint['inertia_components'] === null) {
            unset($this->inertiaComponents[$key]);
        } else {
            $this->inertiaComponents[$key] = $checkpoint['inertia_components'];
        }

        if (! $checkpoint['key_registered']) {
            unset($this->keysByTestId[$checkpoint['id']][$key]);

            if (($this->keysByTestId[$checkpoint['id']] ?? []) === []) {
                unset($this->keysByTestId[$checkpoint['id']]);
            }
        }

        $this->currentKey = null;
        $this->activeCheckpoint = null;
    }

    public function hasActiveTest(): bool
    {
        return $this->currentKey !== null;
    }

    /** @param iterable<int> $executedLines */
    public function recordSourceLines(string $sourceFile, iterable $executedLines): void
    {
        if ($this->currentKey === null) {
            return;
        }

        $this->recordSourceForKeys([$this->currentKey], $sourceFile, $executedLines);
    }

    /**
     * Attach coverage to every registered test matching a PHPUnit test id.
     * Supplying the file lets the caller register an id that was not observed by
     * a Prepared event, while still avoiding Pest-specific class properties.
     *
     * @param iterable<int> $executedLines
     */
    public function recordSourceLinesForTestId(
        string $testId,
        string $sourceFile,
        iterable $executedLines,
        ?string $testFile = null,
    ): void {
        $keys = $this->keysForTestId($testId, $testFile);

        if ($keys !== []) {
            $this->recordSourceForKeys($keys, $sourceFile, $executedLines);
        }
    }

    /**
     * Consume PHPUnit CodeCoverage::getData()->lineCoverage().
     *
     * @param array<string, array<int, array<int, string>|null>> $lineCoverage
     * @param array<string, string> $testFilesById
     * @return list<string> Test ids with at least one recorded source line.
     */
    public function recordPhpUnitLineCoverage(array $lineCoverage, array $testFilesById = []): array
    {
        /** @var array<string, array<string, array<int, true>>> $grouped */
        $grouped = [];

        foreach ($lineCoverage as $sourceFile => $lines) {
            if (! is_string($sourceFile) || ! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line => $testIds) {
                if ((! is_int($line) && ! ctype_digit((string) $line)) || ! is_array($testIds)) {
                    continue;
                }

                $line = (int) $line;

                if ($line < 1) {
                    continue;
                }

                foreach ($testIds as $testId) {
                    if (is_string($testId) && $testId !== '') {
                        $grouped[$testId][$sourceFile][$line] = true;
                    }
                }
            }
        }

        foreach ($grouped as $testId => $sources) {
            foreach ($sources as $sourceFile => $lines) {
                $testFile = $testFilesById[$testId] ?? null;
                $this->recordSourceLinesForTestId($testId, $sourceFile, array_keys($lines), $testFile);
            }
        }

        return array_keys($grouped);
    }

    public function recordTable(string $table): void
    {
        if ($this->currentKey !== null) {
            $this->recordLink([$this->currentKey], $this->tables, $table, lower: true);
        }
    }

    public function recordTableForTestId(string $testId, string $table, ?string $testFile = null): void
    {
        $this->recordLink($this->keysForTestId($testId, $testFile), $this->tables, $table, lower: true);
    }

    public function recordBlade(string $bladeFile): void
    {
        if ($this->currentKey !== null) {
            $this->recordBladeForKeys([$this->currentKey], $bladeFile);
        }
    }

    public function recordBladeForTestId(string $testId, string $bladeFile, ?string $testFile = null): void
    {
        $this->recordBladeForKeys($this->keysForTestId($testId, $testFile), $bladeFile);
    }

    public function recordInertiaComponent(string $component): void
    {
        if ($this->currentKey !== null) {
            $this->recordLink([$this->currentKey], $this->inertiaComponents, $component);
        }
    }

    public function recordInertiaComponentForTestId(
        string $testId,
        string $component,
        ?string $testFile = null,
    ): void {
        $this->recordLink(
            $this->keysForTestId($testId, $testFile),
            $this->inertiaComponents,
            $component,
        );
    }

    public function snapshot(): RuntimeEvidenceSnapshot
    {
        $generatedAt = $this->timestamp();
        $tests = [];

        foreach ($this->tests as $key => $test) {
            $sources = [];

            foreach ($this->sourceLines[$key] ?? [] as $file => $lines) {
                $sources[] = RuntimeSourceEvidence::fromExecutedLines(
                    $file,
                    $this->hashFor($file),
                    array_keys($lines),
                );
            }

            $tests[] = RuntimeTestEvidence::create(
                id: $test['id'],
                file: $test['file'],
                testFileSha256: $test['test_hash'],
                sessionId: $this->sessionId,
                capturedAt: $test['captured_at'],
                sources: $sources,
                tables: array_keys($this->tables[$key] ?? []),
                blades: array_keys($this->blades[$key] ?? []),
                inertiaComponents: array_keys($this->inertiaComponents[$key] ?? []),
            );
        }

        return RuntimeEvidenceSnapshot::create($this->sessionId, $generatedAt, $tests);
    }

    public function reset(): void
    {
        $this->tests = [];
        $this->keysByTestId = [];
        $this->sourceLines = [];
        $this->tables = [];
        $this->blades = [];
        $this->inertiaComponents = [];
        $this->fileHashes = [];
        $this->currentKey = null;
        $this->activeCheckpoint = null;
    }

    private function register(RuntimeTestMetadata $metadata, bool $replaceEvidence): ?string
    {
        $id = $metadata->id();
        $file = $this->paths->relative($metadata->file());

        if ($file === null || $id === '' || strlen($id) > RuntimeTestEvidence::MAX_ID_BYTES) {
            return null;
        }

        $key = $file."\0".$id;

        if (! isset($this->tests[$key]) && count($this->tests) >= RuntimeEvidenceSnapshot::MAX_TESTS) {
            throw new LengthException('Runtime evidence collector contains too many tests.');
        }

        $this->tests[$key] = [
            'id' => $id,
            'file' => $file,
            'test_hash' => $this->hashFor($file),
            'captured_at' => $this->timestamp(),
        ];
        $this->keysByTestId[$id][$key] = true;

        if ($replaceEvidence) {
            $this->sourceLines[$key] = [];
            $this->tables[$key] = [];
            $this->blades[$key] = [];
            $this->inertiaComponents[$key] = [];
        }

        return $key;
    }

    /** @return list<string> */
    private function keysForTestId(string $testId, ?string $testFile): array
    {
        if ($testFile !== null) {
            $metadata = RuntimeTestMetadata::fromStrings($testId, $testFile);
            $key = $metadata === null ? null : $this->register($metadata, replaceEvidence: false);

            return $key === null ? [] : [$key];
        }

        return array_keys($this->keysByTestId[$testId] ?? []);
    }

    /**
     * @param list<string> $keys
     * @param iterable<int> $executedLines
     */
    private function recordSourceForKeys(array $keys, string $sourceFile, iterable $executedLines): void
    {
        $file = $this->paths->relative($sourceFile);

        if ($file === null) {
            return;
        }

        // Direct coverage arrives at the end of each test. Hash immediately so
        // later tests that generate or rewrite source cannot relabel its trace.
        $this->hashFor($file);

        $lines = [];
        $seen = 0;

        foreach ($executedLines as $line) {
            $seen++;

            if ($seen > RuntimeSourceEvidence::MAX_EXECUTED_LINES) {
                throw new LengthException('Runtime evidence collector contains too many executed lines.');
            }

            if (! is_int($line)) {
                throw new InvalidArgumentException('Runtime evidence line numbers must be integers.');
            }

            if ($line > 0) {
                $lines[$line] = true;
            }
        }

        foreach ($keys as $key) {
            if (! isset($this->tests[$key])) {
                continue;
            }

            if (! isset($this->sourceLines[$key][$file])
                && count($this->sourceLines[$key] ?? []) >= RuntimeTestEvidence::MAX_SOURCES) {
                throw new LengthException('Runtime evidence test contains too many source files.');
            }

            $this->sourceLines[$key][$file] ??= [];

            foreach (array_keys($lines) as $line) {
                $this->sourceLines[$key][$file][$line] = true;
            }

            if (count($this->sourceLines[$key][$file]) > RuntimeSourceEvidence::MAX_EXECUTED_LINES) {
                throw new LengthException('Runtime evidence source contains too many executed lines.');
            }
        }
    }

    /**
     * @param list<string> $keys
     */
    private function recordBladeForKeys(array $keys, string $bladeFile): void
    {
        $file = $this->paths->relative($bladeFile);

        if ($file === null) {
            return;
        }

        // Blade paths are outside PHPUnit's PHP source filter, so capture
        // their bytes at observation time rather than at suite shutdown.
        $this->hashFor($file);
        $this->recordLink($keys, $this->blades, $file);
        $this->recordSourceForKeys($keys, $file, []);
    }

    /**
     * @param list<string> $keys
     * @param array<string, array<string, true>> $bucket
     */
    private function recordLink(array $keys, array &$bucket, string $value, bool $lower = false): void
    {
        $value = trim($value);

        if ($lower) {
            $value = strtolower($value);
        }

        if ($value === '' || strlen($value) > RuntimeTestEvidence::MAX_LINK_BYTES || str_contains($value, "\0")) {
            return;
        }

        foreach ($keys as $key) {
            if (! isset($this->tests[$key])) {
                continue;
            }

            if (! isset($bucket[$key][$value])
                && count($bucket[$key] ?? []) >= RuntimeTestEvidence::MAX_LINKS_PER_KIND) {
                throw new LengthException('Runtime evidence test contains too many links.');
            }

            $bucket[$key][$value] = true;
        }
    }

    private function hashFor(string $relativePath): ?string
    {
        if (array_key_exists($relativePath, $this->fileHashes)) {
            return $this->fileHashes[$relativePath];
        }

        $absolute = $this->paths->absolute($relativePath);

        if ($absolute === null || ! is_file($absolute)) {
            return $this->fileHashes[$relativePath] = null;
        }

        $hash = @hash_file('sha256', $absolute);

        return $this->fileHashes[$relativePath] = is_string($hash) ? $hash : null;
    }

    private function timestamp(): string
    {
        $value = ($this->clock)();

        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('Runtime evidence clock must return a timestamp string or DateTimeInterface.');
        }

        return RuntimeTimestamp::normalise($value);
    }
}
