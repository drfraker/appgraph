<?php

namespace AppGraph\Runtime;

use InvalidArgumentException;
use LengthException;

final class RuntimeTestEvidence
{
    public const MAX_ID_BYTES = 8192;

    public const MAX_SESSION_ID_BYTES = 256;

    public const MAX_SOURCES = 4096;

    public const MAX_LINKS_PER_KIND = 4096;

    public const MAX_LINK_BYTES = 1024;

    /**
     * @param list<RuntimeSourceEvidence> $sources
     * @param list<string> $tables
     * @param list<string> $blades
     * @param list<string> $inertiaComponents
     */
    private function __construct(
        private readonly string $id,
        private readonly string $file,
        private readonly ?string $testFileSha256,
        private readonly string $sessionId,
        private readonly string $capturedAt,
        private readonly array $sources,
        private readonly array $tables,
        private readonly array $blades,
        private readonly array $inertiaComponents,
    ) {
    }

    /**
     * @param list<RuntimeSourceEvidence> $sources
     * @param list<string> $tables
     * @param list<string> $blades
     * @param list<string> $inertiaComponents
     */
    public static function create(
        string $id,
        string $file,
        ?string $testFileSha256,
        string $sessionId,
        string $capturedAt,
        array $sources = [],
        array $tables = [],
        array $blades = [],
        array $inertiaComponents = [],
    ): self {
        self::assertIdentity($id, $file, $sessionId);

        return new self(
            $id,
            $file,
            self::normaliseSha256($testFileSha256),
            $sessionId,
            RuntimeTimestamp::normalise($capturedAt),
            self::normaliseSources($sources),
            self::normaliseLinks($tables, lower: true),
            self::normaliseLinks($blades),
            self::normaliseLinks($inertiaComponents),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ProjectPathNormalizer $paths): self
    {
        $id = $data['id'] ?? null;
        $rawFile = $data['file'] ?? null;
        $sha256 = $data['sha256'] ?? null;
        $sessionId = $data['session_id'] ?? null;
        $capturedAt = $data['captured_at'] ?? null;

        if (! is_string($id)
            || ! is_string($rawFile)
            || ! is_string($sessionId)
            || ! is_string($capturedAt)
            || ($file = $paths->relative($rawFile)) === null) {
            throw new InvalidArgumentException('Runtime test evidence identity is invalid.');
        }

        if ($sha256 !== null && ! is_string($sha256)) {
            throw new InvalidArgumentException('Runtime test file hash must be a string or null.');
        }

        $rawSources = $data['sources'] ?? [];
        $tables = $data['tables'] ?? [];
        $rawBlades = $data['blades'] ?? [];
        $inertiaComponents = $data['inertia_components'] ?? [];

        if (! is_array($rawSources)
            || ! is_array($tables)
            || ! is_array($rawBlades)
            || ! is_array($inertiaComponents)) {
            throw new InvalidArgumentException('Runtime test evidence links must be arrays.');
        }

        if (count($rawSources) > self::MAX_SOURCES) {
            throw new LengthException('Runtime test evidence contains too many source files.');
        }

        $sources = [];

        foreach ($rawSources as $source) {
            if (! is_array($source)) {
                throw new InvalidArgumentException('Runtime test source evidence must be an object.');
            }

            $sources[] = RuntimeSourceEvidence::fromArray($source, $paths);
        }

        $blades = [];

        foreach ($rawBlades as $blade) {
            if (! is_string($blade) || ($relative = $paths->relative($blade)) === null) {
                throw new InvalidArgumentException('Runtime Blade evidence contains an unsafe path.');
            }

            $blades[] = $relative;
        }

        return self::create(
            $id,
            $file,
            $sha256,
            $sessionId,
            $capturedAt,
            $sources,
            self::stringList($tables, 'table'),
            $blades,
            self::stringList($inertiaComponents, 'Inertia component'),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function testFileSha256(): ?string
    {
        return $this->testFileSha256;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function capturedAt(): string
    {
        return $this->capturedAt;
    }

    /** @return list<RuntimeSourceEvidence> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return list<string> */
    public function blades(): array
    {
        return $this->blades;
    }

    /** @return list<string> */
    public function inertiaComponents(): array
    {
        return $this->inertiaComponents;
    }

    public function key(): string
    {
        return $this->file."\0".$this->id;
    }

    /**
     * Merge two persisted observations of the same test. A more recent test
     * execution replaces stale evidence. Equal captures from the same session
     * are partials and are safely unioned.
     */
    public function merge(self $other): self
    {
        if ($this->key() !== $other->key()) {
            throw new InvalidArgumentException('Runtime test evidence can only merge the same test.');
        }

        $timeComparison = $this->capturedAt <=> $other->capturedAt;

        if ($timeComparison !== 0) {
            return $timeComparison > 0 ? $this : $other;
        }

        if ($this->sessionId !== $other->sessionId) {
            return strcmp($this->sessionId, $other->sessionId) >= 0 ? $this : $other;
        }

        $sources = [];

        foreach ([...$this->sources, ...$other->sources] as $source) {
            $sources[$source->file()] = isset($sources[$source->file()])
                ? $sources[$source->file()]->merge($source)
                : $source;
        }

        $sha256 = $this->testFileSha256 ?? $other->testFileSha256;

        if ($this->testFileSha256 !== null
            && $other->testFileSha256 !== null
            && $this->testFileSha256 !== $other->testFileSha256) {
            $sha256 = null;
        }

        return self::create(
            $this->id,
            $this->file,
            $sha256,
            $this->sessionId,
            $this->capturedAt,
            array_values($sources),
            [...$this->tables, ...$other->tables],
            [...$this->blades, ...$other->blades],
            [...$this->inertiaComponents, ...$other->inertiaComponents],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'file' => $this->file,
        ];

        if ($this->testFileSha256 !== null) {
            $data['sha256'] = $this->testFileSha256;
        }

        $data['session_id'] = $this->sessionId;
        $data['captured_at'] = $this->capturedAt;
        $data['sources'] = array_map(
            static fn (RuntimeSourceEvidence $source): array => $source->toArray(),
            $this->sources,
        );
        $data['tables'] = $this->tables;
        $data['blades'] = $this->blades;
        $data['inertia_components'] = $this->inertiaComponents;

        return $data;
    }

    private static function assertIdentity(string $id, string $file, string $sessionId): void
    {
        if ($id === ''
            || strlen($id) > self::MAX_ID_BYTES
            || str_contains($id, "\0")
            || $file === ''
            || strlen($file) > ProjectPathNormalizer::MAX_PATH_BYTES
            || str_contains($file, "\0")
            || $sessionId === ''
            || strlen($sessionId) > self::MAX_SESSION_ID_BYTES
            || str_contains($sessionId, "\0")) {
            throw new InvalidArgumentException('Runtime test evidence identity exceeds its safe bounds.');
        }
    }

    /**
     * @param list<RuntimeSourceEvidence> $sources
     * @return list<RuntimeSourceEvidence>
     */
    private static function normaliseSources(array $sources): array
    {
        if (count($sources) > self::MAX_SOURCES) {
            throw new LengthException('Runtime test evidence contains too many source files.');
        }

        $byFile = [];

        foreach ($sources as $source) {
            if (! $source instanceof RuntimeSourceEvidence) {
                throw new InvalidArgumentException('Runtime test evidence sources are invalid.');
            }

            $byFile[$source->file()] = isset($byFile[$source->file()])
                ? $byFile[$source->file()]->merge($source)
                : $source;
        }

        ksort($byFile);

        return array_values($byFile);
    }

    /**
     * @param array<int, string> $links
     * @return list<string>
     */
    private static function normaliseLinks(array $links, bool $lower = false): array
    {
        if (count($links) > self::MAX_LINKS_PER_KIND) {
            throw new LengthException('Runtime test evidence contains too many links.');
        }

        $normalised = [];

        foreach ($links as $link) {
            $link = trim($link);

            if ($lower) {
                $link = strtolower($link);
            }

            if ($link === '' || strlen($link) > self::MAX_LINK_BYTES || str_contains($link, "\0")) {
                throw new InvalidArgumentException('Runtime test evidence contains an invalid link.');
            }

            $normalised[$link] = true;
        }

        $values = array_keys($normalised);
        sort($values);

        return $values;
    }

    /**
     * @param array<int, mixed> $values
     * @return list<string>
     */
    private static function stringList(array $values, string $kind): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("Runtime {$kind} evidence must contain strings.");
            }

            $strings[] = $value;
        }

        return $strings;
    }

    private static function normaliseSha256(?string $sha256): ?string
    {
        if ($sha256 === null || $sha256 === '') {
            return null;
        }

        $sha256 = strtolower($sha256);

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Runtime test evidence SHA-256 is invalid.');
        }

        return $sha256;
    }
}
