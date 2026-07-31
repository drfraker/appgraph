<?php

namespace AppGraph\Runtime;

use InvalidArgumentException;
use LengthException;

final class RuntimeSourceEvidence
{
    public const MAX_RANGES = 8192;

    public const MAX_EXECUTED_LINES = 1_000_000;

    /**
     * @param list<array{0: int, 1: int}> $ranges
     */
    private function __construct(
        private readonly string $file,
        private readonly ?string $sha256,
        private readonly array $ranges,
    ) {
    }

    /**
     * @param iterable<int> $executedLines
     */
    public static function fromExecutedLines(
        string $file,
        ?string $sha256,
        iterable $executedLines,
    ): self {
        $lines = [];
        $seen = 0;

        foreach ($executedLines as $line) {
            $seen++;

            if ($seen > self::MAX_EXECUTED_LINES) {
                throw new LengthException('Runtime source evidence contains too many executed lines.');
            }

            if (! is_int($line)) {
                throw new InvalidArgumentException('Runtime source line numbers must be integers.');
            }

            if ($line > 0) {
                $lines[$line] = true;
            }
        }

        $numbers = array_keys($lines);
        sort($numbers, SORT_NUMERIC);
        $ranges = [];

        foreach ($numbers as $line) {
            $lastIndex = count($ranges) - 1;

            if ($lastIndex >= 0 && $line === $ranges[$lastIndex][1] + 1) {
                $ranges[$lastIndex][1] = $line;

                continue;
            }

            $ranges[] = [$line, $line];

            if (count($ranges) > self::MAX_RANGES) {
                throw new LengthException('Runtime source evidence contains too many line ranges.');
            }
        }

        return self::create($file, $sha256, $ranges);
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     */
    public static function create(string $file, ?string $sha256, array $ranges): self
    {
        if ($file === '' || strlen($file) > ProjectPathNormalizer::MAX_PATH_BYTES) {
            throw new InvalidArgumentException('Runtime source evidence file is invalid.');
        }

        return new self(
            $file,
            self::normaliseSha256($sha256),
            self::normaliseRanges($ranges),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ProjectPathNormalizer $paths): self
    {
        $rawFile = $data['file'] ?? null;

        if (! is_string($rawFile) || ($file = $paths->relative($rawFile)) === null) {
            throw new InvalidArgumentException('Runtime source evidence contains an unsafe file path.');
        }

        $sha256 = $data['sha256'] ?? null;
        $ranges = $data['ranges'] ?? [];

        if ($sha256 !== null && ! is_string($sha256)) {
            throw new InvalidArgumentException('Runtime source evidence hash must be a string or null.');
        }

        if (! is_array($ranges)) {
            throw new InvalidArgumentException('Runtime source evidence ranges must be an array.');
        }

        return self::create($file, $sha256, $ranges);
    }

    public function file(): string
    {
        return $this->file;
    }

    public function sha256(): ?string
    {
        return $this->sha256;
    }

    /** @return list<array{0: int, 1: int}> */
    public function ranges(): array
    {
        return $this->ranges;
    }

    public function merge(self $other): self
    {
        if ($this->file !== $other->file) {
            throw new InvalidArgumentException('Runtime source evidence can only merge the same file.');
        }

        $sha256 = $this->sha256 ?? $other->sha256;

        if ($this->sha256 !== null && $other->sha256 !== null && $this->sha256 !== $other->sha256) {
            $sha256 = null;
        }

        return self::create($this->file, $sha256, [...$this->ranges, ...$other->ranges]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['file' => $this->file];

        if ($this->sha256 !== null) {
            $data['sha256'] = $this->sha256;
        }

        $data['ranges'] = $this->ranges;

        return $data;
    }

    /**
     * @param array<int, mixed> $ranges
     * @return list<array{0: int, 1: int}>
     */
    private static function normaliseRanges(array $ranges): array
    {
        if (count($ranges) > self::MAX_RANGES) {
            throw new LengthException('Runtime source evidence contains too many line ranges.');
        }

        $normalised = [];

        foreach ($ranges as $range) {
            if (! is_array($range)
                || ! array_key_exists(0, $range)
                || ! array_key_exists(1, $range)
                || ! is_int($range[0])
                || ! is_int($range[1])
                || $range[0] < 1
                || $range[1] < $range[0]) {
                throw new InvalidArgumentException('Runtime source evidence contains an invalid line range.');
            }

            $normalised[] = [$range[0], $range[1]];
        }

        usort($normalised, static fn (array $left, array $right): int => $left <=> $right);
        $merged = [];

        foreach ($normalised as [$start, $end]) {
            $lastIndex = count($merged) - 1;

            if ($lastIndex >= 0 && $start <= $merged[$lastIndex][1] + 1) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }

    private static function normaliseSha256(?string $sha256): ?string
    {
        if ($sha256 === null || $sha256 === '') {
            return null;
        }

        $sha256 = strtolower($sha256);

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Runtime source evidence SHA-256 is invalid.');
        }

        return $sha256;
    }
}
