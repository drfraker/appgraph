<?php

namespace AppGraph\Runtime\PHPUnit;

interface RuntimeCoverageRecorder
{
    /**
     * Start a coverage segment owned by this recorder.
     *
     * Returns false when no supported driver is available or another coverage
     * consumer already owns the process-wide driver.
     */
    public function start(): bool;

    /**
     * Stop the owned segment and return executed source lines by absolute file.
     *
     * @return array<string, list<int>>|null
     */
    public function stop(): ?array;

    /** Stop and discard an owned segment without disturbing foreign coverage. */
    public function abort(): void;

    public function isCollecting(): bool;
}
