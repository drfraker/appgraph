<?php

namespace AppGraph\Runtime\PHPUnit;

use AppGraph\Runtime\RuntimeSourceEvidence;
use AppGraph\Runtime\RuntimeSourceScope;
use AppGraph\Runtime\RuntimeTestEvidence;
use InvalidArgumentException;
use Throwable;
use WeakReference;

/**
 * Direct per-test PCOV/Xdebug recording adapted from Pest v5.0.2's TIA
 * Recorder (MIT). See THIRD_PARTY_NOTICES.md.
 *
 * Coverage drivers are process-global. Xdebug exposes active-state detection,
 * while PCOV does not. For PCOV, AppGraph coordinates its own recorders and
 * refuses to clear queued coverage, but cannot detect a foreign active session
 * that has not recorded anything yet. Direct PCOV use therefore requires
 * exclusive ownership of the process-wide driver.
 */
final class DirectCoverageRecorder implements RuntimeCoverageRecorder
{
    private const DEFAULT_MAX_TOTAL_EXECUTED_LINES = 2_000_000;

    /** @var WeakReference<self>|null */
    private static ?WeakReference $pcovOwner = null;

    private readonly ?string $driver;

    private bool $collecting = false;

    private bool $pcovStartAttempted = false;

    public function __construct(
        private readonly RuntimeSourceScope $scope,
        ?string $detectedDriver = null,
        private readonly int $maxCoveredFiles = RuntimeTestEvidence::MAX_SOURCES,
        private readonly int $maxTotalExecutedLines = self::DEFAULT_MAX_TOTAL_EXECUTED_LINES,
        private readonly int $maxExecutedLinesPerFile = RuntimeSourceEvidence::MAX_EXECUTED_LINES,
    ) {
        if ($maxCoveredFiles < 1 || $maxCoveredFiles > RuntimeTestEvidence::MAX_SOURCES) {
            throw new InvalidArgumentException(sprintf(
                'Runtime coverage file limit must be between 1 and %d.',
                RuntimeTestEvidence::MAX_SOURCES,
            ));
        }

        if ($maxTotalExecutedLines < 1
            || $maxTotalExecutedLines > self::DEFAULT_MAX_TOTAL_EXECUTED_LINES) {
            throw new InvalidArgumentException(sprintf(
                'Runtime coverage total line limit must be between 1 and %d.',
                self::DEFAULT_MAX_TOTAL_EXECUTED_LINES,
            ));
        }

        if ($maxExecutedLinesPerFile < 1
            || $maxExecutedLinesPerFile > RuntimeSourceEvidence::MAX_EXECUTED_LINES) {
            throw new InvalidArgumentException(sprintf(
                'Runtime coverage per-file line limit must be between 1 and %d.',
                RuntimeSourceEvidence::MAX_EXECUTED_LINES,
            ));
        }

        $detectedDriver ??= CoverageDriver::detect();

        $this->driver = in_array($detectedDriver, [CoverageDriver::PCOV, CoverageDriver::XDEBUG], true)
            ? $detectedDriver
            : null;
    }

    public function start(): bool
    {
        if ($this->collecting || $this->driver === null) {
            return false;
        }

        try {
            if ($this->driver === CoverageDriver::PCOV) {
                return $this->startPcov();
            }

            return $this->startXdebug();
        } catch (Throwable) {
            $this->collecting = false;
            $this->discardDriverCoverage();

            return false;
        }
    }

    public function __destruct()
    {
        $this->abort();
    }

    public function stop(): ?array
    {
        if (! $this->collecting || $this->driver === null) {
            return null;
        }

        if (! $this->driverIsCollecting()) {
            $this->collecting = false;

            if ($this->driver === CoverageDriver::PCOV) {
                $this->clearAndReleasePcovOwnership();
            }

            return null;
        }

        $this->collecting = false;

        try {
            $coverage = $this->driver === CoverageDriver::PCOV
                ? $this->stopPcov()
                : $this->stopXdebug();

            if ($coverage === null) {
                return null;
            }

            $executed = $this->executedLines($coverage);

            if ($executed === null) {
                return null;
            }

            // PCOV exposes no public "is collecting" API. Treat an empty trace
            // as indeterminate so a test that stops PCOV itself cannot erase
            // previously useful evidence with a false-empty capture.
            return $this->driver === CoverageDriver::PCOV && $executed === []
                ? null
                : $executed;
        } catch (Throwable) {
            $this->discardDriverCoverage();

            return null;
        }
    }

    public function abort(): void
    {
        if (! $this->collecting) {
            return;
        }

        $this->collecting = false;
        $this->discardDriverCoverage();
    }

    public function isCollecting(): bool
    {
        return $this->collecting;
    }

    private function startPcov(): bool
    {
        $owner = self::$pcovOwner?->get();

        if ($owner !== null && $owner !== $this) {
            return false;
        }

        if (! function_exists('pcov\\start')
            || ! function_exists('pcov\\stop')
            || ! function_exists('pcov\\clear')
            || ! function_exists('pcov\\waiting')
            || ! function_exists('pcov\\collect')
            || (function_exists('pcov\\collecting') && @\pcov\collecting())) {
            return false;
        }

        $waiting = @\pcov\waiting();

        if (! is_array($waiting) || $waiting !== []) {
            return false;
        }

        self::$pcovOwner = WeakReference::create($this);
        @\pcov\clear();
        $this->pcovStartAttempted = true;
        @\pcov\start();

        if (function_exists('pcov\\collecting') && ! @\pcov\collecting()) {
            $this->discardDriverCoverage();

            return false;
        }

        $this->collecting = true;

        return true;
    }

    private function startXdebug(): bool
    {
        if (! function_exists('xdebug_start_code_coverage')
            || ! function_exists('xdebug_get_code_coverage')
            || ! function_exists('xdebug_stop_code_coverage')
            || ! function_exists('xdebug_code_coverage_started')
            || @xdebug_code_coverage_started()) {
            return false;
        }

        @xdebug_start_code_coverage();

        if (! @xdebug_code_coverage_started()) {
            return false;
        }

        $this->collecting = true;

        return true;
    }

    /** @return array<string, mixed>|null */
    private function stopPcov(): ?array
    {
        try {
            @\pcov\stop();
            $waiting = @\pcov\waiting();

            if (! is_array($waiting)) {
                return null;
            }

            $files = [];

            foreach ($waiting as $file) {
                if (! is_string($file) || ! $this->scope->contains($file)) {
                    continue;
                }

                $real = realpath($file);

                if ($real === false || ! $this->scope->contains($real)) {
                    continue;
                }

                $files[$real] = true;

                if (count($files) > $this->maxCoveredFiles) {
                    return null;
                }
            }

            $paths = array_keys($files);
            sort($paths, SORT_STRING);
            $inclusive = defined('pcov\\inclusive') ? constant('pcov\\inclusive') : 1;
            $coverage = @\pcov\collect($inclusive, $paths);

            return is_array($coverage) ? $coverage : null;
        } finally {
            $this->clearAndReleasePcovOwnership();
        }
    }

    /** @return array<string, mixed>|null */
    private function stopXdebug(): ?array
    {
        $coverage = @xdebug_get_code_coverage();
        @xdebug_stop_code_coverage(true);

        return is_array($coverage) ? $coverage : null;
    }

    private function driverIsCollecting(): bool
    {
        try {
            if ($this->driver === CoverageDriver::PCOV) {
                if (! $this->ownsPcov()) {
                    return false;
                }

                return ! function_exists('pcov\\collecting') || @\pcov\collecting();
            }

            return $this->driver === CoverageDriver::XDEBUG
                && function_exists('xdebug_code_coverage_started')
                && @xdebug_code_coverage_started();
        } catch (Throwable) {
            return false;
        }
    }

    private function discardDriverCoverage(): void
    {
        if ($this->driver === CoverageDriver::PCOV) {
            try {
                if ($this->ownsPcov()
                    && $this->pcovStartAttempted
                    && (! function_exists('pcov\\collecting') || @\pcov\collecting())) {
                    @\pcov\stop();
                }
            } catch (Throwable) {
                // Continue to clear and release ownership below.
            } finally {
                $this->clearAndReleasePcovOwnership();
            }

            return;
        }

        try {
            if ($this->driver === CoverageDriver::XDEBUG
                && function_exists('xdebug_code_coverage_started')
                && @xdebug_code_coverage_started()
                && function_exists('xdebug_stop_code_coverage')) {
                @xdebug_stop_code_coverage(true);
            }
        } catch (Throwable) {
            // Cleanup is best-effort and must never affect the test result.
        }
    }

    private function clearAndReleasePcovOwnership(): void
    {
        try {
            if ($this->ownsPcov() && function_exists('pcov\\clear')) {
                @\pcov\clear();
            }
        } catch (Throwable) {
            // Ownership still has to be released when cleanup fails.
        } finally {
            $this->pcovStartAttempted = false;
            $this->releasePcovOwnership();
        }
    }

    private function ownsPcov(): bool
    {
        return self::$pcovOwner?->get() === $this;
    }

    private function releasePcovOwnership(): void
    {
        if ($this->ownsPcov()) {
            self::$pcovOwner = null;
        }
    }

    /**
     * @param array<string, mixed> $coverage
     * @return array<string, list<int>>|null
     */
    private function executedLines(array $coverage): ?array
    {
        $result = [];
        $totalLines = 0;

        foreach ($coverage as $file => $lines) {
            if (! is_string($file)
                || ! is_array($lines)
                || ! $this->scope->contains($file)) {
                continue;
            }

            $real = realpath($file);

            // Resolve and re-check after the initial scope test so a path
            // swapped to an outside symlink cannot enter persisted evidence.
            if ($real === false || ! $this->scope->contains($real)) {
                continue;
            }

            $absolute = $real;
            $fileLines = [];

            foreach ($lines as $line => $hits) {
                if ((! is_int($line) && ! ctype_digit((string) $line))
                    || ! is_int($hits)
                    || $hits <= 0) {
                    continue;
                }

                $line = (int) $line;

                if ($line < 1 || isset($fileLines[$line])) {
                    continue;
                }

                $fileLines[$line] = true;

                if (count($fileLines) > $this->maxExecutedLinesPerFile) {
                    return null;
                }
            }

            if ($fileLines === []) {
                continue;
            }

            if (! isset($result[$absolute]) && count($result) >= $this->maxCoveredFiles) {
                return null;
            }

            $result[$absolute] ??= [];

            foreach (array_keys($fileLines) as $line) {
                if (isset($result[$absolute][$line])) {
                    continue;
                }

                if (count($result[$absolute]) >= $this->maxExecutedLinesPerFile
                    || $totalLines >= $this->maxTotalExecutedLines) {
                    return null;
                }

                $result[$absolute][$line] = true;
                $totalLines++;
            }
        }

        foreach ($result as $file => $lines) {
            $numbers = array_keys($lines);
            sort($numbers, SORT_NUMERIC);
            $result[$file] = $numbers;
        }

        ksort($result, SORT_STRING);

        /** @var array<string, list<int>> $result */
        return $result;
    }
}
