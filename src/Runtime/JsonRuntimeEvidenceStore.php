<?php

namespace AppGraph\Runtime;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Snapshot persistence is adapted from Pest v5.0.2 TIA state/partial storage
 * concepts (MIT), with AppGraph-owned JSON validation and locked atomic merges.
 * See THIRD_PARTY_NOTICES.md.
 */
final class JsonRuntimeEvidenceStore implements RuntimeEvidenceStore
{
    private readonly ProjectPathNormalizer $projectPaths;

    public function __construct(
        private readonly string $snapshotPath,
        string $projectRoot,
    ) {
        if ($snapshotPath === '' || str_contains($snapshotPath, "\0")) {
            throw new RuntimeException('Runtime evidence snapshot path is invalid.');
        }

        $this->projectPaths = new ProjectPathNormalizer($projectRoot);
    }

    public function path(): string
    {
        return $this->snapshotPath;
    }

    public function modifiedAt(): ?int
    {
        clearstatcache(true, $this->snapshotPath);
        $modifiedAt = @filemtime($this->snapshotPath);

        return $modifiedAt === false ? null : $modifiedAt;
    }

    public function read(): ?RuntimeEvidenceSnapshot
    {
        if (! is_file($this->snapshotPath)) {
            return null;
        }

        return $this->withLock(LOCK_SH, fn (): ?RuntimeEvidenceSnapshot => $this->readUnlocked());
    }

    public function readSource(string $json): RuntimeEvidenceSnapshot
    {
        return RuntimeEvidenceSnapshot::fromJson($json, $this->projectPaths->root());
    }

    public function write(RuntimeEvidenceSnapshot $snapshot): void
    {
        $this->withLock(LOCK_EX, function () use ($snapshot): void {
            $this->writeUnlocked($snapshot);
        });
    }

    public function merge(RuntimeEvidenceSnapshot $snapshot): RuntimeEvidenceSnapshot
    {
        return $this->withLock(LOCK_EX, function () use ($snapshot): RuntimeEvidenceSnapshot {
            $current = $this->readUnlocked();
            $merged = $current === null ? $snapshot : $current->merge($snapshot);

            $this->writeUnlocked($merged);

            return $merged;
        });
    }

    private function readUnlocked(): ?RuntimeEvidenceSnapshot
    {
        if (! is_file($this->snapshotPath)) {
            return null;
        }

        $size = @filesize($this->snapshotPath);

        if (is_int($size) && $size > RuntimeEvidenceSnapshot::MAX_JSON_BYTES) {
            throw new RuntimeException('Runtime evidence snapshot exceeds the maximum JSON size.');
        }

        $json = @file_get_contents($this->snapshotPath);

        if ($json === false) {
            throw new RuntimeException("Unable to read runtime evidence snapshot [{$this->snapshotPath}].");
        }

        return $this->readSource($json);
    }

    private function writeUnlocked(RuntimeEvidenceSnapshot $snapshot): void
    {
        $directory = dirname($this->snapshotPath);
        $this->ensureDirectory($directory);

        $json = $snapshot->toJson();
        $temporary = $this->snapshotPath.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw new RuntimeException("Unable to create temporary runtime evidence snapshot [{$temporary}].");
        }

        try {
            $remaining = $json;

            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if ($written === false || $written === 0) {
                    throw new RuntimeException("Unable to write temporary runtime evidence snapshot [{$temporary}].");
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($handle)) {
                throw new RuntimeException("Unable to flush temporary runtime evidence snapshot [{$temporary}].");
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException("Unable to sync temporary runtime evidence snapshot [{$temporary}].");
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            @unlink($temporary);

            throw $throwable;
        }

        fclose($handle);
        @chmod($temporary, 0644);

        if (! @rename($temporary, $this->snapshotPath)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to atomically publish runtime evidence snapshot [{$this->snapshotPath}].");
        }
    }

    /** @template T */
    /**
     * @param Closure(): T $callback
     * @return T
     */
    private function withLock(int $operation, Closure $callback): mixed
    {
        $directory = dirname($this->snapshotPath);
        $this->ensureDirectory($directory);

        $lockPath = $this->snapshotPath.'.lock';
        $handle = @fopen($lockPath, 'c+b');

        if ($handle === false) {
            throw new RuntimeException("Unable to open runtime evidence lock [{$lockPath}].");
        }

        if (! flock($handle, $operation)) {
            fclose($handle);

            throw new RuntimeException("Unable to acquire runtime evidence lock [{$lockPath}].");
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create runtime evidence directory [{$directory}].");
        }
    }
}
