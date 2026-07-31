<?php

namespace AppGraph\Runtime;

use InvalidArgumentException;
use JsonException;
use LengthException;

/**
 * The versioned test-to-source snapshot is adapted from Pest v5.0.2's TIA
 * graph persistence concept (MIT). Its bounded schema is AppGraph-specific.
 * See THIRD_PARTY_NOTICES.md.
 */
final class RuntimeEvidenceSnapshot
{
    public const SCHEMA_VERSION = 1;

    public const MAX_JSON_BYTES = 16 * 1024 * 1024;

    public const MAX_TESTS = 50_000;

    /** @param list<RuntimeTestEvidence> $tests */
    private function __construct(
        private readonly string $sessionId,
        private readonly string $generatedAt,
        private readonly array $tests,
    ) {
    }

    /** @param list<RuntimeTestEvidence> $tests */
    public static function create(string $sessionId, string $generatedAt, array $tests = []): self
    {
        if ($sessionId === ''
            || strlen($sessionId) > RuntimeTestEvidence::MAX_SESSION_ID_BYTES
            || str_contains($sessionId, "\0")) {
            throw new InvalidArgumentException('Runtime evidence session id is invalid.');
        }

        if (count($tests) > self::MAX_TESTS) {
            throw new LengthException('Runtime evidence snapshot contains too many tests.');
        }

        $byKey = [];

        foreach ($tests as $test) {
            if (! $test instanceof RuntimeTestEvidence) {
                throw new InvalidArgumentException('Runtime evidence snapshot tests are invalid.');
            }

            $byKey[$test->key()] = isset($byKey[$test->key()])
                ? $byKey[$test->key()]->merge($test)
                : $test;
        }

        $tests = array_values($byKey);
        usort(
            $tests,
            static fn (RuntimeTestEvidence $left, RuntimeTestEvidence $right): int =>
                [$left->file(), $left->id()] <=> [$right->file(), $right->id()],
        );

        return new self($sessionId, RuntimeTimestamp::normalise($generatedAt), $tests);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $projectRoot): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported runtime evidence snapshot schema.');
        }

        $session = $data['session'] ?? null;
        $rawTests = $data['tests'] ?? null;

        if (! is_array($session)
            || ! is_string($session['id'] ?? null)
            || ! is_string($session['generated_at'] ?? null)
            || ! is_array($rawTests)) {
            throw new InvalidArgumentException('Runtime evidence snapshot structure is invalid.');
        }

        if (count($rawTests) > self::MAX_TESTS) {
            throw new LengthException('Runtime evidence snapshot contains too many tests.');
        }

        $paths = new ProjectPathNormalizer($projectRoot);
        $tests = [];

        foreach ($rawTests as $rawTest) {
            if (! is_array($rawTest)) {
                throw new InvalidArgumentException('Runtime evidence snapshot test entries must be objects.');
            }

            $tests[] = RuntimeTestEvidence::fromArray($rawTest, $paths);
        }

        return self::create($session['id'], $session['generated_at'], $tests);
    }

    /** @throws JsonException */
    public static function fromJson(string $json, string $projectRoot): self
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new LengthException('Runtime evidence snapshot exceeds the maximum JSON size.');
        }

        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Runtime evidence snapshot JSON must contain an object.');
        }

        return self::fromArray($data, $projectRoot);
    }

    public function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function generatedAt(): string
    {
        return $this->generatedAt;
    }

    /** @return list<RuntimeTestEvidence> */
    public function tests(): array
    {
        return $this->tests;
    }

    /** @return list<string> */
    public function testFiles(): array
    {
        $files = [];

        foreach ($this->tests as $test) {
            $files[$test->file()] = true;
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * Merge cumulative snapshots without losing observations made by another
     * PHPUnit or ParaTest worker. Entry capture times resolve repeated tests.
     */
    public function merge(self $other): self
    {
        $tests = [];

        foreach ([...$this->tests, ...$other->tests] as $test) {
            $tests[$test->key()] = isset($tests[$test->key()])
                ? $tests[$test->key()]->merge($test)
                : $test;
        }

        if ($this->generatedAt === $other->generatedAt) {
            $latest = strcmp($this->sessionId, $other->sessionId) >= 0 ? $this : $other;
        } else {
            $latest = $this->generatedAt > $other->generatedAt ? $this : $other;
        }

        return self::create(
            $latest->sessionId,
            max($this->generatedAt, $other->generatedAt),
            array_values($tests),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'session' => [
                'id' => $this->sessionId,
                'generated_at' => $this->generatedAt,
            ],
            'tests' => array_map(
                static fn (RuntimeTestEvidence $test): array => $test->toArray(),
                $this->tests,
            ),
        ];
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";

        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new LengthException('Runtime evidence snapshot exceeds the maximum JSON size.');
        }

        return $json;
    }
}
