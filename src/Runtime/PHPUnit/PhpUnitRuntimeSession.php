<?php

namespace AppGraph\Runtime\PHPUnit;

use AppGraph\Runtime\JsonRuntimeEvidenceStore;
use AppGraph\Runtime\RuntimeEvidenceCollector;
use AppGraph\Runtime\RuntimeEvidenceRegistry;
use AppGraph\Runtime\RuntimeEvidenceSnapshot;
use AppGraph\Runtime\RuntimeTestMetadata;
use PHPUnit\Runner\CodeCoverage as PhpUnitCodeCoverage;
use Throwable;

/**
 * Mutable state shared by the PHPUnit event subscribers in one process.
 *
 * The hybrid direct/piggyback lifecycle follows Pest v5.0.2 TIA, adapted to
 * retain line ranges and generic PHPUnit metadata. See THIRD_PARTY_NOTICES.md.
 */
final class PhpUnitRuntimeSession
{
    /** @var array<string, string> */
    private array $testFilesById = [];

    /** @var array<string, true> */
    private array $finishedTestIds = [];

    /** @var array<string, true> */
    private array $coverageCapturedTestIds = [];

    private ?string $activeTestId = null;

    private ?string $activeMode = null;

    /** @var array{id: string, finished: bool, coverage_captured: bool}|null */
    private ?array $activeCompletionCheckpoint = null;

    private bool $phpUnitCoverageObserved = false;

    private bool $flushed = false;

    public function __construct(
        private readonly RuntimeEvidenceCollector $collector,
        private readonly JsonRuntimeEvidenceStore $store,
        private readonly ?RuntimeCoverageRecorder $directRecorder = null,
        private readonly bool $directCaptureAllowed = true,
    ) {
    }

    public function preparationStarted(object $event): void
    {
        $this->aborted();

        try {
            $metadata = RuntimeTestMetadata::fromPhpUnitEvent($event);

            if ($metadata === null || ! $this->collector->beginTest($metadata)) {
                return;
            }

            $this->activeCompletionCheckpoint = [
                'id' => $metadata->id(),
                'finished' => isset($this->finishedTestIds[$metadata->id()]),
                'coverage_captured' => isset($this->coverageCapturedTestIds[$metadata->id()]),
            ];
            unset(
                $this->finishedTestIds[$metadata->id()],
                $this->coverageCapturedTestIds[$metadata->id()],
            );
            $this->testFilesById[$metadata->id()] = $metadata->file();

            if (PhpUnitCodeCoverage::instance()->isActive()) {
                $this->phpUnitCoverageObserved = true;
                $this->activeTestId = $metadata->id();
                $this->activeMode = 'phpunit';

                return;
            }

            if (! $this->directCaptureAllowed
                || RuntimeTestMetadata::isProcessIsolatedEvent($event)
                || $this->directRecorder === null
                || ! $this->directRecorder->start()) {
                $this->aborted();

                return;
            }

            $this->activeTestId = $metadata->id();
            $this->activeMode = 'direct';
        } catch (Throwable) {
            $this->aborted();
        }
    }

    public function finished(object $event): void
    {
        try {
            $metadata = RuntimeTestMetadata::fromPhpUnitEvent($event);

            if ($metadata === null
                || $this->activeTestId === null
                || $metadata->id() !== $this->activeTestId) {
                $this->aborted();

                return;
            }

            if ($this->activeMode === 'direct') {
                $lineCoverage = $this->directRecorder?->stop();

                if (! is_array($lineCoverage)) {
                    $this->aborted();

                    return;
                }

                foreach ($lineCoverage as $sourceFile => $lines) {
                    $this->collector->recordSourceLines($sourceFile, $lines);
                }

                $this->coverageCapturedTestIds[$metadata->id()] = true;
            }

            if ($this->activeMode !== null) {
                $this->finishedTestIds[$metadata->id()] = true;
            }

            $this->collector->commitTest();
            $this->activeTestId = null;
            $this->activeMode = null;
            $this->activeCompletionCheckpoint = null;
        } catch (Throwable) {
            $this->aborted();
        }
    }

    /** Discard an incomplete setup, skip, or interrupted direct segment. */
    public function aborted(): void
    {
        if ($this->activeMode === 'direct') {
            $this->directRecorder?->abort();
        }

        $this->collector->abortTest();

        if ($this->activeCompletionCheckpoint !== null) {
            $id = $this->activeCompletionCheckpoint['id'];

            if ($this->activeCompletionCheckpoint['finished']) {
                $this->finishedTestIds[$id] = true;
            } else {
                unset($this->finishedTestIds[$id]);
            }

            if ($this->activeCompletionCheckpoint['coverage_captured']) {
                $this->coverageCapturedTestIds[$id] = true;
            } else {
                unset($this->coverageCapturedTestIds[$id]);
            }
        }

        $this->activeTestId = null;
        $this->activeMode = null;
        $this->activeCompletionCheckpoint = null;
    }

    public function flush(): void
    {
        if ($this->flushed) {
            return;
        }

        $this->flushed = true;

        try {
            $this->aborted();

            if ($this->phpUnitCoverageObserved) {
                $coverage = PhpUnitCodeCoverage::instance();

                if ($coverage->isActive()) {
                    $lineCoverage = $coverage->codeCoverage()->getData()->lineCoverage();

                    if (is_array($lineCoverage)) {
                        foreach ($this->collector->recordPhpUnitLineCoverage(
                            $lineCoverage,
                            $this->testFilesById,
                        ) as $testId) {
                            if (isset($this->finishedTestIds[$testId])) {
                                $this->coverageCapturedTestIds[$testId] = true;
                            }
                        }
                    }
                }
            }

            $snapshot = $this->collector->snapshot();
            $finishedTests = array_values(array_filter(
                $snapshot->tests(),
                fn ($test): bool => isset($this->finishedTestIds[$test->id()])
                    && isset($this->coverageCapturedTestIds[$test->id()]),
            ));

            if ($finishedTests !== []) {
                $this->store->merge(RuntimeEvidenceSnapshot::create(
                    $snapshot->sessionId(),
                    $snapshot->generatedAt(),
                    $finishedTests,
                ));
            }
        } catch (Throwable) {
            // Runtime evidence is deliberately best-effort and non-fatal.
        } finally {
            RuntimeEvidenceRegistry::deactivate($this->collector);
        }
    }
}
